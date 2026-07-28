<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contas fixas mensais: condomínio, aluguel, parcela do carro, escola — o que
 * vence todo mês e "não pode vencer".
 *
 * Deliberadamente NÃO reaproveita `transactions.recurring`, que só é aceito em
 * cartão de crédito, não tem dia próprio (copia o vencimento do cartão) e só
 * gera o mês seguinte quando alguém clica em pagar — na prática, nunca.
 *
 * As OCORRÊNCIAS mensais não são materializadas: são calculadas na leitura pelo
 * FixedBillService. Só existe linha em `transactions` quando a conta é PAGA.
 * Motivo: uma transação em aberto já reduz o saldo hoje, então materializar 12
 * meses derrubaria o saldo do usuário em 12 aluguéis de uma vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_bills', function (Blueprint $table) {
            $table->id();
            // Dono = titular da família (mesma semântica das demais tabelas).
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('made_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('name');
            // Valor ESPERADO. O valor real de cada mês vai na transação do pagamento
            // (conta de luz varia), e este segue servindo de previsão.
            $table->decimal('amount', 15, 2);
            // Dia do vencimento: 1..31. O clamp de mês curto é feito em PHP
            // (Account::dayInMonth), por isso aqui aceita 31 — ao contrário do
            // 1..28 dos cartões.
            $table->unsignedTinyInteger('due_day');

            // Método de pagamento e categoria padrão (opcionais).
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->date('starts_on');            // primeira competência
            $table->date('ends_on')->nullable();  // null = sem fim
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_bills');
    }
};
