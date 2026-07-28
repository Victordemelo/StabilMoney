<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoria da fonte do dinheiro quando o saldo disponível não cobriu a despesa
 * sozinho: o usuário escolheu cheque especial ou resgate de investimento.
 *
 * Sem isso não há como reconstituir depois POR QUE uma conta ficou negativa.
 *
 * Os valores aceitos são validados na aplicação (App\Support\FundingSource) e
 * não como enum de banco: enum diverge entre MySQL e sqlite e é caro de alterar.
 *
 * `funding_amount` é quanto veio da fonte escolhida — pode ser menor que
 * `amount` quando parte foi coberta pelo disponível.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('funding_source', 24)->nullable()->after('paid_at');
            $table->decimal('funding_amount', 15, 2)->nullable()->after('funding_source');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['funding_source', 'funding_amount']);
        });
    }
};
