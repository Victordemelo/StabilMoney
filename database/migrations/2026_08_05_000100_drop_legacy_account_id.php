<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faxina: remove a coluna temporária `legacy_account_id` das três tabelas.
 *
 * Ela nasceu na migration `2026_07_28_000000`, que reancorou na conta vinculada
 * os movimentos que estavam presos a um cartão de débito (o cartão não tem saldo
 * próprio, então o dinheiro sumia). A coluna guardava de onde cada movimento
 * veio, para o `down()` daquela migration ser real — e a própria migration já
 * previa esta faxina.
 *
 * **Por que agora é seguro:** as três colunas estão VAZIAS (0 linhas preenchidas
 * em `transactions`, `goal_contributions` e `investment_contributions`), medido
 * antes de escrever isto. Ou seja, a reversibilidade que elas compravam hoje é
 * zero: o `down()` de 28/07 percorreria a coluna e não teria nada a devolver.
 * Não se perde informação nenhuma.
 *
 * **Efeito no `down()` de 28/07:** ele já se protege com `Schema::hasColumn()`,
 * então vira um no-op limpo em vez de estourar. E o `down()` DESTA migration
 * recria as colunas (vazias), deixando o banco exatamente no estado em que
 * estava — que era, também, vazio.
 *
 * A cobertura do bug C-5 (o `down()` de 28/07 perdia o mapeamento ao encontrar um
 * ponteiro para cartão já excluído) continua em `MigracoesReversiveisTest`: o
 * teste passou a recriar as colunas para reproduzir o cenário histórico, em vez
 * de depender de elas existirem no schema vivo.
 */
return new class extends Migration
{
    /** As mesmas três tabelas que a migration de 28/07 tocou. */
    private const TABELAS = ['transactions', 'goal_contributions', 'investment_contributions'];

    public function up(): void
    {
        foreach (self::TABELAS as $tabela) {
            if (! Schema::hasColumn($tabela, 'legacy_account_id')) {
                continue;
            }

            Schema::table($tabela, function (Blueprint $table) {
                $table->dropColumn('legacy_account_id');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABELAS as $tabela) {
            if (Schema::hasColumn($tabela, 'legacy_account_id')) {
                continue;
            }

            Schema::table($tabela, function (Blueprint $table) {
                $table->unsignedBigInteger('legacy_account_id')->nullable()->after('account_id');
            });
        }
    }
};
