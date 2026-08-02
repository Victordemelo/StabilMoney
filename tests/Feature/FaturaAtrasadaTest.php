<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FaturaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fatura de cartão em ATRASO — cinco defeitos do mesmo tema: a dívida que já
 * fechou ficava invisível e impagável pela interface.
 *
 * A) `closedCycle()` olhava exatamente UM ciclo para trás. Com dois ou mais
 *    ciclos sem pagar (fecha dia 10, hoje 15/09, compras de 15/06 e 15/07), as
 *    compras antigas não caíam nem no ciclo aberto nem no fechado: sumiam de
 *    /faturas, sumiam do sino, e comiam o limite para sempre — `committed`
 *    conta tudo que não foi pago, sem olhar data.
 * B) O ramo `ciclo=fechado` do `payInvoice` era código morto: nenhuma tela o
 *    enviava, e o único botão pagava sempre o ciclo aberto.
 * C) `paid_on` também era código morto no pagamento de fatura — o campo só
 *    existia no modal de CONTA FIXA, então todo pagamento gravava "hoje".
 * D) O sino somava a mesma recorrência de cartão duas vezes (uma dentro da
 *    fatura, outra como recorrência solta).
 * E) Excluir compra de cartão JÁ PAGA deixava a saída de caixa órfã.
 *
 * "Hoje" é fixado em 15/09/2026 e o cartão fecha dia 10 / vence dia 20, então:
 *   ciclo aberto = (10/09, 10/10]   ·   já fechado = tudo até 10/09.
 */
class FaturaAtrasadaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    private Account $cartao;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15');

        $this->user = User::factory()->create();

        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'name' => 'Corrente',
            'initial_balance' => 5000,
            'overdraft_limit' => 0,
        ]);

        $this->cartao = Account::factory()->for($this->user)->creditCard()->create([
            'name' => 'Nubank',
            'credit_limit' => 5000,
            'closing_day' => 10,
            'due_day' => 20,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Despesa no cartão, em aberto. */
    private function compra(string $data, float $valor, string $descricao, array $extra = []): Transaction
    {
        return Transaction::factory()->for($this->user)->for($this->cartao)->expense()->create(array_merge([
            'amount' => $valor,
            'date' => $data,
            'description' => $descricao,
        ], $extra));
    }

    /**
     * Cenário-base do bug A: duas faturas fechadas sem pagar (15/06 e 15/07) e
     * uma compra no ciclo aberto (15/09).
     */
    private function duasFaturasAtrasadas(): void
    {
        $this->compra('2026-06-15', 300, 'Mercado de junho');
        $this->compra('2026-07-15', 200, 'Farmácia de julho');
        $this->compra('2026-09-15', 100, 'Padaria de hoje');
    }

    private function disponivel(): float
    {
        return Account::find($this->conta->id)->available;
    }

    // ===================== A) Dívida de vários ciclos =====================

    public function test_duas_faturas_fechadas_viram_uma_divida_so_e_nao_somem_da_tela(): void
    {
        $this->duasFaturasAtrasadas();

        $cartao = Account::find($this->cartao->id);

        // Antes: a janela do "fechado" era (10/08, 10/09] — nenhuma das duas
        // compras caía nela, e o valor devido aparecia como R$ 0,00.
        $this->assertSame(500.0, $cartao->closedInvoiceDue, 'a dívida das duas faturas fechadas soma');
        $this->assertSame(100.0, $cartao->openInvoiceDue, 'a compra de hoje continua no ciclo aberto');
        $this->assertSame(600.0, $cartao->committed, 'o limite segue preso enquanto nada foi pago');
    }

    public function test_a_fatura_atrasada_informa_o_vencimento_mais_antigo_e_os_dias_de_atraso(): void
    {
        $this->duasFaturasAtrasadas();

        $atrasada = Account::find($this->cartao->id)->overdueInvoice;

        $this->assertNotNull($atrasada, 'com dívida de junho o cartão está atrasado');
        $this->assertSame(500.0, $atrasada['valor']);
        // A compra de 15/06 pertence ao ciclo que fechou em 10/07 e venceu em 20/07.
        $this->assertSame('2026-07-20', $atrasada['vencimento']->toDateString());
        $this->assertSame(57, $atrasada['diasAtraso']);
    }

    public function test_fatura_que_fechou_e_ainda_esta_no_prazo_nao_e_marcada_como_vencida(): void
    {
        // Só uma compra, do ciclo que fechou em 10/09 e vence em 20/09 (futuro).
        $this->compra('2026-09-05', 400, 'Compra do ciclo que acabou de fechar');

        $cartao = Account::find($this->cartao->id);

        $this->assertSame(400.0, $cartao->closedInvoiceDue);
        $this->assertNull($cartao->overdueInvoice, 'fechou, mas ainda está no prazo');

        $fechada = $cartao->closedInvoice;
        $this->assertFalse($fechada['vencida']);
        $this->assertSame('2026-09-20', $fechada['vencimento']->toDateString());
        $this->assertSame(0, $fechada['diasAtraso']);
    }

    // ===================== B) Pagar a fatura fechada =====================

    public function test_pagar_a_fatura_fechada_quita_as_duas_e_devolve_o_limite(): void
    {
        $this->duasFaturasAtrasadas();

        $this->actingAs($this->user)
            ->post(route('faturas.fatura.pagar', $this->cartao), [
                'pay_account_id' => $this->conta->id,
                'ciclo' => 'fechado',
            ])
            ->assertSessionHasNoErrors();

        $cartao = Account::find($this->cartao->id);

        $this->assertSame(0.0, $cartao->closedInvoiceDue, 'a dívida atrasada foi quitada inteira');
        $this->assertSame(4500.0, $this->disponivel(), 'saíram os R$ 500 do caixa');
        $this->assertSame(100.0, $cartao->committed, 'só a compra do ciclo aberto continua presa');

        foreach (['Mercado de junho', 'Farmácia de julho'] as $descricao) {
            $compra = Transaction::where('description', $descricao)->firstOrFail();
            $this->assertNotNull($compra->paid_at, "{$descricao} devia estar paga");
            $this->assertNotNull($compra->settled_by_id, 'cada compra aponta para a quitação que a pagou');
        }
    }

    public function test_pagar_a_fatura_fechada_nao_arrasta_a_do_ciclo_aberto(): void
    {
        $this->duasFaturasAtrasadas();

        $this->actingAs($this->user)->post(route('faturas.fatura.pagar', $this->cartao), [
            'pay_account_id' => $this->conta->id,
            'ciclo' => 'fechado',
        ])->assertSessionHasNoErrors();

        $hoje = Transaction::where('description', 'Padaria de hoje')->firstOrFail();

        $this->assertNull($hoje->paid_at, 'a compra do ciclo aberto ainda não venceu — não se paga por engano');
        $this->assertSame(100.0, Account::find($this->cartao->id)->openInvoiceDue);

        // E o caixa saiu exatamente 500 — não 600.
        $this->assertSame(4500.0, $this->disponivel());
    }

    public function test_pagar_o_ciclo_aberto_nao_arrasta_a_divida_atrasada(): void
    {
        $this->duasFaturasAtrasadas();

        $this->actingAs($this->user)->post(route('faturas.fatura.pagar', $this->cartao), [
            'pay_account_id' => $this->conta->id,
            'ciclo' => 'aberto',
        ])->assertSessionHasNoErrors();

        $cartao = Account::find($this->cartao->id);

        $this->assertSame(4900.0, $this->disponivel(), 'só os R$ 100 do ciclo aberto');
        $this->assertSame(500.0, $cartao->closedInvoiceDue, 'a dívida atrasada continua devendo');
        $this->assertSame(0.0, $cartao->openInvoiceDue);
    }

    public function test_a_tela_de_faturas_oferece_o_botao_de_pagar_a_fatura_vencida(): void
    {
        $this->duasFaturasAtrasadas();

        $resposta = $this->actingAs($this->user)->get(route('faturas.index'));

        $resposta->assertOk()
            // O botão novo, com o ciclo que o servidor espera…
            ->assertSee('Pagar fatura vencida')
            ->assertSee('data-ciclo="fechado"', false)
            // …e o modal com o hidden e o campo de data (que ninguém enviava).
            ->assertSee('name="ciclo"', false)
            ->assertSee('name="paid_on"', false)
            // Piso da data = compra em aberto mais antiga.
            ->assertSee('data-min="2026-06-15"', false);

        $dados = app(FaturaService::class)->build($this->user->id);
        $card = $dados['cards']->first();

        $this->assertSame(500.0, $card['closedInvoice']['valor']);
        $this->assertTrue($card['closedInvoice']['vencida']);
        $this->assertSame('2026-06-15', $card['payFloor']);
        // Total do topo = ciclo aberto (100) + o que já fechou e não foi pago (500).
        $this->assertSame(600.0, $dados['stats']['totalFaturas']);
    }

    public function test_sem_divida_fechada_a_tela_nao_mostra_o_bloco_de_fatura_vencida(): void
    {
        $this->compra('2026-09-15', 100, 'Padaria de hoje');

        $this->actingAs($this->user)->get(route('faturas.index'))
            ->assertOk()
            ->assertDontSee('Pagar fatura vencida')
            ->assertDontSee('Pagar fatura fechada')
            ->assertSee('Marcar como paga');
    }

    // ===================== C) Data do pagamento =====================

    public function test_paid_on_grava_a_data_informada_no_pagamento_da_fatura(): void
    {
        $this->duasFaturasAtrasadas();

        $this->actingAs($this->user)->post(route('faturas.fatura.pagar', $this->cartao), [
            'pay_account_id' => $this->conta->id,
            'ciclo' => 'fechado',
            'paid_on' => '2026-09-01',
        ])->assertSessionHasNoErrors();

        $quitacao = Transaction::whereNotNull('settles_account_id')->firstOrFail();

        // Antes: gravava sempre HOJE (15/09) — não dava para registrar o dia em
        // que o dinheiro realmente saiu.
        $this->assertSame('2026-09-01', $quitacao->date->toDateString());
        $this->assertSame('2026-09-01', $quitacao->paid_at->toDateString());

        $compra = Transaction::where('description', 'Mercado de junho')->firstOrFail();
        $this->assertSame('2026-09-01', $compra->paid_at->toDateString());
    }

    public function test_paid_on_anterior_a_compra_mais_antiga_e_recusado(): void
    {
        $this->duasFaturasAtrasadas();

        $this->actingAs($this->user)->post(route('faturas.fatura.pagar', $this->cartao), [
            'pay_account_id' => $this->conta->id,
            'ciclo' => 'fechado',
            'paid_on' => '2026-01-05',
        ])->assertSessionHasErrors('paid_on');

        $this->assertSame(5000.0, $this->disponivel(), 'nada foi debitado');
        $this->assertSame(500.0, Account::find($this->cartao->id)->closedInvoiceDue);
    }

    public function test_paid_on_no_futuro_e_recusado(): void
    {
        $this->duasFaturasAtrasadas();

        $this->actingAs($this->user)->post(route('faturas.fatura.pagar', $this->cartao), [
            'pay_account_id' => $this->conta->id,
            'paid_on' => '2026-09-20',
        ])->assertSessionHasErrors('paid_on');

        $this->assertSame(5000.0, $this->disponivel());
    }

    // ===================== D) Sino sem dobrar a dívida =====================

    /** Recorrência de cartão em aberto, já dentro da janela fechada. */
    private function recorrenciaNoCartao(): Transaction
    {
        return $this->compra('2026-08-05', 149.70, 'Streaming', [
            'group_id' => (string) Str::uuid(),
            'recurring' => true,
        ]);
    }

    public function test_recorrencia_de_cartao_aparece_uma_vez_so_no_sino(): void
    {
        $this->recorrenciaNoCartao();

        $sino = app(FaturaService::class)->upcomingDue($this->user->id, 7);

        // Antes: 2 itens (a fatura vencida + a mesma recorrência solta) e o
        // total avisado ao usuário vinha dobrado.
        $this->assertCount(1, $sino);
        $this->assertSame('fatura', $sino->first()['tipo']);
        $this->assertSame(149.70, round((float) $sino->sum('valor'), 2));
        $this->assertTrue((bool) $sino->first()['vencida']);
    }

    public function test_recorrencia_fora_do_cartao_continua_no_sino(): void
    {
        Transaction::factory()->for($this->user)->for($this->conta)->expense()->create([
            'amount' => 80,
            'date' => '2026-09-18',
            'description' => 'Academia',
            'group_id' => (string) Str::uuid(),
            'recurring' => true,
        ]);

        $sino = app(FaturaService::class)->upcomingDue($this->user->id, 7);

        $this->assertCount(1, $sino);
        $this->assertSame('recorrente', $sino->first()['tipo']);
        $this->assertSame('Academia', $sino->first()['nome']);
    }

    // ===================== E) Excluir compra já quitada =====================

    private function pagarTudo(): void
    {
        $this->actingAs($this->user)->post(route('faturas.fatura.pagar', $this->cartao), [
            'pay_account_id' => $this->conta->id,
            'ciclo' => 'fechado',
        ])->assertSessionHasNoErrors();
    }

    public function test_excluir_compra_de_cartao_ja_paga_e_recusada(): void
    {
        $compra = $this->compra('2026-06-15', 300, 'Mercado de junho');
        $this->pagarTudo();

        $this->actingAs($this->user)
            ->delete(route('faturas.compra.destroy', $compra))
            ->assertSessionHasErrors('transaction');

        // A compra continua lá — senão a saída de caixa de R$ 300 ficaria órfã.
        $this->assertDatabaseHas('transactions', ['id' => $compra->id]);
        $this->assertSame(4700.0, $this->disponivel(), 'o pagamento não foi desfeito por um delete');
        $this->assertSame(1, Transaction::whereNotNull('settles_account_id')->count());
    }

    public function test_excluir_parcelado_com_todas_as_parcelas_pagas_e_recusado(): void
    {
        $grupo = (string) Str::uuid();
        $primeira = $this->compra('2026-06-15', 150, 'Geladeira', [
            'group_id' => $grupo, 'installment_no' => 1, 'installments' => 2,
        ]);
        $this->compra('2026-07-15', 150, 'Geladeira', [
            'group_id' => $grupo, 'installment_no' => 2, 'installments' => 2,
        ]);

        $this->pagarTudo();

        $this->actingAs($this->user)
            ->delete(route('faturas.compra.destroy', $primeira))
            ->assertSessionHasErrors('transaction');

        $this->assertSame(2, Transaction::where('group_id', $grupo)->count());
    }

    public function test_excluir_compra_de_cartao_em_aberto_continua_funcionando(): void
    {
        $compra = $this->compra('2026-09-15', 100, 'Padaria de hoje');

        $this->actingAs($this->user)
            ->delete(route('faturas.compra.destroy', $compra))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('transactions', ['id' => $compra->id]);
        $this->assertSame(0.0, Account::find($this->cartao->id)->committed);
    }

    public function test_excluir_despesa_paga_em_conta_corrente_continua_funcionando(): void
    {
        // Fora do cartão, `paid_at` é só registro: a despesa já descontou do
        // saldo no lançamento, e apagar devolve o dinheiro corretamente.
        $despesa = Transaction::factory()->for($this->user)->for($this->conta)->expense()->create([
            'amount' => 250,
            'date' => '2026-09-10',
            'description' => 'Luz',
            'paid_at' => '2026-09-10',
        ]);

        $this->actingAs($this->user)
            ->delete(route('faturas.compra.destroy', $despesa))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('transactions', ['id' => $despesa->id]);
        $this->assertSame(5000.0, $this->disponivel());
    }

    public function test_estornar_o_pagamento_devolve_a_divida_atrasada_para_a_fatura(): void
    {
        $this->duasFaturasAtrasadas();
        $this->pagarTudo();

        $quitacao = Transaction::whereNotNull('settles_account_id')->firstOrFail();

        $this->actingAs($this->user)
            ->delete(route('faturas.fatura.estornar', $quitacao))
            ->assertSessionHasNoErrors();

        $cartao = Account::find($this->cartao->id);

        $this->assertSame(5000.0, $this->disponivel(), 'o dinheiro volta');
        $this->assertSame(500.0, $cartao->closedInvoiceDue, 'a dívida atrasada volta inteira');
        $this->assertSame(600.0, $cartao->committed);
    }
}
