<?php
namespace App\Console\Commands;

use App\Models\Alert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Scheduled daily: low stock, pending approvals, overdue customer credit and supplier invoices. */
class GenerateAlerts extends Command
{
    protected $signature = 'erp:alerts';
    protected $description = 'Generate low-stock, approval and credit reminders';

    public function handle(): int
    {
        Alert::where('created_at', '<', now()->subDays(30))->delete();
        $low = DB::table('stock_balances')->join('products', 'products.id', '=', 'stock_balances.product_id')->join('warehouses', 'warehouses.id', '=', 'stock_balances.warehouse_id')
            ->whereColumn('stock_balances.qty', '<=', 'products.reorder_level')->where('products.reorder_level', '>', 0)
            ->get(['products.name as product', 'warehouses.name as warehouse', 'stock_balances.qty', 'warehouses.branch_id']);
        foreach ($low as $r) {
            Alert::create(['type' => 'low_stock', 'level' => 'warning', 'branch_id' => $r->branch_id, 'message' => "Low stock: {$r->product} at {$r->warehouse} ({$r->qty} left)"]);
        }
        $pending = DB::table('expenses')->where('status', 'pending')->count() + DB::table('stock_adjustments')->where('status', 'pending')->count() + DB::table('commissions')->where('status', 'pending')->count();
        if ($pending) Alert::create(['type' => 'approvals', 'level' => 'info', 'message' => "$pending items are waiting for approval."]);
        $overdue = DB::table('supplier_invoices')->whereIn('status', ['unpaid', 'partial'])->where('due_date', '<', now()->toDateString())->count();
        if ($overdue) Alert::create(['type' => 'supplier_payment', 'level' => 'warning', 'message' => "$overdue supplier invoices are overdue."]);
        $credit = DB::table('customers')->where('credit_balance', '>', 0)->count();
        if ($credit) Alert::create(['type' => 'customer_credit', 'level' => 'info', 'message' => "$credit customers have outstanding credit."]);
        $this->info('Alerts generated.');
        return self::SUCCESS;
    }
}
