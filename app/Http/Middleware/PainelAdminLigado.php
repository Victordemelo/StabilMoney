<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interruptor geral do painel: desligado, ele não existe.
 *
 * **404, nunca 403.** A diferença importa: 403 confirma que há um painel ali e convida
 * o atacante a insistir; 404 é indistinguível de uma URL que nunca existiu. Pelo mesmo
 * motivo isto roda ANTES de qualquer autenticação — não deve nem existir formulário de
 * login para descobrir.
 */
class PainelAdminLigado
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('admin.enabled'), 404);

        return $next($request);
    }
}
