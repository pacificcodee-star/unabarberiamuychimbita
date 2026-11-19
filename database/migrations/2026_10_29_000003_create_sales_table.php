<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barber_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained('cash_sessions')->nullOnDelete();
            $table->string('client_name')->nullable();
            $table->string('payment_method', 20);
            $table->decimal('total', 12, 2);
            $table->decimal('commission_percentage', 5, 2)->default(0);
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->dateTime('sold_at');
            $table->timestamps();

            $table->index('sold_at');
            $table->index('payment_method');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
