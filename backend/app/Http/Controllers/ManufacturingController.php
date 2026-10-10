<?php
namespace App\Http\Controllers;

use App\Models\Bom;
use App\Models\ProductionOrder;
use App\Services\CostingService;
use App\Services\ManufacturingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManufacturingController extends Controller
{
    public function __construct(private ManufacturingService $mfg, private CostingService $costing) {}

    private function bomRules(): array
    {
        return [
            'product_id' => 'required|exists:products,id', 'output_qty' => 'required|numeric|gt:0',
            'labour_cost' => 'nullable|numeric|min:0', 'packaging_cost' => 'nullable|numeric|min:0', 'overhead_cost' => 'nullable|numeric|min:0', 'extra_cost' => 'nullable|numeric|min:0',
            'is_active' => 'boolean', 'notes' => 'nullable|string',
            'items' => 'required|array|min:1', 'items.*.product_id' => 'required|exists:products,id', 'items.*.qty' => 'required|numeric|gt:0', 'items.*.wastage_percent' => 'nullable|numeric|min:0|max:100',
        ];
    }

    public function storeBom(Request $r)
    {
        $d = $r->validate($this->bomRules());
        $bom = DB::transaction(function () use ($d) {
            $items = $d['items']; unset($d['items']);
            $d['version'] = (int) Bom::where('product_id', $d['product_id'])->max('version') + 1;
            $bom = Bom::create($d);
            foreach ($items as $i) $bom->items()->create($i);
            $this->costing->recordVersion($bom->load('items.product'));
            return $bom;
        });
        return response()->json(['data' => $bom->load('product', 'items.product'), 'cost' => $this->costing->bomCost($bom)], 201);
    }

    public function updateBom(Request $r, int $id)
    {
        $bom = Bom::findOrFail($id);
        if (ProductionOrder::where('bom_id', $id)->exists()) {
            return response()->json(['message' => 'This recipe version has production history. Create a new version instead.'], 422);
        }
        $d = $r->validate($this->bomRules());
        DB::transaction(function () use ($bom, $d) {
            $items = $d['items']; unset($d['items'], $d['product_id']);
            $bom->update($d);
            $bom->items()->delete();
            foreach ($items as $i) $bom->items()->create($i);
            $this->costing->recordVersion($bom->load('items.product'));
        });
        return response()->json(['data' => $bom->fresh('product', 'items.product'), 'cost' => $this->costing->bomCost($bom->fresh('items.product'))]);
    }

    public function bomCost(int $id) { return response()->json(['data' => $this->costing->bomCost(Bom::with('items.product')->findOrFail($id))]); }

    public function storeOrder(Request $r)
    {
        $d = $r->validate([
            'bom_id' => 'required|exists:boms,id', 'batches' => 'required|numeric|gt:0',
            'source_warehouse_id' => 'required|exists:warehouses,id', 'target_warehouse_id' => 'required|exists:warehouses,id',
            'planned_date' => 'nullable|date', 'notes' => 'nullable|string',
        ]);
        return response()->json(['data' => $this->mfg->create($d)], 201);
    }

    public function transition(Request $r, int $id, string $action)
    {
        $o = ProductionOrder::findOrFail($id);
        $res = match ($action) {
            'release' => $this->mfg->release($o),
            'start' => $this->mfg->start($o),
            'cancel' => $this->mfg->cancel($o),
            'complete' => $this->mfg->complete($o, (float) $r->validate(['qty_produced' => 'required|numeric|gt:0', 'wastage_qty' => 'nullable|numeric|min:0'])['qty_produced'], (float) $r->input('wastage_qty', 0)),
            default => abort(404),
        };
        return response()->json(['data' => $res->load('product', 'materials.product')]);
    }
}
