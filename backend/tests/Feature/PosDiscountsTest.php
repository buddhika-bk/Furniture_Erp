<?php
namespace Tests\Feature;
use App\Models\{Invoice,Product,StockBalance,Warehouse};
use Tests\TestCase;

class PosDiscountsTest extends TestCase
{
    public function test_product_and_bill_discounts_reconcile_split_payments_and_returns(): void {
        $h=$this->loginAs();
        $p=Product::where('sku','FG-BS-Q')->first();$p->update(['sale_price'=>100]);
        $q=Product::where('sku','FG-BS-K')->first();$q->update(['sale_price'=>50]);
        $wh=Warehouse::where('code','WH-SR1')->first();
        foreach([$p,$q] as $product) StockBalance::updateOrCreate(['product_id'=>$product->id,'warehouse_id'=>$wh->id],['qty'=>30]);
        $payload=['branch_id'=>$wh->branch_id,'items'=>[
            ['product_id'=>$p->id,'qty'=>2,'discount_type'=>'percentage','discount_value'=>10],
            ['product_id'=>$q->id,'qty'=>3,'discount_type'=>'fixed','discount_value'=>20]],
            'bill_discount_type'=>'percentage','bill_discount_value'=>10,
            'payments'=>[['method'=>'cash','amount'=>50],['method'=>'card','amount'=>50],['method'=>'bank','amount'=>50],['method'=>'koko','amount'=>50],['method'=>'other','amount'=>79]]];
        $d=$this->postJson('/api/pos/checkout',$payload,$h)->assertCreated()->json('data');
        $this->assertEquals(350,$d['subtotal']);$this->assertEquals(71,$d['discount']);$this->assertEquals(279,$d['total']);
        $this->assertEquals(31,$d['bill_discount_amount']);$this->assertCount(5,$d['payments']);
        $this->assertEquals(279,array_sum(array_column($d['items'],'line_total')));
        $this->assertEquals(162,$d['items'][0]['line_total']);$this->assertEquals(117,$d['items'][1]['line_total']);
        $this->postJson('/api/invoices/'.$d['id'].'/return',['items'=>[['product_id'=>$p->id,'qty'=>1]],'refund_method'=>'cash'],$h)->assertCreated()->assertJsonPath('data.refund_amount',81);
        $this->get('/api/sales/invoices/'.$d['id'].'/pdf',$h)->assertOk()->assertHeader('Content-Type','application/pdf');
        $invalid=$payload;$invalid['items'][0]['discount_value']=101;
        $this->postJson('/api/pos/checkout',$invalid,$h)->assertStatus(422);
        $invalid=$payload;$invalid['bill_discount_type']='fixed';$invalid['bill_discount_value']=311;
        $this->postJson('/api/pos/checkout',$invalid,$h)->assertStatus(422);
        $this->assertEquals(1,Invoice::count());
    }
    public function test_fixed_bill_discount_and_fully_discounted_sale(): void {
        $h=$this->loginAs('cashier1@erp.test');$p=Product::where('sku','FG-BS-Q')->first();$p->update(['sale_price'=>100]);
        $d=['items'=>[['product_id'=>$p->id,'qty'=>1,'discount_type'=>'fixed','discount_value'=>10]],'bill_discount_type'=>'fixed','bill_discount_value'=>15,'payments'=>[['method'=>'cash','amount'=>75]]];
        $sale=$this->postJson('/api/pos/checkout',$d,$h)->assertCreated()->json('data');
        $this->assertEquals(75,$sale['total']);$this->assertEquals(75,$sale['items'][0]['line_total']);
        $d['bill_discount_value']=90;$d['payments']=[];
        $this->postJson('/api/pos/checkout',$d,$h)->assertCreated()->assertJsonPath('data.total',0);
        $d['bill_discount_value']=89.99;
        $this->postJson('/api/pos/checkout',$d,$h)->assertStatus(422);
    }
}
