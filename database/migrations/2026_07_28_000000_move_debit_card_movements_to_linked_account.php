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

    public function down(): void
    {
        foreach (self::TABELAS as $tabela) {
            // Devolve cada movimento ao cartão de onde veio.
            DB::table($tabela)
                ->whereNotNull('legacy_account_id')
                ->update(['account_id' => DB::raw('legacy_account_id')]);

            Schema::table($tabela, function (Blueprint $table) {
                $table->dropColumn('legacy_account_id');
            });
        }
    }
};
