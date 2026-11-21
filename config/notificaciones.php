<?php

// Ajustes del sistema automático de turnos y de las notificaciones a clientes.
return [

    // Minutos que tiene el cliente para llegar después del aviso "puedes pasar".
    // Al cumplirse, empieza a correr el tiempo del servicio aunque el barbero no toque nada.
    'turn_grace_minutes' => (int) env('TURN_GRACE_MINUTES', 10),

    // Duración que se asume cuando el turno no tiene servicio o el servicio no tiene duración.
    'default_service_minutes' => (int) env('TURN_DEFAULT_SERVICE_MINUTES', 30),

    // Activa o desactiva el avance automático de la fila (finalizar y llamar al siguiente sin botones).
    'auto_queue' => (bool) env('TURN_AUTO_QUEUE', true),

    // Recordatorios de citas: minutos antes de la hora => minutos de vigencia del aviso.
    'appointment_reminders' => [
        30 => 20,
    ],

    // Minutos antes del inicio estimado del turno en que se avisa al cliente.
    'turn_reminder_minutes' => (int) env('TURN_REMINDER_MINUTES', 15),

    // Minutos que tiene el cliente para confirmar después del recordatorio por WhatsApp.
    // Si no confirma, la cita o el turno se cancela y se le avisa. Además, una cita agendada
    // faltando menos de este tiempo queda confirmada sola.
    'confirm_window_minutes' => (int) env('CONFIRM_WINDOW_MINUTES', 15),

    // Vigencia (minutos) de los avisos inmediatos. Pasado ese tiempo ya no se entregan.
    'ttl_minutes' => [
        'confirmation' => 120,
        'turn_next' => 30,
        'turn_called' => 15,
        'change' => 120,
    ],

    // Reintentos máximos cuando el envío falla por un error temporal.
    'max_attempts' => 3,
];
