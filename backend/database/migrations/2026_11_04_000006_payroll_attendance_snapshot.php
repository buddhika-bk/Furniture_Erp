<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('payslips', fn (Blueprint $t) => $t->json('attendance_snapshot')->nullable()); }
    public function down(): void { Schema::table('payslips', fn (Blueprint $t) => $t->dropColumn('attendance_snapshot')); }
};
