<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cuando una cita o un turno pasa a "atendido" se registra el servicio realizado (ingreso); aquí se guarda cuál. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->after('status')->constrained('sales')->nullOnDelete();
        });
        Schema::table('turns', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->after('status')->constrained('sales')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', fn (Blueprint $table) => $table->dropConstrainedForeignId('sale_id'));
        Schema::table('turns', fn (Blueprint $table) => $table->dropConstrainedForeignId('sale_id'));
    }
};
