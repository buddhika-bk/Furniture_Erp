<?php
namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\CashierSession;
use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\Customer;
use App\Models\DiscountRule;
use App\Models\Invoice;
use App\Models\LoyaltyLedger;
use App\Models\LoyaltyTier;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesReturn;
use App\Models\Warehouse;
use App\Support\DocNumber;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/** POS checkout, returns, cashier sessions, loyalty and commission - all backend rules. */
class SalesService
{
    public function __construct(private InventoryService $inventory, private FinanceService $finance) {}

    public function openSession(int $branchId, float $openingCash): CashierSession
    {
        if (CashierSession::where('user_id', auth_user_id())->where('status', 'open')->exists()) {
            throw new BusinessRuleException('You already have an open cashier session.');
        }
        return CashierSession::create(['branch_id' => $branchId, 'user_id' => auth_user_id(), 'opening_cash' => $openingCash, 'expected_cash' => $openingCash]);
    }

    public function closeSession(CashierSession $s, float $countedCash, ?string $notes): CashierSession
    {
        if ($s->status !== 'open') throw new BusinessRuleException('Session is already closed.');
        $s->update([
            'closing_cash' => $countedCash, 'variance' => $countedCash - (float) $s->expected_cash,
            'status' => 'closed', 'closed_at' => now(), 'notes' => $notes,
        ]);
        return $s;
    }

