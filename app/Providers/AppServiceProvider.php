<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use App\Services\FaturaService;
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
            // Escopo por família: o patrimônio é o do titular (ownerId), visível também aos dependentes.
            $view->with('patrimonio', $user ? app(SidebarService::class)->build($user->ownerId()) : null);
        });

        // Notificações da topbar: contas a vencer nos próximos 7 dias (faturas de
        // cartão em aberto + recorrências não pagas). Escopo por família.
        View::composer('partials.topbar', function (\Illuminate\View\View $view) {
            $user = auth()->user();
            $view->with('vencimentos', $user
                ? app(FaturaService::class)->upcomingDue($user->ownerId(), 7)
                : collect());
        });

        // Modal global de "Lançar" (nova transação), presente no shell de todas as
        // páginas autenticadas. Escopo por família (ownerId).
        View::composer('partials.launch-modal', function (\Illuminate\View\View $view) {
            $user = auth()->user();
            $ownerId = $user?->ownerId();

            $view->with([
                'lmAccounts' => $ownerId
                    ? Account::where('user_id', $ownerId)->orderBy('name')->get()
                    : collect(),
                'lmCategories' => $ownerId
                    ? Category::where('user_id', $ownerId)->orderBy('type')->orderBy('name')->get()
                    : collect(),
                'lmFamily' => $ownerId ? User::familyOf($ownerId)->get() : collect(),
            ]);
        });
    }
}
