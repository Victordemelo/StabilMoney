<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dois pedidos do Victor (08/10/2026), a partir de um levantamento de uso: 4 dos 6 titulares se
 * cadastraram e não criaram nada.
 *
 * - `last_login_at` / `last_seen_at`: quando a pessoa entrou e quando usou o app pela última
 *   vez. Antes o painel lia isso das SESSÕES, que somem no logout e na expiração — e quem saiu
 *   aparecia como "nunca acessou". Só os dois carimbos, nada de histórico de navegação.
 * - `primeiros_passos_ocultos_at`: quando a pessoa escondeu o card "Primeiros passos".
 *
 * Quem JÁ usa o app de verdade (a família tem conta de banco E algum lançamento) começa com o
 * card escondido: ele é para quem não sabe por onde começar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('primeiros_passos_ocultos_at')->nullable();
        });

        $familiasEmUso = DB::table('accounts')->whereIn('type', ['checking', 'savings'])
            ->whereExists(fn ($q) => $q->from('transactions')->whereColumn('transactions.user_id', 'accounts.user_id'))
            ->distinct()->pluck('user_id');

        DB::table('users')
            ->where(fn ($q) => $q->whereIn('id', $familiasEmUso)->orWhereIn('account_owner_id', $familiasEmUso))
            ->update(['primeiros_passos_ocultos_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['last_login_at', 'last_seen_at', 'primeiros_passos_ocultos_at']);
        });
    }
};
