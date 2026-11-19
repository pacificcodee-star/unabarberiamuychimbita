<?php

namespace App\Console\Commands;

use App\Services\ClientNotifier;
use App\Services\TurnQueueService;
use Illuminate\Console\Command;

class ProcessQueueAndNotifications extends Command
{
    protected $signature = 'barberia:tick';

    protected $description = 'Avanza la fila automática de turnos y envía los avisos y recordatorios que ya deben salir';

    public function handle(TurnQueueService $queue, ClientNotifier $notifier): int
    {
        $queue->advanceAll();
        $sent = $notifier->processDue();

        $this->info('Fila revisada. Avisos enviados: '.$sent);

        return self::SUCCESS;
    }
}
