<?php

use App\Http\Controllers\SaudeController;
use App\Http\Middleware\BloqueiaUsuarioBanido;
use App\Http\Middleware\PainelAdminLigado;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        // Sem o `health: '/up'` do framework: ele servia HTML com script de CDN de
        // terceiro e respondia 200 com o banco fora do ar. O /up agora é nosso.
        then: function (): void {
            // Checagem de saúde para o monitor da VPS. FORA do grupo `web` de propósito:
            // sem sessão e sem cookie a cada batida do monitor. Ver SaudeController.
            Route::get('/up', SaudeController::class)->name('saude');

            // Painel administrativo: arquivo próprio, guard próprio, interruptor
            // próprio. Ver routes/admin.php.
            Route::middleware('web')->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // O /up responde ELE MESMO em manutenção — 503 em texto, que é o que o monitor
        // lê (ver SaudeController). Sem esta exceção o middleware de manutenção
        // responderia antes, com a página HTML de erro. Não é middleware novo: o
        // interruptor do painel logo abaixo continua sendo o ÚLTIMO da pilha global.
        $middleware->preventRequestsDuringMaintenance(except: ['up']);

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
        // Toda resposta de ERRO sai com os cabeçalhos de segurança — página, JSON ou
        // redirect. O `SecurityHeaders` é do grupo `web`, e muito erro nasce fora do
        // alcance dele: 404/405 do roteador, 503 da manutenção e 413 antes de qualquer
        // rota; 419 do CSRF, 429 do limite e 404 do model binding dentro do grupo, mas
        // antes dele na fila. Só entra o que falta — no erro que ainda atravessa o
        // `SecurityHeaders` na volta, a CSP com nonce dele substitui esta.
        //
        // As páginas em si ficam em resources/views/errors/ (sem @vite, sem banco, sem
        // sessão, zero <script>) — ver o comentário do errors/minimal.blade.php.
        $exceptions->respond(
            fn (Response $resposta, Throwable $e, Request $request) => SecurityHeaders::completarRespostaSemScript($resposta, $request),
        );
    })->create();
