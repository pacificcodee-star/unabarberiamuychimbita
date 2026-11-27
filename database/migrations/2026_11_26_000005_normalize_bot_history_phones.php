<?php

use App\Models\Appointment;
use App\Models\Turn;
use App\Services\WhatsAppService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $whatsApp = app(WhatsAppService::class);
        foreach ([new Appointment, new Turn] as $model) {
            $table = DB::table($model->getTable());
            (clone $table)->select('id', 'client_phone', 'history_whatsapp_phone')->whereIn('id', DB::table('consent_records')->select('subject_id')
                ->where('subject_type', $model->getMorphClass())->where('purpose', 'tratamiento_reserva')
                ->where('channel', 'whatsapp_bot'))
                ->chunkById(500, function ($rows) use ($table, $whatsApp) {
                    foreach ($rows as $row) {
                        $phone = $whatsApp->normalizePhone($row->history_whatsapp_phone ?? (string) $row->client_phone, international: $row->history_whatsapp_phone !== null);
                        if ($row->history_whatsapp_phone !== $phone && preg_match('/^[1-9][0-9]{6,14}$/D', $phone)) {
                            (clone $table)->where('id', $row->id)->where('client_phone', $row->client_phone)
                                ->where('history_whatsapp_phone', $row->history_whatsapp_phone)
                                ->update(['history_whatsapp_phone' => $phone]);
                        }
                    }
                }, 'id');
        }
    }

    public function down(): void
    {
        // El dato normalizado también puede haber sido verificado después de la migración.
    }
};
