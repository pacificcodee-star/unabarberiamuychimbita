<?php

namespace App\Console\Commands;

use App\Services\AttendanceAutomation;
use App\Services\ClientNotifier;
use App\Services\TurnQueueService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessQueueAndNotifications extends Command
{
    protected $signature = 'barberia:tick';

    protected $description = 'Avanza la fila automática de turnos y envía los avisos y recordatorios que ya deben salir';

    public function handle(TurnQueueService $queue, ClientNotifier $notifier, AttendanceAutomation $attendance): int
    {
        // Cada paso va aislado: si uno falla, los demás (sobre todo los avisos) siguen saliendo.
        $failed = 0;
        $step = function (string $name, callable $work, $fallback = null) use (&$failed) {
            $started = microtime(true);
            try {
                $result = $work();
            } catch (\Throwable $e) {
                $failed++;
                report($e);
                Log::critical('tarea.paso_fallido', ['paso' => $name, 'error' => class_basename($e)]);

                return $fallback;
            }
            $ms = (int) ((microtime(true) - $started) * 1000);
            if ($ms > 20000) {
                Log::warning('tarea.paso_lento', ['paso' => $name, 'ms' => $ms]);
            }

            return $result;
        };

        $step('fila', fn () => $queue->advanceAll());
        $step('recordatorios_turnos', fn () => $queue->remindUpcoming());
        // Sin confirmación 15 min después del recordatorio → cancelar; cita confirmada y terminada → atendida + gracias.
        $result = $step('asistencia', fn () => $attendance->run(), ['cancelled' => 0, 'attended' => 0]);
        $sent = $step('avisos', fn () => $notifier->processDue(), 0);

        // Latido: /salud avisa si la tarea dejó de correr (scheduler o worker caído).
        Cache::put('salud:tick', now()->toIso8601String(), now()->addDay());

        $this->info('Fila revisada. Canceladas sin confirmar: '.$result['cancelled'].'. Citas finalizadas: '.$result['attended'].'. Avisos enviados: '.$sent);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
