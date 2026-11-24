<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_credentials', function (Blueprint $table) {
            $table->string('whatsapp_phone', 20)->nullable();
        });

        foreach (['appointments', 'turns'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('history_whatsapp_phone', 20)->nullable()->index();
                $table->timestamp('attendance_confirmed_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('guest_credentials', fn (Blueprint $table) => $table->dropColumn('whatsapp_phone'));

        foreach (['appointments', 'turns'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['history_whatsapp_phone']);
                $table->dropColumn(['history_whatsapp_phone', 'attendance_confirmed_at']);
            });
        }
    }
};
