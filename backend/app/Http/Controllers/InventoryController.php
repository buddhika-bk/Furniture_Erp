<?php
namespace App\Http\Controllers;

use App\Models\StockAdjustment;
use App\Services\InventoryService;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(private InventoryService $inv) {}

    public function transfer(Request $r)
    {
        $d = $r->validate([
            'from_warehouse_id' => 'required|exists:warehouses,id', 'to_warehouse_id' => 'required|exists:warehouses,id',
            'items' => 'required|array|min:1', 'items.*.product_id' => 'required|exists:products,id', 'items.*.qty' => 'required|numeric|gt:0', 'note' => 'nullable|string|max:255',
        ]);
        return response()->json(['data' => $this->inv->transfer($d['from_warehouse_id'], $d['to_warehouse_id'], $d['items'], $d['note'] ?? null)], 201);
    }

    public function requestAdjustment(Request $r)
    {
        $d = $r->validate(['warehouse_id' => 'required|exists:warehouses,id', 'product_id' => 'required|exists:products,id', 'counted_qty' => 'required|numeric|min:0', 'reason' => 'nullable|string|max:255']);
        return response()->json(['data' => $this->inv->requestAdjustment($d['warehouse_id'], $d['product_id'], (float) $d['counted_qty'], $d['reason'] ?? null)], 201);
    }

    public function decide(Request $r, int $id, string $decision)
    {
        return response()->json(['data' => $this->inv->decideAdjustment(StockAdjustment::findOrFail($id), $decision === 'approve')]);
    }

    /** Opening stock load (data migration, spec section 24). */
    public function opening(Request $r)
    {
        $d = $r->validate(['warehouse_id' => 'required|exists:warehouses,id', 'product_id' => 'required|exists:products,id', 'qty' => 'required|numeric|gt:0', 'unit_cost' => 'required|numeric|min:0']);
        $m = $this->inv->move($d['product_id'], $d['warehouse_id'], (float) $d['qty'], 'opening', ['unit_cost' => (float) $d['unit_cost'], 'note' => 'Opening balance']);
        return response()->json(['data' => $m], 201);
    }
}
