<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Session;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Comandos do app
|--------------------------------------------------------------------------
*/

/**
 * Apaga as sessões expiradas da tabela `sessions`.
 *
 * Por que NÃO é `php artisan session:prune`: esse comando não existe no Laravel 12
 * (conferido em 02/08/2026 — o framework traz só `make:session-table`). O que existe
 * é o `gc()` do handler de sessão, que o middleware `StartSession` chama por
 * LOTERIA — `config('session.lottery')` = [2, 100], ou seja, em 2% das requisições.
 *
 * Loteria depende de tráfego. Num app novo, com poucas visitas por dia, uma linha
 * expirada pode ficar meses na tabela. E `sessions` guarda **IP e user-agent** —
 * dado pessoal, com retenção prometida na Política de Privacidade (LGPD). Este
 * comando torna a limpeza determinística, sem depender de sorte.
 *
 * Só apaga o que já passou de `session.lifetime` (120 min): ninguém é desconectado.
 */
Artisan::command('sessoes:limpar', function () {
    $segundos = (int) config('session.lifetime') * 60;
    $driver = config('session.driver');
    $tabela = config('session.table', 'sessions');

    $antes = $driver === 'database' ? DB::table($tabela)->count() : null;

    Session::getHandler()->gc($segundos);

    if ($antes === null) {
        $this->info("Sessões expiradas limpas (driver: {$driver}).");

        return;
    }

    $depois = DB::table($tabela)->count();
    $this->info(sprintf(
        'Sessões: %d → %d (%d expiradas removidas).',
        $antes, $depois, $antes - $depois
    ));
})->purpose('Apaga as sessões expiradas — a tabela guarda IP e user-agent (LGPD)');

/*
|--------------------------------------------------------------------------
| Agendamentos
|--------------------------------------------------------------------------
| ⚠️ Nada aqui roda sozinho. O scheduler do Laravel precisa de UMA entrada de cron
| no servidor, que acorda o artisan a cada minuto:
|
|   * * * * * cd /caminho/do/projeto && php artisan schedule:run >> /dev/null 2>&1
|
| Em dev (Docker), dá para rodar em primeiro plano: `php artisan schedule:work`.
| Conferir o que está agendado: `php artisan schedule:list`.
| Passo a passo do deploy em docs/checklist-de-publicacao.md.
*/

Schedule::command('sessoes:limpar')
    ->dailyAt('03:10')
    ->withoutOverlapping();
