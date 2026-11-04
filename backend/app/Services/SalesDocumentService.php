<?php
namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\{Bom, Branch, CashierSession, Customer, Invoice, ManufacturingRequest, Payment, Product, Quotation, StockBalance, Warehouse};
use App\Support\{DocNumber, Settings};
use Illuminate\Support\Facades\DB;

class SalesDocumentService
{
    public function __construct(private InventoryService $inventory, private FinanceService $finance, private ManufacturingService $manufacturing) {}

    private function snapshots(array $d): array {
        $customer = !empty($d['customer_id']) ? Customer::findOrFail($d['customer_id']) : null;
        if ($customer && $customer->status !== 'active') throw new BusinessRuleException('Select an active customer.');
        $branch = Branch::where('is_active', true)->findOrFail($d['branch_id']);
        return [
            'customer_snapshot' => $customer ? $customer->only(['name','phone','email','address']) : ['name' => ($d['customer_name'] ?? 'Walk-in customer'), 'phone' => $d['customer_phone'] ?? '', 'address' => $d['customer_address'] ?? ''],
            'shop_snapshot' => ['name' => Settings::get('company_name', config('app.name')), 'branch' => $branch->name, 'address' => $branch->address, 'phone' => $branch->phone, 'currency' => Settings::get('currency', 'LKR')],
        ];
    }

    private function lines(array $items, bool $quotation): array {
        $lines = [];
        // Stable lock order reduces deadlocks between simultaneous invoice submissions.
        usort($items, fn ($a, $b) => $a['product_id'] <=> $b['product_id']);
        foreach ($items as $item) {
            $p = Product::findOrFail($item['product_id']);
            if (!$p->is_active || (!$quotation && $p->type !== 'finished_good')) throw new BusinessRuleException("{$p->name} is not available for this document.");
            $qty = round((float) $item['qty'], 3);
            $line = ['product_id' => $p->id, 'description' => $p->name.($p->variant ? ' · '.$p->variant : '').' ['.$p->sku.']', 'qty' => $qty,
                'unit_price' => (float) $p->sale_price, 'line_total' => round($qty * (float) $p->sale_price, 2), 'unit_cost' => (float) $p->cost_price];
            if (!$quotation) {
                $price = round((float)($item['unit_price'] ?? $p->sale_price),2);
                $gross = round($price*$qty,2);
                if($gross>999999999999.99) throw new BusinessRuleException('Product line amount exceeds the supported limit.');
                $type = $item['discount_type'] ?? 'fixed';
                $value = round((float)($item['discount_value'] ?? 0),2);
                $discount = $type==='percentage' ? round($gross*$value/100,2) : $value;
                if ($discount > $gross || ($type==='percentage' && $value>100)) throw new BusinessRuleException('Discount cannot exceed the product amount.');
                $line = array_replace($line, ['unit_price'=>$price,'line_total'=>round($gross-$discount,2),
                    'discount_type'=>$type,'discount_value'=>$value,'discount_amount'=>$discount,
                    'customer_description'=>$item['customer_description']??null,
                    'warranty_snapshot'=>$p->warranty?->only(['name','duration_months','terms']),
                    'loyalty_points'=>(int)$p->loyalty_points,
                    'special_bom'=>(bool)($item['special_bom']??false),'manufacturing_notes'=>$item['manufacturing_notes']??null]);
            }
            $lines[]=$line;
        }
        return $lines;
    }

    public function quotation(array $d): Quotation {
        return DB::transaction(function () use ($d) {
            $lines = $this->lines($d['items'], true);
            $total = array_sum(array_column($lines, 'line_total'));
            $q = Quotation::create($this->snapshots($d) + ['number' => DocNumber::next('QUO'), 'branch_id' => $d['branch_id'], 'customer_id' => $d['customer_id'] ?? null,
                'subtotal' => $total, 'total' => $total, 'valid_until' => $d['valid_until'], 'notes' => $d['notes'] ?? null, 'created_by' => auth_user_id()]);
            foreach ($lines as $line) { unset($line['unit_cost']); $q->items()->create($line); }
            return $q->load('items.product');
        });
    }

