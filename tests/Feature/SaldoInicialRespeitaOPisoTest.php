<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Goal;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * F-4 da auditoria de 02/09/2026: editar o `initial_balance` não pode furar o
 * piso do cheque especial.
 *
 * O modelo de dinheiro v3 promete `available >= −overdraft_limit`. A regra que
 * protegia o limite (`regraDoChequeEspecialEmUso`) cobria só um dos dois campos
 * que compõem esse invariante: reduzir o saldo inicial de uma conta com dinheiro
 * aplicado (ou com cheque especial em uso) produzia um disponível abaixo do piso —
 * um estado que nenhum lançamento consegue criar, porque o `SpendingGuard` o
 * recusaria.
 */
class SaldoInicialRespeitaOPisoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->user);
    }

    private function editar(Account $conta, array $extra): TestResponse
    {
        return $this->patch(route('accounts.update', $conta), array_merge([
            'name' => $conta->name,
            'type' => $conta->type,
            'bank' => 'nubank',
            'initial_balance' => number_format((float) $conta->initial_balance, 2, ',', '.'),
            'overdraft_limit' => number_format((float) $conta->overdraft_limit, 2, ',', '.'),
        ], $extra));
    }

    /** Conta de R$ 1.000 com R$ 800 guardados numa meta: disponível 200. */
    private function contaComAporte(float $initial = 1000, float $aporte = 800, float $cheque = 0): Account
    {
        $conta = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'initial_balance' => $initial,
            'overdraft_limit' => $cheque,
        ]);

        Goal::factory()->for($this->user)->create()->contributions()->create([
            'account_id' => $conta->id,
            'type' => 'aporte',
            'amount' => $aporte,
            'date' => now()->toDateString(),
        ]);

        return $conta;
    }

    /** Cenário A: 1.000 com 800 aplicados, cheque 0. `initial_balance = 100` deixaria −700 sem cheque especial. */
    public function test_reduzir_o_saldo_inicial_abaixo_do_reservado_sem_cheque_especial_e_recusado(): void
    {
        $conta = $this->contaComAporte();

        $this->editar($conta, ['initial_balance' => '100,00'])
            ->assertSessionHasErrors('initial_balance');

        $this->assertSame(1000.00, round((float) $conta->fresh()->initial_balance, 2), 'O saldo inicial não podia ter mudado.');
        $this->assertSame(200.00, $conta->fresh()->available);

        $erro = session('errors')->first('initial_balance');
        $this->assertStringContainsString('−R$ 700,00', $erro, 'A mensagem tem de dizer em quanto a conta ficaria.');
    }

    /** Cenário B: 1.000, cheque 500, despesa de 1.300 via cheque (disp. −300). `initial_balance = 0` daria −1.300 com piso −500. */
    public function test_reduzir_o_saldo_inicial_com_cheque_especial_em_uso_nao_pode_passar_do_piso(): void
    {
        $conta = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'initial_balance' => 1000,
            'overdraft_limit' => 500,
        ]);
        Transaction::factory()->for($this->user)->for($conta)->expense()->create(['amount' => 1300]);

        $this->assertSame(-300.00, $conta->fresh()->available);

        $this->editar($conta, ['initial_balance' => '0'])
            ->assertSessionHasErrors('initial_balance');

        $this->assertSame(1000.00, round((float) $conta->fresh()->initial_balance, 2));

        $erro = session('errors')->first('initial_balance');
        $this->assertStringContainsString('−R$ 1.300,00', $erro);
        $this->assertStringContainsString('R$ 500,00', $erro, 'Cita o limite que seria furado.');

        // Reduzir até EXATAMENTE o piso é legítimo: 800 − 1.300 = −500 = −limite.
        $this->editar($conta, ['initial_balance' => '800,00'])
            ->assertSessionHasNoErrors();

        $this->assertSame(-500.00, $conta->fresh()->available);
    }

    /** Cenário C: uma redução que mantém `available >= −limite` passa. */
    public function test_reduzir_o_saldo_inicial_mantendo_o_disponivel_acima_do_piso_e_aceito(): void
    {
        $conta = $this->contaComAporte();

        // 800 − 800 reservados = 0: exatamente no piso (sem cheque especial).
        $this->editar($conta, ['initial_balance' => '800,00'])
            ->assertSessionHasNoErrors();

        $this->assertSame(800.00, round((float) $conta->fresh()->initial_balance, 2));
        $this->assertSame(0.00, $conta->fresh()->available);
    }

    /** A projeção usa o limite NOVO: reduzir o saldo e abrir cheque especial na mesma edição é coerente. */
    public function test_a_projecao_usa_o_limite_novo_do_cheque_especial(): void
    {
        $conta = $this->contaComAporte();

        // 100 − 800 = −700, e o limite novo de 700 cobre exatamente isso.
        $this->editar($conta, ['initial_balance' => '100,00', 'overdraft_limit' => '700,00'])
            ->assertSessionHasNoErrors();

        $this->assertSame(-700.00, $conta->fresh()->available);
        $this->assertSame(700.00, round((float) $conta->fresh()->overdraft_limit, 2));

        // Com limite de 699,99 já não cabe.
        $this->editar($conta->fresh(), ['initial_balance' => '99,00', 'overdraft_limit' => '699,99'])
            ->assertSessionHasErrors('initial_balance');
    }

    /** Aumentar ou manter o saldo inicial nunca é barrado — nem numa conta que já esteja abaixo do piso. */
    public function test_manter_ou_aumentar_o_saldo_inicial_e_sempre_permitido(): void
    {
        $conta = $this->contaComAporte();

        // Estado "impossível" plantado direto no banco (dado antigo, antes da regra).
        $conta->forceFill(['initial_balance' => 100])->save();
        $this->assertSame(-700.00, $conta->fresh()->available);

        $this->editar($conta->fresh(), ['initial_balance' => '100,00', 'name' => 'Renomeada'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Renomeada', $conta->fresh()->name);

        $this->editar($conta->fresh(), ['initial_balance' => '500,00'])
            ->assertSessionHasNoErrors();
        $this->assertSame(-300.00, $conta->fresh()->available);
    }

    /** Poupança também tem saldo próprio: a mesma regra vale (piso 0, sem cheque especial). */
    public function test_poupanca_segue_a_mesma_regra(): void
    {
        $conta = Account::factory()->for($this->user)->create([
            'type' => 'savings',
            'initial_balance' => 1000,
            'overdraft_limit' => 0,
        ]);
        Goal::factory()->for($this->user)->create()->contributions()->create([
            'account_id' => $conta->id,
            'type' => 'aporte',
            'amount' => 800,
            'date' => now()->toDateString(),
        ]);

        $this->editar($conta, ['initial_balance' => '799,99'])
            ->assertSessionHasErrors('initial_balance');

        $this->editar($conta, ['initial_balance' => '800,00'])
            ->assertSessionHasNoErrors();
    }

    /** Cenário D: cartão não tem saldo inicial — a regra não pode nem olhar para ele. */
    public function test_cartao_de_credito_nao_e_afetado(): void
    {
        $cartao = Account::factory()->for($this->user)->creditCard()->create([
            'credit_limit' => 3000,
            'closing_day' => 5,
            'due_day' => 15,
        ]);
        // Fatura em aberto: o `available` de um cartão é naturalmente negativo.
        Transaction::factory()->for($this->user)->for($cartao)->expense()->create(['amount' => 2500]);

        $this->patch(route('accounts.update', $cartao), [
            'name' => 'Cartão Renomeado',
            'type' => 'credit_card',
            'bank' => 'nubank',
            'credit_limit' => '3.000,00',
            'closing_day' => 5,
            'due_day' => 15,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Cartão Renomeado', $cartao->fresh()->name);
    }

    /** Pelo modal (JSON) o 422 chega no mesmo formato dos demais erros do Form Request. */
    public function test_pelo_modal_o_erro_vem_como_422_json(): void
    {
        $conta = $this->contaComAporte();

        $this->patchJson(route('accounts.update', $conta), [
            'name' => $conta->name,
            'type' => 'checking',
            'bank' => 'nubank',
            'initial_balance' => '100,00',
            'overdraft_limit' => '0',
        ])->assertStatus(422)->assertJsonValidationErrors('initial_balance');
    }

    /**
     * A regra roda na validação, ANTES da policy do controller. Sem a guarda de
     * posse, o texto do erro entregava o saldo de conta alheia ("ficaria em
     * −R$ 123,45") a quem chutasse um id — uma sonda. Conta de outra família
     * recebe o 403 de sempre, sem número nenhum.
     */
    public function test_conta_de_outra_familia_nao_vira_sonda_de_saldo(): void
    {
        $outro = User::factory()->create(['is_admin' => true]);
        $alheia = Account::factory()->for($outro)->create([
            'type' => 'checking', 'initial_balance' => 1000, 'overdraft_limit' => 0,
        ]);
        Goal::factory()->for($outro)->create()->contributions()->create([
            'account_id' => $alheia->id, 'type' => 'aporte', 'amount' => 800, 'date' => now()->toDateString(),
        ]);

        $r = $this->editar($alheia, ['initial_balance' => '0,00']);

        $r->assertForbidden();
        $this->assertSame(1000.0, (float) $alheia->fresh()->initial_balance);
    }
}
