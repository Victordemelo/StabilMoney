<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SidebarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TED como método de pagamento (out/2026 — pedido do Victor: "métodos de pagamento, Pix ou
 * TED"). Mesma classe do Pix: método ESPELHO de UMA conta, sem saldo próprio — o dinheiro sai
 * da corrente/poupança escolhida, e o cheque especial dela entra se o saldo acabar.
 */
class TedComoMetodoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $corrente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->corrente = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'name' => 'Corrente Nubank', 'bank' => 'nubank', 'initial_balance' => 1000,
        ]);
    }

    private function ted(): Account
    {
        $this->actingAs($this->user)->post(route('accounts.store'), [
            'name' => 'TED do Nubank', 'type' => 'ted', 'bank' => 'nubank', 'pix_account_id' => $this->corrente->id,
        ])->assertSessionHasNoErrors();

        return Account::where('type', 'ted')->sole();
    }

    public function test_cadastra_a_ted_apontando_para_uma_conta_e_ela_espelha_o_saldo(): void
    {
        $ted = $this->ted();

        $this->assertSame($this->corrente->id, $ted->checking_account_id);
        $this->assertNull($ted->savings_account_id);
        $this->assertTrue($ted->espelhaConta());
        $this->assertTrue($ted->usaUmaContaSo());
        $this->assertSame('debito', $ted->classe());
        $this->assertSame('TED', $ted->typeLabel());
        $this->assertSame(1000.0, $ted->fresh()->available);
    }

    public function test_a_ted_sem_conta_vinculada_e_recusada(): void
    {
        $this->actingAs($this->user)->post(route('accounts.store'), [
            'name' => 'TED solta', 'type' => 'ted', 'bank' => 'nubank',
        ])->assertSessionHasErrors('checking_account_id');

        $this->assertSame(0, Account::where('type', 'ted')->count());
    }

    public function test_a_ted_nao_entra_no_patrimonio_nem_conta_o_dinheiro_duas_vezes(): void
    {
        $this->ted();

        $this->assertSame(1000.0, app(SidebarService::class)->build($this->user->id)['saldoTotal']);
    }

    public function test_o_select_manda_a_conta_e_lancar_direto_na_ted_e_recusado(): void
    {
        $ted = $this->ted();

        $opcao = Account::paymentOptions($this->user->id)->firstWhere('type', 'ted');
        $this->assertSame($this->corrente->id, $opcao->id);
        $this->assertStringContainsString('TED do Nubank', $opcao->rotulo);

        $this->actingAs($this->user)->post(route('transactions.store'), [
            'type' => 'expense', 'amount' => '100,00', 'account_id' => $ted->id, 'date' => now()->toDateString(),
        ])->assertSessionHasErrors('account_id');
        $this->assertSame(0, Transaction::count());
    }

    public function test_a_tela_mostra_o_grupo_pix_e_ted_e_o_cadastro_agrupa_os_tipos(): void
    {
        $this->ted();

        $this->actingAs($this->user)->get(route('accounts.index'))->assertOk()
            ->assertSee('>Pix e TED <span class="acct-grupo-n">1</span>', false)
            ->assertSee('disponível para TED');

        $html = $this->actingAs($this->user)->get(route('accounts.create'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '#<optgroup label="Contas de banco">.*Conta Corrente.*Conta Poupança.*</optgroup>\s*<optgroup label="Cartões">.*Cartão de Crédito.*Cartão de Débito.*</optgroup>\s*<optgroup label="Métodos de pagamento">.*>Pix<.*>TED<.*</optgroup>#s',
            $html,
        );
        $this->assertStringContainsString('Conta da chave Pix', $html);
    }
}
