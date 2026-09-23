<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Investment;
use App\Models\InvestmentContribution;
use App\Models\Transaction;
use App\Models\User;
use App\Support\FundingSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use PDOException;
use Tests\TestCase;

/**
 * A nova tentativa da edição do Histórico recomeça do BANCO, não da memória (achado da
 * rodada 1, registrado em "Deadlock no `spend()`" no CLAUDE.md).
 *
 * `TransactionController::update` grava a edição de uma despesa que JÁ TINHA fonte dentro
 * de `DB::transaction($gravar, attempts: 3)`. Num deadlock (MySQL 1213) depois do
 * `$transaction->update()` do write — na gravação do resgate —, o rollback desfaz o banco,
 * mas não o model em memória: o Eloquent dá os valores novos por gravados. Na repetição, o
 * `$ignore` saía do valor novo, só a conta nova era travada, e o `update()` do write não
 * gravava nada. É o mesmo defeito que a mutação da rodada 1 provou no `spend()` (despesa em
 * R$ 50, resgate de R$ 200 pendurado, "Transação atualizada"), agora pelo caminho de FORA.
 *
 * ⚠️ `DatabaseMigrations`, não `RefreshDatabase`: com este último o teste inteiro roda numa
 * transação, a do controller vira aninhada, e o Laravel não repete transação aninhada —
 * nada aqui chegaria a exercitar a nova tentativa.
 */
class EdicaoComFonteRefeitaDoBancoNoDeadlockTest extends TestCase
{
    use DatabaseMigrations;

    private User $user;

    private Investment $cdb;

    protected function setUp(): void
    {
        parent::setUp();

        // Os deadlocks daqui são de propósito: o relatório deles não vai para o log.
        config(['logging.default' => 'null']);

        $this->user = User::factory()->create(['is_admin' => true, 'account_owner_id' => null]);
        $this->cdb = Investment::create(['user_id' => $this->user->id, 'name' => 'CDB', 'classe' => 'renda_fixa']);
    }

    /** O que o MySQL devolve à vítima de um deadlock, do jeito que o Laravel embrulha. */
    private function deadlock(): QueryException
    {
        return new QueryException(
            'mysql',
            'insert into `investment_contributions` (`amount`) values (?)',
            [100],
            new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction', 40001),
        );
    }

