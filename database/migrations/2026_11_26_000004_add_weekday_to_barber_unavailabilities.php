<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('barber_unavailabilities', function (Blueprint $table) {
            $table->date('date')->nullable()->change();
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->index(['barber_id', 'weekday']);
        });
    }

    public function down(): void
    {
        DB::table('barber_unavailabilities')->whereNull('date')->delete();

        Schema::table('barber_unavailabilities', function (Blueprint $table) {
            $table->dropIndex(['barber_id', 'weekday']);
            $table->dropColumn('weekday');
            $table->date('date')->nullable(false)->change();
        });
    }
};
