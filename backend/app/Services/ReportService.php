<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Reports are built from authoritative transactional tables (spec section 17). */
class ReportService
{
    private function range(array $f): array
    {
        return [($f['from'] ?? now()->startOfMonth()->toDateString()) . ' 00:00:00', ($f['to'] ?? now()->toDateString()) . ' 23:59:59'];
    }

    private function branch(array $f): ?int { return !empty($f['branch_id']) ? (int) $f['branch_id'] : null; }

    private function invoices(array $f)
    {
        [$a, $b] = $this->range($f);
        return DB::table('invoices')->whereBetween('invoices.created_at', [$a, $b])
            ->when($this->branch($f), fn ($q, $br) => $q->where('invoices.branch_id', $br));
    }

    private function returned(array $f): float
    {
        [$a, $b] = $this->range($f);
        return (float) DB::table('sales_returns')->join('invoices', 'invoices.id', '=', 'sales_returns.invoice_id')
            ->whereBetween('sales_returns.created_at', [$a, $b])
            ->when($this->branch($f), fn ($q, $br) => $q->where('invoices.branch_id', $br))->sum('refund_amount');
    }

    private function expenses(array $f)
    {
        [$a, $b] = $this->range($f);
        return DB::table('expenses')->where('status', 'approved')->whereBetween('expense_date', [substr($a, 0, 10), substr($b, 0, 10)])
            ->when($this->branch($f), fn ($q, $br) => $q->where('branch_id', $br));
    }

