<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liga o resgate à despesa que ele cobriu ("qual resgate pagou esta compra").
 * Preenchido pelo FundingService quando o usuário escolhe tirar do investimento
 * para cobrir um gasto; null nos aportes/resgates feitos direto na tela.
 *
 * DELIBERADAMENTE SEM FOREIGN KEY: dropForeign em sqlite (usado nos testes)
 * exige recriar a tabela e deixa o down() frágil. A integridade fica no
 * FundingService, que grava os dois registros na mesma DB::transaction.
 */
return new class extends Migration
{
    private const TABELAS = ['goal_contributions', 'investment_contributions'];

    public function up(): void
    {
        foreach (self::TABELAS as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->unsignedBigInteger('transaction_id')->nullable()->after('account_id');
                $table->index('transaction_id');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABELAS as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->dropIndex(['transaction_id']);
                $table->dropColumn('transaction_id');
            });
        }
    }
};
