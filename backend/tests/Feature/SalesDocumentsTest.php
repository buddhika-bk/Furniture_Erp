<?php
namespace Tests\Feature;

use App\Models\{Bom, Invoice, ManufacturingRequest, Payment, Product, StockBalance, Warehouse};
use Tests\TestCase;

class SalesDocumentsTest extends TestCase
{
    protected function setUp(): void {
        parent::setUp();
        // These tests exercise many submissions in milliseconds; auth and permissions remain enabled.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }
    private function order(array $overrides = []): array {
        return array_replace(['customer_name' => 'Example Customer', 'customer_phone' => '0771234567', 'customer_id' => \App\Models\Customer::where('is_walk_in',false)->first()->id,
            'items' => [['product_id' => Product::where('sku','FG-BS-Q')->value('id'), 'qty' => 50]],
            'payments' => [['method' => 'cash', 'amount' => 100]]], $overrides);
    }
    public function test_shortage_invoice_deducts_only_available_stock_and_creates_exact_factory_order(): void {
        $h = $this->loginAs('cashier1@erp.test');
        $p = Product::where('sku','FG-BS-Q')->first(); $wh = Warehouse::where('code','WH-SR1')->first();
        StockBalance::where('product_id',$p->id)->where('warehouse_id',$wh->id)->update(['qty'=>30]);
        $h['Idempotency-Key'] = 'shortage-order-test';
        $res = $this->postJson('/api/sales/invoices', $this->order(), $h)->assertCreated();
        $id = $res->json('data.id');
        $this->assertEquals(30, $res->json('data.items.0.allocated_qty'));
        $this->assertEquals(0, StockBalance::where('product_id',$p->id)->where('warehouse_id',$wh->id)->value('qty'));
        $req = ManufacturingRequest::where('invoice_id',$id)->firstOrFail();
        $this->assertEquals(20, $req->qty); $this->assertEquals('queued', $req->status);
        $this->assertEquals(20, $req->productionOrder->qty_planned);
        $this->assertEquals(2395, $res->json('data.balance_due'));
        $this->postJson('/api/sales/invoices', $this->order(), $h)->assertCreated()->assertJsonPath('data.id',$id);
        $this->assertEquals(1, Invoice::count()); $this->assertEquals(1, Payment::count());
        $admin = $this->postJson('/api/auth/login',['email'=>'admin@erp.test','password'=>'Password@123'])->json('token');
        $ah=['Authorization'=>'Bearer '.$admin]; $pid=$req->production_order_id;
        $this->getJson('/api/invoices/'.$id,$h)->assertJsonPath('data.payment_status','pending')->assertJsonPath('data.manufacturing_requests.0.progress_status','ready');
        $this->postJson("/api/production-orders/$pid/release",[],$h)->assertForbidden();
        $this->postJson("/api/production-orders/$pid/complete",['qty_produced'=>20],$ah)->assertStatus(422);
        $this->postJson("/api/production-orders/$pid/release",[],$ah)->assertOk()->assertJsonPath('data.progress_status','start');
        $this->getJson('/api/invoices/'.$id,$h)->assertJsonPath('data.manufacturing_requests.0.progress_status','start');
        $this->postJson("/api/production-orders/$pid/start",[],$ah)->assertOk()->assertJsonPath('data.progress_status','process');
        $this->getJson('/api/manufacturing-requests?status=process',$ah)->assertOk()->assertJsonPath('data.0.progress_status','process');
        $this->getJson('/api/invoices/'.$id,$h)->assertJsonPath('data.manufacturing_requests.0.progress_status','process');
        $this->postJson("/api/production-orders/$pid/start",[],$ah)->assertStatus(422);
        $this->postJson("/api/production-orders/$pid/complete",['qty_produced'=>19],$ah)->assertStatus(422);
        $this->postJson("/api/production-orders/$pid/complete",['qty_produced'=>20],$ah)->assertOk();
        $this->assertEquals('completed',$req->fresh()->status);
        $this->getJson('/api/invoices/'.$id,$h)->assertJsonPath('data.payment_status','pending')->assertJsonPath('data.manufacturing_requests.0.progress_status','completed');
        $this->assertEquals(50,Invoice::find($id)->items->first()->allocated_qty);
        $this->assertEqualsWithDelta(Invoice::find($id)->items->first()->unit_cost * 50, Invoice::find($id)->cost_total, 0.01);
        $this->getJson('/api/reports/invoice-balances',$ah)->assertOk()->assertJsonPath('data.0.balance_due',2395);
        $this->assertEquals(0,StockBalance::where('warehouse_id',$req->productionOrder->target_warehouse_id)->where('product_id',$p->id)->value('qty'));
    }
    public function test_payment_status_and_filters_change_only_when_balance_is_zero(): void {
        $h=$this->loginAs('cashier1@erp.test');
        $d=$this->order(['payments'=>[]]);
        $id=$this->postJson('/api/sales/invoices',$d,$h)->assertCreated()->assertJsonPath('data.payment_status','pending')->json('data.id');
        $this->getJson('/api/invoices?filter[payment_status]=pending',$h)->assertJsonPath('total',1);
        $this->getJson('/api/invoices?filter[payment_status]=completed',$h)->assertJsonPath('total',0);
        $this->postJson("/api/invoices/$id/payments",['method'=>'cash','amount'=>2494.99],$h)->assertOk()->assertJsonPath('data.payment_status','pending');
        $this->postJson("/api/invoices/$id/payments",['method'=>'card','amount'=>0.01],$h)->assertOk()->assertJsonPath('data.payment_status','completed');
        $this->getJson('/api/invoices?filter[payment_status]=completed',$h)->assertJsonPath('total',1);
        $this->getJson('/api/invoices?filter[payment_status]=pending',$h)->assertJsonPath('total',0);
        // Paying does not falsely mark the factory order completed.
        $this->getJson('/api/invoices/'.$id,$h)->assertJsonPath('data.manufacturing_requests.0.progress_status','ready');
    }
    public function test_special_order_waits_for_bom_approval_and_keeps_store_stock_untouched(): void {
        $h=$this->loginAs('cashier1@erp.test');
        $p=Product::where('sku','FG-BS-Q')->first();
        $before=StockBalance::where('product_id',$p->id)->sum('qty');
        $this->postJson('/api/sales/invoices',$this->order(['items'=>[['product_id'=>$p->id,'qty'=>50,'special_bom'=>true]]]),$h)->assertStatus(422);
        $res=$this->postJson('/api/sales/invoices',$this->order(['items'=>[['product_id'=>$p->id,'qty'=>50,'special_bom'=>true,'manufacturing_notes'=>'Red fabric, custom finish']]]),$h)->assertCreated();
        $req=ManufacturingRequest::where('invoice_id',$res->json('data.id'))->firstOrFail();
        $this->assertEquals('awaiting_bom',$req->status); $this->assertEquals(50,$req->qty);
        $this->assertEquals($before,StockBalance::where('product_id',$p->id)->sum('qty'));
        $d=['bom_id'=>Bom::where('product_id',$p->id)->value('id'),'factory_warehouse_id'=>Warehouse::where('code','FAC-RAW')->value('id')];
        $this->postJson('/api/manufacturing-requests/'.$req->id.'/approve',$d,$h)->assertForbidden();
        $admin=$this->postJson('/api/auth/login',['email'=>'admin@erp.test','password'=>'Password@123'])->json('token');
        $ah=['Authorization'=>'Bearer '.$admin];
        $this->postJson('/api/manufacturing-requests/'.$req->id.'/approve',$d,$ah)->assertOk();
        $this->assertStringContainsString('Red fabric',$req->fresh()->productionOrder->notes);
        $this->postJson('/api/manufacturing-requests/'.$req->id.'/approve',$d,$ah)->assertStatus(422);
        $this->postJson('/api/production-orders/'.$req->fresh()->production_order_id.'/cancel',[],$ah)->assertStatus(422);
    }
    public function test_missing_recipe_enters_approval_queue_and_invalid_payment_rolls_back(): void {
        $h=$this->loginAs('cashier1@erp.test');
        Bom::query()->update(['is_active'=>false]);
        $this->postJson('/api/sales/invoices',$this->order(['payments'=>[['method'=>'cash','amount'=>999999]]]),$h)->assertStatus(422);
        $this->assertEquals(0,Invoice::count());
        $res=$this->postJson('/api/sales/invoices',$this->order(['payments'=>[]]),$h)->assertCreated();
        $this->assertEquals('awaiting_bom',$res->json('data.manufacturing_requests.0.status'));
        $this->assertEquals(2495,$res->json('data.balance_due'));
        $this->postJson('/api/sales/invoices',$this->order(['items'=>[['product_id'=>Product::first()->id,'qty'=>0.0001]]]),$h)->assertStatus(422);
    }
    public function test_payment_methods_balance_idempotency_and_document_permissions(): void {
        $h=$this->loginAs('cashier1@erp.test');
        $res=$this->postJson('/api/sales/invoices',$this->order(['payments'=>[]]),$h)->assertCreated();$id=$res->json('data.id');
        foreach(['cash','card','bank','koko','other'] as $method){
            $ph=$h+['Idempotency-Key'=>'payment-test-key-'.$method];$d=['method'=>$method,'amount'=>10,'reference'=>'TEST-'.$method];
            $this->postJson("/api/invoices/$id/payments",$d,$ph)->assertOk();
            $this->postJson("/api/invoices/$id/payments",$d,$ph)->assertOk();
        }
        $this->assertEquals(50,Invoice::find($id)->paid);$this->assertEquals(2445,Invoice::find($id)->balance_due);
        $this->assertEquals(5,Payment::count());
        $this->postJson("/api/invoices/$id/payments",['method'=>'cash','amount'=>2446],$h)->assertStatus(422);
        $this->postJson('/api/sales/invoices',$this->order(['branch_id'=>Warehouse::where('code','FAC-RAW')->value('branch_id')]),$h)->assertForbidden();
        $pdf=$this->get('/api/sales/invoices/'.$id.'/pdf',$h)->assertOk()->assertHeader('Content-Type','application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        if(getenv('DOCUMENT_QA_OUTPUT')) file_put_contents(getenv('DOCUMENT_QA_OUTPUT').'/invoice.pdf',$pdf->getContent());
        $other=$this->postJson('/api/auth/login',['email'=>'cashier2@erp.test','password'=>'Password@123'])->assertOk()->json('token');
        $this->get('/api/sales/invoices/'.$id.'/pdf',['Authorization'=>'Bearer '.$other])->assertNotFound();
        $this->getJson('/api/manufacturing-requests',$h)->assertForbidden();
    }
    public function test_quotations_include_out_of_stock_and_raw_products_without_stock_or_payment_changes(): void {
        $h=$this->loginAs('cashier1@erp.test');
        $p=Product::where('sku','RM-COT-01')->first(); $stock=StockBalance::sum('qty');
        $this->getJson('/api/sales/catalog?quotation=1&search=RM-COT-01',$h)->assertOk()->assertJsonPath('products.data.0.id',$p->id);
        $d=['customer_name'=>'Quotation Customer','items'=>[['product_id'=>$p->id,'qty'=>100]],'valid_until'=>now()->addDays(30)->toDateString(),'notes'=>'Delivery subject to confirmation'];
        $res=$this->postJson('/api/sales/quotations',$d,$h)->assertCreated();$id=$res->json('data.id');
        $this->assertEquals($stock,StockBalance::sum('qty'));$this->assertEquals(0,Payment::count());$this->assertEquals(0,ManufacturingRequest::count());
        $this->getJson('/api/quotations',$h)->assertOk()->assertJsonPath('data.0.id',$id);
        $pdf=$this->get('/api/sales/quotations/'.$id.'/pdf',$h)->assertOk();$this->assertStringStartsWith('%PDF-',$pdf->getContent());
        // Rendered fixtures for visual PDF QA; this test uses only the isolated in-memory database.
        if(getenv('DOCUMENT_QA_OUTPUT')) file_put_contents(getenv('DOCUMENT_QA_OUTPUT').'/quotation.pdf',$pdf->getContent());
    }

    public function test_selected_payment_date_updates_invoice_pdf_without_backdating_audit_time(): void {
        $h=$this->loginAs('cashier1@erp.test');
        $id=$this->postJson('/api/sales/invoices',$this->order(['payments'=>[]]),$h)->assertCreated()->json('data.id');
        Invoice::find($id)->update(['created_at'=>now()->subDays(3)]);
        $date=now('Asia/Colombo')->subDay()->toDateString();
        $payload=['method'=>'bank','amount'=>100,'payment_date'=>$date,'reference'=>'DATED-PAYMENT'];
        $headers=$h+['Idempotency-Key'=>'dated-payment-once-2026'];
        $this->postJson('/api/invoices/'.$id.'/payments',$payload,$headers)->assertOk()->assertJsonPath('data.payments.0.payment_date',$date);
        $this->postJson('/api/invoices/'.$id.'/payments',$payload,$headers)->assertOk();
        $this->assertEquals(1,Payment::where('invoice_id',$id)->count());
        $payment=Payment::where('invoice_id',$id)->first();
        $this->assertEquals(now('Asia/Colombo')->toDateString(),$payment->created_at->timezone('Asia/Colombo')->toDateString());
        $this->assertEquals(2395,Invoice::find($id)->balance_due);
        foreach([now('Asia/Colombo')->addDay()->toDateString(),now('Asia/Colombo')->subDays(5)->toDateString(),'not-a-date'] as $bad) {
            $this->postJson('/api/invoices/'.$id.'/payments',array_replace($payload,['payment_date'=>$bad]),$h)->assertStatus(422);
        }
        $document=Invoice::with('items.product','payments')->find($id);
        $html=view('sales-document',['document'=>$document,'quote'=>false,'shop'=>$document->shop_snapshot,'customer'=>$document->customer_snapshot])->render();
        $this->assertStringContainsString($date,$html);
        $this->assertStringContainsString('2,395.00',$html);
        $this->get('/api/sales/invoices/'.$id.'/pdf',$h)->assertOk()->assertHeader('Content-Type','application/pdf');
        $ping=$this->getJson('/api/ping')->assertOk();
        $this->assertNotNull($ping->json('server_time'));
    }
}
