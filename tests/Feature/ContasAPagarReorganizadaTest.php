<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contas a pagar reorganizada (out/2026):
 *  - "Despesas em conta" logo abaixo das contas fixas, antes dos cartões;
 *  - cada cartão é um <details> recolhível, que abre sozinho com fatura vencida;
 *  - remover uma despesa pede a SENHA (modal), conferida no servidor.
 */
class ContasAPagarReorganizadaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    private Account $cartao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(new \DateTimeImmutable('2026-10-04 10:00:00'));
        $this->user = User::factory()->create();
        $this->conta = Account::factory()->for($this->user)->create(['type' => 'checking', 'initial_balance' => 2000]);
        $this->cartao = Account::factory()->for($this->user)->creditCard()->create(['name' => 'Roxinho']);
    }

    private function despesaNaConta(float $valor = 50): Transaction
    {
        return Transaction::factory()->for($this->user)->expense()->create([
            'account_id' => $this->conta->id, 'amount' => $valor, 'date' => '2026-10-02',
            'description' => 'Padaria', 'paid_at' => '2026-10-02',
        ]);
    }

    public function test_despesas_em_conta_vem_antes_dos_cartoes_e_o_cartao_e_recolhivel(): void
    {
        $this->despesaNaConta();
        Transaction::factory()->for($this->user)->expense()->create([
            'account_id' => $this->cartao->id, 'amount' => 80, 'date' => '2026-10-03', 'description' => 'Livro', 'paid_at' => null,
        ]);

        $html = $this->actingAs($this->user)->get(route('faturas.index'))->assertOk()->getContent();

        $this->assertLessThan(strpos($html, '<details class="card fatura-card span12 fatura-recolhe"'), strpos($html, 'Despesas em conta'),
            '"Despesas em conta" tem de vir antes dos cartões.');
        // Sem fatura vencida, o cartão nasce FECHADO.
        $this->assertMatchesRegularExpression('#<details class="card fatura-card span12 fatura-recolhe"\s*>#', $html);
        $this->assertStringContainsString('<summary class="fatura-head">', $html);
    }

    public function test_cartao_com_fatura_vencida_abre_sozinho(): void
    {
        // Compra de agosto num cartão que fecha dia 10 e vence dia 20: fatura vencida.
        Transaction::factory()->for($this->user)->expense()->create([
            'account_id' => $this->cartao->id, 'amount' => 300, 'date' => '2026-08-05', 'description' => 'TV', 'paid_at' => null,
        ]);

        $html = $this->actingAs($this->user)->get(route('faturas.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<details class="card fatura-card span12 fatura-recolhe"\s+open\s*>#', $html);
        $this->assertStringContainsString('class="fh-vencida">vencida</em>', $html);
    }

    public function test_remover_sem_senha_ou_com_a_senha_errada_nao_remove_nada(): void
    {
        $despesa = $this->despesaNaConta();

        $this->actingAs($this->user)->from(route('faturas.index'))
            ->delete(route('faturas.compra.destroy', $despesa))
            ->assertSessionHasErrorsIn('remocao', ['password']);
        $this->actingAs($this->user)->from(route('faturas.index'))
            ->delete(route('faturas.compra.destroy', $despesa), ['password' => 'errada', '_alvo' => $despesa->id])
            ->assertSessionHasErrorsIn('remocao', ['password']);

        $this->assertModelExists($despesa);
    }

    public function test_com_a_senha_certa_remove(): void
    {
        $despesa = $this->despesaNaConta();

        $this->actingAs($this->user)->delete(route('faturas.compra.destroy', $despesa), ['password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertModelMissing($despesa);
    }

    public function test_a_tela_tem_o_modal_e_o_x_abre_o_modal_em_vez_do_confirm(): void
    {
        $despesa = $this->despesaNaConta();

        $html = $this->actingAs($this->user)->get(route('faturas.index'))->assertOk()->getContent();

        $this->assertStringContainsString('id="removerDespesaModal"', $html);
        $this->assertMatchesRegularExpression('#action="'.preg_quote(route('faturas.compra.destroy', $despesa), '#').'"\s+data-remover-despesa data-pergunta="Remover esta despesa\?"#', $html);
    }

    public function test_depois_da_senha_errada_o_modal_reabre_pelo_id_e_nunca_por_url_do_formulario(): void
    {
        $despesa = $this->despesaNaConta();

        $this->actingAs($this->user)->from(route('faturas.index'))
            ->delete(route('faturas.compra.destroy', $despesa), ['password' => 'errada', '_alvo' => (string) $despesa->id, '_pergunta' => 'Remover esta despesa?']);
        $html = $this->actingAs($this->user)->get(route('faturas.index'))->getContent();
        $this->assertStringContainsString('data-reabrir-acao="'.route('faturas.compra.destroy', $despesa).'"', $html);
        $this->assertStringContainsString('Senha incorreta. Nada foi removido.', $html);

        // Um `_alvo` que não é número não vira ação nenhuma (nada de URL vinda do formulário).
        $this->actingAs($this->user)->from(route('faturas.index'))
            ->delete(route('faturas.compra.destroy', $despesa), ['password' => 'errada', '_alvo' => 'https://outro.site/x']);
        $html = $this->actingAs($this->user)->get(route('faturas.index'))->getContent();
        $this->assertStringContainsString('data-reabrir-acao=""', $html);
        $this->assertStringNotContainsString('outro.site', $html);
    }
}
