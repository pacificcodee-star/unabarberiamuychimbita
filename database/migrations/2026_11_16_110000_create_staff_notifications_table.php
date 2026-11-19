<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Avisos para el equipo (campana del panel): turnos nuevos, citas nuevas, cancelaciones de clientes, etc.
        Schema::create('staff_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barber_id')->nullable()->constrained('users')->nullOnDelete(); // a qué barbero le interesa
            $table->string('type', 40);
            $table->string('title', 150);
            $table->string('body', 500)->nullable();
            $table->string('url', 255)->nullable();
            $table->timestamps();

            $table->index('created_at');
        });

        // Hasta cuándo ha visto cada usuario sus avisos (para el contador de la campana).
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('notifications_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notifications_seen_at');
        });
        Schema::dropIfExists('staff_notifications');
    }
};
