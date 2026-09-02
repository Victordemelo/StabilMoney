<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticação do painel, em vez do `auth:admin` do framework.
 *
 * **Por que não usar o `auth:admin`:** o `Illuminate\Auth\Middleware\Authenticate` está
 * na lista de PRIORIDADE do Laravel, que reordena a pilha e o fazia rodar antes do
 * `PainelAdminLigado`. Resultado: com o painel desligado, a resposta era 302 (o redirect
 * do auth) em vez de 404 — e 302 confirma que a rota existe, que é exatamente o que o
 * 404 deveria esconder. Um middleware próprio fica fora da lista de prioridade e
 * respeita a ordem declarada na rota.
 *
 * Bônus: o redirect aponta para o login DO PAINEL sem precisar configurar
 * `redirectGuestsTo` globalmente.
 */
class AutenticaNoPainel
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('admin')->check()) {
            return redirect()->guest(route('painel.login'));
        }

        return $next($request);
    }
}
