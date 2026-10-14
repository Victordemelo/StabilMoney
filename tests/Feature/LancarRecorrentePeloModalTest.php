<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "Repete todo mês" no modal Lançar (14/10/2026): o "Lançar despesa" de Contas a pagar passou a
 * ser o mesmo modal Lançar, e a compra recorrente no cartão (que só existia lá) veio junto. A
 * opção chega como `installments=recorrente` e grava a mesma linha do `faturas.lancar`: uma
 * despesa com `group_id` + `recurring`, cuja próxima ocorrência nasce quando a fatura fechar.
 */
class LancarRecorrentePeloModalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $cartao;

    private Account $corrente;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-01-15');
        $this->user = User::factory()->create();
        $this->cartao = Account::factory()->for($this->user)->creditCard()->create(['credit_limit' => 10000]);
        $this->corrente = Account::factory()->for($this->user)->create(['type' => 'checking', 'initial_balance' => 1000]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lancar(array $dados)
    {
        return $this->actingAs($this->user)->postJson(route('transactions.store'), $dados + [
            'type' => 'expense', 'amount' => '39,90', 'account_id' => $this->cartao->id,
            'date' => '2027-01-15', 'description' => 'Streaming',
        ]);
    }

    public function test_no_cartao_grava_uma_linha_que_abre_a_serie(): void
    {
        $this->lancar(['installments' => 'recorrente'])->assertSuccessful();

        $linha = Transaction::where('account_id', $this->cartao->id)->sole();
        $this->assertTrue((bool) $linha->recurring);
        $this->assertNotNull($linha->group_id);
        $this->assertNull($linha->installments);
        $this->assertSame('39.90', (string) $linha->amount);
        $this->assertNull($linha->paid_at);
    }

    public function test_fora_do_cartao_e_recusado(): void
    {
        $this->lancar(['installments' => 'recorrente', 'account_id' => $this->corrente->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recorrente');
        $this->assertSame(0, Transaction::count());
    }

    public function test_em_receita_e_recusado(): void
    {
        $this->lancar(['installments' => 'recorrente', 'type' => 'income', 'account_id' => $this->corrente->id])
            ->assertStatus(422);
        $this->assertSame(0, Transaction::count());
    }

    public function test_parcelado_e_recorrente_juntos_e_recusado(): void
    {
        $this->lancar(['installments' => 3, 'recorrente' => '1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recorrente');
        $this->assertSame(0, Transaction::count());
    }

    public function test_o_select_do_lancar_tem_a_opcao(): void
    {
        $html = $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('<option value="recorrente">Repete todo mês</option>', $html);
    }
}