    public function invoice(array $d): Invoice {
        return DB::transaction(function () use ($d) {
            \App\Models\User::whereKey(auth_user_id())->lockForUpdate()->firstOrFail();
            $wh = Warehouse::where('branch_id', $d['branch_id'])->where('is_active', true)->where('type', 'showroom')->first();
            if (!$wh) throw new BusinessRuleException('Select a branch with an active showroom warehouse.');
            if (!empty($d['customer_id']) && Customer::findOrFail($d['customer_id'])->is_walk_in) $d['customer_id']=null;
            $lines = $this->lines($d['items'], false);
            $subtotal = round(array_sum(array_map(fn($l)=>round($l['qty']*$l['unit_price'],2),$lines)),2);
            $discount = round(array_sum(array_column($lines,'discount_amount')),2);
            $delivery = round((float)($d['delivery_charge']??0),2);
            $services = $d['service_charges']??[];
            $total = round($subtotal-$discount+$delivery+array_sum(array_column($services,'amount')),2);
            if($total>999999999999.99 || $subtotal>999999999999.99) throw new BusinessRuleException('Invoice amount exceeds the supported limit.');
            $paid = round(array_sum(array_column($d['payments'] ?? [], 'amount')), 2);
            if ($paid > $total) throw new BusinessRuleException('Payments cannot exceed the invoice total.');
            if (empty($d['customer_id']) && abs($paid-$total)>0.001) throw new BusinessRuleException('Walk-in customers must pay the full invoice amount. Register a customer for advance or credit invoices.');
            $invoice = Invoice::create($this->snapshots($d) + ['number' => DocNumber::next('INV'), 'branch_id' => $d['branch_id'], 'warehouse_id' => $wh->id,
                'customer_id' => $d['customer_id'] ?? null, 'subtotal' => $subtotal, 'discount'=>$discount, 'delivery_charge'=>$delivery, 'service_charges'=>$services, 'pricing_version'=>2, 'total' => $total, 'paid' => 0, 'balance_due' => $total,
                'is_order' => true, 'is_special' => false, 'notes' => $d['notes'] ?? null, 'due_date' => $d['due_date'] ?? null, 'created_by' => auth_user_id()]);
            $cost = 0;
            foreach ($lines as $line) {
                $special=$line['special_bom'];
                StockBalance::firstOrCreate(['product_id' => $line['product_id'], 'warehouse_id' => $wh->id], ['qty' => 0]);
                $balance = StockBalance::where('product_id', $line['product_id'])->where('warehouse_id', $wh->id)->lockForUpdate()->firstOrFail();
                $allocated = $special ? 0 : min($line['qty'], max(0, (float) $balance->qty));
                $item = $invoice->items()->create($line + ['allocated_qty' => $allocated]);
                if ($allocated > 0) $this->inventory->move($line['product_id'], $wh->id, -$allocated, 'sale', ['ref_type' => 'invoice', 'ref_id' => $invoice->id, 'unit_cost' => $line['unit_cost']]);
                // Pending production uses the current standard cost; completion replaces it with actual cost.
                $cost += $line['qty'] * $line['unit_cost'];
                $short = round($line['qty'] - $allocated, 3);
                if ($short > 0) {
                    // Route automatically only when there is exactly one factory destination.
                    $factories = Warehouse::where('is_active', true)->where('type', 'factory_raw')->get();
                    $factory = $factories->count() === 1 ? $factories->first() : null;
                    $request = ManufacturingRequest::create(['number' => DocNumber::next('MREQ'), 'invoice_id' => $invoice->id, 'invoice_item_id' => $item->id,
                        'product_id' => $line['product_id'], 'qty' => $short, 'is_special' => $special, 'notes' => $line['manufacturing_notes'], 'factory_warehouse_id' => $factory?->id]);
                    $bom = Bom::where('product_id', $line['product_id'])->where('is_active', true)->orderByDesc('version')->first();
                    if (!$special && $bom && (float) $bom->output_qty > 0 && $bom->items()->exists() && $factory && Warehouse::where('branch_id', $factory->branch_id)->where('type', 'factory_fg')->where('is_active', true)->exists()) $this->queue($request, $bom, $factory);
                }
            }
            $invoice->update(['cost_total' => round($cost, 2)]);
            foreach ($d['payments'] ?? [] as $payment) $this->recordPayment($invoice, $payment);
            app(DeliveryService::class)->forInvoice($invoice);
            app(LoyaltyService::class)->award($invoice);
            return $invoice->fresh(['items.product', 'payments', 'manufacturingRequests.productionOrder']);
        }, 3);
    }

