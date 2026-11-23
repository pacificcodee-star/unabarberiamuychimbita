<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_status_events', function (Blueprint $table) {
            $table->id();
            $table->morphs('subject');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('actor_type', 20)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'created_at'], 'reservation_status_events_timeline');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_status_events');
    }
};
