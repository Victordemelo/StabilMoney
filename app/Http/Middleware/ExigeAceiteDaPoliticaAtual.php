<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quem aceitou uma versão ANTERIOR dos Termos/Política (ou nunca aceitou — o dependente, criado
 * pelo titular) cai na tela de aceite antes de usar o app (out/2026 — decisão do Victor;
 * `AceiteDaPoliticaAtualTest`). A versão atual é a `legal.version`; o aceite grava data, versão e
 * IP (`users.terms_*`), como no cadastro.
 *
 * Ficam livres: a própria tela de aceite (fora do grupo), o token CSRF da fila offline, a foto do
 * shell e — por causa do direito de eliminação da LGPD — a aba Conta das Configurações e a
 * exclusão da conta: quem não concorda pode sair de vez sem aceitar nada.
 */
class ExigeAceiteDaPoliticaAtual
{
    private const LIVRES = ['csrf.token', 'avatar.show', 'profile.destroy'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->terms_version === config('legal.version') || $this->livre($request)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Os Termos de Uso e a Política de Privacidade mudaram. Aceite a nova versão para continuar.',
                'aceitar' => route('termos.aceite'),
            ], 403);
        }

        // Guarda para onde a pessoa ia, para voltar depois do aceite (só GET).
        if ($request->isMethod('GET')) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route('termos.aceite');
    }

    private function livre(Request $request): bool
    {
        return $request->routeIs(...self::LIVRES)
            || ($request->routeIs('settings') && $request->route('tab') === 'conta');
    }
}
