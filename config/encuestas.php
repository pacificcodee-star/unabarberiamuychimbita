<?php

/*
| Encuestas de satisfacción después del servicio.
*/

return [
    'enabled' => (bool) env('SURVEYS_ENABLED', true),

    // Minutos después de marcar el servicio como atendido en que sale la invitación. Da margen al
    // barbero para corregir si el cliente no llegó (la invitación se cancela si deja de estar atendido).
    'send_delay_minutes' => (int) env('SURVEY_SEND_DELAY_MINUTES', 30),

    // Días que la invitación se puede responder.
    'expire_days' => (int) env('SURVEY_EXPIRE_DAYS', 14),

    // Responder por WhatsApp dentro del chat (pregunta por pregunta).
    // Ojo: con WHATSAPP_DRIVER=evolution el canal es WhatsApp Web (no oficial); ver docs/ENCUESTAS.md.
    'whatsapp' => (bool) env('SURVEY_WHATSAPP_ENABLED', false),

    // Si el cliente autoriza, el comentario se publica solo cuando pasa la moderación objetiva.
    'auto_publish' => (bool) env('SURVEY_AUTO_PUBLISH', true),

    // Mínimo de encuestas para mostrar el promedio de un barbero o servicio (evita rankings engañosos).
    'min_sample' => (int) env('SURVEY_MIN_SAMPLE', 5),

    // Días que se guardan las encuestas sin terminar antes de borrarlas.
    'retention_incomplete_days' => (int) env('SURVEY_RETENTION_INCOMPLETE_DAYS', 180),
];
