<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['appointments', 'turns'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('attendance_status', 20)->nullable();
                $blueprint->timestamp('attendance_verified_at')->nullable();
                $blueprint->foreignId('attendance_verified_by')->nullable()->constrained('users')->nullOnDelete();
                $blueprint->timestamp('attendance_prompted_at')->nullable()->index();
            });
        }

        Schema::table('appointments', fn (Blueprint $blueprint) => $blueprint->timestamp('started_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $blueprint) {
            $blueprint->dropIndex(['attendance_prompted_at']);
            $blueprint->dropConstrainedForeignId('attendance_verified_by');
            $blueprint->dropColumn(['attendance_status', 'attendance_verified_at', 'attendance_prompted_at', 'started_at']);
        });

        Schema::table('turns', function (Blueprint $blueprint) {
            $blueprint->dropIndex(['attendance_prompted_at']);
            $blueprint->dropConstrainedForeignId('attendance_verified_by');
            $blueprint->dropColumn(['attendance_status', 'attendance_verified_at', 'attendance_prompted_at']);
        });
    }
};
