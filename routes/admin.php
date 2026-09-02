<?php

use App\Http\Controllers\Admin\ModeracaoController;
use App\Http\Controllers\Admin\PainelController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Admin\TwoFactorController;
use App\Http\Middleware\AutenticaNoPainel;
use App\Http\Middleware\ExigeDoisFatoresDoAdmin;
use App\Http\Middleware\PainelAdminLigado;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Painel administrativo
|--------------------------------------------------------------------------
|
| Arquivo separado do `web.php` de propósito: o painel tem guard próprio
| (`admin`), middlewares próprios e um interruptor geral. Misturado com as rotas
| do app, uma linha fora do lugar herdaria o middleware errado sem ninguém notar.
|
| CAMADAS, na ordem em que uma requisição as atravessa:
|   1. `PainelAdminLigado`  — desligado no .env, tudo aqui é 404.
|   2. `throttle`           — limites por IP, mais apertados que os do app.
|   3. `auth:admin`         — sessão do guard próprio (a do app não vale).
|   4. `ExigeDoisFatoresDoAdmin` — TOTP obrigatório, sem exceção.
|
| O prefixo vem do config (`admin.path`), então mudar a URL do painel não exige
| mexer em código.
|
*/

Route::middleware([PainelAdminLigado::class])
    ->prefix(config('admin.path'))
    ->name('painel.')
    ->group(function () {

        // ── Sem sessão ──────────────────────────────────────────────────────
        // Sem `guest:admin`: aquele middleware redireciona para a home do APP quando
        // já há sessão, o que aqui seria jogar o admin para fora do painel. Quem já
        // está autenticado é desviado dentro do próprio controller.
        Route::get('/', [SessionController::class, 'create'])->name('login');
        Route::post('/', [SessionController::class, 'store'])
            ->middleware('throttle:painel-login')->name('autenticar');

        // ── Com sessão, ANTES do segundo fator ──────────────────────────────
        // Estas rotas não podem exigir 2FA: são elas que o configuram/verificam.
        Route::middleware(AutenticaNoPainel::class)->group(function () {
            Route::get('/dois-fatores/configurar', [TwoFactorController::class, 'setup'])->name('2fa.setup');
            Route::post('/dois-fatores/configurar', [TwoFactorController::class, 'confirmar'])
                ->middleware('throttle:painel-totp')->name('2fa.confirmar');
            Route::get('/dois-fatores/codigos', [TwoFactorController::class, 'recuperacao'])->name('2fa.recuperacao');

            Route::get('/dois-fatores', [TwoFactorController::class, 'desafio'])->name('2fa.desafio');
            Route::post('/dois-fatores', [TwoFactorController::class, 'verificar'])
                ->middleware('throttle:painel-totp')->name('2fa.verificar');

            Route::post('/sair', [SessionController::class, 'destroy'])->name('logout');
        });

        // ── Painel de verdade: exige sessão E segundo fator provado ──────────
        Route::middleware([AutenticaNoPainel::class, ExigeDoisFatoresDoAdmin::class])->group(function () {
            Route::get('/inicio', [PainelController::class, 'home'])->name('home');
            Route::get('/pessoas', [PainelController::class, 'pessoas'])->name('pessoas');
            Route::get('/pessoas/{id}', [PainelController::class, 'pessoa'])->whereNumber('id')->name('pessoa');
            Route::get('/historico', [PainelController::class, 'historico'])->name('historico');

            // Ações que mexem em conta alheia. Throttle próprio: são raras por
            // natureza, e um pico aqui é sinal de sessão sequestrada.
            Route::middleware('throttle:painel-acao')->group(function () {
                Route::post('/pessoas/{user}/banir', [ModeracaoController::class, 'banir'])->name('banir');
                Route::post('/pessoas/{user}/desbanir', [ModeracaoController::class, 'desbanir'])->name('desbanir');
                Route::delete('/pessoas/{user}', [ModeracaoController::class, 'excluir'])->name('excluir');
            });
        });
    });
