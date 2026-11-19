<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('reference', 50)->nullable();
            $table->decimal('sale_price', 12, 2);
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('min_stock')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('active');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_products');
    }
};
