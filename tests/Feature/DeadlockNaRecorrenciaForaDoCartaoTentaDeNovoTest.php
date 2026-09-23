<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FundingService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use Tests\TestCase;

/**
 * Deadlock ao pagar uma recorrência FORA do cartão vira nova tentativa, não HTTP 500
 * (achado da rodada 1, em `FaturaController::pay`).
 *
 * O defeito: o ramo da recorrência legada (em conta corrente) abre um
 * `DB::transaction` SEM `attempts` e, dentro dele, lança a próxima ocorrência pelo
 * `FundingService::spend()`. Aninhado, o `spend()` não repete sozinho — no deadlock
 * o MySQL desfaz a transação de FORA inteira, e quem tem de repetir é ela (ver
 * `DeadlockNoGastoTentaDeNovoTest`, caso 2). Sem tentativas lá fora, o deadlock subia
 * como 500 num clique que, repetido um instante depois, passaria.
 *
 * Repetir o fechamento inteiro é seguro — e estes testes provam o porquê: ele não
 * reusa model que uma tentativa anterior salvou (a armadilha que a rodada 1 achou no
 * `TransactionController`). O `paid_at` sai de um UPDATE do query builder, desfeito e
 * reaplicado; a ocorrência clicada só é lida; a sucessora é um `create` novo a cada
 * tentativa. Por isso os testes com deadlock DEPOIS de uma gravação conferem que
 * nada ficou pela metade nem dobrado.
 *
 * ⚠️ `DatabaseMigrations`, não `RefreshDatabase`: com este, o teste inteiro roda
 * dentro de uma transação, a do `pay()` ficaria aninhada — e aninhada ela não repete.
 */
class DeadlockNaRecorrenciaForaDoCartaoTentaDeNovoTest extends TestCase
{
    use DatabaseMigrations;

    private User $user;

    private Account $conta;

    private Transaction $academia;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-05');

        // Os deadlocks daqui são de propósito: o relatório deles não vai para o log de verdade.
        config(['logging.default' => 'null']);

