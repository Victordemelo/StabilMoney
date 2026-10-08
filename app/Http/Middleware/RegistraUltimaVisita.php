<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Grava a última vez que a pessoa USOU o app (`users.last_seen_at`) — no máximo uma vez por
 * hora, para não escrever no banco a cada requisição (08/10/2026). Quem fica logado dias seguidos
 * pelo "lembrar de mim" não passa por um login novo; é por aqui que o painel sabe que ela voltou.
 *
 * Direto na tabela (sem evento de model, sem mexer no `updated_at`). Só o guard do app.
 */
class RegistraUltimaVisita
{
    public const INTERVALO_EM_MINUTOS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        if ($user && ($user->last_seen_at === null || $user->last_seen_at->lt(now()->subMinutes(self::INTERVALO_EM_MINUTOS)))) {
            $agora = now();
            DB::table('users')->where('id', $user->getKey())->update(['last_seen_at' => $agora]);
            $user->setRawAttributes(['last_seen_at' => $agora] + $user->getAttributes(), true);
        }

        return $next($request);
    }
}
