<?php
namespace App\Services;

use App\Models\Bom;
use App\Models\ProductCost;

/** Product costing: material (incl. wastage) + labour + packaging + overhead + configured extras. */
class CostingService
{
    /** Per-unit cost breakdown for a BOM using current material costs. */
    public function bomCost(Bom $bom): array
    {
        $bom->loadMissing('items.product');
        $material = 0.0;
        foreach ($bom->items as $it) {
            $material += (float) $it->qty * (1 + (float) $it->wastage_percent / 100) * (float) $it->product->cost_price;
        }
        $out = max((float) $bom->output_qty, 0.0001);
        $b = [
            'material' => $material / $out,
            'labour' => (float) $bom->labour_cost / $out,
            'packaging' => (float) $bom->packaging_cost / $out,
            'overhead' => (float) $bom->overhead_cost / $out,
            'extra' => (float) $bom->extra_cost / $out,
        ];
        $b['total'] = array_sum($b);
        return array_map(fn ($v) => round($v, 4), $b);
    }

    /** Stores a cost version (history) so past sales keep their own cost snapshot. */
    public function recordVersion(Bom $bom, string $source = 'bom', ?int $productionOrderId = null, ?array $breakdown = null): ProductCost
    {
        $c = $breakdown ?? $this->bomCost($bom);
        return ProductCost::create([
            'product_id' => $bom->product_id, 'bom_id' => $bom->id, 'production_order_id' => $productionOrderId,
            'material_cost' => $c['material'], 'labour_cost' => $c['labour'], 'packaging_cost' => $c['packaging'],
            'overhead_cost' => $c['overhead'], 'extra_cost' => $c['extra'], 'total_cost' => $c['total'],
            'source' => $source, 'effective_from' => now(),
        ]);
    }
}
