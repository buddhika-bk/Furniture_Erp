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
        $l = Leave::findOrFail($id);
        abort_if($l->status !== 'pending', 422, 'Leave already decided.');
        $l->update(['status' => $decision === 'approve' ? 'approved' : 'rejected']);
        return response()->json(['data' => $l]);
    }

    public function storePeriod(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:255|unique:payroll_periods,name', 'start_date' => 'required|date', 'end_date' => 'required|date|after_or_equal:start_date']);
        return response()->json(['data' => PayrollPeriod::create($d)], 201);
    }

    public function generate(int $id) { return response()->json(['data' => $this->payroll->generate(PayrollPeriod::findOrFail($id))]); }
    public function advance(int $id) { return response()->json(['data' => $this->payroll->advance(PayrollPeriod::findOrFail($id))]); }
    public function period(int $id) { return response()->json(['data' => PayrollPeriod::with('payslips.employee')->findOrFail($id)]); }
}
