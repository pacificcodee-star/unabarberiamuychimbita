<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para las consultas de lectura más repetidas:
 *  - horas libres de un barbero en un día (Reservar, Mis servicios y el bot: barbero + fecha),
 *  - barberos activos (inicio, barberos, reservas: rol + activo),
 *  - reseñas aprobadas más recientes (inicio y reseñas),
 *  - servicios activos ordenados por precio (inicio, servicios, reservas).
 * Solo agregan índices: no cambian datos ni columnas.
 */
return new class extends Migration
{
    private const INDEXES = [
        'appointments' => ['appointments_barber_date_index' => ['barber_id', 'appointment_date']],
        'users' => ['users_role_active_index' => ['role', 'active']],
        'reviews' => ['reviews_approved_created_index' => ['approved', 'created_at']],
        'services' => ['services_active_price_index' => ['active', 'price']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($indexes as $name => $columns) {
                if (array_diff($columns, Schema::getColumnListing($table)) !== [] || Schema::hasIndex($table, $name)) {
                    continue;
                }
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
                }
            }
        }
    }
};