    public function dashboard(array $f): array
    {
        $inv = $this->invoices($f);
        $gross = (float) (clone $inv)->sum('total');
        $returns = $this->returned($f);
        $cogs = (float) DB::table('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->whereBetween('invoices.created_at', $this->range($f))
            ->when($this->branch($f), fn ($q, $br) => $q->where('invoices.branch_id', $br))
            ->sum(DB::raw('(invoice_items.qty - invoice_items.returned_qty) * invoice_items.unit_cost'));
        $net = $gross - $returns;
        $expenses = (float) $this->expenses($f)->sum('amount');
        $lowStock = DB::table('stock_balances')->join('products', 'products.id', '=', 'stock_balances.product_id')
            ->whereColumn('stock_balances.qty', '<=', 'products.reorder_level')->where('products.reorder_level', '>', 0)->count();

        return [
            'sales' => round($net, 2), 'invoice_count' => (clone $inv)->count(), 'cogs' => round($cogs, 2),
            'gross_profit' => round($net - $cogs, 2), 'expenses' => round($expenses, 2), 'net_profit' => round($net - $cogs - $expenses, 2),
            'receivables' => (float) DB::table('customers')->sum('credit_balance'),
            'payables' => (float) DB::table('suppliers')->sum('outstanding_balance'),
            'production_output' => (float) DB::table('production_orders')->where('status', 'completed')->whereBetween('completed_at', $this->range($f))->sum('qty_produced'),
            'open_production_orders' => DB::table('production_orders')->whereIn('status', ['draft', 'released', 'in_progress'])->count(),
            'stock_value' => round((float) DB::table('stock_balances')->join('products', 'products.id', '=', 'stock_balances.product_id')->sum(DB::raw('stock_balances.qty * products.cost_price')), 2),
            'low_stock_items' => $lowStock,
            'pending_approvals' => DB::table('expenses')->where('status', 'pending')->count() + DB::table('stock_adjustments')->where('status', 'pending')->count()
                + DB::table('purchase_orders')->where('status', 'draft')->count() + DB::table('commissions')->where('status', 'pending')->count(),
            'sales_by_day' => $this->salesByDay($f),
            'sales_by_branch' => $this->salesByBranch($f),
        ];
    }

    public function salesByDay(array $f): array
    {
        return $this->invoices($f)->selectRaw('DATE(invoices.created_at) as day, SUM(total) as total, COUNT(*) as invoices')
            ->groupBy('day')->orderBy('day')->get()->map(fn ($r) => ['day' => $r->day, 'total' => (float) $r->total, 'invoices' => (int) $r->invoices])->all();
    }

    public function salesByBranch(array $f): array
    {
        return $this->invoices($f)->join('branches', 'branches.id', '=', 'invoices.branch_id')
            ->selectRaw('branches.name as branch, SUM(invoices.total) as total, COUNT(*) as invoices')->groupBy('branches.name')->orderByDesc('total')->get()->all();
    }

    public function salesByProduct(array $f): array
    {
        return DB::table('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')->join('products', 'products.id', '=', 'invoice_items.product_id')
            ->whereBetween('invoices.created_at', $this->range($f))->when($this->branch($f), fn ($q, $br) => $q->where('invoices.branch_id', $br))
            ->selectRaw('products.sku, products.name, SUM(invoice_items.qty - invoice_items.returned_qty) as qty, SUM((invoice_items.qty - invoice_items.returned_qty) * invoice_items.unit_price) as revenue')
            ->groupBy('products.sku', 'products.name')->orderByDesc('revenue')->get()->all();
    }

    public function productProfitability(array $f): array
    {
        return DB::table('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')->join('products', 'products.id', '=', 'invoice_items.product_id')
            ->whereBetween('invoices.created_at', $this->range($f))->when($this->branch($f), fn ($q, $br) => $q->where('invoices.branch_id', $br))
            ->selectRaw('products.sku, products.name,
                SUM(invoice_items.qty - invoice_items.returned_qty) as qty,
                SUM((invoice_items.qty - invoice_items.returned_qty) * invoice_items.unit_price) as revenue,
                SUM((invoice_items.qty - invoice_items.returned_qty) * invoice_items.unit_cost) as cost')
            ->groupBy('products.sku', 'products.name')->get()
            ->map(fn ($r) => ['sku' => $r->sku, 'name' => $r->name, 'qty' => (float) $r->qty, 'revenue' => round((float) $r->revenue, 2), 'cost' => round((float) $r->cost, 2),
                'profit' => round((float) $r->revenue - (float) $r->cost, 2), 'margin_percent' => $r->revenue > 0 ? round(($r->revenue - $r->cost) / $r->revenue * 100, 1) : 0])->all();
    }

    public function branchProfitability(array $f): array
    {
        [$a, $b] = $this->range($f);
        $rows = [];
        foreach (DB::table('branches')->get() as $br) {
            $g = ['from' => $f['from'] ?? null, 'to' => $f['to'] ?? null, 'branch_id' => $br->id];
            $rev = (float) $this->invoices($g)->sum('total') - $this->returned($g);
            $cogs = (float) DB::table('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')->where('invoices.branch_id', $br->id)
                ->whereBetween('invoices.created_at', [$a, $b])->sum(DB::raw('(invoice_items.qty - invoice_items.returned_qty) * invoice_items.unit_cost'));
            $exp = (float) $this->expenses($g)->sum('amount');
            if ($rev || $exp) $rows[] = ['branch' => $br->name, 'revenue' => round($rev, 2), 'cogs' => round($cogs, 2), 'expenses' => round($exp, 2), 'profit' => round($rev - $cogs - $exp, 2)];
        }
        return $rows;
    }

    public function profitAndLoss(array $f): array
    {
        $d = $this->dashboard($f);
        [$a, $b] = $this->range($f);
        $payroll = (float) DB::table('financial_transactions')->where('category', 'payroll')->whereBetween('created_at', [$a, $b])->sum('amount');
        return [
            'revenue' => $d['sales'], 'cost_of_goods_sold' => $d['cogs'], 'gross_profit' => $d['gross_profit'],
            'operating_expenses' => $d['expenses'], 'payroll' => round($payroll, 2),
            'net_profit' => round($d['gross_profit'] - $d['expenses'] - $payroll, 2),
        ];
    }

    public function stock(array $f): array
    {
        return DB::table('stock_balances')->join('products', 'products.id', '=', 'stock_balances.product_id')->join('warehouses', 'warehouses.id', '=', 'stock_balances.warehouse_id')
            ->when(!empty($f['warehouse_id']), fn ($q) => $q->where('stock_balances.warehouse_id', $f['warehouse_id']))
            ->when(!empty($f['type']), fn ($q) => $q->where('products.type', $f['type']))
            ->selectRaw('products.sku, products.name, products.type, warehouses.name as warehouse, stock_balances.qty, products.reorder_level, products.cost_price, (stock_balances.qty * products.cost_price) as value')
            ->orderBy('products.name')->get()->all();
    }

    public function production(array $f): array
    {
        [$a, $b] = $this->range($f);
        return DB::table('production_orders')->join('products', 'products.id', '=', 'production_orders.product_id')
            ->whereBetween('production_orders.created_at', [$a, $b])
            ->select('production_orders.number', 'products.name as product', 'production_orders.status', 'production_orders.qty_planned', 'production_orders.qty_produced', 'production_orders.wastage_qty', 'production_orders.material_cost', 'production_orders.conversion_cost', 'production_orders.unit_cost', 'production_orders.batch_no')
            ->orderByDesc('production_orders.id')->get()->all();
    }

    public function receivables(): array
    {
        return DB::table('customers')->where('credit_balance', '>', 0)->select('code', 'name', 'phone', 'credit_limit', 'credit_balance')->orderByDesc('credit_balance')->get()->all();
    }

    public function payables(): array
    {
        return DB::table('supplier_invoices')->join('suppliers', 'suppliers.id', '=', 'supplier_invoices.supplier_id')->whereIn('supplier_invoices.status', ['unpaid', 'partial'])
            ->selectRaw('suppliers.name as supplier, supplier_invoices.number, supplier_invoices.due_date, supplier_invoices.amount, supplier_invoices.paid, (supplier_invoices.amount - supplier_invoices.paid) as balance')
            ->orderBy('supplier_invoices.due_date')->get()->all();
    }

    public function expensesByCategory(array $f): array
    {
        return $this->expenses($f)->join('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->selectRaw('expense_categories.name as category, SUM(expenses.amount) as total')->groupBy('expense_categories.name')->orderByDesc('total')->get()->all();
    }

    public function salespersonPerformance(array $f): array
    {
        return $this->invoices($f)->join('employees', 'employees.id', '=', 'invoices.salesperson_id')
            ->selectRaw('employees.name as salesperson, COUNT(*) as invoices, SUM(invoices.total) as sales, SUM(invoices.commission_amount) as commission')
            ->groupBy('employees.name')->orderByDesc('sales')->get()->all();
    }

    public function marketing(array $f): array
    {
        return DB::table('campaigns')->leftJoin('campaign_expenses', 'campaign_expenses.campaign_id', '=', 'campaigns.id')
            ->selectRaw('campaigns.id, campaigns.name, campaigns.budget, COALESCE(SUM(campaign_expenses.amount),0) as spent, (SELECT COALESCE(SUM(total),0) FROM invoices WHERE invoices.campaign_id = campaigns.id) as attributed_sales')
            ->groupBy('campaigns.id', 'campaigns.name', 'campaigns.budget')->get()->all();
    }

    public function deliveryCosts(array $f): array
    {
        [$a, $b] = $this->range($f);
        $d = DB::table('delivery_orders')->whereBetween('created_at', [$a, $b])->selectRaw('COUNT(*) as deliveries, COALESCE(SUM(delivery_charge),0) as charges, COALESCE(SUM(fuel_cost),0) as fuel')->first();
        $logs = DB::table('vehicle_logs')->whereBetween('log_date', [substr($a, 0, 10), substr($b, 0, 10)])->selectRaw('type, SUM(amount) as total')->groupBy('type')->pluck('total', 'type');
        return ['deliveries' => (int) $d->deliveries, 'delivery_charges' => (float) $d->charges, 'trip_fuel_cost' => (float) $d->fuel,
            'vehicle_fuel_logged' => (float) ($logs['fuel'] ?? 0), 'vehicle_maintenance' => (float) ($logs['maintenance'] ?? 0)];
    }
}
