<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Throwable;

class GenerateVapidKeys extends Command
{
    protected $signature = 'barberia:push-keys';

    protected $description = 'Genera las claves VAPID para las notificaciones push (se copian en el archivo .env)';

    public function handle(): int
    {
        if (! class_exists(\Minishlink\WebPush\VAPID::class)) {
            $this->error('Falta el paquete de Web Push. Ejecuta primero: composer require minishlink/web-push');

            return self::FAILURE;
        }

        try {
            $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
        } catch (Throwable $e) {
            $this->error('No se pudieron generar las claves con PHP: '.$e->getMessage());
            $this->line('Alternativa: ejecuta  npx web-push generate-vapid-keys  y copia las dos claves en el .env.');

            return self::FAILURE;
        }

        $this->info('Copia estas líneas en tu archivo .env (la clave privada es secreta: no la subas a GitHub):');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('VAPID_SUBJECT=mailto:tucorreo@ejemplo.com');
        $this->newLine();
        $this->warn('Genéralas UNA sola vez. Si las cambias, los clientes ya suscritos dejan de recibir avisos.');
        $this->line('Después ejecuta: php artisan config:clear');

        return self::SUCCESS;
    }
}
