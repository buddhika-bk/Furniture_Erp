<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('boms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained('products');       // finished good
            $t->unsignedInteger('version')->default(1);
            $t->decimal('output_qty', 14, 3)->default(1);                // quantity produced by one batch of this recipe
            $t->decimal('labour_cost', 14, 4)->default(0);               // per recipe batch
            $t->decimal('packaging_cost', 14, 4)->default(0);
            $t->decimal('overhead_cost', 14, 4)->default(0);
            $t->decimal('extra_cost', 14, 4)->default(0);
            $t->boolean('is_active')->default(true);
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['product_id', 'version']);
        });
        Schema::create('bom_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bom_id')->constrained('boms')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('products');       // raw material / packaging
            $t->decimal('qty', 16, 4);                                    // per recipe batch
            $t->decimal('wastage_percent', 6, 2)->default(0);
        });
        Schema::create('production_orders', function (Blueprint $t) {
            $t->id();
            $t->string('number', 30)->unique();
            $t->foreignId('bom_id')->constrained('boms');
            $t->foreignId('product_id')->constrained('products');
            $t->foreignId('source_warehouse_id')->constrained('warehouses');   // raw materials
            $t->foreignId('target_warehouse_id')->constrained('warehouses');   // finished goods
            $t->decimal('batches', 12, 3);                                      // number of recipe batches planned
            $t->decimal('qty_planned', 16, 3);
            $t->decimal('qty_produced', 16, 3)->default(0);
            $t->decimal('wastage_qty', 16, 3)->default(0);
            $t->decimal('material_cost', 16, 4)->default(0);
            $t->decimal('conversion_cost', 16, 4)->default(0);                   // labour + packaging + overhead + extra
            $t->decimal('unit_cost', 14, 4)->default(0);
            $t->string('batch_no', 40)->nullable()->index();
            $t->enum('status', ['draft', 'released', 'in_progress', 'completed', 'cancelled'])->default('draft');
            $t->date('planned_date')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->foreignId('created_by')->nullable();
            $t->foreignId('approved_by')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('production_materials', function (Blueprint $t) {
            $t->id();
            $t->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('products');
            $t->decimal('required_qty', 16, 4);
            $t->decimal('issued_qty', 16, 4)->default(0);
            $t->decimal('unit_cost', 14, 4)->default(0);
        });
        // Cost version history for finished goods
        Schema::create('product_costs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained('products');
            $t->foreignId('bom_id')->nullable()->constrained('boms');
            $t->foreignId('production_order_id')->nullable()->constrained('production_orders');
            $t->decimal('material_cost', 14, 4)->default(0);
            $t->decimal('labour_cost', 14, 4)->default(0);
            $t->decimal('packaging_cost', 14, 4)->default(0);
            $t->decimal('overhead_cost', 14, 4)->default(0);
            $t->decimal('extra_cost', 14, 4)->default(0);
            $t->decimal('total_cost', 14, 4)->default(0);                // per unit
            $t->string('source', 20)->default('bom');                    // bom | production
            $t->timestamp('effective_from')->useCurrent();
            $t->timestamps();
        });
    }
    public function down(): void
    {
        foreach (['product_costs','production_materials','production_orders','bom_items','boms'] as $x) Schema::dropIfExists($x);
    }
};
