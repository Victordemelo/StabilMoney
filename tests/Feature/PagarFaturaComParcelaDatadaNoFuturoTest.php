<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FaturaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A fatura aberta que só tem lançamentos datados DEPOIS de hoje — a parcela seguinte
 * de uma compra parcelada — pode ser paga hoje (achado do teste de propriedades de
 * 24/09/2026).
 *
 * O piso da "data do pagamento" é a despesa em aberto mais antiga do cartão, para
 * ninguém datar o pagamento em 2001 e sumir com a despesa dos relatórios. E o teto é
 * hoje (pagamento é fato consumado). Com a fatura anterior já paga, a mais antiga em
 * aberto é a parcela 2/3, datada no mês que vem: piso no futuro, teto hoje — NENHUMA
 * data era aceita. A tela mostrava "A pagar" e "Marcar como paga" (com o `min` do
 * campo de data depois do `max`), e o servidor recusava com "A data do pagamento não
 * pode ser anterior à compra mais antiga da fatura" — sendo que a compra é do mês
 * passado; só a parcela é datada adiante. O piso agora é a despesa mais antiga ou
 * hoje, o que vier primeiro: continua barrando o pagamento datado antes da compra.
 */
class PagarFaturaComParcelaDatadaNoFuturoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_fatura_aberta_so_com_a_parcela_do_mes_que_vem_pode_ser_paga_hoje(): void
    {
        Carbon::setTestNow('2026-06-25 10:00:00');
        $user = User::factory()->create();
        $corrente = Account::factory()->for($user)->create(['type' => 'checking', 'initial_balance' => 5000]);
        $cartao = Account::factory()->for($user)->creditCard()->create(['closing_day' => 19, 'due_day' => 16]);

        // 3x de 300 em 05/06: parcelas em 05/06, 05/07 e 05/08 — uma por ciclo.
        $this->actingAs($user)->post(route('faturas.lancar'), [
            'description' => 'Geladeira', 'amount' => '900,00', 'date' => '2026-06-05',
            'account_id' => $cartao->id, 'mode' => 'parcelado', 'installments' => 3,
        ])->assertSessionHasNoErrors();

        // Paga a fatura que fechou em 19/06 (a parcela 1/3).
        $this->actingAs($user)->post(route('faturas.fatura.pagar', $cartao), [
            'pay_account_id' => $corrente->id, 'ciclo' => 'fechado', 'paid_on' => '2026-06-25',
        ])->assertSessionHasNoErrors();

        // A fatura aberta (19/06–19/07) tem só a parcela 2/3, datada em 05/07.
        $card = collect(app(FaturaService::class)->build($user->id)['cards'])->first();
        $this->assertTrue($card['canPay']);
        $this->assertSame(300.0, (float) $card['invoiceDue']);
        $this->assertLessThanOrEqual('2026-06-25', $card['payFloor'], 'o campo de data não pode ter o mínimo depois do máximo (hoje)');

        // ANTES: 422 em paid_on — nenhuma data entre o piso (05/07) e hoje (25/06).
        $this->actingAs($user)->post(route('faturas.fatura.pagar', $cartao), [
            'pay_account_id' => $corrente->id, 'ciclo' => 'aberto', 'paid_on' => '2026-06-25',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(Transaction::where('installment_no', 2)->value('paid_at'));
        $this->assertSame(4400.0, $corrente->fresh()->available);
    }

    public function test_o_piso_continua_barrando_pagamento_datado_antes_da_compra(): void
    {
        Carbon::setTestNow('2026-06-25 10:00:00');
        $user = User::factory()->create();
        $corrente = Account::factory()->for($user)->create(['type' => 'checking', 'initial_balance' => 5000]);
        $cartao = Account::factory()->for($user)->creditCard()->create(['closing_day' => 19, 'due_day' => 16]);
        $this->actingAs($user)->postJson(route('transactions.store'), [
            'type' => 'expense', 'amount' => '200,00', 'account_id' => $cartao->id, 'date' => '2026-06-22',
        ])->assertCreated();

        $this->actingAs($user)->post(route('faturas.fatura.pagar', $cartao), [
            'pay_account_id' => $corrente->id, 'paid_on' => '2026-06-21',
        ])->assertSessionHasErrors('paid_on');
    }
}
