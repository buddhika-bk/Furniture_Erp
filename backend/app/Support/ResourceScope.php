<?php
namespace App\Support;

/** Shared record scope for listings, search, details and exports. */
class ResourceScope
{
    public static function apply($query, array $config)
    {
        $branch = auth_user()?->branchScope();
        if (!$branch) return $query;
        if (!empty($config['branch'])) return $query->where($config['branch'], $branch);
        $table = $query->getModel()->getTable();
        if (in_array($table, ['stock_movements', 'stock_balances', 'stock_adjustments', 'purchase_orders'])) {
            return $query->whereHas('warehouse', fn ($q) => $q->where('branch_id', $branch));
        }
        if ($table === 'stock_transfers') return $query->where(fn ($q) => $q->whereHas('from', fn ($w) => $w->where('branch_id', $branch))->orWhereHas('to', fn ($w) => $w->where('branch_id', $branch)));
        if ($table === 'production_orders') return $query->where(fn ($q) => $q->whereHas('sourceWarehouse', fn ($w) => $w->where('branch_id', $branch))->orWhereHas('targetWarehouse', fn ($w) => $w->where('branch_id', $branch)));
        if (in_array($table, ['attendance', 'leaves', 'commissions', 'payslips'])) return $query->whereHas('employee', fn ($q) => $q->where('branch_id', $branch));
        if (in_array($table, ['accounts', 'financial_transactions'])) return $query->where('branch_id', $branch);
        if ($table === 'alerts') return $query->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch));
        if ($table === 'branches') return $query->whereKey($branch);
        if ($table === 'supplier_invoices') return $query->whereIn('goods_receipt_id', \Illuminate\Support\Facades\DB::table('goods_receipts')->join('purchase_orders','purchase_orders.id','=','goods_receipts.purchase_order_id')->join('warehouses','warehouses.id','=','purchase_orders.warehouse_id')->where('warehouses.branch_id',$branch)->select('goods_receipts.id'));
        return $query;
    }
}
