<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Investment;
use App\Models\InvestmentContribution;
use App\Models\Transaction;
use App\Models\User;
use App\Support\FundingSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * EDITAR uma despesa paga com resgate também respeita o piso do cheque especial
 * (achado do teste de propriedades de 24/09/2026 — `InvariantesDoDinheiroEmSequenciaTest`).
 *
 * O `TirarDinheiroDaContaRespeitaOPisoTest` fechou os caminhos que tiram dinheiro da
 * conta sem despesa nova: EXCLUIR a despesa cujo resgate cobriu o vermelho de antes, e
 * editar RECEITA. Faltava a EDIÇÃO dessa mesma despesa: levá-la para outra conta — ou
 * transformá-la em receita — passa pela reconciliação, que devolve ao investimento o
 * resgate INTEIRO. Ele é o faltante, que inclui o vermelho que a conta já tinha, então é
 * maior que a despesa; se a conta voltou a usar o cheque especial depois, ela ia abaixo
 * do piso em silêncio (o `spend` só confere a conta que recebe a despesa nova).
 *
 * Corrente com R$ 1.000 num CDB (disponível zero) e cheque especial de R$ 500: cheque de
 * 100 (−100), despesa de 300 paga com RESGATE — que traz 400: os 300 e os 100 do
 * vermelho — e mais 500 de cheque (−500, o limite todo).
 */
class EditarDespesaPagaComResgateRespeitaOPisoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $corrente;

    private Account $poupanca;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-05 10:00:00');

        $this->user = User::factory()->create();
        $this->corrente = Account::factory()->for($this->user)->create([
            'name' => 'Corrente', 'type' => 'checking', 'initial_balance' => 1000, 'overdraft_limit' => 500,
        ]);
        $this->poupanca = Account::factory()->for($this->user)->create([
            'name' => 'Poupança', 'type' => 'savings', 'initial_balance' => 5000,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lancar(float $valor, array $extra = []): TestResponse
    {
        return $this->actingAs($this->user)->postJson(route('transactions.store'), $extra + [
            'type' => 'expense', 'amount' => number_format($valor, 2, ',', '.'),
            'account_id' => $this->corrente->id, 'date' => '2026-08-05',
        ]);
    }

    private function editar(Transaction $t, array $campos): TestResponse
    {
        return $this->actingAs($this->user)->from(route('transactions.index'))
            ->put(route('transactions.update', $t), $campos + [
                'type' => $t->type, 'amount' => number_format((float) $t->amount, 2, ',', '.'),
                'account_id' => $t->account_id, 'date' => $t->date->toDateString(),
            ]);
    }

    /** A despesa de 300 paga com o resgate de 400 (o disponível termina em zero). */
    private function despesaPagaComResgateQueCobriuOVermelho(): Transaction
    {
        $this->actingAs($this->user)->post(route('investimentos.store'), [
            'name' => 'CDB', 'classe' => 'renda_fixa', 'valor_inicial' => '1000', 'account_id' => $this->corrente->id,
        ])->assertSessionHasNoErrors();

        $this->lancar(100, ['funding_source' => FundingSource::CHEQUE_ESPECIAL, 'funding_max_amount' => '100.00'])->assertCreated();
        $this->lancar(300, [
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
            'funding_investment_id' => Investment::firstOrFail()->id,
            'funding_max_amount' => '400.00',
        ])->assertCreated();

        $despesa = Transaction::where('amount', 300)->firstOrFail();
        $this->assertSame(400.0, (float) InvestmentContribution::where('transaction_id', $despesa->id)->value('amount'));
        $this->assertSame(0.0, $this->corrente->fresh()->available);

        return $despesa;
    }

    public function test_levar_para_outra_conta_nao_fura_o_piso_da_antiga(): void
    {
        $despesa = $this->despesaPagaComResgateQueCobriuOVermelho();
        $this->lancar(500, ['funding_source' => FundingSource::CHEQUE_ESPECIAL, 'funding_max_amount' => '500.00'])->assertCreated();
        $this->assertSame(-500.0, $this->corrente->fresh()->available);

        // ANTES: "Transação atualizada" e a corrente em −R$ 600,00 com limite de R$ 500,00
        // (+300 da despesa que saiu, −400 do resgate devolvido ao CDB).
        $this->editar($despesa, ['account_id' => $this->poupanca->id])->assertSessionHasErrors('transaction');

        $this->assertSame($this->corrente->id, $despesa->fresh()->account_id);
        $this->assertSame(1, InvestmentContribution::where('transaction_id', $despesa->id)->count());
        $this->assertSame(-500.0, $this->corrente->fresh()->available);
        $this->assertSame(5000.0, $this->poupanca->fresh()->available);
    }

    public function test_transformar_em_receita_nao_fura_o_piso(): void
    {
        $despesa = $this->despesaPagaComResgateQueCobriuOVermelho();
        $this->lancar(500, ['funding_source' => FundingSource::CHEQUE_ESPECIAL, 'funding_max_amount' => '500.00'])->assertCreated();

        // ANTES: −500 + 300 (a despesa sai) − 400 (o resgate volta) + 10 (a receita) = −590.
        $this->editar($despesa, ['type' => 'income', 'amount' => '10,00'])->assertSessionHasErrors('transaction');

        $this->assertSame('expense', $despesa->fresh()->type);
        $this->assertSame(-500.0, $this->corrente->fresh()->available);
    }

    public function test_dentro_do_cheque_especial_a_despesa_financiada_muda_de_conta(): void
    {
        // Sem o cheque especial usado depois: sem o resgate a corrente volta aos −100 de
        // antes, dentro do limite — a edição passa, e o resgate volta ao CDB.
        $despesa = $this->despesaPagaComResgateQueCobriuOVermelho();

        $this->editar($despesa, ['account_id' => $this->poupanca->id])->assertSessionHasNoErrors();

        $this->assertSame($this->poupanca->id, $despesa->fresh()->account_id);
        $this->assertSame(-100.0, $this->corrente->fresh()->available);
        $this->assertSame(4700.0, $this->poupanca->fresh()->available);
        $this->assertSame(1000.0, Investment::firstOrFail()->aplicado);
    }
}
