<?php
namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Support\DocNumber;
use Illuminate\Support\Facades\DB;

class PurchasingService
{
    public function __construct(private InventoryService $inventory, private FinanceService $finance) {}

    public function createOrder(array $d): PurchaseOrder
    {
        if (empty($d['items'])) throw new BusinessRuleException('Add at least one item.');
        return DB::transaction(function () use ($d) {
            $po = PurchaseOrder::create([
                'number' => DocNumber::next('PO'), 'supplier_id' => $d['supplier_id'], 'warehouse_id' => $d['warehouse_id'],
                'order_date' => $d['order_date'] ?? now()->toDateString(), 'status' => 'draft',
                'notes' => $d['notes'] ?? null, 'created_by' => auth_user_id(),
            ]);
            $total = 0;
            foreach ($d['items'] as $i) {
                $po->items()->create(['product_id' => $i['product_id'], 'qty' => $i['qty'], 'unit_price' => $i['unit_price']]);
                $total += (float) $i['qty'] * (float) $i['unit_price'];
            }
            $po->update(['total' => $total]);
            return $po->load('items.product', 'supplier');
        });
    }

    public function approve(PurchaseOrder $po): PurchaseOrder
    {
        if ($po->status !== 'draft') throw new BusinessRuleException('Only draft orders can be approved.');
        $po->update(['status' => 'approved', 'approved_by' => auth_user_id(), 'approved_at' => now()]);
        return $po;
    }

    /** @param array<int,array{item_id:int,qty:float}> $lines */
    public function receive(PurchaseOrder $po, array $lines, ?string $batch = null): GoodsReceipt
    {
        return DB::transaction(function () use ($po, $lines, $batch) {
            $po = PurchaseOrder::lockForUpdate()->with('items')->findOrFail($po->id);
            if (!in_array($po->status, ['approved', 'partially_received'], true)) throw new BusinessRuleException('Approve the purchase order before receiving goods.');
            $grn = GoodsReceipt::create(['number' => DocNumber::next('GRN'), 'purchase_order_id' => $po->id, 'received_by' => auth_user_id(), 'batch_no' => $batch]);
            $total = 0;
            foreach ($lines as $l) {
                $item = $po->items->firstWhere('id', $l['item_id']);
                $qty = (float) $l['qty'];
                if (!$item || $qty <= 0) throw new BusinessRuleException('Invalid receipt line.');
                if ($qty > (float) $item->qty - (float) $item->received_qty + 0.0001) throw new BusinessRuleException('Receiving more than ordered.');
                $this->inventory->move($item->product_id, $po->warehouse_id, $qty, 'purchase_receipt', [
                    'ref_type' => 'goods_receipt', 'ref_id' => $grn->id, 'unit_cost' => (float) $item->unit_price, 'batch_no' => $batch,
                ]);
                $item->received_qty = (float) $item->received_qty + $qty;
                $item->save();
                $total += $qty * (float) $item->unit_price;
            }
            $grn->update(['total' => $total]);
            $done = $po->items()->get()->every(fn ($i) => (float) $i->received_qty + 0.0001 >= (float) $i->qty);
            $po->update(['status' => $done ? 'received' : 'partially_received']);

            SupplierInvoice::create([
                'number' => DocNumber::next('SINV'), 'supplier_id' => $po->supplier_id, 'goods_receipt_id' => $grn->id,
                'amount' => $total, 'due_date' => now()->addDays(30)->toDateString(),
            ]);
            Supplier::where('id', $po->supplier_id)->increment('outstanding_balance', $total);
            return $grn;
        });
    }

    public function paySupplier(SupplierInvoice $inv, float $amount, int $accountId): SupplierPayment
    {
        return DB::transaction(function () use ($inv, $amount, $accountId) {
            $inv = SupplierInvoice::lockForUpdate()->findOrFail($inv->id);
            $due = (float) $inv->amount - (float) $inv->paid;
            if ($amount <= 0 || $amount > $due + 0.005) throw new BusinessRuleException('Payment must be between 0 and the balance due.');
            $pay = SupplierPayment::create([
                'number' => DocNumber::next('SPAY'), 'supplier_id' => $inv->supplier_id, 'supplier_invoice_id' => $inv->id,
                'account_id' => $accountId, 'amount' => $amount, 'user_id' => auth_user_id(),
            ]);
            $inv->paid = (float) $inv->paid + $amount;
            $inv->status = $inv->paid + 0.005 >= (float) $inv->amount ? 'paid' : 'partial';
            $inv->save();
            Supplier::where('id', $inv->supplier_id)->decrement('outstanding_balance', $amount);
            $this->finance->post($accountId, 'out', $amount, 'supplier_payment', 'supplier_payment', $pay->id, null, "Supplier invoice {$inv->number}");
            return $pay;
        });
    }
}
