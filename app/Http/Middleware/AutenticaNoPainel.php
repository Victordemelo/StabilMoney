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
    /**
     * Onde fica a página que o admin tentou abrir antes de entrar.
     *
     * Chave PRÓPRIA, e não o `url.intended` que o `redirect()->guest()` grava (achado A-14 da
     * auditoria de 05/09/2026). A sessão é uma só para o app e para o painel, e o
     * `url.intended` é do app: é dele que o login do app e o desafio do 2FA tiram o destino.
     * Com o `guest()`, quem batia em `/painel_admin/inicio` sem sessão e depois entrava no APP
     * era jogado na tela de login do painel — e, no sentido contrário, o painel mandava o
     * admin recém-autenticado para a página do app que estivesse guardada ali.
     */
    public const CHAVE_DESTINO = 'admin_destino';

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('admin')->check()) {
            // Só página que dá para reabrir com um GET. Para os outros verbos o `guest()`
            // guardava o endereço ANTERIOR, que pode ser uma tela do app — e reabrir o de um
            // POST com GET cairia num 405.
            if ($request->isMethod('GET') && ! $request->expectsJson()) {
                $request->session()->put(self::CHAVE_DESTINO, $request->fullUrl());
            }

            return redirect()->route('painel.login');
        }

        return $next($request);
    }
}
