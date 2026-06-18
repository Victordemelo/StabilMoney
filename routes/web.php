<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DependentController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

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

    // Dependentes (conta-família) — só o titular gerencia
    Route::get('/dependentes', [DependentController::class, 'index'])->name('dependentes');
    Route::post('/dependentes', [DependentController::class, 'store'])->name('dependentes.store');
    Route::delete('/dependentes/{dependent}', [DependentController::class, 'destroy'])->name('dependentes.destroy');

    // Seções do design ainda não implementadas (placeholder "em breve")
    foreach ([
        'investimentos' => ['Investimentos', 'Acompanhe sua carteira, rentabilidade e novas oportunidades.'],
        'metas' => ['Metas', 'Crie objetivos, acompanhe o progresso e conquiste seus sonhos.'],
        'faturas' => ['Faturas / Despesas', 'Faturas por cartão, parcelas e despesas em conta — em breve.'],
    ] as $slug => [$title, $description]) {
        Route::view('/' . $slug, 'coming-soon', [
            'title' => $title,
            'description' => $description,
            'page' => $slug,
        ])->name($slug);
    }
});

require __DIR__ . '/auth.php';
