<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Séries recorrentes ENCERRADAS (R2-2 da auditoria financeira, rodada 2).
 *
 * Excluir uma recorrência em /faturas (`faturas.compra.destroy`) apaga as
 * ocorrências EM ABERTO e mantém as já pagas — histórico financeiro não se
 * reescreve. Só que a paga mais recente continuava sendo "a última da série":
 * voltava em "Recorrente · Lançar neste ciclo" e um clique recriava a sucessora.
 * A assinatura cancelada ressuscitava, porque nada dizia que a série tinha acabado.
 *
 * Uma linha aqui = a série (`transactions.group_id`) não gera mais ocorrência.
 *
 * Tabela à parte, e não coluna em `transactions`, de propósito: o marcador não
 * toca em NENHUMA linha da série. As ocorrências pagas continuam idênticas
 * (valor, `paid_at`, `settled_by_id`, o selo "Recorrente" no histórico) e nenhum
 * saldo, limite ou fatura muda — encerrar é só "não gere a próxima".
 *
 * Sem FK em `group_id`: ele não é chave de tabela nenhuma (é um uuid repetido nas
 * linhas da série). O `user_id` tem FK com cascade, como as demais tabelas da
 * família — excluir o titular leva os marcadores junto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ended_recurrences', function (Blueprint $table) {
            $table->id();
            // Dono = titular da família (mesma semântica de `transactions.user_id`).
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // O `group_id` da série — mesmo tamanho da coluna em `transactions`.
            $table->string('group_id', 36);
            // Quem encerrou (titular ou dependente). Só registro.
            $table->foreignId('ended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Encerrar duas vezes (duplo clique) não duplica — e é por este par que
            // toda consulta procura, então o índice serve às duas coisas.
            $table->unique(['user_id', 'group_id']);
        });
    }

    /**
     * Sem os marcadores, as séries excluídas voltam a oferecer "Lançar neste ciclo"
     * — o comportamento de antes. Nenhuma transação é tocada, nem aqui nem no up().
     */
    public function down(): void
    {
        Schema::dropIfExists('ended_recurrences');
    }
};
