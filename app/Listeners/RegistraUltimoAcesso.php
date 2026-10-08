<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\DB;

/**
 * Grava QUANDO a pessoa entrou pela última vez (`users.last_login_at`), em todo login do app:
 * senha, código do 2FA, Google, cadastro e o "lembrar de mim" (08/10/2026 — pedido do Victor,
 * "só para entender o fluxo"). Só o carimbo; o registro de atividade continua com o resto.
 *
 * Direto na tabela, de propósito: pelo model, o `save()` dispararia o registro de atividade e
 * mexeria no `updated_at` da conta a cada login.
 */
class RegistraUltimoAcesso
{
    public function handle(Login $evento): void
    {
        if ($evento->guard !== 'web' || ! $evento->user instanceof User) {
            return;
        }

        $agora = now();
        DB::table('users')->where('id', $evento->user->getKey())
            ->update(['last_login_at' => $agora, 'last_seen_at' => $agora]);
        $evento->user->setRawAttributes(
            ['last_login_at' => $agora, 'last_seen_at' => $agora] + $evento->user->getAttributes(), true,
        );
    }
}