    public function checkout(array $d): Invoice
    {
        if (empty($d['items'])) throw new BusinessRuleException('The cart is empty.');
        return DB::transaction(function () use ($d) {
            $branchId = (int) $d['branch_id'];
            $wh = Warehouse::where('branch_id', $branchId)->where('type', 'showroom')->first()
                ?? Warehouse::where('branch_id', $branchId)->first();
            if (!$wh) throw new BusinessRuleException('This branch has no stock location.');

            // 1. price lines from the database (never trust client prices)
            $lines = []; $subtotal = 0.0; $cost = 0.0;
            foreach ($d['items'] as $i) {
                $p = Product::findOrFail($i['product_id']);
                $qty = (float) $i['qty'];
                if ($qty <= 0) throw new BusinessRuleException('Quantity must be positive.');
                if ($p->type !== 'finished_good' || !$p->is_active) throw new BusinessRuleException("{$p->name} cannot be sold.");
                $lt = round($qty * (float) $p->sale_price, 2);
                $lines[] = ['p' => $p, 'qty' => $qty, 'price' => (float) $p->sale_price, 'cost' => (float) $p->cost_price, 'total' => $lt];
                $subtotal += $lt; $cost += $qty * (float) $p->cost_price;
            }

            // 2. discount rule
            $discount = 0.0;
            if (!empty($d['discount_rule_id'])) {
                $r = DiscountRule::findOrFail($d['discount_rule_id']);
                $today = now()->toDateString();
                $valid = $r->is_active && (!$r->starts_on || $r->starts_on <= $today) && (!$r->ends_on || $r->ends_on >= $today) && $subtotal >= (float) $r->min_total;
                if (!$valid) throw new BusinessRuleException('This discount does not apply to the current cart.');
                $discount = $r->type === 'percent' ? round($subtotal * (float) $r->value / 100, 2) : min((float) $r->value, $subtotal);
            }

            // 3. customer + loyalty redemption
            $customer = !empty($d['customer_id']) ? Customer::lockForUpdate()->findOrFail($d['customer_id']) : null;
            if ($customer && $customer->status !== 'active') throw new BusinessRuleException('This customer account is not active.');
            $pointValue = (float) Settings::get('loyalty_point_value', 0.01);      // currency per point
            $loyaltyDiscount = 0.0; $pointsRedeemed = 0;
            $wanted = (int) ($d['redeem_points'] ?? 0);
            if ($wanted > 0) {
                if (!$customer) throw new BusinessRuleException('Select a customer to redeem points.');
                if ($wanted > $customer->loyalty_points) throw new BusinessRuleException('Not enough loyalty points.');
                $loyaltyDiscount = min(round($wanted * $pointValue, 2), $subtotal - $discount);
                $pointsRedeemed = $pointValue > 0 ? (int) ceil($loyaltyDiscount / $pointValue - 0.000001) : 0;
            }
            $total = round($subtotal - $discount - $loyaltyDiscount, 2);

            // 4. payment allocation
            $payments = $d['payments'] ?? [];
            $paid = 0.0; $credit = 0.0;
            foreach ($payments as $pm) {
                $amt = (float) $pm['amount'];
                if ($amt <= 0) throw new BusinessRuleException('Payment amounts must be positive.');
                if ($pm['method'] === 'credit') $credit += $amt; else $paid += $amt;
            }
            if (abs(($paid + $credit) - $total) > 0.01) {
                throw new BusinessRuleException(sprintf('Payments (%.2f) must equal the invoice total (%.2f).', $paid + $credit, $total));
            }
            if ($credit > 0) {
                if (!$customer) throw new BusinessRuleException('Credit sales need a customer.');
                if ((float) $customer->credit_balance + $credit > (float) $customer->credit_limit + 0.005) {
                    throw new BusinessRuleException('This sale exceeds the customer credit limit.');
                }
            }

            // 5. invoice + stock deduction (cost snapshot per line)
            $invoice = Invoice::create([
                'number' => DocNumber::next('INV'), 'branch_id' => $branchId, 'warehouse_id' => $wh->id,
                'customer_id' => $customer?->id, 'salesperson_id' => $d['salesperson_id'] ?? null,
                'cashier_session_id' => $d['cashier_session_id'] ?? null, 'campaign_id' => $d['campaign_id'] ?? null,
                'subtotal' => $subtotal, 'discount' => $discount, 'loyalty_discount' => $loyaltyDiscount, 'total' => $total,
                'paid' => $paid, 'credit_amount' => $credit, 'cost_total' => round($cost, 2),
                'points_redeemed' => $pointsRedeemed, 'created_by' => auth_user_id(),
            ]);
            foreach ($lines as $l) {
                $invoice->items()->create([
                    'product_id' => $l['p']->id, 'qty' => $l['qty'], 'unit_price' => $l['price'], 'unit_cost' => $l['cost'], 'line_total' => $l['total'],
                ]);
                $this->inventory->move($l['p']->id, $wh->id, -$l['qty'], 'sale', ['ref_type' => 'invoice', 'ref_id' => $invoice->id, 'unit_cost' => $l['cost']]);
            }

            // 6. payment rows + finance posting
            foreach ($payments as $pm) {
                $acc = $pm['method'] === 'credit' ? null : $this->finance->accountFor($branchId, $pm['method']);
                $pay = Payment::create([
                    'invoice_id' => $invoice->id, 'customer_id' => $customer?->id, 'branch_id' => $branchId,
                    'cashier_session_id' => $d['cashier_session_id'] ?? null, 'account_id' => $acc?->id,
                    'method' => $pm['method'], 'direction' => 'receipt', 'amount' => $pm['amount'],
                    'reference' => $pm['reference'] ?? null, 'user_id' => auth_user_id(),
                ]);
                if ($acc) $this->finance->post($acc->id, 'in', (float) $pm['amount'], 'sale', 'invoice', $invoice->id, $branchId, "Sale {$invoice->number}");
                if ($pm['method'] === 'cash' && !empty($d['cashier_session_id'])) {
                    CashierSession::where('id', $d['cashier_session_id'])->increment('expected_cash', (float) $pm['amount']);
                }
            }

            // 7. customer credit + loyalty
            if ($customer) {
                if ($credit > 0) $customer->credit_balance = (float) $customer->credit_balance + $credit;
                if ($pointsRedeemed > 0) {
                    $customer->loyalty_points -= $pointsRedeemed;
                    LoyaltyLedger::create(['customer_id' => $customer->id, 'invoice_id' => $invoice->id, 'points' => -$pointsRedeemed, 'type' => 'redeem']);
                }
                $perCurrency = max((float) Settings::get('loyalty_currency_per_point', 100), 0.01);   // spend this much to earn 1 point
                $mult = (float) (LoyaltyTier::where('name', $customer->tier)->value('earn_multiplier') ?? 1);
                $earn = (int) floor(($total / $perCurrency) * $mult);
                if ($earn > 0) {
                    $customer->loyalty_points += $earn;
                    LoyaltyLedger::create(['customer_id' => $customer->id, 'invoice_id' => $invoice->id, 'points' => $earn, 'type' => 'earn']);
                    $invoice->points_earned = $earn;
                }
                $customer->tier = LoyaltyTier::where('min_points', '<=', $customer->loyalty_points)->orderByDesc('min_points')->value('name') ?? $customer->tier;
                $customer->save();
            }

            // 8. commission (pending until approved)
            if (!empty($d['salesperson_id'])) {
                $rule = CommissionRule::where('is_active', true)->where('min_invoice_total', '<=', $total)
                    ->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id'))
                    ->orderByRaw('branch_id is null')->orderByDesc('rate_percent')->first();
                if ($rule) {
                    $amt = round($total * (float) $rule->rate_percent / 100, 2);
                    Commission::create(['employee_id' => $d['salesperson_id'], 'invoice_id' => $invoice->id, 'amount' => $amt]);
                    $invoice->commission_amount = $amt;
                }
            }
            $invoice->save();
            return $invoice->load('items.product', 'customer', 'payments');
        });
    }

