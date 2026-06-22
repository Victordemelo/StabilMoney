<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Banco do método de pagamento (para mostrar a logo/cartão do banco) e, para o
 * Cartão de Débito, os vínculos com a Conta Corrente e/ou Poupança que ele
 * espelha (o saldo do débito = soma dos saldos vinculados, mostrados separados).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('bank', 30)->nullable()->after('type');
            // Cartão de débito espelha estas contas (nullOnDelete: se a conta some, o vínculo zera).
            $table->foreignId('checking_account_id')->nullable()->after('bank')
                ->constrained('accounts')->nullOnDelete();
            $table->foreignId('savings_account_id')->nullable()->after('checking_account_id')
                ->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checking_account_id');
            $table->dropConstrainedForeignId('savings_account_id');
            $table->dropColumn('bank');
        });
    }
};
