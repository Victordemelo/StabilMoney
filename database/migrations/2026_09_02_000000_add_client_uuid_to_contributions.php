<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotência de aporte/resgate (metas e investimentos) e do aporte inicial
 * de um investimento — auditoria de 02/09/2026.
 *
 * Convenção do projeto: toda escrita de dinheiro disparada por clique leva um
 * `client_uuid`. As transações já tinham; as contribuições dos "cofrinhos" eram
 * o único caminho de escrita sem dedupe: um duplo clique registrava o mesmo
 * aporte duas vezes (não cria dinheiro, mas grava em dobro o que a pessoa fez
 * uma vez, e o disponível cai de verdade nas duas).
 *
 * O índice é único por PAI (goal_id / investment_id) + uuid, e nos dois drivers
 * ele ignora linhas com NULL — as contribuições gravadas pelo `FundingService`
 * (resgate que cobre uma despesa) e as antigas seguem sem uuid e não colidem.
 */
return new class extends Migration
{
    private const TABELAS = [
        'goal_contributions' => 'goal_id',
        'investment_contributions' => 'investment_id',
    ];

    public function up(): void
    {
        foreach (self::TABELAS as $tabela => $pai) {
            Schema::table($tabela, function (Blueprint $table) use ($pai) {
                $table->uuid('client_uuid')->nullable()->after('transaction_id');
                $table->unique([$pai, 'client_uuid']);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABELAS as $tabela => $pai) {
            // Índice primeiro, coluna depois, em passos separados: o sqlite (suíte)
            // não derruba coluna que ainda participa de um índice.
            Schema::table($tabela, function (Blueprint $table) use ($pai) {
                $table->dropUnique([$pai, 'client_uuid']);
            });
            Schema::table($tabela, function (Blueprint $table) {
                $table->dropColumn('client_uuid');
            });
        }
    }
};
