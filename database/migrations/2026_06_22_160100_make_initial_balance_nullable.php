<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * initial_balance passa a ser nullable: cartões (crédito/débito) não têm saldo
 * próprio, então a coluna fica nula para eles (só conta corrente/poupança usam).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->decimal('initial_balance', 15, 2)->nullable()->default(null)->change();
        });
    }

    /**
     * Voltar a coluna para NOT NULL exige que NÃO exista nenhum NULL — e existe
     * sempre que há um cartão cadastrado (cartão nasce com initial_balance null).
     * Sem o UPDATE abaixo o down() estoura e o rollback trava:
     *   MySQL  → SQLSTATE[22004] 1138 Invalid use of NULL value
     *   sqlite → SQLSTATE[23000] 19 NOT NULL constraint failed
     *
     * Zerar é a leitura correta do domínio: a coluna volta a significar "saldo
     * inicial", e cartão não tem saldo inicial — 0 é exatamente o que o schema
     * anterior guardava para eles.
     */
    public function down(): void
    {
        DB::table('accounts')->whereNull('initial_balance')->update(['initial_balance' => 0]);

        Schema::table('accounts', function (Blueprint $table) {
            $table->decimal('initial_balance', 15, 2)->nullable(false)->default(0)->change();
        });
    }
};
