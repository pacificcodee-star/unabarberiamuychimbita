<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comisiones por servicio para cada barbero e insumos del negocio.
 *  - barber_service_commissions: % que gana cada barbero en cada servicio (si no hay, se usa su % general).
 *  - supplies: insumos que el negocio le entrega al barbero (cuchillas, talco, toallas…).
 *  - barber_supply_percentages: % del costo de cada insumo que se le descuenta a cada barbero.
 *  - supply_deliveries: entregas de insumos a un barbero (lo descontado sale de sus comisiones).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('barber_service_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barber_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->decimal('percentage', 5, 2);
            $table->timestamps();
            $table->unique(['barber_id', 'service_id']);
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('commission_percentage', 5, 2)->default(0)->after('subtotal');
            $table->decimal('commission_amount', 12, 2)->default(0)->after('commission_percentage');
        });

        Schema::create('supplies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('unit', 40)->nullable();
            $table->decimal('cost', 12, 2)->default(0);
            $table->decimal('default_percentage', 5, 2)->default(100);
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('barber_supply_percentages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barber_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('supply_id')->constrained('supplies')->cascadeOnDelete();
            $table->decimal('percentage', 5, 2);
            $table->timestamps();
            $table->unique(['barber_id', 'supply_id']);
        });

        Schema::create('supply_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barber_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('supply_id')->nullable()->constrained('supplies')->nullOnDelete();
            $table->string('supply_name', 120);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->decimal('percentage', 5, 2)->default(100);
            $table->decimal('amount', 12, 2)->default(0);
            $table->date('delivered_at');
            $table->string('notes', 300)->nullable();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_deliveries');
        Schema::dropIfExists('barber_supply_percentages');
        Schema::dropIfExists('supplies');
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['commission_percentage', 'commission_amount']);
        });
        Schema::dropIfExists('barber_service_commissions');
    }
};
