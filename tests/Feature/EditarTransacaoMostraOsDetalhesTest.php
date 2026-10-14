<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Editar transação" ganhou o painel "Detalhes" ao lado do formulário (out/2026): antes era
 * uma coluna estreita no meio da tela. O painel diz o que a linha É e — o mais útil — o que
 * TRAVA a edição dela, com a mesma regra do `update` (dita antes de a pessoa clicar em Salvar).
 */
class EditarTransacaoMostraOsDetalhesTest extends TestCase
{
    use RefreshDatabase;

    public function test_parcela_mostra_todas_as_parcelas_o_total_e_a_trava(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-10-04 10:00:00'));
        $user = User::factory()->create();
        $cartao = Account::factory()->for($user)->creditCard()->create(['name' => 'Roxinho', 'bank' => 'nubank']);
        $this->actingAs($user)->post(route('faturas.lancar'), [
            'description' => 'Sofá', 'amount' => '600,00', 'date' => '2026-10-04',
            'account_id' => $cartao->id, 'mode' => 'parcelado', 'installments' => 3,
        ])->assertSessionHasNoErrors();
        $segunda = Transaction::where('description', 'Sofá')->where('installment_no', 2)->firstOrFail();

        $html = $this->actingAs($user)->get(route('transactions.edit', $segunda))->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Detalhes da transação"', $html);
        $this->assertStringContainsString('0 de 3 pagas · total R$ 600,00', $html);
        $this->assertSame(3, substr_count($html, '<span>R$ 200,00</span>'), 'As três parcelas listadas.');
        $this->assertStringContainsString('Nenhuma parcela paga ainda', $html);
        // Compra parcelada (13/10/2026): o que vale para as 3 parcelas e o que não muda.
        $this->assertStringContainsString('O que você editar abaixo vale para as 3 parcelas', $html);
        $this->assertStringContainsString('Excluir a compra inteira', $html);
        $this->assertStringContainsString('Roxinho (Crédito · Nubank)', $html);
    }

    /**
     * Despesa na conta já paga (data de hoje): desde 14/10/2026 o valor muda na edição
     * (`ValorEditavelNaEdicaoTest`) — o painel não mostra trava, e o campo avisa que o saldo
     * acompanha o valor novo.
     */
    public function test_despesa_na_conta_diz_que_saiu_da_conta_e_que_o_saldo_acompanha_o_valor(): void
    {
        $user = User::factory()->create(['name' => 'Ana']);
        $conta = Account::factory()->for($user)->create(['type' => 'checking', 'initial_balance' => 500]);
        $linha = Transaction::factory()->for($user)->expense()->create([
            'account_id' => $conta->id, 'amount' => 30, 'made_by_user_id' => $user->id,
            'date' => now()->toDateString(), 'paid_at' => now(),
        ]);

        $this->actingAs($user)->get(route('transactions.edit', $linha))->assertOk()
            ->assertSee('Paga — saiu da conta')
            ->assertSee('<dt>Quem fez</dt><dd>Ana</dd>', false)
            ->assertDontSee('<p class="tx-d-aviso" role="note">', false)
            ->assertSee('Ao salvar, o saldo da conta acompanha o valor novo.');
    }

    /** Agendada (data futura): nada trava, o painel não mostra aviso nenhum. */
    public function test_despesa_agendada_nao_mostra_trava(): void
    {
        $user = User::factory()->create(['name' => 'Ana']);
        $conta = Account::factory()->for($user)->create(['type' => 'checking', 'initial_balance' => 500]);
        $linha = Transaction::factory()->for($user)->expense()->create([
            'account_id' => $conta->id, 'amount' => 30, 'made_by_user_id' => $user->id,
            'date' => now()->addDays(5)->toDateString(),
        ]);

        $this->actingAs($user)->get(route('transactions.edit', $linha))->assertOk()
            ->assertSee('<dt>Quem fez</dt><dd>Ana</dd>', false)
            ->assertDontSee('tx-d-aviso');
    }
}
