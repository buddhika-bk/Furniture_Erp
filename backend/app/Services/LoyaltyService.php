<?php
namespace App\Services;
use App\Models\{Customer, Invoice, LoyaltyLedger, LoyaltyRule, LoyaltyTier};
use App\Support\Settings;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class LoyaltyService {
    // One matching rule per purchase. Credit is part of the purchase amount;
    // collecting the balance later must never award points again.
    public function award(Invoice $invoice): void {
        DB::transaction(function() use($invoice) {
            $invoice=Invoice::lockForUpdate()->findOrFail($invoice->id);
            if(!$invoice->customer_id || $invoice->loyalty_rule_snapshot !== null) return;
            $customer=Customer::lockForUpdate()->findOrFail($invoice->customer_id);
            if($customer->is_walk_in) return;
            $total=(float)$invoice->total;
            $rule=LoyaltyRule::where('is_active',true)->where('min_purchase','<=',$total)
                ->where(fn($q)=>$q->whereNull('max_purchase')->orWhere('max_purchase','>=',$total))
                ->orderByDesc('priority')->orderByDesc('min_purchase')->orderBy('id')->first();
            $points=0;$snapshot=['source'=>'no_matching_rule','purchase_amount'=>$total];
            if($rule) {
                $blocks=intdiv((int)round($total*100),(int)round($rule->spend_amount*100));
                $points=$blocks*(int)$rule->points;
                $snapshot=$rule->only(['id','name','min_purchase','max_purchase','spend_amount','points','priority'])+['source'=>'rule','purchase_amount'=>$total];
            } elseif(!LoyaltyRule::exists()) {
                // Preserve existing installations until the owner defines their first rule.
                $spend=max((float)Settings::get('loyalty_currency_per_point',100),0.01);
                $mult=(float)(LoyaltyTier::where('name',$customer->tier)->value('earn_multiplier')??1);
                $points=(int)floor($total/$spend*$mult);
                $snapshot=['source'=>'legacy_setting','spend_amount'=>$spend,'multiplier'=>$mult,'purchase_amount'=>$total];
            }
            if($points>2000000000 || $customer->loyalty_points+$points>2000000000) throw new BusinessRuleException('Loyalty points exceed the supported balance. Reduce the earning rule.');
            if($points>0){
                $customer->loyalty_points+=$points;
                LoyaltyLedger::create(['customer_id'=>$customer->id,'invoice_id'=>$invoice->id,'points'=>$points,'type'=>'earn','note'=>$rule?->name??'Purchase points']);
                $customer->tier=LoyaltyTier::where('min_points','<=',$customer->loyalty_points)->orderByDesc('min_points')->value('name')??$customer->tier;
                $customer->save();
            }
            $invoice->update(['points_earned'=>$points,'loyalty_rule_snapshot'=>$snapshot]);
        });
    }
}
