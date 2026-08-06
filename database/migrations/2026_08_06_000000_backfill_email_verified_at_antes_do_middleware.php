<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repete o backfill de `email_verified_at` — agora que o middleware `verified` ENTROU.
 *
 * A migration `2026_08_02_000300` já tinha feito isso, e o comentário dela dizia a razão:
 * "no dia em que alguém proteger uma rota com `verified`, todo usuário com
 * `email_verified_at` nulo perde o acesso ao próprio dinheiro". **Esse dia é hoje** —
 * `routes/web.php` passou a usar `['auth', 'verified']`.
 *
 * Rodar de novo não é redundância: entre 02/08 e hoje existia um caminho que **zerava a
 * coluna em produção**. Quem trocasse o e-mail no perfil enquanto o app não conseguia
 * enviar e-mail voltava para "não verificado" (`ProfileController`), e nesse regime não
 * havia link nenhum capaz de destravá-lo. O `ProfileController` foi corrigido no mesmo
 * commit que ligou o middleware, mas as linhas que ele já zerou continuam lá — e é
 * exatamente essa gente que o middleware trancaria, sem ter feito nada errado.
 *
 * Mesma data de "aceite tácito" da anterior (`created_at`) e mesmo `down()` no-op: como
 * não há como distinguir quem esta migration marcou de quem confirmou de verdade,
 * desmarcar em massa trancaria gente fora do app.
 *
 * ⚠️ Esta é a ÚLTIMA rede de proteção. Depois dela, `email_verified_at` nulo passa a ser
 * um estado legítimo (usuário novo que ainda não clicou no link) — e não mais um acidente
 * para consertar em massa.
 */
return new class extends Migration
{
    public function up(): void
    {
        $trancariam = DB::table('users')->whereNull('email_verified_at')->count();

        if ($trancariam === 0) {
            return;
        }

        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        // No-op: ver o docblock. Reverter trancaria usuários fora da conta.
    }
};
