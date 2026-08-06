<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FixedBill;
use App\Models\User;
use App\Services\FaturaService;
use App\Services\FixedBillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Terceiro stat de /faturas: "Contas a pagar".
 *
 * Substituiu "Limite disponível" — que já aparece no card de cada cartão. O que
 * faltava no topo era o tamanho do que ainda TEM de ser pago das contas fixas.
 *
 * ⚠️ O valor é o PREVISTO de cada conta. Luz e água variam todo mês, e o valor
 * real só nasce no pagamento (é ele que vai na transação). Por isso o card diz
 * "valor previsto" — prometer exatidão num número que muda seria enganar quem se
 * programa por ele.
 */
class ContasAPagarNoTopoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-06');

        $this->user = User::factory()->create();
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'initial_balance' => 10000,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function contaFixa(string $nome, float $valor, int $dia): FixedBill
    {
        return FixedBill::create([
            'user_id' => $this->user->id,
            'name' => $nome,
            'amount' => $valor,
            'due_day' => $dia,
            'account_id' => $this->conta->id,
            'starts_on' => '2026-07-01',
            'active' => true,
        ]);
    }

    private function stats(): array
    {
        return app(FaturaService::class)->build($this->user->id)['stats'];
    }

    public function test_soma_as_contas_fixas_em_aberto(): void
    {
        $this->contaFixa('Condomínio', 500, 10);
        $this->contaFixa('Aluguel', 1800, 15);

        $stats = $this->stats();

        // Julho e agosto de cada uma: as duas competências ainda em aberto.
        $this->assertGreaterThan(0, $stats['numContas']);
        $this->assertSame(
            round($stats['totalContas'], 2),
            round((float) $stats['totalContas'], 2),
        );
        // O total tem de bater com a soma das ocorrências abertas, não com o
        // cadastro: uma conta pode ter mais de um mês pendente.
        $abertas = app(FixedBillService::class)
            ->currentAndOverdue($this->user->id)
            ->where('paga', false);

        $this->assertSame(round((float) $abertas->sum('valor'), 2), $stats['totalContas']);
        $this->assertSame($abertas->count(), $stats['numContas']);
    }

    public function test_conta_paga_sai_do_total(): void
    {
        $bill = $this->contaFixa('Condomínio', 500, 10);
        $antes = $this->stats();

        $this->actingAs($this->user)->post(route('contas-fixas.pagar', [$bill, '2026-08']), [
            'amount' => '500,00',
            'account_id' => $this->conta->id,
            'paid_on' => '2026-08-06',
        ])->assertSessionHasNoErrors();

        $depois = $this->stats();

        $this->assertSame(round($antes['totalContas'] - 500, 2), $depois['totalContas']);
        $this->assertSame($antes['numContas'] - 1, $depois['numContas']);
    }

    public function test_conta_paga_com_valor_diferente_do_previsto(): void
    {
        // Conta de luz varia: o previsto é 200, mas veio 273,40. O total do topo é
        // sempre o PREVISTO — é o que existe antes de a conta chegar.
        $bill = $this->contaFixa('Luz', 200, 10);

        $this->assertSame(200.0, $this->stats()['totalContas'] - ($this->stats()['totalContas'] - 200.0));

        $this->actingAs($this->user)->post(route('contas-fixas.pagar', [$bill, '2026-08']), [
            'amount' => '273,40',
            'account_id' => $this->conta->id,
            'paid_on' => '2026-08-06',
        ])->assertSessionHasNoErrors();

        // O que SAIU do caixa é o valor real, não o previsto.
        $this->assertSame(10000.0 - 273.40, round($this->conta->fresh()->balance, 2));
    }

    public function test_conta_vencida_e_contada_a_parte(): void
    {
        // Vence dia 10; hoje é 06/08, então julho está vencido e agosto não.
        $this->contaFixa('Condomínio', 500, 10);

        $stats = $this->stats();

        $this->assertGreaterThan(0, $stats['contasVencidas']);
        $this->assertLessThanOrEqual($stats['numContas'], $stats['contasVencidas']);
    }

    public function test_sem_conta_fixa_o_card_mostra_zero(): void
    {
        $stats = $this->stats();

        $this->assertSame(0.0, $stats['totalContas']);
        $this->assertSame(0, $stats['numContas']);
        $this->assertSame(0, $stats['contasVencidas']);
    }

    public function test_a_tela_mostra_o_card_e_avisa_que_o_valor_e_previsto(): void
    {
        $this->contaFixa('Condomínio', 500, 10);

        $this->actingAs($this->user)->get(route('faturas.index'))
            ->assertOk()
            ->assertSee('Contas a pagar')
            // O aviso não é decorativo: sem ele o número parece exato.
            ->assertSee('valor previsto')
            ->assertDontSee('Limite disponível');
    }
}
