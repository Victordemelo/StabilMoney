<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DependentController;
use App\Http\Controllers\FaturaController;
use App\Http\Controllers\GoalContributionController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\InvestmentContributionController;
use App\Http\Controllers\InvestmentController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

// PWA — PÚBLICO de propósito (fora do 'auth'): o navegador lê o manifest
// para oferecer "Instalar" (inclusive na tela de login) e registra o
// service worker sem sessão. Nada aqui expõe dado do usuário.
Route::get('/site.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
Route::get('/sw.js', [PwaController::class, 'serviceWorker'])->name('pwa.sw');
Route::view('/offline', 'pwa.offline')->name('pwa.offline');

// Todas as telas do app exigem login (multiusuário desde a Fase 1).
Route::middleware('auth')->group(function () {
    // Dashboard (tela inicial)
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Recursos principais
    Route::resource('transactions', TransactionController::class)->except('show');
    Route::resource('accounts', AccountController::class)->except('show');
    Route::resource('categories', CategoryController::class)->except('show');

    // Meu perfil (dados pessoais: nome, e-mail, telefone, foto)
    Route::get('/meu-perfil', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/meu-perfil', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/meu-perfil', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Configurações (subabas: Segurança / Conta)
    Route::get('/configuracoes/{tab?}', [SettingsController::class, 'index'])->name('settings');

    // Segurança: encerrar as demais sessões/dispositivos conectados
    Route::delete('/configuracoes/sessoes', [SecurityController::class, 'destroyOtherSessions'])
        ->name('settings.sessions.destroy');

    // Dependentes (conta-família) — só o titular gerencia
    Route::get('/dependentes', [DependentController::class, 'index'])->name('dependentes');
    Route::post('/dependentes', [DependentController::class, 'store'])->name('dependentes.store');
    Route::delete('/dependentes/{dependent}', [DependentController::class, 'destroy'])->name('dependentes.destroy');

    // Metas (objetivos de poupança — modelo "cofrinho"). Compartilhadas na família.
    Route::get('/metas', [GoalController::class, 'index'])->name('metas.index');
    Route::post('/metas', [GoalController::class, 'store'])->name('metas.store');
    Route::patch('/metas/{meta}', [GoalController::class, 'update'])->name('metas.update');
    Route::delete('/metas/{meta}', [GoalController::class, 'destroy'])->name('metas.destroy');
    // Movimentações da meta: aporte (reserva) e resgate (devolve à conta).
    Route::post('/metas/{meta}/aportes', [GoalContributionController::class, 'store'])->name('metas.aportes.store');
    Route::post('/metas/{meta}/resgates', [GoalContributionController::class, 'withdraw'])->name('metas.resgates.store');

    // Investimentos (modelo "cofrinho" + metadados/projeções). Compartilhados na família.
    Route::get('/investimentos', [InvestmentController::class, 'index'])->name('investimentos.index');
    Route::post('/investimentos', [InvestmentController::class, 'store'])->name('investimentos.store');
    Route::patch('/investimentos/{investimento}', [InvestmentController::class, 'update'])->name('investimentos.update');
    Route::delete('/investimentos/{investimento}', [InvestmentController::class, 'destroy'])->name('investimentos.destroy');
    // Movimentações do investimento: aporte (reserva) e resgate (devolve à conta).
    Route::post('/investimentos/{investimento}/aportes', [InvestmentContributionController::class, 'store'])->name('investimentos.aportes.store');
    Route::post('/investimentos/{investimento}/resgates', [InvestmentContributionController::class, 'withdraw'])->name('investimentos.resgates.store');

    // Faturas / Despesas — faturas por cartão (parcelas/recorrência) + despesas
    // avulsas em conta. Compartilhado na família.
    Route::get('/faturas', [FaturaController::class, 'index'])->name('faturas.index');
    Route::post('/faturas/lancar', [FaturaController::class, 'store'])->name('faturas.lancar');
    Route::delete('/faturas/compra/{transaction}', [FaturaController::class, 'destroy'])
        ->name('faturas.compra.destroy');
});

require __DIR__ . '/auth.php';
