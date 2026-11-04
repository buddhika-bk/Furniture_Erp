<?php
namespace App\Http\Controllers;

use App\Models\Leave;
use App\Models\PayrollPeriod;
use App\Services\PayrollService;
use Illuminate\Http\Request;

class HrController extends Controller
{
    public function __construct(private PayrollService $payroll) {}

    public function decideLeave(int $id, string $decision)
    {
        $l = $this->scoped('leaves', $id);
        abort_if($l->status !== 'pending', 422, 'Leave already decided.');
        $l->update(['status' => $decision === 'approve' ? 'approved' : 'rejected']);
        return response()->json(['data' => $l]);
    }

    public function storePeriod(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:255|unique:payroll_periods,name', 'start_date' => 'required|date', 'end_date' => 'required|date|after_or_equal:start_date']);
        return response()->json(['data' => PayrollPeriod::create($d)], 201);
    }

    public function generate(int $id) { abort_if(auth_user()->branchScope(),403,'Organization-wide payroll requires organization access.'); return response()->json(['data' => $this->payroll->generate(PayrollPeriod::findOrFail($id))]); }
    public function advance(int $id) { abort_if(auth_user()->branchScope(),403,'Organization-wide payroll requires organization access.'); return response()->json(['data' => $this->payroll->advance(PayrollPeriod::findOrFail($id))]); }
    public function period(int $id)
    {
        $p = PayrollPeriod::with(['payslips' => fn ($q) => $q->with('employee')->when(auth_user()->branchScope(), fn ($q, $branch) => $q->whereHas('employee', fn ($e) => $e->where('branch_id', $branch)))])->findOrFail($id);
        foreach ($p->payslips as $slip) {
            $slip->setAttribute('attendance_details', $slip->attendance_snapshot ?? $this->payroll->attendanceDetails($slip->employee_id, $p));
            $slip->setAttribute('attendance_source', $slip->attendance_snapshot ? 'snapshot' : 'live');
        }
        return response()->json(['data' => $p]);
    }
}
