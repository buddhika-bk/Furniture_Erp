<?php
namespace Tests\Feature;
use App\Models\{Customer,Invoice,LoyaltyLedger,LoyaltyRule,Product,StockBalance,Warehouse};
use Tests\TestCase;

class LoyaltyRulesTest extends TestCase {
    private function rule(array $changes=[]): array {return array_replace(['name'=>'Every 100 earns 10','min_purchase'=>0,'max_purchase'=>null,'spend_amount'=>100,'points'=>10,'priority'=>0,'is_active'=>true],$changes);}
    public function test_owner_manages_rules_and_cashier_cannot_change_them(): void {
        $h=$this->loginAs();
        $id=$this->postJson('/api/loyalty_rules',$this->rule(),$h)->assertCreated()->json('data.id');
        $this->putJson('/api/loyalty_rules/'.$id,['max_purchase'=>50,'min_purchase'=>100],$h)->assertStatus(422);
        $this->postJson('/api/loyalty_rules',$this->rule(['spend_amount'=>0]),$h)->assertStatus(422);
        $this->putJson('/api/loyalty_rules/'.$id,['is_active'=>false],$h)->assertOk();
        $token=$this->postJson('/api/auth/login',['email'=>'cashier1@erp.test','password'=>'Password@123'])->json('token');
        $this->postJson('/api/loyalty_rules',$this->rule(),['Authorization'=>'Bearer '.$token])->assertForbidden();
    }
    public function test_purchase_rules_use_net_amount_priority_and_credit_without_double_awards(): void {
        $h=$this->loginAs('cashier1@erp.test');
        $customer=Customer::where('code','CUS-003')->first();$customer->update(['loyalty_points'=>0,'credit_limit'=>1000]);
        $p=Product::where('sku','FG-BS-Q')->first();$p->update(['sale_price'=>150]);
        LoyaltyRule::create($this->rule());
        $winner=LoyaltyRule::create($this->rule(['name'=>'Priority offer','min_purchase'=>200,'max_purchase'=>300,'points'=>20,'priority'=>5]));
        $payload=['customer_id'=>$customer->id,'items'=>[['product_id'=>$p->id,'qty'=>2,'discount_type'=>'fixed','discount_value'=>50]],'payments'=>[['method'=>'credit','amount'=>250]]];
        $headers=$h+['Idempotency-Key'=>'loyalty-credit-sale-once'];
        $id=$this->postJson('/api/pos/checkout',$payload,$headers)->assertCreated()->assertJsonPath('data.points_earned',40)->json('data.id');
        $this->postJson('/api/pos/checkout',$payload,$headers)->assertCreated();
        $this->assertEquals(40,$customer->fresh()->loyalty_points);$this->assertEquals(250,$customer->fresh()->credit_balance);
        $this->assertEquals($winner->id,Invoice::find($id)->loyalty_rule_snapshot['id']);
        $winner->update(['points'=>999]);
        $this->assertEquals(20,Invoice::find($id)->loyalty_rule_snapshot['points']);
        LoyaltyRule::query()->update(['is_active'=>false]);
        $this->postJson('/api/pos/checkout',$payload,$h)->assertCreated()->assertJsonPath('data.points_earned',0);
        $this->assertEquals(40,$customer->fresh()->loyalty_points);
        $this->assertEquals(1,LoyaltyLedger::where('invoice_id',$id)->where('type','earn')->count());
    }
    public function test_order_payment_does_not_reearn_and_partial_returns_reverse_all_points(): void {
        $h=$this->loginAs();$customer=Customer::where('code','CUS-003')->first();$customer->update(['loyalty_points'=>0]);
        $p=Product::where('sku','FG-BS-Q')->first();$p->update(['sale_price'=>100]);
        $wh=Warehouse::where('code','WH-SR1')->first();StockBalance::updateOrCreate(['product_id'=>$p->id,'warehouse_id'=>$wh->id],['qty'=>30]);
        LoyaltyRule::create($this->rule(['spend_amount'=>300,'points'=>2]));
        $payload=['branch_id'=>$wh->branch_id,'customer_id'=>$customer->id,'items'=>[['product_id'=>$p->id,'qty'=>3]],'payments'=>[]];
        $id=$this->postJson('/api/sales/invoices',$payload,$h)->assertCreated()->assertJsonPath('data.points_earned',2)->json('data.id');
        $this->postJson('/api/invoices/'.$id.'/payments',['method'=>'cash','amount'=>300],$h)->assertOk()->assertJsonPath('data.points_earned',2);
        foreach([2,1,0] as $expected){
            $this->postJson('/api/invoices/'.$id.'/return',['items'=>[['product_id'=>$p->id,'qty'=>1]],'refund_method'=>'cash'],$h)->assertCreated();
            $this->assertEquals($expected,$customer->fresh()->loyalty_points);
        }
        $this->assertEquals(-2,LoyaltyLedger::where('invoice_id',$id)->where('type','reversal')->sum('points'));
    }
}
