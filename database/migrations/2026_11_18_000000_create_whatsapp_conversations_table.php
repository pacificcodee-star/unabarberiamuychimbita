<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bandeja de WhatsApp del panel: conversaciones con los clientes y cada mensaje
 * entrante/saliente. Se llena desde el webhook de Evolution API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('jid', 80)->unique();               // 573001234567@s.whatsapp.net
            $table->string('phone', 20)->unique();             // 573001234567 (sin +)
            $table->string('client_name', 120)->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->string('last_message_preview', 255)->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->boolean('bot_active')->default(true);      // el admin puede pausar el bot por chat
            $table->timestamps();

            $table->index('last_message_at');
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')
                ->constrained('whatsapp_conversations')
                ->cascadeOnDelete();
            $table->string('direction', 3)->default('in');     // in = cliente, out = negocio
            $table->string('type', 20)->default('text');
            $table->mediumText('body')->nullable();
            $table->string('evolution_id', 100)->nullable()->unique(); // id del mensaje en WhatsApp
            $table->string('status', 20)->nullable();          // enviado | entregado | leido | fallido
            $table->string('sender_name', 120)->nullable();    // quién escribió (grupo o cliente)
            $table->json('raw')->nullable();                   // payload original del webhook
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_conversations');
    }
};
