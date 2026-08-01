<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Goal;
use App\Models\Investment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Trava os `down()` que já quebraram na prática.
 *
 * Migration não roda na suíte além do `up()` — o `down()` é código que só é
 * exercitado no dia do rollback, ou seja, no pior dia possível. Estes testes
 * chamam o `down()` de verdade, com o dado que o fazia estourar.
 *
 * Os cenários vieram da auditoria de 28/07/2026 (C-5 e M-13) e foram
 * reproduzidos em MySQL antes de virarem teste; aqui rodam em sqlite, que
 * cobre a lógica (ordem das operações, filtro de ponteiro morto, tratamento
 * de NULL) ainda que a mensagem de erro do driver seja outra.
 */
class MigracoesReversiveisTest extends TestCase
{
    use RefreshDatabase;

    private const MIG_DEBITO = 'database/migrations/2026_07_28_000000_move_debit_card_movements_to_linked_account.php';

    private const MIG_SALDO = 'database/migrations/2026_06_22_160100_make_initial_balance_nullable.php';

    /**
     * C-5: o down() devolvia cada movimento ao cartão de origem tabela por
     * tabela (update, dropColumn, update, dropColumn...). Bastava UM
     * `legacy_account_id` apontando para um cartão já excluído — não há FK
     * nessa coluna — para o update da 2ª tabela estourar FK DEPOIS de a 1ª já
     * ter perdido a coluna. Como MySQL não tem DDL transacional, o mapeamento
     * de `transactions` virava pó e o rollback travava naquele ponto para
     * sempre.
     */
    public function test_down_do_cartao_de_debito_ignora_ponteiro_morto_e_nao_perde_o_mapeamento(): void
    {
        $user = User::factory()->create();
        $corrente = Account::factory()->for($user)->create(['type' => 'checking']);
        $cartaoVivo = Account::factory()->for($user)->create(['type' => 'debit_card']);
        $cartaoExcluido = Account::factory()->for($user)->create(['type' => 'debit_card']);

        $goal = Goal::factory()->for($user)->create();
        $investment = Investment::factory()->for($user)->create();

        // Estado pós-up(): tudo reancorado na corrente, `legacy` guarda a origem.
        $transacaoId = DB::table('transactions')->insertGetId([
            'user_id' => $user->id, 'account_id' => $corrente->id,
            'legacy_account_id' => $cartaoVivo->id,
            'type' => 'expense', 'amount' => 100, 'date' => '2026-07-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // O ponteiro MORTO fica numa tabela POSTERIOR a transactions — é o que
        // fazia o estrago passar despercebido até a coluna já ter sido dropada.
        $aporteMetaId = DB::table('goal_contributions')->insertGetId([
            'goal_id' => $goal->id, 'account_id' => $corrente->id,
            'legacy_account_id' => $cartaoExcluido->id,
            'type' => 'aporte', 'amount' => 200, 'date' => '2026-07-02',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $aporteInvId = DB::table('investment_contributions')->insertGetId([
            'investment_id' => $investment->id, 'account_id' => $corrente->id,
            'legacy_account_id' => $cartaoVivo->id,
            'type' => 'aporte', 'amount' => 300, 'date' => '2026-07-03',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // O usuário exclui o cartão: nada no schema impede que `legacy_account_id`
        // fique apontando para o vazio.
        $idExcluido = $cartaoExcluido->id;
        DB::table('accounts')->where('id', $idExcluido)->delete();

        (require base_path(self::MIG_DEBITO))->down();

        // Movimentos com origem viva voltaram para o cartão...
        $this->assertSame($cartaoVivo->id, DB::table('transactions')->find($transacaoId)->account_id);
        $this->assertSame($cartaoVivo->id, DB::table('investment_contributions')->find($aporteInvId)->account_id);

        // ...e o de origem morta ficou onde estava, em vez de derrubar o rollback.
        $this->assertSame($corrente->id, DB::table('goal_contributions')->find($aporteMetaId)->account_id);

        // O down() foi até o fim: as três colunas saíram.
        foreach (['transactions', 'goal_contributions', 'investment_contributions'] as $tabela) {
            $this->assertFalse(
                Schema::hasColumn($tabela, 'legacy_account_id'),
                "A coluna legacy_account_id continua em {$tabela} — o down() parou no meio."
            );
        }
    }

    /** O down() precisa poder rodar de novo num banco que ficou meio-revertido. */
    public function test_down_do_cartao_de_debito_e_reentrante(): void
    {
        $migration = require base_path(self::MIG_DEBITO);

        $migration->down();
        $migration->down(); // não pode estourar por a coluna já não existir

        $this->assertFalse(Schema::hasColumn('transactions', 'legacy_account_id'));
    }

    /**
     * M-13: voltar `initial_balance` para NOT NULL estourava sempre que havia
     * um cartão cadastrado (cartão nasce com a coluna nula).
     * MySQL: SQLSTATE[22004] 1138 · sqlite: SQLSTATE[23000] 19.
     */
    public function test_down_de_initial_balance_tolera_contas_com_saldo_nulo(): void
    {
        $user = User::factory()->create();
        Account::factory()->for($user)->create(['type' => 'checking', 'initial_balance' => 250]);
        $cartao = Account::factory()->for($user)->create(['type' => 'credit_card', 'initial_balance' => null]);

        $this->assertNull($cartao->fresh()->initial_balance, 'Pré-condição: o cartão precisa ter saldo nulo.');

        (require base_path(self::MIG_SALDO))->down();

        // O nulo virou 0 (que é o que o schema anterior guardava para cartões)
        // e nenhuma outra conta foi tocada.
        $this->assertSame(0.0, (float) DB::table('accounts')->find($cartao->id)->initial_balance);
        $this->assertSame(0, DB::table('accounts')->whereNull('initial_balance')->count());
    }

    /**
     * M-12: as consultas de ciclo de cartão filtram `account_id` + faixa de
     * `date` (e `paid_at IS NULL` nas de fatura em aberto). Só havia índice em
     * `account_id`, que entrega a faixa inteira para varredura.
     */
    public function test_indices_de_ciclo_de_cartao_existem(): void
    {
        $this->assertTrue(
            Schema::hasIndex('transactions', ['account_id', 'date']),
            'Falta o índice (account_id, date) — as consultas de ciclo voltam a varrer o cartão inteiro.'
        );

        $this->assertTrue(
            Schema::hasIndex('transactions', ['account_id', 'paid_at', 'date']),
            'Falta o índice (account_id, paid_at, date) — usado por fatura em aberto e limite comprometido.'
        );
    }
}
