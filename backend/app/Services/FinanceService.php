<?php
namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\FinancialTransaction;
use App\Models\Payment;
use App\Support\DocNumber;
use Illuminate\Support\Facades\DB;

class FinanceService
{
    /** Money in/out. Always references the originating document (spec section 16). */
    public function post(int $accountId, string $direction, float $amount, string $category, ?string $refType, ?int $refId, ?int $branchId = null, ?string $desc = null): FinancialTransaction
    {
        if ($amount <= 0) throw new BusinessRuleException('Amount must be positive.');
        return DB::transaction(function () use ($accountId, $direction, $amount, $category, $refType, $refId, $branchId, $desc) {
            $acc = Account::lockForUpdate()->findOrFail($accountId);
            $acc->balance = (float) $acc->balance + ($direction === 'in' ? $amount : -$amount);
            $acc->save();
            return FinancialTransaction::create([
                'account_id' => $accountId, 'branch_id' => $branchId, 'direction' => $direction, 'amount' => $amount,
                'category' => $category, 'ref_type' => $refType, 'ref_id' => $refId, 'description' => $desc, 'user_id' => auth_user_id(),
            ]);
        });
    }

    /** Pick the account that receives a payment method for a branch (cash drawer vs bank). */
    public function accountFor(?int $branchId, string $method): Account
    {
        $type = $method === 'cash' ? 'cash' : 'bank';
        $q = Account::where('type', $type);
        $acc = ($branchId ? (clone $q)->where('branch_id', $branchId)->first() : null)
            ?? (clone $q)->whereNull('branch_id')->first() ?? Account::where('type', $type)->first() ?? Account::first();
        if (!$acc) throw new BusinessRuleException('No finance account is configured.');
        return $acc;
    }

    public function createExpense(array $d): Expense
    {
        $d['number'] = DocNumber::next('EXP');
        $d['status'] = 'pending';
        $d['created_by'] = auth_user_id();
        return Expense::create($d);
    }

    public function decideExpense(Expense $e, bool $approve): Expense
    {
        if ($e->status !== 'pending') throw new BusinessRuleException('Expense already decided.');
        return DB::transaction(function () use ($e, $approve) {
            if ($approve) {
                $accId = $e->account_id ?? $this->accountFor($e->branch_id, 'cash')->id;
                $this->post($accId, 'out', (float) $e->amount, 'expense', 'expense', $e->id, $e->branch_id, $e->description);
                $e->account_id = $accId;
            }
            $e->status = $approve ? 'approved' : 'rejected';
            $e->approved_by = auth_user_id();
            $e->approved_at = now();
            $e->save();
            return $e;
        });
    }

    /** Customer pays down their credit balance (receivable collection). */
    public function collectFromCustomer(Customer $c, string $method, float $amount, ?int $branchId, ?string $ref = null): Payment
    {
        if ($amount <= 0) throw new BusinessRuleException('Amount must be positive.');
        if ($method === 'credit') throw new BusinessRuleException('Choose cash, card or bank.');
        return DB::transaction(function () use ($c, $method, $amount, $branchId, $ref) {
            $c = Customer::lockForUpdate()->find($c->id);
            if ($amount > (float) $c->credit_balance + 0.005) throw new BusinessRuleException('Payment exceeds the outstanding balance.');
            $acc = $this->accountFor($branchId, $method);
            $p = Payment::create([
                'customer_id' => $c->id, 'branch_id' => $branchId, 'account_id' => $acc->id, 'method' => $method,
                'direction' => 'receipt', 'amount' => $amount, 'reference' => $ref, 'user_id' => auth_user_id(),
            ]);
            $c->credit_balance = max(0, (float) $c->credit_balance - $amount);
            $c->save();
            $this->post($acc->id, 'in', $amount, 'customer_receipt', 'payment', $p->id, $branchId, "Receipt from {$c->name}");
            return $p;
        });
    }
}
