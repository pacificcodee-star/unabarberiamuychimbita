<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_visitors', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->nullable()->index();
            // Solo se llena cuando el cliente demuestra ser dueño del número; el índice único
            // evita que dos visitantes verificados compartan el mismo teléfono.
            $table->string('verified_phone', 20)->nullable()->unique();
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('guest_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_visitor_id')->constrained('guest_visitors')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('guest_verification_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->index();
            $table->char('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('request_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['phone', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_verification_codes');
        Schema::dropIfExists('guest_credentials');
        Schema::dropIfExists('guest_visitors');
    }
};
