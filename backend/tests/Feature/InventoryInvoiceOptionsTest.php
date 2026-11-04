<?php
namespace Tests\Feature;

use App\Models\{Customer, Invoice, Product, StockBalance, Unit, Warehouse, Warranty};
use Tests\TestCase;

class InventoryInvoiceOptionsTest extends TestCase
{
    protected function setUp(): void {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }
    public function test_product_codes_warranty_opening_stock_and_actual_location_filter(): void {
        $h=$this->loginAs();
        $w=$this->postJson('/api/warranties',['name'=>'Standard fabric cover','duration_months'=>12,'terms'=>'Manufacturing defects only.','is_active'=>true],$h)->assertCreated()->json('data.id');
        $wh=Warehouse::where('code','WH-SR1')->first();
        $payload=['name'=>'New bedsheet','type'=>'finished_good','unit_id'=>Unit::first()->id,'warranty_id'=>$w,'loyalty_points'=>8,'warehouse_id'=>$wh->id,'opening_qty'=>30,'cost_price'=>10,'sale_price'=>20,'is_active'=>true];
        $p=$this->postJson('/api/products',$payload,$h)->assertCreated()->json('data');
        $this->assertStringStartsWith('SKU-', $p['sku']);
        $second=$this->postJson('/api/products',array_replace($payload,['opening_qty'=>0]),$h)->assertCreated()->json('data');
        $this->assertNotEquals($p['sku'],$second['sku']);
        $this->assertEquals(30,StockBalance::where('product_id',$p['id'])->where('warehouse_id',$wh->id)->value('qty'));
        $this->getJson('/api/products?search=New%20bedsheet&filter[stock_location]='.$wh->id,$h)->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.warranty.name','Standard fabric cover');
        $this->putJson('/api/products/'.$p['id'],['sku'=>'OVERRIDE','loyalty_points'=>9],$h)->assertOk()->assertJsonPath('data.sku',$p['sku']);
        $this->getJson('/api/lookups/warranties?filter[is_active]=1',$h)->assertOk()->assertJsonPath('data.0.id',$w);
        $cashier=$this->postJson('/api/auth/login',['email'=>'cashier1@erp.test','password'=>'Password@123'])->json('token');
        $ch=['Authorization'=>'Bearer '.$cashier];
        $this->postJson('/api/warranties',['name'=>'Unauthorized','duration_months'=>1],$ch)->assertForbidden();
    }
    public function test_walkin_requires_full_payment_and_cashier_registers_with_required_credit_limit(): void {
        $h=$this->loginAs('cashier1@erp.test');$p=Product::where('sku','FG-BS-Q')->first();
        $payload=['items'=>[['product_id'=>$p->id,'qty'=>1]],'payments'=>[]];
        $this->postJson('/api/sales/invoices',$payload,$h)->assertStatus(422);
        $this->assertEquals(0,Invoice::count());
        $this->postJson('/api/sales/invoices',$payload+['customer_id'=>Customer::where('is_walk_in',true)->value('id')],$h)->assertStatus(422);
        $full=$this->postJson('/api/sales/invoices',array_replace($payload,['payments'=>[['method'=>'cash','amount'=>$p->sale_price]]]),$h)->assertCreated();
        $full->assertJsonPath('data.payment_status','completed')->assertJsonPath('data.customer_snapshot.name','Walk-in customer');
        $this->postJson('/api/sales/customers',['name'=>'Missing credit limit'],$h)->assertStatus(422);
        $this->postJson('/api/sales/customers',['name'=>'Invalid credit limit','credit_limit'=>-1],$h)->assertStatus(422);
        $customer=$this->postJson('/api/sales/customers',['name'=>'New registered customer','phone'=>'0771111111','code'=>'CUSTOM','credit_limit'=>999999,'status'=>'blocked'],$h+['Idempotency-Key'=>'register-customer-safe'])->assertCreated()->json('data');
        $this->assertStringStartsWith('CUS-',$customer['code']);$this->assertEquals(999999,$customer['credit_limit']);$this->assertEquals('active',$customer['status']);
        $this->getJson('/api/sales/customers/'.$customer['id'].'/credit',$h)->assertOk()->assertJsonPath('data.credit_limit',999999);
        $this->postJson('/api/sales/customers',['name'=>'New registered customer','phone'=>'0771111111','code'=>'CUSTOM','credit_limit'=>999999,'status'=>'blocked'],$h+['Idempotency-Key'=>'register-customer-safe'])->assertCreated()->assertJsonPath('data.id',$customer['id']);
        $this->postJson('/api/sales/invoices',$payload+['customer_id'=>$customer['id']],$h)->assertCreated()->assertJsonPath('data.payment_status','pending');
    }
    public function test_mixed_lines_discount_charges_snapshot_and_private_factory_pdf(): void {
        $h=$this->loginAs('cashier1@erp.test');
        $p=Product::where('sku','FG-BS-Q')->first();
        $q=Product::where('type','finished_good')->where('id','!=',$p->id)->first();
        $w=Warranty::create(['name'=>'Premium cover','duration_months'=>24,'terms'=>'Seams and stitching.']);$p->update(['warranty_id'=>$w->id,'loyalty_points'=>7]);
        $wh=Warehouse::where('code','WH-SR1')->first();
        StockBalance::updateOrCreate(['product_id'=>$p->id,'warehouse_id'=>$wh->id],['qty'=>30]);
        StockBalance::updateOrCreate(['product_id'=>$q->id,'warehouse_id'=>$wh->id],['qty'=>30]);
        $payload=['customer_id'=>Customer::where('is_walk_in',false)->first()->id,'items'=>[
            ['product_id'=>$p->id,'qty'=>2,'unit_price'=>100,'discount_type'=>'percentage','discount_value'=>10,'customer_description'=>'Customer-facing red fabric','special_bom'=>true,'manufacturing_notes'=>'INTERNAL_FACTORY_ONLY: custom dye'],
            ['product_id'=>$q->id,'qty'=>3,'unit_price'=>50,'discount_type'=>'fixed','discount_value'=>20]],
            'delivery_charge'=>25,'service_charges'=>[['name'=>'Installation','amount'=>15],['name'=>'Gift packaging','amount'=>5]],'payments'=>[['method'=>'card','amount'=>100]]];
        $d=$this->postJson('/api/sales/invoices',$payload,$h)->assertCreated()->json('data');
        $this->assertEquals(350,$d['subtotal']);$this->assertEquals(40,$d['discount']);$this->assertEquals(355,$d['total']);$this->assertEquals(255,$d['balance_due']);
        $this->assertCount(1,$d['manufacturing_requests']);$this->assertEquals(2,$d['manufacturing_requests'][0]['qty']);
        $this->assertEquals('awaiting_bom',$d['manufacturing_requests'][0]['status']);
        $this->assertEquals(30,StockBalance::where('product_id',$p->id)->where('warehouse_id',$wh->id)->value('qty'));
        $this->assertEquals(27,StockBalance::where('product_id',$q->id)->where('warehouse_id',$wh->id)->value('qty'));
        $w->update(['name'=>'Changed warranty']);$document=Invoice::with('items.product','payments','manufacturingRequests.productionOrder','manufacturingRequests.product')->find($d['id']);
        $html=view('sales-document',['document'=>$document,'quote'=>false,'shop'=>$document->shop_snapshot,'customer'=>$document->customer_snapshot])->render();
        foreach(['Premium cover','Customer-facing red fabric','Installation','Gift packaging','Delivery charge'] as $text)$this->assertStringContainsString($text,$html);
        foreach(['INTERNAL_FACTORY_ONLY','Factory / BOM','To make','Allocated','Changed warranty'] as $text)$this->assertStringNotContainsString($text,$html);
        $invoicePdf=$this->get('/api/sales/invoices/'.$d['id'].'/pdf',$h)->assertOk();
        $factoryPdf=$this->get('/api/sales/invoices/'.$d['id'].'/factory-pdf',$h)->assertOk()->assertHeader('Content-Type','application/pdf');
        $this->assertStringStartsWith('%PDF-',$factoryPdf->getContent());
        $this->get('/api/sales/invoices/'.$d['id'].'/print',$h)->assertOk()->assertSee('window.print()',false)->assertSee('FACTORY / BOM REQUEST')->assertSee('Installation');
        if(getenv('DOCUMENT_QA_OUTPUT')){file_put_contents(getenv('DOCUMENT_QA_OUTPUT').'/invoice-options.pdf',$invoicePdf->getContent());file_put_contents(getenv('DOCUMENT_QA_OUTPUT').'/factory-options.pdf',$factoryPdf->getContent());}
        $other=$this->postJson('/api/auth/login',['email'=>'cashier2@erp.test','password'=>'Password@123'])->json('token');
        $this->get('/api/sales/invoices/'.$d['id'].'/factory-pdf',['Authorization'=>'Bearer '.$other])->assertNotFound();
        $bad=$payload;$bad['items'][0]['discount_value']=101;
        $this->postJson('/api/sales/invoices',$bad,$h)->assertStatus(422);$this->assertEquals(1,Invoice::count());
    }
    public function test_returns_refund_discounted_goods_without_refunding_service_charges(): void {
        $h=$this->loginAs();$p=Product::where('sku','FG-BS-Q')->first();$wh=Warehouse::where('code','WH-SR1')->first();
        StockBalance::updateOrCreate(['product_id'=>$p->id,'warehouse_id'=>$wh->id],['qty'=>30]);
        $d=$this->postJson('/api/sales/invoices',['branch_id'=>$wh->branch_id,'items'=>[['product_id'=>$p->id,'qty'=>2,'unit_price'=>100,'discount_type'=>'fixed','discount_value'=>20]],'delivery_charge'=>25,'service_charges'=>[['name'=>'Installation','amount'=>15]],'payments'=>[['method'=>'cash','amount'=>220]]],$h)->assertCreated()->json('data');
        $this->postJson('/api/invoices/'.$d['id'].'/return',['items'=>[['product_id'=>$p->id,'qty'=>1]],'refund_method'=>'cash','reason'=>'Returned one unit'],$h)->assertCreated()->assertJsonPath('data.refund_amount',90);
    }
}
