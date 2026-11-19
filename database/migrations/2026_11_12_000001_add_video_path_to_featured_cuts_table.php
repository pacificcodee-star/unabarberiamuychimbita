<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('featured_cuts', function (Blueprint $table) {
            if (! Schema::hasColumn('featured_cuts', 'video_path')) {
                $table->string('video_path')->nullable()->after('image_path');
            }
            // Un corte puede tener solo video (la imagen pasa a ser opcional).
            $table->string('image_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('featured_cuts', function (Blueprint $table) {
            if (Schema::hasColumn('featured_cuts', 'video_path')) {
                $table->dropColumn('video_path');
            }
        });
    }
};
