<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liga cada COMPRA à transação que a quitou.
 *
 * Pagar uma fatura marca N compras com `paid_at` e cria 1 saída de caixa
 * (`settles_account_id`). Sem esta coluna, o ESTORNO teria de adivinhar quais
 * compras pertenciam àquele pagamento (por data/ciclo) — impreciso e frágil.
 * Com ela o estorno é exato: `where('settled_by_id', <id da quitação>)`.
 *
 * Sem FK de propósito: o estorno apaga a quitação e limpa esta coluna na mesma
 * transação, e uma FK com cascade apagaria as COMPRAS junto (perda de histórico).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('settled_by_id')->nullable()->after('settles_account_id');
            $table->index('settled_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['settled_by_id']);
            $table->dropColumn('settled_by_id');
        });
    }
};
