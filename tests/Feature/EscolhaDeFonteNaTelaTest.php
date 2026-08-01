<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FixedBill;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A-2 da auditoria de 27/07/2026: o HTTP 409 "de onde sai esse dinheiro?" não
 * tinha consumidor nem em /faturas nem nas contas fixas.
 *
 * `RequiresFundingChoice` fazia `back()->with('fonteNecessaria', ...)` e
 * NENHUMA view lia essa chave. Efeito prático invertido: quem TEM cheque
 * especial clicava em "Confirmar pagamento", voltava para /faturas sem
 * mensagem nenhuma e a fatura continuava vencida — enquanto quem não tinha
 * fonte alguma conseguia pagar (a conta ficava negativa, como decidido).
 *
 * O caminho AJAX (fetch + `pedirFonte()`) não tem como ser testado aqui (o
 * projeto não tem suíte JS), então estes testes cobrem o CONTRATO que ele usa
 * (409 com payload) e o fallback SEM JS, que é o que degrada bem na PWA.
 */
class EscolhaDeFonteNaTelaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['is_admin' => true, 'account_owner_id' => null]);

        // R$ 50 em conta e R$ 500 de cheque especial: é o cenário exato do
        // achado — há fonte que cobre, então o servidor PERGUNTA em vez de pagar.
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'name' => 'Conta Corrente',
            'initial_balance' => 50,
            'overdraft_limit' => 500,
        ]);
    }

    /** Cartão com uma fatura de R$ 300 em aberto no ciclo corrente. */
    private function cartaoComFatura(float $valor = 300): Account
    {
        $card = Account::factory()->for($this->user)->creditCard()->create([
            'closing_day' => 10,
            'due_day' => 20,
            'credit_limit' => 5000,
        ]);

        Transaction::create([
            'user_id' => $this->user->id,
            'account_id' => $card->id,
            'type' => 'expense',
            'amount' => $valor,
            'date' => CarbonImmutable::today()->toDateString(),
        ]);

        return $card;
    }

    private function contaFixa(float $valor = 300): FixedBill
    {
        return FixedBill::create([
            'user_id' => $this->user->id,
            'name' => 'Aluguel',
            'amount' => $valor,
            'due_day' => 5,
            'account_id' => $this->conta->id,
            'starts_on' => CarbonImmutable::today()->startOfMonth()->toDateString(),
            'active' => true,
        ]);
    }

    // ---------------------------------------------------------------- fatura

    /**
     * O BUG: POST comum (sem JS) volta para /faturas e a tela não diz NADA.
     * Depois da correção a página traz o bloco de escolha da fonte.
     */
    public function test_pagar_fatura_sem_js_mostra_a_escolha_de_fonte_na_tela(): void
    {
        $card = $this->cartaoComFatura();

        $this->actingAs($this->user)
            ->post(route('faturas.fatura.pagar', $card), ['pay_account_id' => $this->conta->id])
            ->assertRedirect()
            ->assertSessionHas('fonteNecessaria');

        // Nada foi pago enquanto ele não escolhe.
        $this->assertSame(50.0, Account::find($this->conta->id)->available);

        $tela = $this->actingAs($this->user)->get(route('faturas.index'))->assertOk();

        // `data-funding-fallback` é o bloco server-rendered — o modal do shell
        // também diz "De onde sai esse dinheiro?", mas fica fechado e vazio.
        $tela->assertSee('data-funding-fallback', false);
        // O bloco é um formulário de verdade: reenvia para a MESMA rota, com os
        // campos originais e o `funding_source` escolhido.
        $tela->assertSee(route('faturas.fatura.pagar', $card), false);
        $tela->assertSee('name="funding_source"', false);
        $tela->assertSee('cheque_especial', false);
        // Os campos da requisição original voltam como hidden (replay).
        $tela->assertSee('name="pay_account_id"', false);
    }

    /** O payload em sessão precisa levar rota + campos para o replay sem JS. */
    public function test_payload_da_sessao_leva_acao_e_campos_para_reenvio(): void
    {
        $card = $this->cartaoComFatura();

        $resp = $this->actingAs($this->user)
            ->post(route('faturas.fatura.pagar', $card), ['pay_account_id' => $this->conta->id]);

        $payload = $resp->getSession()->get('fonteNecessaria');

        $this->assertIsArray($payload);
        $this->assertSame(route('faturas.fatura.pagar', $card), $payload['acao'] ?? null);
        $this->assertSame('POST', $payload['metodo'] ?? null);
        $this->assertSame((string) $this->conta->id, (string) ($payload['campos']['pay_account_id'] ?? null));
        // Segurança: o token da sessão nunca é reemitido pelo payload.
        $this->assertArrayNotHasKey('_token', $payload['campos']);
    }

    /** Confirmando a fonte pelo bloco (reenvio), a fatura é paga de verdade. */
    public function test_confirmar_a_fonte_no_bloco_paga_a_fatura(): void
    {
        $card = $this->cartaoComFatura();

        $this->actingAs($this->user)
            ->post(route('faturas.fatura.pagar', $card), ['pay_account_id' => $this->conta->id])
            ->assertSessionHas('fonteNecessaria');

        $this->actingAs($this->user)->post(route('faturas.fatura.pagar', $card), [
            'pay_account_id' => $this->conta->id,
            'funding_source' => 'cheque_especial',
        ])->assertSessionHasNoErrors();

        $this->assertSame(-250.0, Account::find($this->conta->id)->available);
        $this->assertNotNull(Transaction::where('account_id', $card->id)->first()->paid_at);
    }

    // ----------------------------------------------------------- conta fixa

    /** Mesmo bug, mesma tela: pagar conta fixa voltava sem explicação. */
    public function test_pagar_conta_fixa_sem_js_mostra_a_escolha_de_fonte_na_tela(): void
    {
        $bill = $this->contaFixa();
        $competencia = CarbonImmutable::today()->format('Y-m');

        $this->actingAs($this->user)
            ->post(route('contas-fixas.pagar', [$bill, $competencia]), [
                'account_id' => $this->conta->id,
                'amount' => '300,00',
            ])
            ->assertRedirect()
            ->assertSessionHas('fonteNecessaria');

        $this->assertSame(0, Transaction::where('fixed_bill_id', $bill->id)->count());

        $tela = $this->actingAs($this->user)->get(route('faturas.index'))->assertOk();

        $tela->assertSee('data-funding-fallback', false);
        $tela->assertSee(route('contas-fixas.pagar', [$bill, $competencia]), false);
        $tela->assertSee('name="funding_source"', false);
        // O valor digitado volta no replay (o usuário não redigita).
        $tela->assertSee('name="amount"', false);
    }

    public function test_confirmar_a_fonte_paga_a_conta_fixa(): void
    {
        $bill = $this->contaFixa();
        $competencia = CarbonImmutable::today()->format('Y-m');

        $this->actingAs($this->user)->post(route('contas-fixas.pagar', [$bill, $competencia]), [
            'account_id' => $this->conta->id,
            'amount' => '300,00',
            'funding_source' => 'cheque_especial',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Transaction::where('fixed_bill_id', $bill->id)->count());
        $this->assertSame(-250.0, Account::find($this->conta->id)->available);
    }

    // ------------------------------------------------- contrato do AJAX (409)

    /**
     * O caminho com JS: os dois formulários passam a enviar por fetch com
     * `Accept: application/json`. O contrato que o `sm/faturas.js` consome é
     * este — 409 com `precisa_fonte` e as fontes montadas.
     */
    public function test_pagamentos_respondem_409_com_payload_para_o_ajax(): void
    {
        $card = $this->cartaoComFatura();

        $r = $this->actingAs($this->user)
            ->postJson(route('faturas.fatura.pagar', $card), ['pay_account_id' => $this->conta->id])
            ->assertStatus(409);

        $r->assertJson(['precisa_fonte' => true]);
        $this->assertEquals(250.0, $r->json('fonte.faltante'));
        $this->assertSame('cheque_especial', $r->json('fonte.fontes.0.id'));

        $bill = $this->contaFixa();
        $this->actingAs($this->user)
            ->postJson(route('contas-fixas.pagar', [$bill, CarbonImmutable::today()->format('Y-m')]), [
                'account_id' => $this->conta->id,
                'amount' => '300,00',
            ])
            ->assertStatus(409)
            ->assertJson(['precisa_fonte' => true]);
    }
    /**
     * O replay sem JS precisa preservar o MÉTODO da requisição original.
     *
     * Editar uma transação é PATCH; se o formulário de escolha postar POST puro na rota
     * de update, a resposta é 405 e o usuário fica sem saída — o mesmo beco sem saída que
     * esta feature veio resolver.
     */
    public function test_fallback_preserva_o_metodo_original_da_requisicao(): void
    {
        $conta = \App\Models\Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'initial_balance' => 50,
            'overdraft_limit' => 500,
        ]);
        $categoria = \App\Models\Category::factory()->for($this->user)->expense()->create();

        $transacao = \App\Models\Transaction::factory()->for($this->user)->create([
            'account_id' => $conta->id,
            'category_id' => $categoria->id,
            'type' => 'expense',
            'amount' => 10,
            'date' => now()->toDateString(),
        ]);

        // PATCH que estoura o disponível e precisa de escolha de fonte.
        $resposta = $this->actingAs($this->user)
            ->from(route('transactions.index'))
            ->patch(route('transactions.update', $transacao), [
                'type' => 'expense',
                'amount' => '300,00',
                'date' => now()->toDateString(),
                'account_id' => $conta->id,
                'category_id' => $categoria->id,
            ]);

        $payload = $resposta->getSession()->get('fonteNecessaria');

        $this->assertIsArray($payload, 'A escolha de fonte deveria ter sido oferecida.');
        $this->assertSame(
            'PATCH',
            $payload['metodo'] ?? null,
            'O payload precisa levar o método original para o replay não virar 405.',
        );

        // E o HTML renderizado tem de emitir o _method correspondente.
        $html = $this->actingAs($this->user)->get(route('transactions.index'))->getContent();

        if (str_contains($html, 'fonte-fallback') || str_contains($html, 'funding_source')) {
            $this->assertStringContainsString(
                'name="_method" value="PATCH"',
                $html,
                'O formulário de replay não emite o método original.',
            );
        }
    }
}
