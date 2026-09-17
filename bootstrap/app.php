<?php

use App\Http\Middleware\BloqueiaUsuarioBanido;
use App\Http\Middleware\PainelAdminLigado;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Painel administrativo: arquivo próprio, guard próprio, interruptor
            // próprio. Ver routes/admin.php.
            Route::middleware('web')->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Interruptor do painel ANTES do roteador: desligado, qualquer verbo em qualquer
        // caminho sob o prefixo recebe o mesmo 404 de uma URL que não existe. Só na rota
        // ele chegava tarde (405, 419, cookie de sessão) — ver PainelAdminLigado.
        //
        // `append`, não `prepend`: o FIM da pilha global é exatamente onde o roteador
        // rodaria. Na frente, o prefixo responderia 404 onde qualquer outra URL responde
        // 413 (POST grande demais) ou 503 (manutenção) — outra diferença para o scanner.
        $middleware->append(PainelAdminLigado::class);

        // Cabeçalhos de segurança (CSP, nosniff, anti-clickjacking) em todas as
        // respostas web. Ver App\Http\Middleware\SecurityHeaders.
        //
        // `BloqueiaUsuarioBanido` vem junto: a checagem precisa valer em TODA
        // requisição autenticada, senão o cookie de "lembrar de mim" re-autentica
        // quem acabou de ser banido.
        $middleware->web(append: [
            SecurityHeaders::class,
            BloqueiaUsuarioBanido::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
