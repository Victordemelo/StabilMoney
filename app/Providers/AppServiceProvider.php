<?php

namespace App\Providers;

use App\Services\SidebarService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;
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

        // Card "Patrimônio total" da sidebar: dados agregados injetados em
        // toda renderização do partial (todas as páginas autenticadas).
        View::composer('partials.sidebar', function (\Illuminate\View\View $view) {
            $user = auth()->user();
            $view->with('patrimonio', $user ? app(SidebarService::class)->build($user->id) : null);
        });
    }
}
