<?php

use App\Models\Service;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('services', 'category')) {
            Schema::table('services', function (Blueprint $table) {
                $table->string('category', 30)->nullable()->after('name')->index();
            });
        }

        // Clasifica los servicios que ya existen según su nombre (el admin puede cambiarlo después).
        DB::table('services')->whereNull('category')->orderBy('id')->get(['id', 'name'])
            ->each(fn ($service) => DB::table('services')
                ->where('id', $service->id)
                ->update(['category' => Service::guessCategory($service->name)]));
    }

    public function down(): void
    {
        if (Schema::hasColumn('services', 'category')) {
            Schema::table('services', function (Blueprint $table) {
                $table->dropIndex(['category']);
                $table->dropColumn('category');
            });
        }
    }
};
