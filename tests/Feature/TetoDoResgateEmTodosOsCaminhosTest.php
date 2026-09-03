<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\FixedBill;
use App\Models\Investment;
use App\Models\Transaction;
use App\Models\User;
use App\Support\FundingSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * F-3 da auditoria de 02/09/2026: o teto do resgate aprovado (`funding_max_amount`)
 * vale em TODOS os caminhos de gasto, não só em `transactions.store`.
 *
 * O front já enviava o campo nos quatro caminhos (`funding.js`, `funding-modal`);
 * só um o consumia. Nos outros três, um pagamento que dormiu na fila enquanto o
 * disponível caía resgatava o que fosse preciso — não o número que a pessoa viu
 * e aprovou no modal. A `TetoDoResgateAprovadoTest` cobre o `store`; aqui, os
 * demais: edição no Histórico, lançamento em /faturas, pagamento de fatura de
 * cartão e pagamento de conta fixa.
 *
 * Cenário comum: conta R$ 1.000 com R$ 900 aplicados (disponível 100). Gasto de
 * R$ 300 → faltante real 200. Teto de 100 → 409 sem gravar nada; teto de 200 → grava.
 */
class TetoDoResgateEmTodosOsCaminhosTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    private Category $categoria;

    private Investment $investimento;

    protected function setUp(): void
    {
        parent::setUp();
        // Dia 5: o ciclo aberto do cartão (fecha dia 10) é (10/07, 10/08]; uma
        // compra em 01/08 cai nele e a fatura é pagável. A conta fixa que vence
        // dia 10 ainda não venceu (não é obrigação, mas o teto vale igual).
        Carbon::setTestNow('2026-08-05');

        $this->user = User::factory()->create(['is_admin' => true]);

        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'name' => 'Corrente',
            'initial_balance' => 1000,
            'overdraft_limit' => 0,
        ]);

        $this->categoria = Category::factory()->for($this->user)->expense()->create();

        $this->investimento = Investment::create([
            'user_id' => $this->user->id,
            'name' => 'CDB',
            'classe' => array_key_first(Investment::CLASSES),
        ]);

        $this->investimento->contributions()->create([
            'account_id' => $this->conta->id,
            'type' => 'aporte',
            'amount' => 900.00,
            'date' => '2026-08-01',
        ]);

        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function aplicado(): float
    {
        return round((float) $this->investimento->fresh()->aplicado, 2);
    }

    private function resgate(float $teto): array
    {
        return [
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
            'funding_investment_id' => $this->investimento->id,
            'funding_max_amount' => number_format($teto, 2, '.', ''),
        ];
    }

    // ================= transactions.update =================

    /** Despesa de R$ 100 já gravada (disponível 0); editar para 300 → faltante 200. */
    private function despesaExistente(): Transaction
    {
        return Transaction::factory()->for($this->user)->create([
            'account_id' => $this->conta->id,
            'category_id' => $this->categoria->id,
            'type' => 'expense',
            'amount' => 100,
            'date' => '2026-08-05',
            'description' => 'Mercado',
        ]);
    }

    private function edicao(array $extra = []): array
    {
        return array_merge([
            'type' => 'expense',
            'amount' => '300,00',
            'account_id' => $this->conta->id,
            'category_id' => $this->categoria->id,
            'date' => '2026-08-05',
            'description' => 'Mercado',
        ], $extra);
    }

    public function test_edicao_nao_resgata_alem_do_teto(): void
    {
        $despesa = $this->despesaExistente();

        $this->patchJson(route('transactions.update', $despesa), $this->edicao($this->resgate(100)))
            ->assertStatus(409)
            ->assertJsonPath('precisa_fonte', true);

        $this->assertSame(900.00, $this->aplicado(), 'A edição resgatou além do que o usuário aprovou.');
        $this->assertSame('100.00', (string) $despesa->fresh()->amount);
        $this->assertNull($despesa->fresh()->funding_source);
    }

    public function test_edicao_dentro_do_teto_grava(): void
    {
        $despesa = $this->despesaExistente();

        $this->patch(route('transactions.update', $despesa), $this->edicao($this->resgate(200)))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('transactions.index'));

        $this->assertSame(700.00, $this->aplicado());
        $this->assertSame('300.00', (string) $despesa->fresh()->amount);
        $this->assertSame('200.00', (string) $despesa->fresh()->funding_amount);
    }

    // ================= faturas.lancar =================

    private function lancamento(array $extra = []): array
    {
        return array_merge([
            'description' => 'Mercado',
            'amount' => '300,00',
            'date' => '2026-08-05',
            'account_id' => $this->conta->id,
            'category_id' => $this->categoria->id,
            'mode' => 'avista',
        ], $extra);
    }

    public function test_lancar_em_faturas_nao_resgata_alem_do_teto(): void
    {
        // Sem JS: o 409 vira redirect com `fonteNecessaria` na sessão.
        $this->from(route('faturas.index'))
            ->post(route('faturas.lancar'), $this->lancamento($this->resgate(100)))
            ->assertRedirect(route('faturas.index'))
            ->assertSessionHas('fonteNecessaria');

        $this->assertSame(900.00, $this->aplicado());
        $this->assertDatabaseMissing('transactions', ['description' => 'Mercado']);
    }

    public function test_lancar_em_faturas_dentro_do_teto_grava(): void
    {
        $this->post(route('faturas.lancar'), $this->lancamento($this->resgate(200)))
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('fonteNecessaria');

        $this->assertSame(700.00, $this->aplicado());
        $this->assertDatabaseHas('transactions', [
            'description' => 'Mercado',
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
        ]);
    }

    public function test_lancar_em_faturas_valida_o_teto_como_dinheiro(): void
    {
        $this->post(route('faturas.lancar'), $this->lancamento(array_merge($this->resgate(100), ['funding_max_amount' => '1e12'])))
            ->assertSessionHasErrors('funding_max_amount');

        $this->assertSame(900.00, $this->aplicado());
    }

    // ================= faturas.fatura.pagar =================

    /** Cartão com compra de R$ 300 em aberto no ciclo corrente. */
    private function cartaoComFatura(): Account
    {
        $cartao = Account::factory()->for($this->user)->create([
            'type' => 'credit_card',
            'name' => 'Nubank',
            'credit_limit' => 5000,
            'closing_day' => 10,
            'due_day' => 20,
        ]);

        Transaction::factory()->for($this->user)->create([
            'account_id' => $cartao->id,
            'category_id' => $this->categoria->id,
            'type' => 'expense',
            'amount' => 300,
            'date' => '2026-08-01',
            'description' => 'Compra no cartão',
        ]);

        return $cartao;
    }

    public function test_pagar_fatura_nao_resgata_alem_do_teto(): void
    {
        $cartao = $this->cartaoComFatura();

        $this->postJson(route('faturas.fatura.pagar', $cartao), ['pay_account_id' => $this->conta->id] + $this->resgate(100))
            ->assertStatus(409)
            ->assertJsonPath('precisa_fonte', true);

        $this->assertSame(900.00, $this->aplicado());
        $this->assertDatabaseMissing('transactions', ['settles_account_id' => $cartao->id]);
        $this->assertNull(Transaction::where('description', 'Compra no cartão')->firstOrFail()->paid_at);
    }

    public function test_pagar_fatura_dentro_do_teto_grava(): void
    {
        $cartao = $this->cartaoComFatura();

        $this->post(route('faturas.fatura.pagar', $cartao), ['pay_account_id' => $this->conta->id] + $this->resgate(200))
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('fonteNecessaria');

        $this->assertSame(700.00, $this->aplicado());
        $this->assertDatabaseHas('transactions', [
            'settles_account_id' => $cartao->id,
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
        ]);
        $this->assertNotNull(Transaction::where('description', 'Compra no cartão')->firstOrFail()->paid_at);
    }

    public function test_pagar_fatura_valida_o_teto_como_dinheiro(): void
    {
        $cartao = $this->cartaoComFatura();

        $this->postJson(route('faturas.fatura.pagar', $cartao), array_merge(
            ['pay_account_id' => $this->conta->id],
            $this->resgate(100),
            ['funding_max_amount' => '1e12'],
        ))
            ->assertStatus(422)
            ->assertJsonValidationErrors('funding_max_amount');
    }

    // ================= contas-fixas.pagar =================

    private function condominio(): FixedBill
    {
        return FixedBill::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Condomínio',
            'amount' => 300,
            'due_day' => 10,
            'account_id' => $this->conta->id,
            'starts_on' => '2026-08-01',
            'active' => true,
        ]);
    }

    public function test_pagar_conta_fixa_nao_resgata_alem_do_teto(): void
    {
        $bill = $this->condominio();

        $this->from(route('faturas.index'))
            ->post(route('contas-fixas.pagar', [$bill, '2026-08']), [
                'account_id' => $this->conta->id,
                'amount' => '300,00',
            ] + $this->resgate(100))
            ->assertRedirect(route('faturas.index'))
            ->assertSessionHas('fonteNecessaria');

        $this->assertSame(900.00, $this->aplicado());
        $this->assertDatabaseMissing('transactions', ['fixed_bill_id' => $bill->id]);
    }

    public function test_pagar_conta_fixa_dentro_do_teto_grava(): void
    {
        $bill = $this->condominio();

        $this->post(route('contas-fixas.pagar', [$bill, '2026-08']), [
            'account_id' => $this->conta->id,
            'amount' => '300,00',
        ] + $this->resgate(200))
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('fonteNecessaria');

        $this->assertSame(700.00, $this->aplicado());
        $this->assertDatabaseHas('transactions', [
            'fixed_bill_id' => $bill->id,
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
        ]);
    }

    public function test_pagar_conta_fixa_valida_o_teto_como_dinheiro(): void
    {
        $bill = $this->condominio();

        $this->post(route('contas-fixas.pagar', [$bill, '2026-08']), array_merge(
            ['account_id' => $this->conta->id, 'amount' => '300,00'],
            $this->resgate(100),
            ['funding_max_amount' => '1e12'],
        ))
            ->assertSessionHasErrors('funding_max_amount');

        $this->assertSame(900.00, $this->aplicado());
    }
}
