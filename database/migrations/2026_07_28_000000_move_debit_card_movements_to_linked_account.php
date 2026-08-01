<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cartão de DÉBITO não é caixa: ele só espelha a corrente/poupança vinculada
 * (Account::getBalanceAttribute). Enquanto ele pôde ser escolhido como conta de
 * uma transação, o dinheiro sumia — a despesa não descontava de conta nenhuma —
 * e um aporte feito "pelo cartão" reservava sem tocar no disponível da corrente,
 * dando para guardar o dobro do que existia.
 *
 * Esta migration reancora os movimentos que estão em cartões de débito na conta
 * que eles espelham (corrente, ou poupança se não houver corrente). A coluna
 * `legacy_account_id` guarda de onde veio, para o down() ser real; uma migration
 * de faxina futura pode removê-la.
 */
return new class extends Migration
{
    /** Tabelas com account_id que aceitavam cartão de débito. */
    private const TABELAS = ['transactions', 'goal_contributions', 'investment_contributions'];

    public function up(): void
    {
        foreach (self::TABELAS as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->unsignedBigInteger('legacy_account_id')->nullable()->after('account_id');
            });
        }

        // Um UPDATE por cartão de débito: barato (são poucos) e legível.
        $debitos = DB::table('accounts')
            ->where('type', 'debit_card')
            ->get(['id', 'checking_account_id', 'savings_account_id']);

        foreach ($debitos as $debito) {
            $destino = $debito->checking_account_id ?? $debito->savings_account_id;

            // Cartão órfão (perdeu os dois vínculos): não há para onde mover.
            // Deixar como está é melhor do que apagar o histórico do usuário.
            if (! $destino) {
                continue;
            }

            foreach (self::TABELAS as $tabela) {
                DB::table($tabela)
                    ->where('account_id', $debito->id)
                    ->update([
                        'legacy_account_id' => $debito->id,
                        'account_id' => $destino,
                    ]);
            }
        }
    }

    /**
     * ORDEM IMPORTA: os 3 UPDATE primeiro, os 3 dropColumn depois.
     *
     * MySQL não tem DDL transacional. Na ordem antiga (update+drop por tabela)
     * bastava UM `legacy_account_id` apontando para um cartão já apagado — nada
     * impede, não há FK nessa coluna — para o UPDATE da 2ª tabela estourar FK
     * DEPOIS de a 1ª já ter perdido a coluna: o mapeamento de `transactions`
     * virava pó, `goal_contributions` ficava com `account_id` errado e a linha
     * seguia em `migrations`, travando `migrate:rollback` para sempre ali.
     *
     * Fazendo todos os UPDATE antes, qualquer falha acontece com as 3 colunas
     * ainda de pé — o mapeamento continua íntegro e dá para tentar de novo.
     *
     * Ponteiros mortos (cartão apagado) são deliberadamente IGNORADOS: não há
     * para onde devolver, então o movimento fica na conta que ele já espelhava,
     * que é exatamente o saldo que o usuário vê hoje.
     *
     * Cada passo é guardado por `hasColumn`, o que torna o down() reentrante:
     * um banco que ficou meio-revertido pela versão antiga consegue concluir.
     */
    public function down(): void
    {
        // (1) Devolve cada movimento ao cartão de onde veio — todas as tabelas.
        foreach (self::TABELAS as $tabela) {
            if (! Schema::hasColumn($tabela, 'legacy_account_id')) {
                continue;
            }

            DB::table($tabela)
                ->whereNotNull('legacy_account_id')
                ->whereIn('legacy_account_id', DB::table('accounts')->select('id'))
                ->update(['account_id' => DB::raw('legacy_account_id')]);
        }

        // (2) Só então derruba as colunas.
        foreach (self::TABELAS as $tabela) {
            if (! Schema::hasColumn($tabela, 'legacy_account_id')) {
                continue;
            }

            Schema::table($tabela, function (Blueprint $table) {
                $table->dropColumn('legacy_account_id');
            });
        }
    }
};
