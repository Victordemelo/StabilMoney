<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Limite do cheque especial da conta corrente: quanto o banco deixa o saldo
 * ficar negativo. É o piso do saldo disponível — abaixo de −overdraft_limit o
 * gasto é recusado.
 *
 * NOT NULL com default 0 (≠ initial_balance, que é nullable): assim os
 * accessors nunca precisam de coalesce e toda conta já existente nasce sem
 * cheque especial. Só faz sentido em `checking` — o zeramento por tipo é feito
 * no prepareForValidation do StoreAccountRequest, não no banco.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->decimal('overdraft_limit', 15, 2)->default(0)->after('initial_balance');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('overdraft_limit');
        });
    }
};
