<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('customers',fn(Blueprint $t)=>$t->boolean('is_walk_in')->default(false));
  \Illuminate\Support\Facades\DB::table('customers')->whereRaw("LOWER(name) IN ('walk-in customer','walk in customer','walk-in','walk in','walkin')")->update(['is_walk_in'=>true]);
  Schema::create('warranties',function(Blueprint $t){$t->id();$t->string('name')->unique();$t->unsignedInteger('duration_months')->default(0);$t->text('terms')->nullable();$t->boolean('is_active')->default(true);$t->timestamps();});
  Schema::table('products',function(Blueprint $t){$t->unsignedInteger('loyalty_points')->default(0);$t->foreignId('warranty_id')->nullable()->constrained('warranties');$t->foreignId('warehouse_id')->nullable()->constrained('warehouses');});
  Schema::table('invoices',function(Blueprint $t){$t->decimal('delivery_charge',14,2)->default(0);$t->json('service_charges')->nullable();$t->unsignedTinyInteger('pricing_version')->default(1);});
  Schema::table('invoice_items',function(Blueprint $t){$t->text('customer_description')->nullable();$t->json('warranty_snapshot')->nullable();$t->string('discount_type',12)->default('fixed');$t->decimal('discount_value',14,2)->default(0);$t->decimal('discount_amount',14,2)->default(0);$t->boolean('special_bom')->default(false);$t->text('manufacturing_notes')->nullable();$t->unsignedInteger('loyalty_points')->default(0);});
 }
 public function down(): void {
  Schema::table('customers',fn(Blueprint $t)=>$t->dropColumn('is_walk_in'));
  Schema::table('invoice_items',fn(Blueprint $t)=>$t->dropColumn(['customer_description','warranty_snapshot','discount_type','discount_value','discount_amount','special_bom','manufacturing_notes','loyalty_points']));
  Schema::table('invoices',fn(Blueprint $t)=>$t->dropColumn(['delivery_charge','service_charges','pricing_version']));
  Schema::table('products',function(Blueprint $t){$t->dropConstrainedForeignId('warranty_id');$t->dropConstrainedForeignId('warehouse_id');$t->dropColumn('loyalty_points');});Schema::dropIfExists('warranties');
 }
};
