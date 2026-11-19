<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opened_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('initial_amount', 12, 2)->default(0);
            $table->decimal('expected_amount', 12, 2)->nullable();
            $table->decimal('counted_amount', 12, 2)->nullable();
            $table->decimal('difference', 12, 2)->nullable();
            $table->string('status', 20)->default('abierta');
            $table->text('notes')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_sessions');
    }
};
