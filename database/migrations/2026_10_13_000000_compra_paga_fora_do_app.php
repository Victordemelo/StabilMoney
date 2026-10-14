<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compra de cartão lançada com data no passado (13/10/2026 — pedido do Victor): a parcela que
 * caiu numa fatura que JÁ VENCEU foi paga no mundo real, antes de a pessoa usar o app. Ela entra
 * com `paid_at` (o vencimento daquela fatura) e esta marca — sem saída de caixa nenhuma, porque o
 * saldo cadastrado da conta já é o de depois desse pagamento.
 *
 * A marca é o que diferencia esta linha de uma compra quitada por um pagamento DO APP: não há
 * pagamento a estornar, então ela pode ser excluída junto com a compra, e o estorno de uma fatura
 * nunca a reabre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->boolean('pago_fora_do_app')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('pago_fora_do_app');
        });
    }
};