    /** Conta com `$inicial` de saldo e `$aplicado` dele no CDB. */
    private function contaComAplicado(string $nome, float $inicial, float $aplicado): Account
    {
        $conta = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'name' => $nome, 'initial_balance' => $inicial, 'overdraft_limit' => 0,
        ]);
        $this->cdb->contributions()->create([
            'account_id' => $conta->id, 'type' => 'aporte', 'amount' => $aplicado,
            'date' => CarbonImmutable::today()->toDateString(),
        ]);

        return $conta;
    }

    private function lancarComResgate(Account $conta, string $valor): Transaction
    {
        $this->actingAs($this->user)->post(route('transactions.store'), [
            'type' => 'expense',
            'amount' => $valor,
            'account_id' => $conta->id,
            'date' => CarbonImmutable::today()->toDateString(),
            'description' => 'Conserto do carro',
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
            'funding_investment_id' => $this->cdb->id,
        ])->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }

    /** O PRIMEIRO resgate gravado a partir daqui morre num deadlock; os seguintes passam. */
    private function deadlockNoPrimeiroResgate(): \Closure
    {
        $falhas = 0;
        InvestmentContribution::creating(function (InvestmentContribution $movimento) use (&$falhas) {
            if ($movimento->type === 'resgate' && ++$falhas === 1) {
                throw $this->deadlock();
            }
        });

        return function () use (&$falhas): int {
            return $falhas;
        };
    }

    /**
     * O cenário da mutação, pelo caminho que ainda tinha o defeito: a despesa JÁ tem
     * resgate, então a edição roda com `attempts: 3`. R$ 1.100 na conta, R$ 1.000
     * aplicados: uma despesa de R$ 150 que coube resgatando R$ 50. Editá-la para R$ 300
     * pede resgate de R$ 200 — e o deadlock cai na gravação desse resgate.
     *
     * Sem recarregar o model, a repetição tratava os R$ 300 como já gravados: a despesa
     * ficava em R$ 150, o resgate voltava a R$ 50, e a tela dizia "Transação atualizada".
     */
    public function test_deadlock_depois_do_update_refaz_a_edicao_inteira_com_o_valor_novo(): void
    {
        $conta = $this->contaComAplicado('Corrente', 1100, 1000);
        $despesa = $this->lancarComResgate($conta, '150,00');
        $this->assertSame(50.0, (float) $despesa->funding_amount);

        $falhas = $this->deadlockNoPrimeiroResgate();

        $resposta = $this->actingAs($this->user)->put(route('transactions.update', $despesa), [
            'type' => 'expense',
            'amount' => '300,00',
            'account_id' => $conta->id,
            'date' => CarbonImmutable::today()->toDateString(),
            'description' => 'Conserto do carro',
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
            'funding_investment_id' => $this->cdb->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, $falhas(), 'A edição não foi repetida depois do deadlock.');

        $atual = $despesa->fresh();
        $this->assertSame(300.0, (float) $atual->amount, 'A repetição não gravou o valor novo.');
        $this->assertSame(FundingSource::RESGATE_INVESTIMENTO, $atual->funding_source);
        $this->assertSame(200.0, (float) $atual->funding_amount);

        // Um resgate só, do tamanho certo, ligado à despesa.
        $resgates = InvestmentContribution::where('type', 'resgate')->get();
        $this->assertCount(1, $resgates);
        $this->assertSame(200.0, (float) $resgates->first()->amount);
        $this->assertSame($despesa->id, $resgates->first()->transaction_id);

        $this->assertSame(800.0, $this->cdb->fresh()->aplicado);
        $this->assertSame(0.0, $conta->fresh()->available);
        $this->assertSame(
            'Transação atualizada. Resgatamos mais R$ 150,00 do investimento para cobrir o novo valor.',
            (string) $resposta->getSession()->get('status'),
        );
    }

    /**
     * O pior caso: a edição TROCA DE CONTA. Na repetição, o model em memória já "estava"
     * na conta nova — só ela era travada, a despesa parecia não ter mudado de conta, e o
     * write não gravava nada: a despesa ficava na conta ANTIGA, sem fonte, com o resgate
     * dela desfeito — a conta antiga ia a −R$ 100 sem cheque especial, abaixo do piso que
     * o modelo promete. E a tela dizia que R$ 100 tinham voltado para o investimento.
     */
    public function test_deadlock_ao_trocar_de_conta_nao_deixa_a_despesa_na_conta_antiga_sem_fonte(): void
    {
        // As duas com tudo aplicado: disponível zero em cada uma.
        $antiga = $this->contaComAplicado('Conta Antiga', 1000, 1000);
        $nova = $this->contaComAplicado('Conta Nova', 1000, 1000);
        $despesa = $this->lancarComResgate($antiga, '100,00');
        $this->assertSame(100.0, (float) $despesa->funding_amount);

        $falhas = $this->deadlockNoPrimeiroResgate();

        $this->actingAs($this->user)->put(route('transactions.update', $despesa), [
            'type' => 'expense',
            'amount' => '100,00',
            'account_id' => $nova->id,
            'date' => CarbonImmutable::today()->toDateString(),
            'description' => 'Conserto do carro',
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
            'funding_investment_id' => $this->cdb->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, $falhas());

        $atual = $despesa->fresh();
        $this->assertSame($nova->id, $atual->account_id, 'A despesa ficou na conta antiga.');
        $this->assertSame(FundingSource::RESGATE_INVESTIMENTO, $atual->funding_source);

        $resgate = InvestmentContribution::where('type', 'resgate')->sole();
        $this->assertSame($nova->id, $resgate->account_id);
        $this->assertSame(100.0, (float) $resgate->amount);

        // Nenhuma das duas abaixo do piso (cheque especial zero).
        $this->assertSame(0.0, $antiga->fresh()->available);
        $this->assertSame(0.0, $nova->fresh()->available);
        $this->assertSame(1900.0, $this->cdb->fresh()->aplicado);
    }
}
