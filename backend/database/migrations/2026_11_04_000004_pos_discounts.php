<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('invoices',function(Blueprint $t){$t->string('bill_discount_type',12)->default('fixed');$t->decimal('bill_discount_value',14,2)->default(0);$t->decimal('bill_discount_amount',14,2)->default(0);});
        Schema::table('invoice_items',fn(Blueprint $t)=>$t->decimal('allocated_discount_amount',14,2)->default(0));
    }
    public function down(): void {
        Schema::table('invoice_items',fn(Blueprint $t)=>$t->dropColumn('allocated_discount_amount'));
        Schema::table('invoices',fn(Blueprint $t)=>$t->dropColumn(['bill_discount_type','bill_discount_value','bill_discount_amount']));
    }
};
