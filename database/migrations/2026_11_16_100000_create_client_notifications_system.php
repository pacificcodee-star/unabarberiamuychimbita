<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Turnos: enlace secreto del cliente, contacto opcional, consentimiento y hora real de inicio del servicio.
        Schema::table('turns', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->unique()->after('id');
            $table->string('client_email', 150)->nullable()->after('client_phone');
            $table->boolean('whatsapp_consent')->default(false)->after('client_email');
            $table->timestamp('started_at')->nullable()->after('called_at');
        });

        // Citas: enlace secreto del cliente, contacto opcional y consentimiento.
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->unique()->after('id');
            $table->string('client_email', 150)->nullable()->after('client_phone');
            $table->boolean('whatsapp_consent')->default(false)->after('client_email');
        });

        // Los turnos y citas que ya existían también reciben su enlace secreto.
        foreach (['turns', 'appointments'] as $tableName) {
            DB::table($tableName)->whereNull('public_token')->orderBy('id')->select('id')->chunkById(200, function ($rows) use ($tableName) {
                foreach ($rows as $row) {
                    DB::table($tableName)->where('id', $row->id)->update(['public_token' => Str::random(40)]);
                }
            });
        }

        // Los turnos que ya estaban en atención conservan su hora de inicio.
        DB::table('turns')->where('status', 'atendiendo')->whereNull('started_at')->update(['started_at' => DB::raw('called_at')]);

        // Dispositivos suscritos a notificaciones push. Cada suscripción pertenece a UN turno o cita (sin cuenta de usuario).
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('notifiable_type', 30);
            $table->unsignedBigInteger('notifiable_id');
            $table->text('endpoint');
            $table->char('endpoint_hash', 64);
            $table->string('p256dh', 255);
            $table->string('auth', 255);
            $table->string('content_encoding', 20)->default('aes128gcm');
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();

            $table->unique(['notifiable_type', 'notifiable_id', 'endpoint_hash'], 'push_sub_unique');
            $table->index('endpoint_hash');
        });

        // Historial y cola de notificaciones: una fila por aviso y por canal (push / whatsapp).
        // Los recordatorios futuros se guardan aquí con su hora programada, así sobreviven a reinicios del servidor.
        Schema::create('client_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('notifiable_type', 30);
            $table->unsignedBigInteger('notifiable_id');
            $table->string('type', 40);
            $table->string('channel', 20);
            $table->string('title', 150);
            $table->text('body');
            $table->string('url', 255)->nullable();
            $table->string('dedupe_key', 190)->unique(); // impide enviar dos veces el mismo aviso
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 20)->default('pendiente'); // pendiente, enviando, enviada, fallida, cancelada, expirada
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_for']);
            $table->index(['notifiable_type', 'notifiable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_notifications');
        Schema::dropIfExists('push_subscriptions');

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn(['public_token', 'client_email', 'whatsapp_consent']);
        });

        Schema::table('turns', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn(['public_token', 'client_email', 'whatsapp_consent', 'started_at']);
        });
    }
};
