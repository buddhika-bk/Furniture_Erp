<?php
namespace App\Http\Controllers;

use App\Models\CashierSession;
use App\Models\Customer;
use App\Models\DiscountRule;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Services\FinanceService;
use App\Services\SalesService;
use Illuminate\Http\Request;

class SalesController extends Controller
{
    public function __construct(private SalesService $sales, private FinanceService $finance) {}

    private function branchId(Request $r): int
    {
        $scope = auth_user()->branchScope();
        $id = $scope ?: (int) $r->input('branch_id', $r->query('branch_id'));
        abort_if(!$id, 422, 'Select a branch.');
        abort_if($scope && $r->filled('branch_id') && (int) $r->input('branch_id') !== $scope, 403, 'You can only sell from your own branch.');
        return $id;
    }

    /** Sellable products with live stock for the branch + active discounts and salespeople. */
    public function catalog(Request $r)
    {
        $branch = $this->branchId($r);
        $wh = Warehouse::where('branch_id', $branch)->where('type', 'showroom')->first() ?? Warehouse::where('branch_id', $branch)->first();
        $stock = $wh ? StockBalance::with('product')->where('warehouse_id', $wh->id)->where('qty', '>', 0)->get()->filter(fn ($b) => $b->product?->type === 'finished_good' && $b->product->is_active) : collect();
        $today = now()->toDateString();
        return response()->json([
            'warehouse' => $wh,
            'products' => $stock->map(fn ($b) => ['id' => $b->product_id, 'sku' => $b->product->sku, 'name' => $b->product->name, 'variant' => $b->product->variant, 'price' => (float) $b->product->sale_price, 'stock' => (float) $b->qty])->values(),
            'discounts' => DiscountRule::where('is_active', true)->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $today))->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))->get(),
            'salespeople' => Employee::where('is_salesperson', true)->where('status', 'active')->where(fn ($q) => $q->where('branch_id', $branch)->orWhereNull('branch_id'))->get(['id', 'name']),
            'session' => CashierSession::where('user_id', auth_user_id())->where('status', 'open')->first(),
        ]);
    }

    public function checkout(Request $r)
    {
        $d = $r->validate([
            'branch_id' => 'nullable|exists:branches,id', 'customer_id' => 'nullable|exists:customers,id', 'salesperson_id' => 'nullable|exists:employees,id',
            'campaign_id' => 'nullable|exists:campaigns,id', 'discount_rule_id' => 'nullable|exists:discount_rules,id', 'redeem_points' => 'nullable|integer|min:0',
            'items' => 'required|array|min:1', 'items.*.product_id' => 'required|exists:products,id', 'items.*.qty' => 'required|numeric|gt:0',
            'payments' => 'required|array|min:1', 'payments.*.method' => 'required|in:cash,card,bank,credit', 'payments.*.amount' => 'required|numeric|gt:0', 'payments.*.reference' => 'nullable|string|max:255',
        ]);
        $d['branch_id'] = $this->branchId($r);
        $d['cashier_session_id'] = CashierSession::where('user_id', auth_user_id())->where('status', 'open')->value('id');
        return response()->json(['data' => $this->sales->checkout($d)], 201);
    }

    public function returnItems(Request $r, int $id)
    {
        $d = $r->validate(['refund_method' => 'required|in:cash,card,bank,credit', 'reason' => 'nullable|string|max:255', 'items' => 'required|array|min:1', 'items.*.product_id' => 'required|exists:products,id', 'items.*.qty' => 'required|numeric|gt:0']);
        $inv = Invoice::findOrFail($id);
        abort_if(($s = auth_user()->branchScope()) && $inv->branch_id !== $s, 403, 'This invoice belongs to another branch.');
        return response()->json(['data' => $this->sales->processReturn($inv, $d['items'], $d['refund_method'], $d['reason'] ?? null)], 201);
    }

    public function openSession(Request $r)
    {
        $d = $r->validate(['opening_cash' => 'required|numeric|min:0']);
        return response()->json(['data' => $this->sales->openSession($this->branchId($r), (float) $d['opening_cash'])], 201);
    }

    public function closeSession(Request $r, int $id)
    {
        $d = $r->validate(['closing_cash' => 'required|numeric|min:0', 'notes' => 'nullable|string']);
        $s = CashierSession::findOrFail($id);
        abort_if($s->user_id !== auth_user_id() && !auth_user()->hasPermission('sales.approve'), 403, 'Only the cashier or a manager can close this session.');
        return response()->json(['data' => $this->sales->closeSession($s, (float) $d['closing_cash'], $d['notes'] ?? null)]);
    }

    public function customerPayment(Request $r, int $id)
    {
        $d = $r->validate(['method' => 'required|in:cash,card,bank', 'amount' => 'required|numeric|gt:0', 'reference' => 'nullable|string|max:255']);
        $branch = auth_user()->branchScope() ?: $r->input('branch_id');
        return response()->json(['data' => $this->finance->collectFromCustomer(Customer::findOrFail($id), $d['method'], (float) $d['amount'], $branch, $d['reference'] ?? null)], 201);
    }

    public function customerHistory(int $id)
    {
        return response()->json(['customer' => Customer::findOrFail($id), 'invoices' => Invoice::where('customer_id', $id)->orderByDesc('id')->limit(50)->get(),
            'points' => \App\Models\LoyaltyLedger::where('customer_id', $id)->orderByDesc('id')->limit(50)->get()]);
    }
}
