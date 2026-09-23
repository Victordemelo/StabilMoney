<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quitação de fatura PELO CRÉDITO de um estorno (R2-3, R2-4 e R2-5 da auditoria
 * financeira, rodada 2).
 *
 * Quando um estorno lançado no cartão cobre EXATAMENTE as compras em aberto, a
 * fatura tem líquido zero: não há o que tirar do caixa. Quitar essa fatura só
 * marcava `paid_at` nas linhas, sem registro nenhum do que tinha acontecido — e o
 * que não deixa registro não se desfaz. A compra quitada assim não podia mais ser
 * excluída: a trava mandava "estornar o pagamento", e não havia pagamento nenhum
 * para estornar.
 *
 * Uma linha aqui = um lote de linhas do cartão quitado pelo crédito, sem saída de
 * caixa. As linhas apontam para ela em `transactions.credit_settlement_id` — o
 * papel que o `settled_by_id` faz na quitação em caixa —, e desfazer é exato: as
 * linhas dela voltam a ficar em aberto (`paid_at` e o vínculo em null) e ela some.
 *
 * Tabela à parte, e não uma "quitação de R$ 0,00" em `transactions`: essa linha
 * apareceria no Histórico, no extrato e nos lançamentos recentes do dashboard como
 * um pagamento que não aconteceu, e ainda precisaria de uma conta de caixa que
 * ninguém usou. Sem coluna de dinheiro de propósito: o valor coberto é a soma das
 * próprias linhas, e um segundo número guardado aqui só teria como divergir dela.
 *
 * `credit_settlement_id` sem FK, como o `settled_by_id`: o desfazer limpa a coluna
 * e apaga a quitação na mesma transação, e uma FK com cascade apagaria as COMPRAS
 * junto. (E `dropForeign` em sqlite exige recriar a tabela.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_settlements', function (Blueprint $table) {
            $table->id();
            // Dono = titular da família (mesma semântica de `transactions.user_id`).
            // Cascade, como as demais tabelas da família: excluir o titular leva junto.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // O cartão cuja fatura foi quitada. Cartão com lançamentos não se exclui,
            // então o cascade só age junto com a exclusão da família inteira.
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            // Quem quitou (titular ou dependente). Só registro.
            $table->foreignId('made_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // O mesmo instante gravado no `paid_at` das linhas que ela quitou.
            // `dateTime`, e não `timestamp` NOT NULL: num MySQL com
            // `explicit_defaults_for_timestamp` desligado, o primeiro `timestamp`
            // NOT NULL da tabela ganha `ON UPDATE CURRENT_TIMESTAMP` sozinho.
            $table->dateTime('paid_at');
            $table->timestamps();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('credit_settlement_id')->nullable()->after('settled_by_id');
            $table->index('credit_settlement_id');
        });
    }

    /**
     * As linhas quitadas pelo crédito continuam com `paid_at`: é o estado de antes
     * desta migration, em que a quitação pelo crédito não deixava registro. Nenhum
     * dinheiro muda, nem aqui nem no up().
     */
    public function down(): void
    {
        if (Schema::hasColumn('transactions', 'credit_settlement_id')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropIndex(['credit_settlement_id']);
                $table->dropColumn('credit_settlement_id');
            });
        }

        Schema::dropIfExists('credit_settlements');
    }
};
