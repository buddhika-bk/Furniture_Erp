<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('financial_transactions', fn(Blueprint $t) => $t->foreignId('payment_id')->nullable()->unique()->constrained('payments')); }
    public function down(): void { Schema::table('financial_transactions', function(Blueprint $t) { $t->dropForeign(['payment_id']); $t->dropColumn('payment_id'); }); }
};
