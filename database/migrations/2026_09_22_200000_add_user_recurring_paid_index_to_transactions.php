<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índice para a consulta de RECORRÊNCIAS do sino (`FaturaService::upcomingDue`, bloco 4),
 * que roda em TODA página autenticada pelo View Composer da topbar:
 *
 *   where user_id = ? and recurring = 1 and paid_at is null and date <= ?
 *     and not exists (conta de cartão de crédito)
 *
 * Sem índice que cubra `recurring`, o MySQL escolhia `(user_id, client_uuid)` — só o
 * prefixo `user_id` servia — e lia a família INTEIRA para devolver três linhas. Medido
 * no MySQL 8.0 com 24.419 transações de 36 meses (V-2 da auditoria de volume de
 * 06/09/2026, refeito em 22/09), mediana de 20 execuções:
 *
 *   antes   ref  key=transactions_user_id_client_uuid_unique  rows=12190  filtered=0,33%  14,3 ms
 *   depois  ref  key=transactions_user_recurring_paid_index   rows=5      Using index condition  0,12 ms
 *
 * `paid_at` entra depois de `recurring` porque `paid_at IS NULL` também é igualdade para
 * o otimizador; o filtro de data fica para as poucas linhas que sobram.
 *
 * O `down()` não precisa recriar índice de FK antes de remover este (ao contrário da
 * migration `add_account_cycle_indexes`): a FK de `user_id` continua apoiada em
 * `(user_id, date)` e `(user_id, client_uuid)`, que já existiam. Conferido no MySQL:
 * down, down, up, up — a FK de pé em todos os passos.
 */
return new class extends Migration
{
    private const INDICE = 'transactions_user_recurring_paid_index';

    public function up(): void
    {
        if (Schema::hasIndex('transactions', self::INDICE)) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['user_id', 'recurring', 'paid_at'], self::INDICE);
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('transactions', self::INDICE)) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(self::INDICE);
        });
    }
};
