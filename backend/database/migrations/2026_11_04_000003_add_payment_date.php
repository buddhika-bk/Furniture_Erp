<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('payments', fn(Blueprint $t) => $t->date('payment_date')->nullable()); }
    public function down(): void { Schema::table('payments', fn(Blueprint $t) => $t->dropColumn('payment_date')); }
};
