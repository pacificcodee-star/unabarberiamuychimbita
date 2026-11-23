<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_visitors', function (Blueprint $table) {
            $table->timestamp('recovery_consent_at')->nullable()->after('phone_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('guest_visitors', function (Blueprint $table) {
            $table->dropColumn('recovery_consent_at');
        });
    }
};
