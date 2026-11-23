<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['appointments', 'turns'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('guest_visitor_id')->nullable()->after('id')
                    ->constrained('guest_visitors')->nullOnDelete();
                $table->string('client_phone_normalized', 10)->nullable()->after('client_phone')->index();
                $table->decimal('service_price', 10, 2)->nullable()->after('service_id');
            });
        }

        $this->backfillPhones('appointments');
        $this->backfillPhones('turns');
        $this->backfillPrices('appointments');
        $this->backfillPrices('turns');
    }

    public function down(): void
    {
        foreach (['appointments', 'turns'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('guest_visitor_id');
                $table->dropColumn(['client_phone_normalized', 'service_price']);
            });
        }
    }

    private function backfillPhones(string $table): void
    {
        DB::table($table)->select('id', 'client_phone')->orderBy('id')->chunk(500, function ($rows) use ($table) {
            foreach ($rows as $row) {
                $digits = preg_replace('/\D+/', '', (string) $row->client_phone);
                DB::table($table)->where('id', $row->id)->update([
                    'client_phone_normalized' => $digits !== '' ? substr($digits, -10) : null,
                ]);
            }
        });
    }

    private function backfillPrices(string $table): void
    {
        $prices = DB::table('services')->pluck('price', 'id');
        DB::table($table)->select('id', 'service_id')->whereNotNull('service_id')->orderBy('id')->chunk(500, function ($rows) use ($table, $prices) {
            foreach ($rows as $row) {
                if (isset($prices[$row->service_id])) {
                    DB::table($table)->where('id', $row->id)->update(['service_price' => $prices[$row->service_id]]);
                }
            }
        });
    }
};
