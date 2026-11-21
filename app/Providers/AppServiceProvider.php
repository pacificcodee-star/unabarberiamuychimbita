<?php

namespace App\Providers;

use App\Models\Appointment;
use App\Models\SiteSetting;
use App\Models\Turn;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Una sola instancia por petición: así los avisos guardados se envían juntos al final (flush).
        $this->app->singleton(\App\Services\PushSender::class);
        $this->app->singleton(\App\Services\ClientNotifier::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Nombres cortos y estables para asociar suscripciones y avisos con turnos y citas.
        Relation::morphMap([
            'turn' => Turn::class,
            'appointment' => Appointment::class,
            'review' => \App\Models\Review::class,
        ]);

        // Encuesta de satisfacción: se programa cuando un servicio queda atendido y se cancela si se revierte.
        foreach ([Appointment::class, Turn::class] as $model) {
            $model::created(function ($subject): void {
                app(\App\Services\Surveys\SurveyService::class)->statusChanged($subject, null);
            });
            $model::updated(function ($subject): void {
                if ($subject->wasChanged('status')) {
                    app(\App\Services\Surveys\SurveyService::class)->statusChanged($subject, $subject->getOriginal('status'));
                }
            });
        }

        View::composer(['layouts.public', 'landing', 'public.*'], function ($view): void {
            $view->with('siteSettings', SiteSetting::currentData());
        });

        // Datos del titular para las páginas legales (config/web.php + configuración del negocio).
        View::composer('public.legal.*', function ($view): void {
            $settings = SiteSetting::currentData();
            $view->with([
                'legalOwner' => config('web.legal.owner') ?: ($settings['brand_name'] ?? 'Una Barbería Muy Chimbita'),
                'legalNit' => config('web.legal.nit'),
                'legalEmail' => config('web.legal.email') ?: ($settings['email'] ?? ''),
                'legalAddress' => trim(($settings['address'] ?? '').', '.($settings['city'] ?? ''), ', '),
                'legalPhone' => $settings['phone'] ?? '',
                'legalUpdated' => Carbon::parse(config('web.legal.updated_at'))->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
            ]);
        });

        // Correo de recuperación de contraseña en español.
        \Illuminate\Auth\Notifications\ResetPassword::toMailUsing(function ($user, string $token) {
            $url = route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()]);

            return (new \Illuminate\Notifications\Messages\MailMessage)
                ->subject('Crea tu contraseña nueva · '.config('sitio.brand_name', config('app.name')))
                ->greeting('Hola, '.$user->name)
                ->line('Recibimos una solicitud para cambiar la contraseña del panel de la barbería.')
                ->action('Crear contraseña nueva', $url)
                ->line('El enlace sirve una sola vez y vence en '.config('auth.passwords.users.expire', 60).' minutos.')
                ->line('Si no lo pediste, ignora este correo: tu contraseña sigue igual.')
                ->salutation('El equipo de '.config('sitio.brand_name', config('app.name')));
        });

        // HTTPS obligatorio en producción: todas las URLs generadas salen con https://.
        if (config('web.force_https')) {
            URL::forceScheme('https');
        }
    }
}
