<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:credencial');

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    // O LoginRequest já limita por e-mail+IP (protege uma conta); 'login-ip' soma o
    // limite por IP, que é o que barra password spraying em muitas contas.
    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login-ip');

    // Segunda etapa do login (2FA por app autenticador, OPCIONAL). Fica no grupo `guest`
    // porque quem está aqui ainda não tem sessão autenticada: passou pela senha e espera
    // o código. Sem um login pendente na sessão, as três rotas devolvem para /login.
    Route::get('verificacao-em-duas-etapas', [TwoFactorChallengeController::class, 'create'])
        ->name('two-factor.login');

    // São 6 dígitos: sem limite, a força bruta acha o número. Ver 'dois-fatores'
    // no AppServiceProvider.
    Route::post('verificacao-em-duas-etapas', [TwoFactorChallengeController::class, 'store'])
        ->middleware('throttle:dois-fatores');

    Route::post('verificacao-em-duas-etapas/cancelar', [TwoFactorChallengeController::class, 'destroy'])
        ->name('two-factor.cancel');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:credencial')
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:credencial')
        ->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    // Ambas validam a senha atual — sem limite, seriam oráculo de força bruta
    // para quem já tem a sessão (ver 'senha' no AppServiceProvider).
    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store'])
        ->middleware('throttle:senha');

    Route::put('password', [PasswordController::class, 'update'])
        ->middleware('throttle:senha')
        ->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
