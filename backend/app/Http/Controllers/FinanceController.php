<?php
namespace App\Http\Controllers;

use App\Models\Commission;
use App\Models\Expense;
use App\Services\FinanceService;
use Illuminate\Http\Request;

class FinanceController extends Controller
{
    public function __construct(private FinanceService $finance) {}

    public function storeExpense(Request $r)
    {
        $d = $r->validate(['expense_category_id' => 'required|exists:expense_categories,id', 'branch_id' => 'nullable|exists:branches,id', 'campaign_id' => 'nullable|exists:campaigns,id', 'account_id' => 'nullable|exists:accounts,id',
            'amount' => 'required|numeric|gt:0', 'expense_date' => 'required|date', 'description' => 'nullable|string|max:255']);
        if ($s = auth_user()->branchScope()) $d['branch_id'] = $s;
        return response()->json(['data' => $this->finance->createExpense($d)], 201);
    }

    public function decideExpense(int $id, string $decision)
    {
        return response()->json(['data' => $this->finance->decideExpense(Expense::findOrFail($id), $decision === 'approve')]);
    }

    public function approveCommission(int $id)
    {
        $c = Commission::findOrFail($id);
        abort_if($c->status !== 'pending', 422, 'Commission already decided.');
        $c->update(['status' => 'approved', 'approved_by' => auth_user_id(), 'approved_at' => now()]);
        return response()->json(['data' => $c]);
    }
}
