<?php

/*
 * Prueba de concurrencia REAL contra MySQL (no corre con PHPUnit: SQLite no tiene concurrencia).
 * Varios procesos arrancan a la vez e intentan tomar el mismo horario o pedir turno al mismo barbero.
 *
 * Uso (siempre con una base aparte, nunca la real):
 *   DB_DATABASE=barberia_concurrencia php artisan migrate:fresh --force
 *   DB_DATABASE=barberia_concurrencia php tests/concurrency/race.php setup
 *   for i in 1 2 3 4 5 6 7 8; do DB_DATABASE=barberia_concurrencia php tests/concurrency/race.php cita <inicio> & done; wait
 *   DB_DATABASE=barberia_concurrencia php tests/concurrency/race.php report
 */

use App\Models\Appointment;
use App\Models\Turn;
use App\Models\User;
use App\Services\TurnService;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$database = config('database.connections.'.config('database.default').'.database');
if ($database === 'barberia' || ! str_contains((string) $database, 'concurrencia')) {
    fwrite(STDERR, "Negado: esta prueba solo corre sobre una base de concurrencia (actual: {$database}).\n");
    exit(2);
}

$mode = $argv[1] ?? 'report';

if ($mode === 'setup') {
    Appointment::query()->delete();
    Turn::query()->delete();
    User::updateOrCreate(['email' => 'citas@prueba.local'], ['name' => 'Barbero Citas', 'password' => 'x', 'role' => 'barbero', 'attention_mode' => 'citas', 'active' => true]);
    User::updateOrCreate(['email' => 'llegada@prueba.local'], ['name' => 'Barbero Llegada', 'password' => 'x', 'role' => 'barbero', 'attention_mode' => 'llegada', 'active' => true]);
    echo "listo\n";
    exit(0);
}

if ($mode === 'report') {
    $citas = Appointment::where('appointment_date', '2030-01-15')->whereTime('appointment_time', '10:00:00')->where('status', '!=', 'cancelada')->count();
    $numeros = Turn::orderBy('turn_number')->pluck('turn_number')->all();
    echo json_encode([
        'citas_en_el_mismo_horario' => $citas,
        'turnos' => count($numeros),
        'numeros_unicos' => count(array_unique($numeros)) === count($numeros),
        'numeros' => $numeros,
    ]).PHP_EOL;
    exit(0);
}

// Todos esperan al mismo instante para chocar de verdad.
$start = (float) ($argv[2] ?? microtime(true));
while (microtime(true) < $start) {
    usleep(500);
}

try {
    if ($mode === 'cita') {
        $barber = User::where('email', 'citas@prueba.local')->firstOrFail();
        Appointment::reserveSlot([
            'barber_id' => $barber->id, 'client_name' => 'Proceso '.getmypid(), 'client_phone' => '3000000000',
            'appointment_date' => '2030-01-15', 'appointment_time' => '10:00', 'status' => 'pendiente',
        ]);
        echo "creada\n";
    } elseif ($mode === 'cita-antigua') {
        // Lógica anterior (comprobar y luego insertar, sin transacción): solo para demostrar el riesgo.
        $barber = User::where('email', 'citas@prueba.local')->firstOrFail();
        $taken = Appointment::where('barber_id', $barber->id)->where('appointment_date', '2030-01-15')->whereTime('appointment_time', '10:00:00')->where('status', '!=', 'cancelada')->exists();
        if ($taken) {
            throw Illuminate\Validation\ValidationException::withMessages(['appointment_time' => 'ocupado']);
        }
        usleep(20000); // el tiempo normal que tarda la validación antes de guardar
        Appointment::create(['barber_id' => $barber->id, 'client_name' => 'Proceso '.getmypid(), 'appointment_date' => '2030-01-15', 'appointment_time' => '10:00', 'status' => 'pendiente']);
        echo "creada\n";
    } else {
        $barber = User::where('email', 'llegada@prueba.local')->firstOrFail();
        $turn = app(TurnService::class)->createFor($barber, ['client_name' => 'Proceso '.getmypid(), 'client_phone' => '3000000000', 'turn_date' => '2030-01-15'], notify: false);
        echo 'turno '.$turn->turn_number."\n";
    }
} catch (Illuminate\Validation\ValidationException) {
    echo "rechazada: horario ocupado\n";
} catch (Throwable $e) {
    echo 'error: '.class_basename($e)."\n";
}