        $this->user = User::factory()->create(['is_admin' => true, 'account_owner_id' => null]);
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'name' => 'Conta Corrente',
            'initial_balance' => 1000,
            'overdraft_limit' => 0,
        ]);

        // Recorrência legada em conta: a de agosto, em aberto.
        $this->academia = Transaction::create([
            'user_id' => $this->user->id,
            'made_by_user_id' => $this->user->id,
            'account_id' => $this->conta->id,
            'type' => 'expense',
            'amount' => 100,
            'description' => 'Academia',
            'date' => '2026-08-05',
            'group_id' => (string) Str::uuid(),
            'recurring' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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

    private function pagar()
    {
        return $this->actingAs($this->user)->post(route('faturas.recorrente.pagar', $this->academia));
    }

    /** @return list<string> datas (Y-m-d) das ocorrências, em ordem */
    private function datas(): array
    {
        return Transaction::orderBy('date')->get()->map(fn ($t) => $t->date->toDateString())->all();
    }

    private function assertPagaUmaVezComUmaSucessora(): void
    {
        $this->assertSame(['2026-08-05', '2026-09-05'], $this->datas(), 'A sucessora não nasceu, ou nasceu em dobro.');
        $this->assertNotNull($this->academia->fresh()->paid_at, 'A ocorrência clicada ficou em aberto.');
        $this->assertNull(Transaction::where('date', '2026-09-05')->value('paid_at'), 'A sucessora nasce em aberto.');
        $this->assertSame(800.0, Account::find($this->conta->id)->available, 'O saldo não bate: 1.000 − 100 − 100.');
    }

    // ── A correção ────────────────────────────────────────────────────────────

    /** O caso do enunciado: o deadlock acontece ao gravar a sucessora. */
    public function test_deadlock_ao_lancar_a_proxima_e_repetido_e_o_clique_passa(): void
    {
        $vezes = 0;
        Transaction::creating(function () use (&$vezes) {
            if (++$vezes === 1) {
                throw $this->deadlock();
            }
        });

        $this->pagar()
            ->assertRedirect(route('faturas.index'))
            ->assertSessionHas('status', 'Recorrência paga — a próxima já foi lançada.');

        $this->assertSame(2, $vezes, 'O deadlock não foi repetido.');
        $this->assertPagaUmaVezComUmaSucessora();
    }

    /**
     * Deadlock DEPOIS do INSERT da sucessora: o rollback leva a linha e o `paid_at`
     * junto, e a repetição refaz as duas coisas do zero — sem sucessora em dobro e sem
     * `paid_at` "dado por gravado" em memória.
     */
    public function test_deadlock_depois_de_gravar_a_sucessora_nao_dobra_nada(): void
    {
        $vezes = 0;
        Transaction::created(function () use (&$vezes) {
            if (++$vezes === 1) {
                throw $this->deadlock();
            }
        });

        $this->pagar()->assertRedirect(route('faturas.index'))->assertSessionHasNoErrors();

        $this->assertSame(2, $vezes);
        $this->assertPagaUmaVezComUmaSucessora();
    }

    /**
     * Deadlock no UPDATE que marca a ocorrência como paga — a primeira escrita do
     * fechamento. A repetição reaplica o UPDATE (o rollback o desfez, então o
     * `whereNull('paid_at')` volta a casar) e segue normalmente.
     */
    public function test_deadlock_ao_marcar_como_paga_repete_o_fechamento_inteiro(): void
    {
        // Pela gramática do banco, não com as aspas do sqlite: no MySQL são crases, e um
        // filtro com `"` nunca casaria — o teste passaria sem testar nada (M-3).
        $g = DB::connection()->getQueryGrammar();
        $update = 'update '.$g->wrapTable('transactions').' set '.$g->wrap('paid_at');

        $vezes = 0;
        DB::listen(function (QueryExecuted $q) use (&$vezes, $update) {
            if (str_starts_with($q->sql, $update) && ++$vezes === 1) {
                throw $this->deadlock();
            }
        });

        $this->pagar()->assertRedirect(route('faturas.index'))->assertSessionHasNoErrors();

        $this->assertSame(2, $vezes, 'O fechamento não foi repetido depois do deadlock no UPDATE.');
        $this->assertPagaUmaVezComUmaSucessora();
    }

    /** Deadlock que não passa: desiste depois das tentativas, sem deixar nada gravado. */
    public function test_deadlock_que_nao_passa_desiste_sem_deixar_nada_pela_metade(): void
    {
        $vezes = 0;
        Transaction::creating(function () use (&$vezes) {
            $vezes++;

            throw $this->deadlock();
        });

        $this->pagar()->assertServerError();

        $this->assertSame(FundingService::TENTATIVAS, $vezes);
        $this->assertSame(['2026-08-05'], $this->datas());
        $this->assertNull($this->academia->fresh()->paid_at, 'O paid_at ficou gravado sem a sucessora.');
        $this->assertSame(900.0, Account::find($this->conta->id)->available);
    }

    /** O mesmo clique repetido depois de um deadlock resolvido continua idempotente. */
    public function test_clique_repetido_depois_do_deadlock_nao_gera_outra_sucessora(): void
    {
        $vezes = 0;
        Transaction::creating(function () use (&$vezes) {
            if (++$vezes === 1) {
                throw $this->deadlock();
            }
        });

        // O 1º clique passa (depois de repetir), e o 2º encontra a ocorrência já paga.
        $this->pagar()->assertRedirect(route('faturas.index'))->assertSessionHasNoErrors();
        $this->pagar()->assertRedirect(route('faturas.index'))->assertSessionHasNoErrors();

        $this->assertSame(2, $vezes, 'O 2º clique tentou gravar outra sucessora.');
        $this->assertPagaUmaVezComUmaSucessora();
    }

    // ── O que já funcionava continua ─────────────────────────────────────────

    /** No cartão a repetição é a do próprio `spend()` (não aninhado): segue passando. */
    public function test_recorrencia_no_cartao_tambem_sobrevive_a_um_deadlock(): void
    {
        $cartao = Account::factory()->for($this->user)->creditCard()->create([
            'credit_limit' => 5000, 'closing_day' => 10, 'due_day' => 20,
        ]);
        $streaming = Transaction::create([
            'user_id' => $this->user->id,
            'account_id' => $cartao->id,
            'type' => 'expense',
            'amount' => 49.90,
            'description' => 'Streaming',
            'date' => '2026-07-01', // ciclo (10/06, 10/07] já fechou
            'group_id' => (string) Str::uuid(),
            'recurring' => true,
        ]);

        $vezes = 0;
        Transaction::creating(function () use (&$vezes) {
            if (++$vezes === 1) {
                throw $this->deadlock();
            }
        });

        $this->actingAs($this->user)->post(route('faturas.recorrente.pagar', $streaming))
            ->assertSessionHas('status', fn ($msg) => str_contains($msg, 'Próxima ocorrência lançada'));

        $this->assertSame(2, $vezes);
        $this->assertSame(1, Transaction::where('description', 'Streaming')->where('date', '2026-08-01')->count());
    }
}
