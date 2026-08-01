<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca a transação que QUITA a fatura de um cartão.
     *
     * Pagar a fatura cria uma saída de caixa — dinheiro que sai de verdade, e por isso
     * ela precisa continuar no saldo e no extrato. Mas ela NÃO é um gasto novo: o gasto
     * já foi contado quando a compra entrou no cartão. Sem distinguir as duas coisas, o
     * dashboard somava as duas e DOBRAVA a despesa do período — quem pagava o cartão em
     * dia via o dobro do que gastou, e o donut ganhava uma fatia "Sem categoria".
     *
     * Guardamos o id do cartão quitado (e não um booleano) porque assim a linha também
     * responde "que fatura este pagamento quitou?", útil para estorno e conciliação.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('settles_account_id')
                ->nullable()
                ->after('fixed_bill_id')
                ->constrained('accounts')
                // O pagamento é histórico financeiro: se o cartão for excluído, a saída
                // de caixa permanece, só perde o vínculo.
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('settles_account_id');
        });
    }
};
