<?php
namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Support\DocNumber;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point for every stock change. Each change is an immutable ledger row
 * (stock_movements) plus an atomically-updated balance (stock_balances).
 */
class InventoryService
{
    /** Movement types that revalue the moving-average cost. */
    private const COSTED_IN = ['purchase_receipt', 'production_output', 'opening'];

    public function balance(int $productId, int $warehouseId): float
    {
        return (float) (StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->value('qty') ?? 0);
    }

    public function move(int $productId, int $warehouseId, float $qty, string $type, array $o = []): StockMovement
    {
        if (abs($qty) < 0.0000001) throw new BusinessRuleException('Quantity must not be zero.');

        return DB::transaction(function () use ($productId, $warehouseId, $qty, $type, $o) {
            StockBalance::firstOrCreate(['product_id' => $productId, 'warehouse_id' => $warehouseId], ['qty' => 0]);
            $bal = StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->lockForUpdate()->first();
            $product = Product::lockForUpdate()->findOrFail($productId);

            if ($qty < 0 && ((float) $bal->qty + $qty) < -0.0000001) {
                $wh = Warehouse::find($warehouseId);
                throw new BusinessRuleException(sprintf(
                    'Insufficient stock for %s in %s (available %s, needed %s).',
                    $product->name, $wh?->name, rtrim(rtrim(number_format((float) $bal->qty, 3, '.', ''), '0'), '.'), rtrim(rtrim(number_format(abs($qty), 3, '.', ''), '0'), '.')
                ));
            }

            $unitCost = $o['unit_cost'] ?? (float) $product->cost_price;

            if ($qty > 0 && in_array($type, self::COSTED_IN, true) && $unitCost > 0) {
                $totalQty = (float) StockBalance::where('product_id', $productId)->sum('qty');
                $newTotal = $totalQty + $qty;
                $product->cost_price = $newTotal > 0 ? (($totalQty * (float) $product->cost_price) + ($qty * $unitCost)) / $newTotal : $unitCost;
                $product->save();
            }

            $bal->qty = (float) $bal->qty + $qty;
            $bal->save();

            return StockMovement::create([
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'qty' => $qty,
                'unit_cost' => $unitCost,
                'type' => $type,
                'ref_type' => $o['ref_type'] ?? null,
                'ref_id' => $o['ref_id'] ?? null,
                'batch_no' => $o['batch_no'] ?? null,
                'user_id' => auth_user_id(),
                'note' => $o['note'] ?? null,
            ]);
        });
    }

    /** @param array<int,array{product_id:int,qty:float}> $items */
    public function transfer(int $from, int $to, array $items, ?string $note = null): StockTransfer
    {
        if ($from === $to) throw new BusinessRuleException('Source and destination must differ.');
        if (!$items) throw new BusinessRuleException('Add at least one item.');

        return DB::transaction(function () use ($from, $to, $items, $note) {
            $tr = StockTransfer::create([
                'number' => DocNumber::next('TRF'), 'from_warehouse_id' => $from, 'to_warehouse_id' => $to,
                'status' => 'completed', 'user_id' => auth_user_id(), 'note' => $note,
            ]);
            foreach ($items as $it) {
                $qty = (float) $it['qty'];
                if ($qty <= 0) throw new BusinessRuleException('Transfer quantity must be positive.');
                $cost = (float) Product::findOrFail($it['product_id'])->cost_price;
                $tr->items()->create(['product_id' => $it['product_id'], 'qty' => $qty]);
                $ref = ['ref_type' => 'stock_transfer', 'ref_id' => $tr->id, 'unit_cost' => $cost];
                $this->move($it['product_id'], $from, -$qty, 'transfer_out', $ref);
                $this->move($it['product_id'], $to, $qty, 'transfer_in', $ref);
            }
            return $tr->load('items');
        });
    }

    public function requestAdjustment(int $warehouseId, int $productId, float $counted, ?string $reason): StockAdjustment
    {
        $system = $this->balance($productId, $warehouseId);
        return StockAdjustment::create([
            'number' => DocNumber::next('ADJ'), 'warehouse_id' => $warehouseId, 'product_id' => $productId,
            'counted_qty' => $counted, 'system_qty' => $system, 'difference' => $counted - $system,
            'reason' => $reason, 'status' => 'pending', 'requested_by' => auth_user_id(),
        ]);
    }

    public function decideAdjustment(StockAdjustment $adj, bool $approve): StockAdjustment
    {
        if ($adj->status !== 'pending') throw new BusinessRuleException('Adjustment already decided.');
        return DB::transaction(function () use ($adj, $approve) {
            if ($approve) {
                // Recompute against the live balance so concurrent sales cannot be overwritten.
                $diff = (float) $adj->counted_qty - $this->balance($adj->product_id, $adj->warehouse_id);
                if (abs($diff) > 0.0000001) {
                    $this->move($adj->product_id, $adj->warehouse_id, $diff, 'adjustment', ['ref_type' => 'stock_adjustment', 'ref_id' => $adj->id, 'note' => $adj->reason]);
                }
            }
            $adj->update(['status' => $approve ? 'approved' : 'rejected', 'approved_by' => auth_user_id(), 'approved_at' => now()]);
            return $adj;
        });
    }
}
