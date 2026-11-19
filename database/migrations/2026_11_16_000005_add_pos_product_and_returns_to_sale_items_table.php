<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('pos_product_id')->nullable()->after('service_id')->constrained('pos_products')->nullOnDelete();
            $table->unsignedInteger('returned_quantity')->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pos_product_id');
            $table->dropColumn('returned_quantity');
        });
    }
};
