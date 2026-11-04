<?php
namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Attendance;
use App\Models\Commission;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use Illuminate\Support\Facades\DB;

/** Payroll periods: draft > reviewed > approved > finalized. Finalized periods are immutable. */
class PayrollService
{
    public const NEXT = ['draft' => 'reviewed', 'reviewed' => 'approved', 'approved' => 'finalized'];

    public function __construct(private FinanceService $finance) {}

    public function attendanceDetails(int $employeeId, PayrollPeriod $p): array
    {
        $rows = Attendance::where('employee_id', $employeeId)->whereBetween('work_date', [$p->start_date, $p->end_date])->orderBy('work_date')->get();
        $working = $rows->whereIn('status', ['present', 'late'])->values()->map(fn ($r) => $r->only(['work_date', 'status', 'check_in', 'check_out', 'worked_hours', 'overtime_hours']))->all();
        $days = [];
        foreach ($rows->where('status', 'leave') as $r) $days[$r->work_date] = ['date' => $r->work_date, 'type' => 'Attendance leave', 'reason' => '', 'source' => 'Attendance'];
        $leaves = \App\Models\Leave::where('employee_id', $employeeId)->where('status', 'approved')->where('from_date', '<=', $p->end_date)->where('to_date', '>=', $p->start_date)->orderBy('id')->get();
        foreach ($leaves as $leave) {
            $start = max($leave->from_date, $p->start_date);
            $end = min($leave->to_date, $p->end_date);
            foreach (\Carbon\CarbonPeriod::create($start, $end) as $day) {
                $date = $day->format('Y-m-d');
                $days[$date] = ['date' => $date, 'type' => $leave->type, 'reason' => $leave->reason, 'source' => 'Approved leave'];
            }
        }
        ksort($days);
        return ['working_days' => count($working), 'worked_hours' => (float) $rows->whereIn('status', ['present', 'late'])->sum('worked_hours'),
            'leave_days' => count($days), 'working_dates' => $working, 'leave_dates' => array_values($days),
            'absent_dates' => $rows->where('status', 'absent')->pluck('work_date')->values()->all(),
            'overtime_dates' => $rows->filter(fn ($r) => (float) $r->overtime_hours > 0)->values()->map(fn ($r) => $r->only(['work_date', 'status', 'overtime_hours']))->all(),
            'captured_at' => now()->toIso8601String()];
    }

    public function generate(PayrollPeriod $p): PayrollPeriod
    {
        if ($p->status !== 'draft') throw new BusinessRuleException('Payslips can only be generated while the period is a draft.');
        return DB::transaction(function () use ($p) {
            $p->payslips()->delete();
            foreach (Employee::whereIn('status', ['active', 'on_leave'])->get() as $e) {
                $otHours = (float) Attendance::where('employee_id', $e->id)->whereBetween('work_date', [$p->start_date, $p->end_date])->sum('overtime_hours');
                $ot = round($otHours * (float) $e->ot_rate, 2);
                $commission = (float) Commission::where('employee_id', $e->id)->where('status', 'approved')->whereNull('payslip_id')->sum('amount');
                $gross = (float) $e->basic_salary + (float) $e->allowances + $ot + $commission;
                Payslip::create([
                    'payroll_period_id' => $p->id, 'employee_id' => $e->id, 'basic' => $e->basic_salary, 'allowances' => $e->allowances,
                    'overtime_hours' => $otHours, 'overtime_amount' => $ot, 'commission' => $commission,
                    'deductions' => $e->deductions, 'gross' => $gross, 'net' => $gross - (float) $e->deductions,
                    'attendance_snapshot' => $this->attendanceDetails($e->id, $p) + ['ot_rate' => (float) $e->ot_rate],
                ]);
            }
            return $p->load('payslips.employee');
        });
    }

    public function advance(PayrollPeriod $p): PayrollPeriod
    {
        $next = self::NEXT[$p->status] ?? null;
        if (!$next) throw new BusinessRuleException('This period is already finalized.');
        if ($p->payslips()->count() === 0) throw new BusinessRuleException('Generate payslips first.');
        return DB::transaction(function () use ($p, $next) {
            $p = PayrollPeriod::lockForUpdate()->findOrFail($p->id);
            $col = ['reviewed' => 'reviewed_by', 'approved' => 'approved_by', 'finalized' => 'finalized_by'][$next];
            $p->update(['status' => $next, $col => auth_user_id()]);
            if ($next === 'finalized') {
                $net = (float) $p->payslips()->sum('net');
                foreach ($p->payslips()->get() as $slip) {
                    Commission::where('employee_id', $slip->employee_id)->where('status', 'approved')->whereNull('payslip_id')
                        ->update(['status' => 'paid', 'payslip_id' => $slip->id]);
                }
                if ($net > 0) {
                    $acc = $this->finance->accountFor(null, 'bank');
                    $this->finance->post($acc->id, 'out', $net, 'payroll', 'payroll_period', $p->id, null, "Payroll {$p->name}");
                }
            }
            return $p;
        });
    }
}
