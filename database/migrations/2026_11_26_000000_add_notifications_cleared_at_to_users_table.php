<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Eliminar todas" en la campana del panel: cada usuario vacía su propia bandeja.
 * Los avisos se comparten (el administrador ve los de todos los barberos), así que no se borran
 * las filas: se guarda desde cuándo esa persona ya no quiere ver los anteriores.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'notifications_cleared_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('notifications_cleared_at')->nullable()->after('notifications_seen_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'notifications_cleared_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('notifications_cleared_at');
            });
        }
    }
};
