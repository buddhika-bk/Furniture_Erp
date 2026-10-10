<?php
namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Services\PurchasingService;
use Illuminate\Http\Request;

class PurchasingController extends Controller
{
    public function __construct(private PurchasingService $svc) {}

    public function store(Request $r)
    {
        $d = $r->validate([
            'supplier_id' => 'required|exists:suppliers,id', 'warehouse_id' => 'required|exists:warehouses,id', 'order_date' => 'nullable|date', 'notes' => 'nullable|string',
            'items' => 'required|array|min:1', 'items.*.product_id' => 'required|exists:products,id', 'items.*.qty' => 'required|numeric|gt:0', 'items.*.unit_price' => 'required|numeric|min:0',
        ]);
        return response()->json(['data' => $this->svc->createOrder($d)], 201);
    }

    public function approve(int $id) { return response()->json(['data' => $this->svc->approve(PurchaseOrder::findOrFail($id))]); }

    public function receive(Request $r, int $id)
    {
        $d = $r->validate(['batch_no' => 'nullable|string|max:40', 'lines' => 'required|array|min:1', 'lines.*.item_id' => 'required|integer', 'lines.*.qty' => 'required|numeric|gt:0']);
        return response()->json(['data' => $this->svc->receive(PurchaseOrder::findOrFail($id), $d['lines'], $d['batch_no'] ?? null)], 201);
    }

    public function pay(Request $r, int $id)
    {
        $d = $r->validate(['amount' => 'required|numeric|gt:0', 'account_id' => 'required|exists:accounts,id']);
        return response()->json(['data' => $this->svc->paySupplier(SupplierInvoice::findOrFail($id), (float) $d['amount'], $d['account_id'])], 201);
    }
}
