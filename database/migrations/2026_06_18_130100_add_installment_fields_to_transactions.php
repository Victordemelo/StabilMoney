<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Parcelamento/recorrência das despesas (feature "Faturas / Despesas").
     * Decisão fechada: parcelamento = N linhas (1 transação por parcela,
     * datada no mês dela). O group_id liga as parcelas/recorrências da MESMA
     * compra, para a exclusão remover a compra inteira.
     *
     * À vista:      group_id null, installment_no null, installments null, recurring false.
     * Parcelado N:  group_id (uuid) + installment_no (1..N) + installments (N).
     * Recorrente:   group_id (uuid) + recurring true (installment_no/installments null).
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('group_id', 36)->nullable()->after('category_id');
            $table->unsignedSmallInteger('installment_no')->nullable()->after('group_id');
            $table->unsignedSmallInteger('installments')->nullable()->after('installment_no');
            $table->boolean('recurring')->default(false)->after('installments');

            $table->index('group_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['group_id']);
            $table->dropColumn(['group_id', 'installment_no', 'installments', 'recurring']);
        });
    }
};
