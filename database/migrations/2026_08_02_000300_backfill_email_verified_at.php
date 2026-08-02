<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Marca como verificado quem já existia antes de a verificação de e-mail existir.
 *
 * `User` passou a implementar `MustVerifyEmail`. Hoje isso não tranca ninguém —
 * nenhuma rota usa o middleware `verified`, e sem mailer o cadastro já nasce
 * verificado. Mas no dia em que o SMTP entrar no `.env` e alguém proteger uma rota
 * com `verified`, todo usuário com `email_verified_at` nulo perde o acesso ao
 * próprio dinheiro — sem ter feito nada errado, e sem conseguir se desbloquear
 * (o link iria para um e-mail que ele talvez nem consiga receber).
 *
 * Usa `created_at` como data do "aceite tácito": é quando a pessoa provou ter o
 * endereço ao se cadastrar, no regime que valia na época.
 *
 * Sem `down()` destrutivo de propósito: desmarcar e-mails verificados trancaria
 * gente fora do app, e não há como distinguir quem esta migration marcou de quem
 * confirmou de verdade depois. Rollback é no-op documentado.
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
        // No-op: ver o docblock. Reverter trancaria usuários fora da conta.
    }
};
