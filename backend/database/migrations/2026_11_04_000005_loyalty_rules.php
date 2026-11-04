<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('loyalty_rules',function(Blueprint $t){
            $t->id();$t->string('name');$t->decimal('min_purchase',14,2)->default(0);$t->decimal('max_purchase',14,2)->nullable();
            $t->decimal('spend_amount',14,2);$t->unsignedInteger('points');$t->unsignedInteger('priority')->default(0);$t->boolean('is_active')->default(true);$t->timestamps();
        });
        Schema::table('invoices',fn(Blueprint $t)=>$t->json('loyalty_rule_snapshot')->nullable());
    }
    public function down(): void {Schema::table('invoices',fn(Blueprint $t)=>$t->dropColumn('loyalty_rule_snapshot'));Schema::dropIfExists('loyalty_rules');}
};
