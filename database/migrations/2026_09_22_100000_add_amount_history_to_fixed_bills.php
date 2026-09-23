<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico do VALOR PREVISTO das contas fixas.
 *
 * Antes, `fixed_bills.amount` era o previsto de TODAS as competências ainda não
 * pagas: reajustar o aluguel em julho reescrevia maio e junho em aberto — e é
 * esse número que o sino, os lembretes por e-mail e o teto de 3× do pagamento
 * usam. Um reajuste para baixo chegava a travar o pagamento do mês anterior com
 * o valor antigo, que passava a estourar o teto.
 *
 * Agora `amount` continua sendo o valor ATUAL (o que o formulário de edição
 * mostra e o que vale do mês do último reajuste em diante), e `amount_history`
 * guarda os valores que já valeram, cada um com a ÚLTIMA competência em que
 * valeu (inclusive), em ordem:
 *
 *   [{"until": "2026-06", "amount": "1500.00"}]  → até jun/2026: 1.500; de jul em diante: `amount`
 *
 * A regra de quando um valor vira histórico mora em `FixedBill::definirValorPrevisto()`.
 *
 * JSON na própria linha, e não tabela à parte, de propósito:
 *  - o valor e o histórico mudam juntos num UPDATE só — não existe estado
 *    intermediário entre duas tabelas para um reajuste concorrente enxergar;
 *  - a projeção roda em TODA página (o sino chama o FixedBillService): uma tabela
 *    à parte seria uma query a mais por requisição, quase sempre para voltar vazia.
 *
 * `json` vira `json` no MySQL e `text` no sqlite; o cast `array` do model lê e
 * grava igual nos dois. Nula = conta que nunca foi reajustada — inclusive todas
 * as que já existiam —, e aí `amount` vale para tudo, exatamente o comportamento
 * anterior. Por isso não há backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('fixed_bills', 'amount_history')) {
            return;
        }

        Schema::table('fixed_bills', function (Blueprint $table) {
            $table->json('amount_history')->nullable()->after('amount');
        });
    }

    /**
     * Descarta o histórico: as competências em aberto voltam a usar o valor atual,
     * que é o comportamento de antes desta migration. Nenhum dinheiro muda — o
     * histórico só alimenta previsões (sino, lembretes, teto do pagamento); o valor
     * PAGO de cada competência mora na transação do pagamento.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('fixed_bills', 'amount_history')) {
            return;
        }

        Schema::table('fixed_bills', function (Blueprint $table) {
            $table->dropColumn('amount_history');
        });
    }
};
