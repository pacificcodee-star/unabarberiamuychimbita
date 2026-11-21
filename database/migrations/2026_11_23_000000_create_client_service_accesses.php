<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_service_accesses', function (Blueprint $table) {
            $table->id();
            $table->char('device_hash', 64);
            $table->foreignId('appointment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('turn_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unique(['device_hash', 'appointment_id']);
            $table->unique(['device_hash', 'turn_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_service_accesses');
    }
};
