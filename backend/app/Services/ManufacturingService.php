<?php
namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Bom;
use App\Models\ProductionOrder;
use App\Support\DocNumber;
use Illuminate\Support\Facades\DB;

/** Production orders with controlled status transitions: draft > released > in_progress > completed. */
class ManufacturingService
{
    public const TRANSITIONS = [
        'draft' => ['released', 'cancelled'],
        'released' => ['in_progress', 'cancelled'],
        'in_progress' => ['completed'],
        'completed' => [],
        'cancelled' => [],
    ];

    public function __construct(private InventoryService $inventory, private CostingService $costing) {}

    private function guard(ProductionOrder $o, string $to): void
    {
        if (!in_array($to, self::TRANSITIONS[$o->status] ?? [], true)) {
            throw new BusinessRuleException("A {$o->status} production order cannot move to {$to}.");
        }
    }

    public function create(array $d): ProductionOrder
    {
        return DB::transaction(function () use ($d) {
            $bom = Bom::with('items')->findOrFail($d['bom_id']);
            if (!$bom->is_active) throw new BusinessRuleException('This recipe is not active.');
            $batches = (float) $d['batches'];
            if ($batches <= 0) throw new BusinessRuleException('Batches must be positive.');

            $o = ProductionOrder::create([
                'number' => DocNumber::next('PRD'), 'bom_id' => $bom->id, 'product_id' => $bom->product_id,
                'source_warehouse_id' => $d['source_warehouse_id'], 'target_warehouse_id' => $d['target_warehouse_id'],
                'batches' => $batches, 'qty_planned' => $batches * (float) $bom->output_qty,
                'planned_date' => $d['planned_date'] ?? null, 'notes' => $d['notes'] ?? null,
                'status' => 'draft', 'created_by' => auth_user_id(),
            ]);
            foreach ($bom->items as $it) {
                $o->materials()->create([
                    'product_id' => $it->product_id,
                    'required_qty' => (float) $it->qty * $batches * (1 + (float) $it->wastage_percent / 100),
                ]);
            }
            return $o->load('materials.product', 'product');
        });
    }

    public function release(ProductionOrder $o): ProductionOrder
    {
        $this->guard($o, 'released');
        $o->update(['status' => 'released', 'approved_by' => auth_user_id()]);
        return $o;
    }

    public function cancel(ProductionOrder $o): ProductionOrder
    {
        $this->guard($o, 'cancelled');
        $o->update(['status' => 'cancelled']);
        return $o;
    }

    /** Issue and consume raw materials from the source warehouse (atomic; fails if any material is short). */
    public function start(ProductionOrder $o): ProductionOrder
    {
        $this->guard($o, 'in_progress');
        return DB::transaction(function () use ($o) {
            $o = ProductionOrder::lockForUpdate()->with('materials.product')->findOrFail($o->id);
            $this->guard($o, 'in_progress');
            $batch = 'LOT-' . substr($o->number, -6) . '-' . date('ymd');
            $materialCost = 0.0;
            foreach ($o->materials as $m) {
                $cost = (float) $m->product->cost_price;
                $this->inventory->move($m->product_id, $o->source_warehouse_id, -(float) $m->required_qty, 'production_issue', [
                    'ref_type' => 'production_order', 'ref_id' => $o->id, 'batch_no' => $batch, 'unit_cost' => $cost,
                ]);
                $m->update(['issued_qty' => $m->required_qty, 'unit_cost' => $cost]);
                $materialCost += (float) $m->required_qty * $cost;
            }
            $o->update(['status' => 'in_progress', 'started_at' => now(), 'batch_no' => $batch, 'material_cost' => $materialCost]);
            return $o->fresh('materials.product');
        });
    }

    /** Receive finished goods; unit cost = (materials issued + conversion costs) / good quantity. */
    public function complete(ProductionOrder $o, float $qtyProduced, float $wastage = 0): ProductionOrder
    {
        $this->guard($o, 'completed');
        if ($qtyProduced <= 0) throw new BusinessRuleException('Produced quantity must be positive.');
        return DB::transaction(function () use ($o, $qtyProduced, $wastage) {
            $o = ProductionOrder::lockForUpdate()->with('bom')->findOrFail($o->id);
            $this->guard($o, 'completed');
            $bom = $o->bom;
            $conversion = (float) $o->batches * ((float) $bom->labour_cost + (float) $bom->packaging_cost + (float) $bom->overhead_cost + (float) $bom->extra_cost);
            $total = (float) $o->material_cost + $conversion;
            $unit = $total / $qtyProduced;

            $this->inventory->move($o->product_id, $o->target_warehouse_id, $qtyProduced, 'production_output', [
                'ref_type' => 'production_order', 'ref_id' => $o->id, 'batch_no' => $o->batch_no, 'unit_cost' => $unit,
            ]);
            $o->update([
                'status' => 'completed', 'qty_produced' => $qtyProduced, 'wastage_qty' => $wastage,
                'conversion_cost' => $conversion, 'unit_cost' => $unit, 'completed_at' => now(),
            ]);

            $perUnit = fn ($v) => (float) $v * (float) $o->batches / $qtyProduced;
            $this->costing->recordVersion($bom, 'production', $o->id, [
                'material' => (float) $o->material_cost / $qtyProduced,
                'labour' => $perUnit($bom->labour_cost), 'packaging' => $perUnit($bom->packaging_cost),
                'overhead' => $perUnit($bom->overhead_cost), 'extra' => $perUnit($bom->extra_cost), 'total' => $unit,
            ]);
            return $o->fresh('materials.product', 'product');
        });
    }
}
