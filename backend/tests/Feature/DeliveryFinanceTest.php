<?php
namespace Tests\Feature;

use App\Models\{Invoice, Product, Customer, Warehouse, DeliveryOrder, FinancialTransaction, Account, Vehicle};
use App\Services\DeliveryService;
use Tests\TestCase;

class DeliveryFinanceTest extends TestCase
{
    public function test_invoice_delivery_and_each_payment_are_linked_without_double_revenue(): void
    {
        $h = $this->loginAs();
        $p = Product::where('sku','FG-BS-Q')->first();
        $p->update(['sale_price'=>100]);
        $c = Customer::where('code','CUS-003')->first();
        $c->update(['address'=>'123 Delivery Road']);
        $wh = Warehouse::where('code','WH-SR1')->first();
        $payload = ['branch_id'=>$wh->branch_id,'customer_id'=>$c->id,'delivery_charge'=>250,'items'=>[['product_id'=>$p->id,'qty'=>1]],'payments'=>[['method'=>'cash','amount'=>100]]];
        $id = $this->postJson('/api/sales/invoices',$payload,$h)->assertCreated()->json('data.id');
        $invoice = Invoice::findOrFail($id);
        $delivery = DeliveryOrder::where('invoice_id',$id)->firstOrFail();
        $this->assertEquals(250,$delivery->delivery_charge);
        $this->assertEquals('123 Delivery Road',$delivery->destination);
        app(DeliveryService::class)->forInvoice($invoice);
        $this->assertEquals(1,DeliveryOrder::where('invoice_id',$id)->count());
        $this->postJson('/api/delivery-orders',['invoice_id'=>$id,'destination'=>'Duplicate','delivery_charge'=>999],$h)->assertStatus(422);
        $this->postJson('/api/invoices/'.$id.'/payments',['method'=>'koko','amount'=>250],$h)->assertOk();
        $entries = FinancialTransaction::where('ref_type','invoice')->where('ref_id',$id)->get();
        $this->assertCount(2,$entries);
        $this->assertEquals(350,$entries->sum('amount'));
        $this->assertCount(2,$entries->pluck('payment_id')->unique());
        $this->assertEquals('koko',$entries->last()->payment->method);
        $this->getJson('/api/payments?filter[invoice_id]='.$id,$h)->assertOk();
        $this->getJson('/api/financial_transactions?search='.urlencode($invoice->number),$h)->assertOk()->assertJsonPath('data.0.payment.invoice.number',$invoice->number);
        $this->putJson('/api/delivery-orders/'.$delivery->id,['destination'=>'Confirmed address','fuel_cost'=>50],$h)->assertOk();
        $this->postJson('/api/delivery-orders/'.$delivery->id.'/status',['status'=>'dispatched'],$h)->assertOk();
        $this->postJson('/api/delivery-orders/'.$delivery->id.'/status',['status'=>'delivered'],$h)->assertOk();
        $this->postJson('/api/delivery-orders/'.$delivery->id.'/status',['status'=>'delivered'],$h)->assertStatus(422);
        $fuel = FinancialTransaction::where('ref_type','delivery_order')->where('ref_id',$delivery->id)->get();
        $this->assertCount(1,$fuel);
        $this->assertEquals(50,$fuel->first()->amount);
        $this->assertEquals('out',$fuel->first()->direction);
        $this->assertEquals(350,FinancialTransaction::where('ref_type','invoice')->where('ref_id',$id)->sum('amount'));
        $this->artisan('erp:sync-invoice-deliveries')->assertSuccessful();
        $this->assertEquals(1,DeliveryOrder::where('invoice_id',$id)->count());
    }

    public function test_new_vehicle_cost_posts_once_and_is_not_editable(): void
    {
        $h=$this->loginAs();
        $id=$this->postJson('/api/vehicle_logs',['vehicle_id'=>Vehicle::first()->id,'type'=>'maintenance','log_date'=>'2026-11-04','amount'=>125],$h)->assertCreated()->json('data.id');
        $entry=FinancialTransaction::where('ref_type','vehicle_log')->where('ref_id',$id)->sole();
        $this->assertEquals(125,$entry->amount);
        $this->putJson('/api/vehicle_logs/'.$id,['amount'=>200],$h)->assertStatus(422);
        $campaign=\App\Models\Campaign::firstOrFail();
        $spend=$this->postJson('/api/campaign_expenses',['campaign_id'=>$campaign->id,'spent_on'=>'2026-11-04','amount'=>75],$h)->assertCreated()->json('data.id');
        $this->assertEquals(75,FinancialTransaction::where('ref_type','campaign_expense')->where('ref_id',$spend)->sole()->amount);
        $this->putJson('/api/campaign_expenses/'.$spend,['amount'=>80],$h)->assertStatus(422);
    }
}
