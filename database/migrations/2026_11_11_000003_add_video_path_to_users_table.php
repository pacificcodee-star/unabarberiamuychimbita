<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'video_path')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('video_path')->nullable()->after('photo_path');
            });
        }

        // Asigna los videos de avatar entregados (barbers/videos/barbero-1..4) a los barberos activos,
        // en orden de registro. El admin puede cambiarlos luego desde Barberos → Editar.
        $barbers = DB::table('users')->where('role', 'barbero')->where('active', true)->whereNull('video_path')->orderBy('id')->get(['id']);
        foreach ($barbers->values() as $index => $barber) {
            $n = $index + 1;
            $video = "barbers/videos/barbero-{$n}.webm";
            $poster = "barbers/videos/barbero-{$n}.png";
            if (! Storage::disk('public')->exists($video)) {
                break;
            }
            DB::table('users')->where('id', $barber->id)->update([
                'video_path' => $video,
                'photo_path' => Storage::disk('public')->exists($poster) ? $poster : DB::raw('photo_path'),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'video_path')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('video_path');
            });
        }
    }
};
