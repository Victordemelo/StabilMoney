<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para as consultas de CICLO DE CARTÃO, que são as mais quentes do app
 * (rodam por cartão, no dashboard e em /faturas): fatura atual, fatura em
 * aberto, fatura do ciclo fechado e a lista de itens do ciclo.
 *
 * Todas filtram `account_id` + uma FAIXA de `date`, e as de "em aberto" somam
 * `paid_at IS NULL`. Só existia `transactions_account_id_foreign (account_id)`,
 * que resolve o igual e entrega a faixa inteira para o filtro varrer linha a
 * linha. Medido com 50 mil transações num cartão (25.004 do cartão):
 *
 *   fatura do ciclo          ref  rows=25004  →  range rows=840  (index (account_id, date))
 *   fatura em aberto         ref  rows=25004  →  range rows=420  (index (account_id, paid_at, date))
 *   itens do ciclo (lista)   ref  rows=25004 + filesort → range rows=840, filesort ELIMINADO
 *
 * O segundo índice põe `paid_at` antes de `date` de propósito: `paid_at IS NULL`
 * é igualdade, então fica na frente e o range de data segue aproveitável. Ele
 * também cobre o prefixo (account_id, paid_at), usado por `committed`.
 *
 * ATENÇÃO — estes índices só entregam o ganho acima nas consultas que comparam
 * `date` DIRETAMENTE. Onde o código usa `whereDate('date', ...)` sobre uma coluna
 * que já é DATE, a função em volta da coluna impede o uso do índice e o plano
 * continua `ref rows=25004`. A troca de `whereDate()` por `where()` vive em app/
 * e é pré-requisito para colher este ganho.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['account_id', 'date'], 'transactions_account_id_date_index');
            $table->index(['account_id', 'paid_at', 'date'], 'transactions_account_paid_date_index');
        });
    }

    /**
     * O InnoDB DESCARTA o índice auto-gerado da FK (`transactions_account_id_foreign`,
     * só `account_id`) assim que passa a existir outro índice começando por
     * `account_id` — ele vira redundante como suporte da constraint. Consequência:
     * ao remover os nossos, o último a sair é o único suporte que sobrou e o MySQL
     * recusa com "1553 Cannot drop index: needed in a foreign key constraint",
     * deixando o rollback pela metade.
     *
     * Por isso o índice da FK é recriado ANTES dos drops. Cada passo é guardado por
     * `hasIndex`, então o down() é reentrante (roda de novo num banco meio-revertido).
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql' && ! Schema::hasIndex('transactions', 'transactions_account_id_foreign')) {
            DB::statement('ALTER TABLE `transactions` ADD INDEX `transactions_account_id_foreign` (`account_id`)');
        }

        foreach (['transactions_account_id_date_index', 'transactions_account_paid_date_index'] as $indice) {
            if (! Schema::hasIndex('transactions', $indice)) {
                continue;
            }

            Schema::table('transactions', function (Blueprint $table) use ($indice) {
                $table->dropIndex($indice);
            });
        }
    }
};
