<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\TransactionController;

// Dashboard financeiro (tela inicial do app)
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

// Recursos principais (Fase 0)
Route::resource('transactions', TransactionController::class)->except('show');
Route::resource('accounts', AccountController::class)->except('show');
Route::resource('categories', CategoryController::class)->except('show');

// Telas antigas (placeholder)
Route::get('/painel', [UserController::class, 'index'])->name('user.dashboard');
Route::get('/admin', [AdminController::class, 'index'])->name('admin.dashboard');
