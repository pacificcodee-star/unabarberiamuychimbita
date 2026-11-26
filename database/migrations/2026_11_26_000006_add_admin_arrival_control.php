<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable();
            $table->timestamp('arrival_notified_at')->nullable();
        });
        Schema::table('turns', fn (Blueprint $table) => $table->timestamp('arrival_notified_at')->nullable());
        // Antes de este cambio el reloj podía iniciar el turno sin que nadie viera al cliente.
        DB::table('turns')->where('status', 'atendiendo')->update([
            'status' => 'llamado', 'called_at' => DB::raw('COALESCE(called_at, started_at, updated_at)'),
            'started_at' => null,
        ]);
    }

    public function down(): void
    {
        DB::table('appointments')->where('status', 'en_atencion')->update(['status' => 'confirmada']);
        DB::table('appointments')->where('status', 'no_asistio')->update(['status' => 'cancelada']);
        DB::table('turns')->where('status', 'no_asistio')->update(['status' => 'cancelado']);
        Schema::table('appointments', fn (Blueprint $table) => $table->dropColumn(['started_at', 'arrival_notified_at']));
        Schema::table('turns', fn (Blueprint $table) => $table->dropColumn('arrival_notified_at'));
    }
};
