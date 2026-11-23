<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Vistas" y "Eliminar todas" se marcan por número de aviso, no por hora.
 *
 * Con la hora fallaba: parte de los avisos importados quedó guardada en UTC (5 horas adelante de
 * Colombia), así que parecían "del futuro" y seguían apareciendo después de eliminar todas o de
 * abrir la campana. El número de aviso siempre crece, sin importar la zona horaria.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'notifications_seen_id')) {
                $table->unsignedBigInteger('notifications_seen_id')->nullable()->after('notifications_cleared_at');
            }
            if (! Schema::hasColumn('users', 'notifications_cleared_id')) {
                $table->unsignedBigInteger('notifications_cleared_id')->nullable()->after('notifications_seen_id');
            }
        });

        // Quien ya había abierto la campana o eliminado todo: se respeta con el último aviso que existía
        // en ese momento según el orden de llegada (el número), no según la hora guardada.
        foreach (DB::table('users')->whereNotNull('notifications_seen_at')->orWhereNotNull('notifications_cleared_at')->get() as $user) {
            $markerFor = function ($moment) {
                if (! $moment) {
                    return null;
                }
                // Último aviso creado (por hora de Colombia) hasta ese momento; los de hora adelantada
                // tienen número menor que ese, así que también quedan cubiertos.
                return DB::table('staff_notifications')->where('created_at', '<=', $moment)->max('id');
            };

            DB::table('users')->where('id', $user->id)->update([
                'notifications_seen_id' => $markerFor($user->notifications_seen_at),
                'notifications_cleared_id' => $markerFor($user->notifications_cleared_at),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['notifications_cleared_id', 'notifications_seen_id'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
