<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('featured_cuts', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->string('subtitle', 160)->nullable();
            $table->text('description')->nullable();
            $table->string('image_path');
            $table->string('gallery_1_path')->nullable();
            $table->string('gallery_2_path')->nullable();
            $table->string('gallery_3_path')->nullable();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('featured_cuts');
    }
};
