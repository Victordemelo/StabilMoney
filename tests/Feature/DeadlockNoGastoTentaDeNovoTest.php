<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Investment;
use App\Models\InvestmentContribution;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FundingService;
use App\Support\FundingSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * Deadlock do MySQL no meio de um gasto vira NOVA TENTATIVA, não HTTP 500 (item 28b da
 * rodada de pré-publicação).
 *
 * O defeito: `FundingService::spend()` abria a `DB::transaction` sem tentativas, ao
 * contrário do `HandlesContributions` (3). Sob concorrência o MySQL escolhe uma transação
 * como vítima (erro 1213, SQLSTATE 40001) e a desfaz inteira — e isso subia como 500 num
 * lançamento que, repetido um instante depois, passaria.
 *
 * A repetição tem DUAS fronteiras, e os últimos testes existem para elas não sumirem
 * numa "simplificação" para `attempts: 3`:
 *  - depois que o `$write` do chamador DEVOLVEU, não se repete: a edição do Histórico
 *    grava num model que ela mesma segura, e repetir o `update()` depois de um save bem
 *    sucedido não grava nada (o Eloquent já dá os valores por gravados) — a linha voltava
 *    ao valor antigo enquanto o resgate era gravado de novo;
 *  - chamado DENTRO de outra transação, quem repete é a de fora (o MySQL desfaz tudo).
 *
 * ⚠️ `DatabaseMigrations`, não `RefreshDatabase`: este segundo roda cada teste dentro de
 * uma transação, e aí o `spend()` estaria SEMPRE aninhado — o caso em que ele, de
 * propósito, não repete. Nada aqui chegaria a exercitar a repetição.
 */
class DeadlockNoGastoTentaDeNovoTest extends TestCase
{
    use DatabaseMigrations;

    private User $user;

    private Account $conta;

