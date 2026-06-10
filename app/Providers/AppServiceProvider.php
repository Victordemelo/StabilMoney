<?php

namespace App\Providers;

use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Datas traduzidas em todo o app (ex.: "terça-feira, 9 de junho"
        // via translatedFormat). O locale vem do .env (APP_LOCALE=pt_BR).
        Carbon::setLocale(config('app.locale'));
    }
}
