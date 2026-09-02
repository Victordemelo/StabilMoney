<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * O segundo fator é OBRIGATÓRIO no painel — não é preferência, é porta.
 *
 * Duas situações, dois destinos:
 *
 *  - admin sem TOTP configurado → tela de setup, e ele não sai de lá enquanto não
 *    confirmar um código. Não existe "configurar depois": senha sozinha protegeria o
 *    painel inteiro, e senha vaza.
 *  - admin com TOTP configurado que ainda não provou NESTA sessão → tela de desafio.
 *
 * A prova fica na sessão (`admin_2fa_ok`), não num cookie: logout ou expiração
 * derrubam junto. Como a sessão é regenerada no login, não há como carregar a marca de
 * uma sessão antiga.
 */
class ExigeDoisFatoresDoAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if (! $admin) {
            return redirect()->route('painel.login');
        }

        if (! $admin->temDoisFatores()) {
            return redirect()->route('painel.2fa.setup');
        }

        if (! $request->session()->get('admin_2fa_ok')) {
            return redirect()->route('painel.2fa.desafio');
        }

        return $next($request);
    }
}
