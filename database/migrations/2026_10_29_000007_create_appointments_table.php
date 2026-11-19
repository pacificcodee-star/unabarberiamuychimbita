<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barber_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->string('client_name');
            $table->string('client_phone', 30)->nullable();
            $table->date('appointment_date');
            $table->time('appointment_time');
            $table->string('status', 20)->default('pendiente');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['appointment_date', 'appointment_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
