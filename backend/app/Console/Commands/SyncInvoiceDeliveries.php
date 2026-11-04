<?php
namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\DeliveryService;
use Illuminate\Console\Command;

class SyncInvoiceDeliveries extends Command
{
    protected $signature = 'erp:sync-invoice-deliveries';
    protected $description = 'Create missing delivery records for existing charged invoices; safe to repeat';
    public function handle(DeliveryService $service): int
    {
        $created = 0;
        Invoice::where('delivery_charge', '>', 0)->where('status', 'completed')->chunkById(100, function ($invoices) use ($service, &$created) {
            foreach ($invoices as $invoice) {
                $delivery = $service->forInvoice($invoice);
                if ($delivery?->wasRecentlyCreated) $created++;
            }
        });
        $this->info("Created {$created} missing deliveries. Existing deliveries and account balances were preserved.");
        return self::SUCCESS;
    }
}
