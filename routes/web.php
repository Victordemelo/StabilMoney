<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
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

    // Perfil / Configurações (Breeze)
    Route::get('/configuracoes', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/configuracoes', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/configuracoes', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Seções do design ainda não implementadas (placeholder "em breve")
    foreach ([
        'investimentos' => ['Investimentos', 'Acompanhe sua carteira, rentabilidade e novas oportunidades.'],
        'metas' => ['Metas', 'Crie objetivos, acompanhe o progresso e conquiste seus sonhos.'],
        'faturas' => ['Faturas', 'Todas as suas contas a pagar organizadas por vencimento.'],
        'relatorios' => ['Relatórios', 'Exporte e visualize relatórios detalhados das suas finanças.'],
        'ajuda' => ['Ajuda', 'Central de ajuda, tutoriais e suporte StabilMoney.'],
    ] as $slug => [$title, $description]) {
        Route::view('/' . $slug, 'coming-soon', [
            'title' => $title,
            'description' => $description,
            'page' => $slug,
        ])->name($slug);
    }
});

require __DIR__ . '/auth.php';