    public function recordPayment(Invoice $invoice, array $d): Invoice {
        return DB::transaction(function () use ($invoice, $d) {
            \App\Models\User::whereKey(auth_user_id())->lockForUpdate()->firstOrFail();
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            $amount = round((float) $d['amount'], 2);
            $paymentDate=$d['payment_date']??now('Asia/Colombo')->toDateString();
            if($paymentDate < $invoice->created_at->copy()->timezone('Asia/Colombo')->toDateString() || $paymentDate > now('Asia/Colombo')->toDateString()) throw new BusinessRuleException('Payment date must be between the invoice date and today (Sri Lanka).');
            if (!$invoice->is_order || $invoice->status !== 'completed') throw new BusinessRuleException('This invoice cannot accept an order payment.');
            if ($amount <= 0 || $amount > (float) $invoice->balance_due) throw new BusinessRuleException('Payment must be positive and no more than the remaining balance.');
            $account = $this->finance->accountFor($invoice->branch_id, $d['method']);
            $session = CashierSession::where('branch_id', $invoice->branch_id)->where('user_id', auth_user_id())->where('status', 'open')->lockForUpdate()->first();
            $payment = Payment::create(['invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id, 'branch_id' => $invoice->branch_id, 'account_id' => $account->id,
                'payment_date'=>$paymentDate, 'cashier_session_id' => $session?->id, 'method' => $d['method'], 'direction' => 'receipt', 'amount' => $amount, 'reference' => $d['reference'] ?? null, 'user_id' => auth_user_id()]);
            $this->finance->post($account->id, 'in', $amount, 'sale', 'invoice', $invoice->id, $invoice->branch_id, 'Payment '.$invoice->number, $payment->id);
            if ($session && $d['method'] === 'cash') $session->increment('expected_cash', $amount);
            $invoice->update(['paid' => round((float) $invoice->paid + $amount, 2), 'balance_due' => round((float) $invoice->balance_due - $amount, 2)]);
            return $invoice->fresh(['items.product', 'payments', 'manufacturingRequests.productionOrder']);
        });
    }

    public function queue(ManufacturingRequest $request, Bom $bom, Warehouse $factory): ManufacturingRequest {
        return DB::transaction(function () use ($request, $bom, $factory) {
            $request = ManufacturingRequest::lockForUpdate()->findOrFail($request->id);
            if ($request->status !== 'awaiting_bom') throw new BusinessRuleException('This request already has a production order.');
            if (!$bom->is_active || $bom->product_id !== $request->product_id || (float) $bom->output_qty <= 0 || !$bom->items()->exists()) throw new BusinessRuleException('Choose an active BOM with materials for this product.');
            if (!$factory->is_active || $factory->type !== 'factory_raw') throw new BusinessRuleException('Choose an active factory raw-material warehouse.');
            $target = Warehouse::where('branch_id', $factory->branch_id)->where('type', 'factory_fg')->where('is_active', true)->first();
            if (!$target) throw new BusinessRuleException('The factory needs a finished-goods warehouse.');
            $order = $this->manufacturing->create(['bom_id' => $bom->id, 'batches' => (float) $request->qty / (float) $bom->output_qty,
                'source_warehouse_id' => $factory->id, 'target_warehouse_id' => $target->id, 'planned_date' => $request->invoice->due_date,
                'notes' => $request->number.' / '.$request->invoice->number.($request->is_special ? ' / SPECIAL: ' : ' / ').$request->notes]);
            $request->update(['factory_warehouse_id' => $factory->id, 'production_order_id' => $order->id, 'status' => 'queued']);
            return $request->fresh(['product', 'invoice', 'productionOrder']);
        });
    }
}
