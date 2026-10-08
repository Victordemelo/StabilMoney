<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Libera quem JÁ se cadastrou (08/10/2026 — decisão do Victor): "quem já se cadastrou está
 * tudo certo, não precisa pedir confirmação; só o próximo pega essa regra".
 *
 * A confirmação do e-mail continua valendo para os cadastros NOVOS por nome, e-mail e senha
 * (e pelo Google quando ele não confirma o e-mail). Quem já existia e ainda não tinha
 * confirmado — por exemplo, o cadastro das 07:52 de 08/10 — entra no painel sem o link.
 * Data do aceite tácito: o `created_at`, como nos backfills de 02/08 e 06/08.
 *
 * `down()` é no-op pelo mesmo motivo daqueles: não há como separar quem esta migration marcou
 * de quem confirmou de verdade, e desmarcar em massa trancaria gente fora do app.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        // No-op: ver o docblock.
    }
};
