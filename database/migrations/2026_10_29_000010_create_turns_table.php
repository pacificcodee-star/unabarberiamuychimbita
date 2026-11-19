<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('turns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barber_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->string('client_name');
            $table->string('client_phone', 30)->nullable();
            $table->date('turn_date');
            $table->unsignedInteger('turn_number');
            $table->string('status', 20)->default('esperando');
            $table->text('notes')->nullable();
            $table->timestamp('called_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['barber_id', 'turn_date', 'turn_number']);
            $table->index(['turn_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('turns');
    }
};
