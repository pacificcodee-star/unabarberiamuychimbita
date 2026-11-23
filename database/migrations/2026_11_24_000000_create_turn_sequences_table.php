<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contador de la numeración diaria de turnos (una fila por fecha).
 * El número de la fila de turnos es consecutivo por día y se reinicia solo al cambiar la fecha;
 * el id del turno sigue siendo el identificador interno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('turn_sequences', function (Blueprint $table) {
            $table->date('turn_date')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('turn_sequences');
    }
};
