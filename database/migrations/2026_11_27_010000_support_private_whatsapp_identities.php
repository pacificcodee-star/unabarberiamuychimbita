<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->change();
            $table->string('lid', 80)->nullable()->unique();
            $table->string('username', 120)->nullable();
        });

        DB::table('whatsapp_conversations')->where('jid', 'like', '%@lid')
            ->update(['lid' => DB::raw('jid')]);

        foreach (['appointments', 'turns'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('whatsapp_lid', 80)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (DB::table('whatsapp_conversations')->whereNull('phone')->exists()
            || DB::table('appointments')->whereNotNull('whatsapp_lid')->exists()
            || DB::table('turns')->whereNotNull('whatsapp_lid')->exists()) {
            throw new RuntimeException('La reversión requiere conservar primero los chats y reservas sin teléfono.');
        }

        foreach (['appointments', 'turns'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['whatsapp_lid']);
                $table->dropColumn('whatsapp_lid');
            });
        }

        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropUnique(['lid']);
            $table->dropColumn(['lid', 'username']);
            $table->string('phone', 20)->nullable(false)->change();
        });
    }
};
