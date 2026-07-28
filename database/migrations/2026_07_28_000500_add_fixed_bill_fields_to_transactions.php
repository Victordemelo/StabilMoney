<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liga o PAGAMENTO de uma conta fixa à conta fixa e à competência (mês) que ele
 * quitou. Só existe linha quando a conta é paga — as competências em aberto são
 * calculadas, não materializadas.
 *
 * O UNIQUE (fixed_bill_id, competence) é a trava de idempotência: duplo clique
 * ou replay offline não paga o mesmo mês duas vezes. Nos dois drivers (MySQL e
 * sqlite) o índice único IGNORA linhas com NULL, então as milhares de
 * transações comuns (ambos null) não colidem entre si.
 *
 * Sem FOREIGN KEY em `fixed_bill_id` de propósito: dropForeign em sqlite (usado
 * nos testes) exige recriar a tabela e deixa o down() frágil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('fixed_bill_id')->nullable()->after('group_id');
            // Sempre o dia 01 do mês de competência.
            $table->date('competence')->nullable()->after('fixed_bill_id');

            $table->unique(['fixed_bill_id', 'competence'], 'transactions_fixed_bill_competence_unique');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('transactions_fixed_bill_competence_unique');
            $table->dropColumn(['fixed_bill_id', 'competence']);
        });
    }
};
