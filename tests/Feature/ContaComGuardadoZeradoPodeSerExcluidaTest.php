<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalContribution;
use App\Models\Investment;
use App\Models\InvestmentContribution;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Conta que JÁ TEVE aporte em meta ou investimento nunca mais podia ser excluída.
 *
 * `AccountController::destroy` recusava qualquer conta com `goalContributions()->exists()`
 * (e o mesmo para investimentos), e a mensagem mandava "resgatar o que está guardado por
 * ela primeiro". Só que o resgate não apaga o aporte — grava outra linha, de resgate —,
 * então a exclusão ficava bloqueada para sempre: a saída que a mensagem prometia não
 * funcionava.
 *
 * Regra nova: a conta sai quando o que ela guardou está ZERADO em cada meta e em cada
 * investimento (aportes − resgates DELA, cofrinho a cofrinho). Os aportes e resgates dela
 * são apagados junto — eles se anulam dentro de cada cofrinho, então nenhum total muda.
 * Com dinheiro ainda guardado, a recusa diz quanto e onde, e seguir o que ela diz destrava.
 */
class ContaComGuardadoZeradoPodeSerExcluidaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    private Account $outra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'name' => 'Conta Antiga', 'initial_balance' => 1000,
        ]);
        $this->outra = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'name' => 'Conta Nova', 'initial_balance' => 5000,
        ]);
    }

    private function excluir(Account $conta): TestResponse
    {
        return $this->actingAs($this->user)
            ->from(route('accounts.index'))
            ->delete(route('accounts.destroy', $conta));
    }

    private function movimentar(Goal|Investment $cofrinho, Account $conta, string $tipo, float $valor): void
    {
        $cofrinho->contributions()->create([
            'account_id' => $conta->id,
            'type' => $tipo,
            'amount' => $valor,
            'date' => now()->toDateString(),
        ]);
    }

    /** O cenário do relatório: aportou, resgatou tudo — e a conta ficava presa para sempre. */
    public function test_conta_que_aportou_e_resgatou_tudo_da_meta_pode_ser_excluida(): void
    {
        $meta = Goal::factory()->for($this->user)->create(['name' => 'Viagem']);
        // A meta também guarda dinheiro de OUTRA conta: esse total não pode mudar.
        $this->movimentar($meta, $this->outra, 'aporte', 500);
        $this->movimentar($meta, $this->conta, 'aporte', 300);
        $this->movimentar($meta, $this->conta, 'resgate', 300);
        $this->assertSame(500.0, $meta->fresh()->saved);

        $this->excluir($this->conta)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('accounts.index'))
            ->assertSessionHas('status', 'Conta removida.');

        $this->assertDatabaseMissing('accounts', ['id' => $this->conta->id]);
        // Os movimentos dela sumiram junto (a FK é restrictOnDelete) e se anulavam.
        $this->assertSame(0, GoalContribution::where('account_id', $this->conta->id)->count());
        $this->assertSame(500.0, $meta->fresh()->saved, 'O guardado da meta não pode mudar.');
        // O da outra conta segue intacto.
        $this->assertSame(1, GoalContribution::where('account_id', $this->outra->id)->count());
        $this->assertSame(4500.0, $this->outra->fresh()->available);
    }

    /** O mesmo com investimento (a outra FK restrictOnDelete). */
    public function test_conta_que_resgatou_tudo_do_investimento_pode_ser_excluida(): void
    {
        $cdb = Investment::factory()->for($this->user)->create(['name' => 'CDB']);
        $this->movimentar($cdb, $this->outra, 'aporte', 2000);
        $this->movimentar($cdb, $this->conta, 'aporte', 700);
        $this->movimentar($cdb, $this->conta, 'resgate', 200);
        $this->movimentar($cdb, $this->conta, 'resgate', 500);
        $this->assertSame(2000.0, $cdb->fresh()->aplicado);

        $this->excluir($this->conta)->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('accounts', ['id' => $this->conta->id]);
        $this->assertSame(0, InvestmentContribution::where('account_id', $this->conta->id)->count());
        $this->assertSame(2000.0, $cdb->fresh()->aplicado, 'O aplicado do investimento não pode mudar.');
    }

    /**
     * Com dinheiro ainda guardado, a recusa diz QUANTO e ONDE — e seguir o que ela diz
     * (resgatar para esta conta, pelas telas de Metas e Investimentos) destrava a exclusão
     * de verdade.
     */
    public function test_a_recusa_diz_quanto_e_onde_e_a_saida_que_ela_aponta_funciona(): void
    {
        $meta = Goal::factory()->for($this->user)->create(['name' => 'Viagem']);
        $cdb = Investment::factory()->for($this->user)->create(['name' => 'CDB']);
        $this->movimentar($meta, $this->conta, 'aporte', 300);
        $this->movimentar($meta, $this->conta, 'resgate', 100);
        $this->movimentar($cdb, $this->conta, 'aporte', 50);

        $this->excluir($this->conta)->assertSessionHasErrors('account');
        $this->assertDatabaseHas('accounts', ['id' => $this->conta->id]);

        $erro = session('errors')->first('account');
        $this->assertStringContainsString('R$ 200,00 na meta "Viagem"', $erro);
        $this->assertStringContainsString('R$ 50,00 no investimento "CDB"', $erro);
        $this->assertStringContainsString('Resgate esse valor de volta para esta conta (nas telas Metas e Investimentos)', $erro);

        // Segue a saída: resgata pelas telas, para ESTA conta.
        $this->actingAs($this->user)->post(route('metas.resgates.store', $meta), [
            'amount' => '200,00', 'account_id' => $this->conta->id,
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post(route('investimentos.resgates.store', $cdb), [
            'amount' => '50,00', 'account_id' => $this->conta->id,
        ])->assertSessionHasNoErrors();

        $this->excluir($this->conta)->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('accounts', ['id' => $this->conta->id]);
        $this->assertSame(0.0, $meta->fresh()->saved);
        $this->assertSame(0.0, $cdb->fresh()->aplicado);
    }

    /**
     * A régua é cofrinho a cofrinho, não o reservado total da conta: +R$ 100 numa meta e
     * −R$ 100 em outra (dado antigo, de antes de o resgate ficar preso à conta de origem)
     * somam zero, mas apagar essas linhas mudaria o guardado das DUAS metas.
     */
    public function test_zerado_no_total_mas_nao_em_cada_meta_continua_bloqueado(): void
    {
        $viagem = Goal::factory()->for($this->user)->create(['name' => 'Viagem']);
        $carro = Goal::factory()->for($this->user)->create(['name' => 'Carro']);
        $this->movimentar($carro, $this->outra, 'aporte', 400);
        $this->movimentar($viagem, $this->conta, 'aporte', 100);
        $this->movimentar($carro, $this->conta, 'resgate', 100);
        $this->assertSame(0.0, $this->conta->fresh()->reserved, 'Pré-condição: zerado no total.');

        $this->excluir($this->conta)->assertSessionHasErrors('account');

        $this->assertDatabaseHas('accounts', ['id' => $this->conta->id]);
        $this->assertSame(100.0, $viagem->fresh()->saved);
        $this->assertSame(300.0, $carro->fresh()->saved);

        $erro = session('errors')->first('account');
        $this->assertStringContainsString('R$ 100,00 na meta "Viagem"', $erro);
        $this->assertStringContainsString('R$ 100,00 a mais na meta "Carro"', $erro);
        $this->assertStringContainsString('(na tela Metas)', $erro);
    }

    /** Continua valendo: conta com lançamentos não sai (apagaria o histórico). */
    public function test_conta_com_lancamentos_continua_sem_poder_ser_excluida_mesmo_com_o_guardado_zerado(): void
    {
        $meta = Goal::factory()->for($this->user)->create();
        $this->movimentar($meta, $this->conta, 'aporte', 300);
        $this->movimentar($meta, $this->conta, 'resgate', 300);
        Transaction::factory()->for($this->user)->for($this->conta)->expense()->create(['amount' => 10]);

        $this->excluir($this->conta)->assertSessionHasErrors('account');

        $this->assertDatabaseHas('accounts', ['id' => $this->conta->id]);
        $this->assertSame(2, GoalContribution::where('account_id', $this->conta->id)->count(), 'Nada pode ter sido apagado.');
        $this->assertStringContainsString('possui transações', session('errors')->first('account'));
    }

    /** A mensagem nunca cita o cofrinho de outra família (nome escopado na família da conta). */
    public function test_conta_de_outra_familia_continua_recebendo_403(): void
    {
        $intruso = User::factory()->create();

        $this->actingAs($intruso)->delete(route('accounts.destroy', $this->conta))->assertForbidden();

        $this->assertDatabaseHas('accounts', ['id' => $this->conta->id]);
    }
}
