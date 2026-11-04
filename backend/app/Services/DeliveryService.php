<?php
namespace App\Services;

use App\Models\{Invoice, DeliveryOrder};
use App\Support\DocNumber;
use Illuminate\Support\Facades\DB;

class DeliveryService
{
    public function forInvoice(Invoice $invoice): ?DeliveryOrder
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            if ((float)$invoice->delivery_charge <= 0) return null;
            $existing = DeliveryOrder::where('invoice_id', $invoice->id)->orderBy('id')->first();
            if ($existing) return $existing;
            return DeliveryOrder::create([
                'number' => DocNumber::next('DLV'), 'invoice_id' => $invoice->id, 'branch_id' => $invoice->branch_id,
                'delivery_charge' => $invoice->delivery_charge, 'status' => 'pending',
                'destination' => mb_substr(trim($invoice->customer_snapshot['address'] ?? '') ?: 'Address to be confirmed', 0, 255),
            ]);
        });
    }
}
