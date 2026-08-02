<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\FixedBill;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 403 entre famílias nas rotas de fatura e conta fixa.
 *
 * A auditoria apontou que essas rotas ESTAVAM protegidas (todas chamam `authorize()` ou
 * `abort_unless`), mas sem teste travando — ou seja, uma refatoração poderia removê-las
 * sem nada acusar. São as rotas que movimentam dinheiro, então o buraco de cobertura
 * incomoda mais aqui do que em qualquer outro lugar.
 *
 * Cada teste verifica DUAS coisas: o status 403/404 e, o que importa de verdade, que
 * NADA mudou no dinheiro da vítima.
 */
class IsolamentoFaturasEContasFixasTest extends TestCase
{
    use RefreshDatabase;

    private User $dono;

    private User $estranho;

    private Account $cartaoDoDono;

    private Account $correnteDoDono;

    private Category $categoriaDoDono;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dono = User::factory()->create(['is_admin' => true]);
        $this->estranho = User::factory()->create(['is_admin' => true]);

        $this->correnteDoDono = Account::factory()->for($this->dono)->create([
            'type' => 'checking',
            'initial_balance' => 5000,
            'overdraft_limit' => 0,
        ]);

        $this->cartaoDoDono = Account::factory()->for($this->dono)->create([
            'type' => 'credit_card',
            'initial_balance' => null,
            'credit_limit' => 5000,
            'closing_day' => 10,
            'due_day' => 20,
        ]);

