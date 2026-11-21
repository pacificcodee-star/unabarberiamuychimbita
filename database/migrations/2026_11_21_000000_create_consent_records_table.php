<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de autorizaciones (Ley 1581 de 2012, art. 9; Decreto 1074 de 2015, art. 2.2.2.25.2.5):
 * prueba de que el titular autorizó cada finalidad, con qué texto (versión), cuándo y por qué medio.
 * Solo agrega una tabla nueva; no toca las existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Finalidad: tratamiento_reserva, avisos_whatsapp, publicacion_resena…
            $table->string('purpose', 40);
            // Versión del texto aceptado (fecha de la política vigente).
            $table->string('text_version', 40);
            // Medio: formulario_web (conducta inequívoca al enviar), casilla_web, whatsapp_bot, panel.
            $table->string('channel', 30);
            // Titular: la reserva, turno u opinión que contiene sus datos (sin copiar nombre ni teléfono).
            $table->string('subject_type', 30);
            $table->unsignedBigInteger('subject_id');
            // Huella verificable: SHA-256 de finalidad + versión + texto + titular + fecha.
            $table->char('evidence_hash', 64);
            $table->timestamp('accepted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_via', 30)->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['purpose', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_records');
    }
};