    protected function setUp(): void
    {
        parent::setUp();

        // Os deadlocks daqui são de propósito: o relatório deles não vai para o log de verdade.
        config(['logging.default' => 'null']);

        $this->user = User::factory()->create(['is_admin' => true, 'account_owner_id' => null]);
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'name' => 'Conta Corrente',
            'initial_balance' => 1000,
            'overdraft_limit' => 0,
        ]);
    }

    /** O que o MySQL devolve à vítima de um deadlock, do jeito que o Laravel embrulha. */
    private function deadlock(): QueryException
    {
        return new QueryException(
            'mysql',
            'insert into `transactions` (`amount`) values (?)',
            [100],
            new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction', 40001),
        );
    }

    private function despesa(float $valor, array $auditoria = []): Transaction
    {
        return Transaction::create($auditoria + [
            'user_id' => $this->user->id,
            'made_by_user_id' => $this->user->id,
            'account_id' => $this->conta->id,
            'type' => 'expense',
            'amount' => $valor,
            'date' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    private function gastar(float $valor, callable $write): ?Transaction
    {
        return app(FundingService::class)->spend(
            account: $this->conta,
            amount: $valor,
            source: null,
            investmentId: null,
            write: $write,
        );
    }

    // ── A correção ────────────────────────────────────────────────────────────

    /** O caso do enunciado: a 1ª execução do write morre num deadlock, depois de inserir. */
    public function test_deadlock_na_primeira_execucao_do_write_e_repetido_e_grava_uma_vez_so(): void
    {
        $execucoes = 0;

        $gravada = $this->gastar(100, function (array $auditoria) use (&$execucoes) {
            $execucoes++;
            $linha = $this->despesa(100, $auditoria);

            // Morre DEPOIS do INSERT: é o rollback que tem de levar a linha junto.
            if ($execucoes === 1) {
                throw $this->deadlock();
            }

            return $linha;
        });

        $this->assertSame(2, $execucoes, 'O deadlock não foi repetido.');
        $this->assertSame(1, Transaction::count(), 'A despesa foi gravada mais de uma vez.');
        $this->assertTrue(Transaction::whereKey($gravada->id)->exists());
        $this->assertSame(900.0, Account::find($this->conta->id)->available);
    }

    /** O mesmo pelo caminho de verdade: o POST do lançamento não vira mais erro 500. */
    public function test_lancamento_pela_tela_sobrevive_a_um_deadlock(): void
    {
        $vezes = 0;
        Transaction::creating(function () use (&$vezes) {
            if (++$vezes === 1) {
                throw $this->deadlock();
            }
        });

        $this->actingAs($this->user)->post(route('transactions.store'), [
            'type' => 'expense',
            'amount' => '100,00',
            'account_id' => $this->conta->id,
            'date' => CarbonImmutable::today()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, $vezes);
        $this->assertSame(1, Transaction::count());
        $this->assertSame(900.0, Account::find($this->conta->id)->available);
    }

    /** Deadlock que não passa: desiste depois das tentativas e não deixa nada gravado. */
    public function test_desiste_depois_das_tentativas_sem_deixar_nada_gravado(): void
    {
        $execucoes = 0;

        try {
            $this->gastar(100, function (array $auditoria) use (&$execucoes) {
                $execucoes++;
                $this->despesa(100, $auditoria);

                throw $this->deadlock();
            });
            $this->fail('O deadlock persistente deveria subir depois das tentativas.');
        } catch (QueryException) {
            // esperado
        }

        $this->assertSame(FundingService::TENTATIVAS, $execucoes);
        $this->assertSame(0, Transaction::count());
    }

    /** Só erro de concorrência se repete. Os demais sobem na hora, como sempre. */
    public function test_erro_que_nao_e_de_concorrencia_nao_se_repete(): void
    {
        $execucoes = 0;

        try {
            $this->gastar(100, function () use (&$execucoes) {
                $execucoes++;

                throw new RuntimeException('falha qualquer');
            });
            $this->fail('A falha deveria subir.');
        } catch (RuntimeException) {
            // esperado
        }

        $this->assertSame(1, $execucoes);
    }

    // ── As fronteiras da repetição ────────────────────────────────────────────

    /**
     * Deadlock DEPOIS que o write devolveu (na gravação do resgate): não repete — e o
     * resultado é o de antes da correção, tudo desfeito, nada pela metade.
     *
     * Pela edição do Histórico, que é o caminho que quebraria: com `attempts: 3` ingênuo,
     * a repetição rodava o `$transaction->update()` num model que já se dava por gravado
     * (sem UPDATE nenhum) e gravava o resgate de novo — a despesa ficava em R$ 50 com um
     * resgate de R$ 200 pendurado nela, e a tela respondia "Transação atualizada".
     */
    public function test_deadlock_depois_do_write_nao_repete_e_nao_grava_pela_metade(): void
    {
        // R$ 1.100 na conta, R$ 1.000 deles investidos a partir dela: disponível R$ 100.
        $this->conta->update(['initial_balance' => 1100]);
        $cdb = Investment::create(['user_id' => $this->user->id, 'name' => 'CDB', 'classe' => 'renda_fixa']);
        $cdb->contributions()->create([
            'account_id' => $this->conta->id,
            'type' => 'aporte',
            'amount' => 1000,
            'date' => CarbonImmutable::today()->toDateString(),
        ]);

        // Uma despesa de R$ 50, sem fonte. Editá-la para R$ 300 pede resgate de R$ 200.
        $despesa = $this->despesa(50);

        $falhas = 0;
        InvestmentContribution::creating(function (InvestmentContribution $movimento) use (&$falhas) {
            if ($movimento->type === 'resgate' && ++$falhas === 1) {
                throw $this->deadlock();
            }
        });

        $edicao = [
            'type' => 'expense',
            'amount' => '300,00',
            'account_id' => $this->conta->id,
            'date' => CarbonImmutable::today()->toDateString(),
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
            'funding_investment_id' => $cdb->id,
        ];

        $this->actingAs($this->user)->put(route('transactions.update', $despesa), $edicao)->assertServerError();

        // Nada pela metade: a despesa, o investimento e o disponível estão como antes.
        $this->assertSame(1, $falhas, 'O resgate foi tentado de novo depois do write.');
        $this->assertSame(50.0, (float) $despesa->fresh()->amount);
        $this->assertNull($despesa->fresh()->funding_source);
        $this->assertSame(0, InvestmentContribution::where('type', 'resgate')->count(), 'Resgate gravado sem a despesa correspondente.');
        $this->assertSame(50.0, Account::find($this->conta->id)->available);

        // E a pessoa repete o clique: agora passa, inteira.
        $this->actingAs($this->user)->put(route('transactions.update', $despesa), $edicao)
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(300.0, (float) $despesa->fresh()->amount);
        $this->assertSame(FundingSource::RESGATE_INVESTIMENTO, $despesa->fresh()->funding_source);
        $this->assertSame(200.0, (float) InvestmentContribution::where('type', 'resgate')->sum('amount'));
        $this->assertSame(0.0, Account::find($this->conta->id)->available);
    }

    /**
     * Dentro de outra transação, quem repete é a de FORA: no deadlock o MySQL desfaz a
     * transação inteira, e repetir só o trecho do `spend()` rodaria o resto sem
     * transação nenhuma. (Em sqlite o sintoma do erro é a 1ª despesa sobreviver: o
     * savepoint dela nunca é desfeito.)
     */
    public function test_dentro_de_outra_transacao_quem_repete_e_a_de_fora(): void
    {
        $execucoesDeFora = 0;
        $execucoesDoWrite = 0;

        DB::transaction(function () use (&$execucoesDeFora, &$execucoesDoWrite) {
            $execucoesDeFora++;

            $this->gastar(100, function (array $auditoria) use (&$execucoesDoWrite) {
                $execucoesDoWrite++;
                $linha = $this->despesa(100, $auditoria);

                if ($execucoesDoWrite === 1) {
                    throw $this->deadlock();
                }

                return $linha;
            });
        }, attempts: 3);

        $this->assertSame(2, $execucoesDeFora, 'O spend() repetiu por conta própria dentro de uma transação alheia.');
        $this->assertSame(2, $execucoesDoWrite);
        $this->assertSame(1, Transaction::count());
    }

    /** Sem `attempts` na de fora, o deadlock sobe como antes — e sobe com o tipo certo. */
    public function test_dentro_de_outra_transacao_sem_tentativas_o_deadlock_sobe(): void
    {
        $execucoesDoWrite = 0;

        try {
            DB::transaction(function () use (&$execucoesDoWrite) {
                $this->gastar(100, function () use (&$execucoesDoWrite) {
                    $execucoesDoWrite++;

                    throw $this->deadlock();
                });
            });
            $this->fail('O deadlock deveria chegar à transação de fora.');
        } catch (DeadlockException) {
            // esperado: é o que o Laravel lança de uma transação aninhada
        }

        $this->assertSame(1, $execucoesDoWrite);
        $this->assertSame(0, Transaction::count());
    }
}
