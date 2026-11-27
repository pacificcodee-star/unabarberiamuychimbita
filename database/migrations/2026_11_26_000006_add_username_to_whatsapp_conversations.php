<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            // El username es metadata actualizable: WhatsApp puede reasignarlo cuando su dueño
            // lo cambia o lo elimina. El JID, no este valor, identifica cada conversación.
            $table->string('username', 35)->nullable()->index()->after('jid');
            $table->string('phone', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Una conversación iniciada con @usuario o @lid puede no tener teléfono. No se borra ni se
        // inventa uno para hacer el rollback: la migración se detiene antes de perder esa identidad.
        if (DB::table('whatsapp_conversations')->whereNull('phone')->exists()) {
            throw new RuntimeException('No se puede revertir: existen conversaciones de WhatsApp sin teléfono.');
        }

        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropIndex(['username']);
            $table->dropColumn('username');
            $table->string('phone', 20)->nullable(false)->change();
        });
    }
};
