<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->decimal('initial_balance', 15, 2)->nullable(false)->default(0)->change();
        });
    }
};
