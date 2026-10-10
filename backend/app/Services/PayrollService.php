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

    public function generate(PayrollPeriod $p): PayrollPeriod
    {
        if ($p->status !== 'draft') throw new BusinessRuleException('Payslips can only be generated while the period is a draft.');
        return DB::transaction(function () use ($p) {
            $p->payslips()->delete();
            foreach (Employee::where('status', 'active')->get() as $e) {
                $otHours = (float) Attendance::where('employee_id', $e->id)->whereBetween('work_date', [$p->start_date, $p->end_date])->sum('overtime_hours');
                $ot = round($otHours * (float) $e->ot_rate, 2);
                $commission = (float) Commission::where('employee_id', $e->id)->where('status', 'approved')->whereNull('payslip_id')->sum('amount');
                $gross = (float) $e->basic_salary + (float) $e->allowances + $ot + $commission;
                Payslip::create([
                    'payroll_period_id' => $p->id, 'employee_id' => $e->id, 'basic' => $e->basic_salary, 'allowances' => $e->allowances,
                    'overtime_hours' => $otHours, 'overtime_amount' => $ot, 'commission' => $commission,
                    'deductions' => $e->deductions, 'gross' => $gross, 'net' => $gross - (float) $e->deductions,
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
