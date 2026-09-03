<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Transferência entre contas de caixa (corrente ↔ poupança da mesma família).
     *
     * Uma transferência é UM PAR de linhas: a saída (`expense`) na origem e a
     * entrada (`income`) no destino, ligadas por este id. `type` continua sendo só
     * `income|expense` — assim `Account::balance`, o extrato e as séries de saldo
     * seguem certos sem mudar uma linha: o dinheiro sai de uma conta e entra na
     * outra, e o patrimônio não se move.
     *
     * O que muda é o DASHBOARD: mover R$ 300 da corrente para a poupança não é
     * receita nem despesa, e antes exigia uma despesa + uma receita que inflavam
     * as duas somas do mês. Quem mede fluxo de caixa (receitas/despesas, donut,
     * trends) ignora linhas com este id — o mesmo tratamento que a quitação de
     * fatura (`settles_account_id`) já recebe.
     *
     * String de 36 (e não `uuid()`) de propósito: no MySQL `uuid()` vira `char(36)`
     * e no sqlite, `varchar` — o mesmo tipo nos dois, sem surpresa na comparação.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('transfer_group_id', 36)->nullable()->after('group_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['transfer_group_id']);
            $table->dropColumn('transfer_group_id');
        });
    }
};
