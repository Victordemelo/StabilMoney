<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A raiz do site (`/`) para quem NÃO entrou é a página inicial pública — a apresentação do
 * Stabil Money (out/2026, `PaginaInicialPublicaTest`). Quem entrou segue para a Visão geral,
 * pelo caminho de sempre (auth → verified → aceite da Política).
 *
 * Na raiz, e não num `/inicio`: é o endereço que os buscadores mais valorizam, e antes ele só
 * redirecionava para o login (que tem pouco texto para o buscador entender o que o app é).
 * Roda ANTES do `auth` na rota `/` (routes/web.php declara a ordem).
 *
 * Navegação de DENTRO do app (pjax, `X-Pjax`) e pedido de JSON seguem para o `auth` mesmo sem
 * usuário: é a sessão que expirou com a aba aberta. Sem isto, clicar em "Visão geral" com a
 * sessão vencida jogava a pessoa na apresentação pública (200) em vez do login — e o service
 * worker, que limpa o HTML guardado quando o `/login` responde 200, não ficava sabendo.
 */
class PaginaInicialParaVisitante
{
    public function handle(Request $request, Closure $next): Response
    {
        $deDentroDoApp = $request->hasHeader('X-Pjax') || $request->expectsJson();

        if ($request->user() === null && ! $deDentroDoApp) {
            return response()->view('inicio');
        }

        return $next($request);
    }
}