    /** @param array<int,array{product_id:int,qty:float}> $lines */
    public function processReturn(Invoice $invoice, array $lines, string $method, ?string $reason): SalesReturn
    {
        if (!$lines) throw new BusinessRuleException('Select items to return.');
        return DB::transaction(function () use ($invoice, $lines, $method, $reason) {
            $invoice = Invoice::lockForUpdate()->with('items')->findOrFail($invoice->id);
            $ratio = (float) $invoice->subtotal > 0 ? (float) $invoice->total / (float) $invoice->subtotal : 1;
            $ret = SalesReturn::create([
                'number' => DocNumber::next('RET'), 'invoice_id' => $invoice->id, 'refund_amount' => 0,
                'refund_method' => $method, 'reason' => $reason, 'user_id' => auth_user_id(),
            ]);
            $refund = 0.0;
            foreach ($lines as $l) {
                $item = $invoice->items->firstWhere('product_id', $l['product_id']);
                $qty = (float) $l['qty'];
                if (!$item || $qty <= 0 || $qty > (float) $item->qty - (float) $item->returned_qty + 0.0001) {
                    throw new BusinessRuleException('Return quantity exceeds what was sold.');
                }
                $amount = round($qty * (float) $item->unit_price * $ratio, 2);
                $ret->items()->create(['product_id' => $item->product_id, 'qty' => $qty, 'amount' => $amount]);
                $item->returned_qty = (float) $item->returned_qty + $qty; $item->save();
                $this->inventory->move($item->product_id, $invoice->warehouse_id, $qty, 'sale_return', ['ref_type' => 'sales_return', 'ref_id' => $ret->id, 'unit_cost' => (float) $item->unit_cost]);
                $refund += $amount;
            }
            $ret->update(['refund_amount' => $refund]);

            // money back
            if ($refund > 0) {
                if ($method === 'credit') {
                    if (!$invoice->customer_id) throw new BusinessRuleException('Credit refunds need a customer.');
                    $c = Customer::lockForUpdate()->find($invoice->customer_id);
                    $c->credit_balance = max(0, (float) $c->credit_balance - $refund); $c->save();
                } else {
                    $acc = $this->finance->accountFor($invoice->branch_id, $method);
                    Payment::create(['invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id, 'branch_id' => $invoice->branch_id, 'account_id' => $acc->id, 'method' => $method, 'direction' => 'refund', 'amount' => $refund, 'user_id' => auth_user_id()]);
                    $this->finance->post($acc->id, 'out', $refund, 'refund', 'sales_return', $ret->id, $invoice->branch_id, "Return {$ret->number}");
                }
            }

            // reverse proportional loyalty + commission
            $share = (float) $invoice->total > 0 ? min(1, $refund / (float) $invoice->total) : 0;
            if ($invoice->customer_id && $invoice->points_earned > 0) {
                $pts = (int) floor($invoice->points_earned * $share);
                if ($pts > 0) {
                    $c = Customer::lockForUpdate()->find($invoice->customer_id);
                    $c->loyalty_points = max(0, $c->loyalty_points - $pts); $c->save();
                    LoyaltyLedger::create(['customer_id' => $c->id, 'invoice_id' => $invoice->id, 'points' => -$pts, 'type' => 'reversal']);
                }
            }
            $com = Commission::where('invoice_id', $invoice->id)->whereIn('status', ['pending', 'approved'])->first();
            if ($com) { $com->amount = round((float) $com->amount * (1 - $share), 2); $com->save(); }

            $allBack = $invoice->items()->get()->every(fn ($i) => (float) $i->returned_qty + 0.0001 >= (float) $i->qty);
            $invoice->update(['status' => $allBack ? 'returned' : 'partially_returned']);
            return $ret->load('items');
        });
    }
}
