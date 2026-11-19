<?php

namespace App\Providers;

use App\Models\Appointment;
use App\Models\SiteSetting;
use App\Models\Turn;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
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
        ]);

        View::composer(['layouts.public', 'landing', 'public.*'], function ($view): void {
            $view->with('siteSettings', SiteSetting::currentData());
        });
    }
}