        $this->categoriaDoDono = Category::factory()->for($this->dono)->expense()->create();
    }

    private function saldoDoDono(): float
    {
        return round(Account::find($this->correnteDoDono->id)->available, 2);
    }

    private function compraNoCartao(float $valor = 300.00): Transaction
    {
        return Transaction::factory()->for($this->dono)->create([
            'account_id' => $this->cartaoDoDono->id,
            'category_id' => $this->categoriaDoDono->id,
            'type' => 'expense',
            'amount' => $valor,
            'date' => now()->toDateString(),
        ]);
    }

    private function contaFixaDoDono(): FixedBill
    {
        return FixedBill::create([
            'user_id' => $this->dono->id,
            'name' => 'Aluguel do vizinho',
            'amount' => 1800.00,
            'due_day' => 5,
            'account_id' => $this->correnteDoDono->id,
            'category_id' => $this->categoriaDoDono->id,
            'starts_on' => now()->subMonth()->startOfMonth()->toDateString(),
            'active' => true,
        ]);
    }

    // ---------------------------------------------------------------- faturas

    public function test_estranho_nao_paga_a_fatura_de_cartao_de_outra_familia(): void
    {
        $this->compraNoCartao();
        $saldoAntes = $this->saldoDoDono();

        // A conta de pagamento é do estranho; o cartão, do dono.
        $correnteDoEstranho = Account::factory()->for($this->estranho)->create([
            'type' => 'checking',
            'initial_balance' => 9000,
        ]);

        $this->actingAs($this->estranho)
            ->post(route('faturas.fatura.pagar', $this->cartaoDoDono), [
                'pay_account_id' => $correnteDoEstranho->id,
            ])
            ->assertForbidden();

        $this->assertSame($saldoAntes, $this->saldoDoDono(), 'O saldo do dono mudou.');
        $this->assertSame(
            300.0,
            round((float) Account::find($this->cartaoDoDono->id)->committed, 2),
            'A fatura do dono foi quitada por um estranho.',
        );
    }

    public function test_estranho_nao_paga_recorrencia_de_outra_familia(): void
    {
        $recorrente = Transaction::factory()->for($this->dono)->create([
            'account_id' => $this->cartaoDoDono->id,
            'category_id' => $this->categoriaDoDono->id,
            'type' => 'expense',
            'amount' => 49.90,
            'date' => now()->toDateString(),
            'recurring' => true,
            'paid_at' => null,
        ]);

        $linhasAntes = Transaction::where('user_id', $this->dono->id)->count();

        $this->actingAs($this->estranho)
            ->post(route('faturas.recorrente.pagar', $recorrente))
            ->assertForbidden();

        $this->assertNull($recorrente->fresh()->paid_at, 'A recorrência do dono foi marcada como paga.');
        $this->assertSame(
            $linhasAntes,
            Transaction::where('user_id', $this->dono->id)->count(),
            'Uma ocorrência nova foi criada na família do dono.',
        );
    }

    public function test_estranho_nao_exclui_compra_de_outra_familia(): void
    {
        $compra = $this->compraNoCartao();

        $this->actingAs($this->estranho)
            ->delete(route('faturas.compra.destroy', $compra))
            ->assertForbidden();

        $this->assertTrue(Transaction::whereKey($compra->id)->exists(), 'A compra do dono foi apagada.');
    }

    public function test_estranho_nao_estorna_quitacao_de_outra_familia(): void
    {
        if (! \Illuminate\Support\Facades\Route::has('faturas.fatura.estornar')) {
            $this->markTestSkipped('Estorno de fatura não disponível nesta versão.');
        }

        $this->compraNoCartao();

        // O dono paga a própria fatura.
        $this->actingAs($this->dono)
            ->post(route('faturas.fatura.pagar', $this->cartaoDoDono), [
                'pay_account_id' => $this->correnteDoDono->id,
            ])->assertSessionHasNoErrors();

        $quitacao = Transaction::where('user_id', $this->dono->id)
            ->whereNotNull('settles_account_id')
            ->firstOrFail();

        $saldoAntes = $this->saldoDoDono();

        $this->actingAs($this->estranho)
            ->delete(route('faturas.fatura.estornar', $quitacao))
            ->assertForbidden();

        $this->assertSame($saldoAntes, $this->saldoDoDono(), 'O estorno alheio mexeu no saldo do dono.');
        $this->assertTrue(
            Transaction::whereKey($quitacao->id)->exists(),
            'A quitação do dono foi desfeita por um estranho.',
        );
    }

    // ----------------------------------------------------------- contas fixas

    public function test_estranho_nao_edita_conta_fixa_de_outra_familia(): void
    {
        $conta = $this->contaFixaDoDono();

        $this->actingAs($this->estranho)
            ->patch(route('contas-fixas.update', $conta), [
                'name' => 'Sequestrada',
                'amount' => '1,00',
                'due_day' => 28,
                'account_id' => $this->correnteDoDono->id,
                'category_id' => $this->categoriaDoDono->id,
                'starts_on' => now()->subMonth()->startOfMonth()->toDateString(),
            ])
            ->assertForbidden();

        $conta->refresh();
        $this->assertSame('Aluguel do vizinho', $conta->name, 'A conta fixa do dono foi renomeada.');
        $this->assertSame('1800.00', (string) $conta->amount, 'O valor da conta fixa do dono foi alterado.');
    }

    public function test_estranho_nao_exclui_conta_fixa_de_outra_familia(): void
    {
        $conta = $this->contaFixaDoDono();

        $this->actingAs($this->estranho)
            ->delete(route('contas-fixas.destroy', $conta))
            ->assertForbidden();

        $conta->refresh();
        $this->assertTrue($conta->exists, 'A conta fixa do dono foi apagada.');
        $this->assertTrue((bool) $conta->active, 'A conta fixa do dono foi desativada por um estranho.');
    }

    public function test_estranho_nao_paga_competencia_de_conta_fixa_alheia(): void
    {
        $conta = $this->contaFixaDoDono();
        $competencia = now()->subMonth()->format('Y-m');
        $saldoAntes = $this->saldoDoDono();

        $this->actingAs($this->estranho)
            ->post(url("/contas-fixas/{$conta->id}/pagar/{$competencia}"), [
                'account_id' => $this->correnteDoDono->id,
                'amount' => '1.800,00',
            ])
            ->assertForbidden();

        $this->assertSame($saldoAntes, $this->saldoDoDono(), 'O saldo do dono foi debitado por um estranho.');
        $this->assertSame(
            0,
            Transaction::where('fixed_bill_id', $conta->id)->count(),
            'Um pagamento foi criado na conta fixa do dono.',
        );
    }

    // ------------------------------------------------------ dependente (pode)

    /**
     * O contraponto: dependente da MESMA família pode tudo isso — é decisão de produto,
     * e um teste de 403 mal calibrado poderia travá-lo sem ninguém perceber.
     */
    public function test_dependente_da_familia_continua_podendo_pagar(): void
    {
        $dependente = User::factory()->create([
            'account_owner_id' => $this->dono->id,
            'is_admin' => false,
        ]);

        $this->compraNoCartao(200.00);

        $this->actingAs($dependente)
            ->post(route('faturas.fatura.pagar', $this->cartaoDoDono), [
                'pay_account_id' => $this->correnteDoDono->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            4800.0,
            $this->saldoDoDono(),
            'O dependente deveria conseguir pagar a fatura da própria família.',
        );
    }
}
