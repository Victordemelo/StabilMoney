<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Investment;
use App\Models\Transaction;
use App\Models\User;
use App\Support\FundingSource;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Auditoria de 02/09/2026: editar SÓ a descrição de uma despesa financiada por
 * resgate desfazia o resgate.
 *
 * `TransactionController::update` reconciliava a fonte do zero em TODA edição
 * (`estornarFonte` + `spend`). Corrigir um typo devolvia R$ 300 ao investimento
 * e avisava "voltaram para o investimento" — coerente, mas o resgate já tinha
 * acontecido no banco de verdade. Regra nova: reconciliar APENAS quando valor,
 * conta ou tipo mudarem. Campo neutro (descrição, categoria, data, autor) grava
 * direto e preserva `funding_*` e o resgate ligado.
 */
class EdicaoNeutraPreservaResgateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    private Investment $inv;

    private Transaction $despesa;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-05');

        $this->user = User::factory()->create();
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'name' => 'Corrente',
            'initial_balance' => 1000,
            'overdraft_limit' => 0,
        ]);

        // R$ 800 aplicados a partir da corrente → 200 disponíveis.
        $this->inv = Investment::create([
            'user_id' => $this->user->id,
            'name' => 'CDB',
            'classe' => 'renda_fixa',
            'indexador' => 'cdi',
            'taxa' => 100,
        ]);
        $this->inv->contributions()->create([
            'account_id' => $this->conta->id,
            'made_by_user_id' => $this->user->id,
            'type' => 'aporte',
            'amount' => 800,
            'date' => '2026-08-01',
        ]);

        // Despesa de R$ 500 que só coube resgatando R$ 300.
        $this->actingAs($this->user)->post(route('transactions.store'), [
            'type' => 'expense',
            'amount' => '500,00',
            'account_id' => $this->conta->id,
            'date' => '2026-08-05',
            'description' => 'Conserto do carro',
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
            'funding_investment_id' => $this->inv->id,
        ])->assertSessionHasNoErrors();

        $this->despesa = Transaction::where('description', 'Conserto do carro')->firstOrFail();

        $this->assertSame('300.00', (string) $this->despesa->funding_amount);
        $this->assertSame(500.0, $this->inv->fresh()->aplicado);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function edicao(array $overrides = []): array
    {
        return array_merge([
            'type' => 'expense',
            'amount' => '500,00',
            'account_id' => $this->conta->id,
            'date' => '2026-08-05',
            'description' => 'Conserto do carro',
        ], $overrides);
    }

    private function editar(array $overrides = [])
    {
        return $this->actingAs($this->user)
            ->from(route('transactions.index'))
            ->patch(route('transactions.update', $this->despesa), $this->edicao($overrides));
    }

    private function resgateLigado(): int
    {
        return $this->inv->contributions()
            ->where('type', 'resgate')
            ->where('transaction_id', $this->despesa->id)
            ->count();
    }

    /** O cenário do relatório: corrigir um typo devolvia R$ 300 ao investimento. */
    public function test_mudar_so_a_descricao_preserva_o_resgate(): void
    {
        $resposta = $this->editar(['description' => 'Conserto do carro (oficina do Zé)'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('transactions.index'));

        $atual = $this->despesa->fresh();
        $this->assertSame('Conserto do carro (oficina do Zé)', $atual->description);
        $this->assertSame(FundingSource::RESGATE_INVESTIMENTO, $atual->funding_source);
        $this->assertSame('300.00', (string) $atual->funding_amount);

        $this->assertSame(500.0, $this->inv->fresh()->aplicado, 'O resgate foi desfeito numa edição neutra.');
        $this->assertSame(1, $this->resgateLigado(), 'O resgate perdeu o vínculo com a despesa.');
        $this->assertSame(0.0, $this->conta->fresh()->available);

        // E o flash não anuncia movimento nenhum de investimento.
        $this->assertSame('Transação atualizada.', (string) $resposta->getSession()->get('status'));
    }

    /** Categoria, data e autor também são neutros. */
    public function test_mudar_categoria_data_e_autor_preserva_o_resgate(): void
    {
        $categoria = Category::factory()->for($this->user)->expense()->create();

        $this->editar([
            'category_id' => $categoria->id,
            'date' => '2026-08-03',
            'made_by_user_id' => $this->user->id,
        ])->assertSessionHasNoErrors();

        $atual = $this->despesa->fresh();
        $this->assertSame($categoria->id, $atual->category_id);
        $this->assertSame('2026-08-03', $atual->date->toDateString());
        $this->assertSame(FundingSource::RESGATE_INVESTIMENTO, $atual->funding_source);
        $this->assertSame(500.0, $this->inv->fresh()->aplicado);
        $this->assertSame(1, $this->resgateLigado());
    }

    /**
     * Edição neutra não passa pelo guard: com a conta no vermelho por uma
     * obrigação, corrigir o texto de uma despesa não pode ser recusado por
     * "saldo insuficiente".
     */
    public function test_edicao_neutra_nao_e_barrada_pela_trava_de_gasto(): void
    {
        // Uma obrigação levou a conta a −100 (o guard aceitaria, com obrigacao: true).
        Transaction::factory()->for($this->user)->create([
            'account_id' => $this->conta->id,
            'type' => 'expense',
            'amount' => 100,
            'date' => '2026-08-05',
            'description' => 'Boleto vencido',
        ]);
        $this->assertSame(-100.0, $this->conta->fresh()->available);

        $this->editar(['description' => 'Conserto do carro — pago'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Conserto do carro — pago', $this->despesa->fresh()->description);
        $this->assertSame(500.0, $this->inv->fresh()->aplicado);
    }

    // ================= mudou o dinheiro → reconcilia como antes =================

    public function test_mudar_o_valor_continua_reconciliando(): void
    {
        $resposta = $this->editar(['amount' => '100,00'])->assertSessionHasNoErrors();

        // 100 cabe nos 200 livres: o resgate inteiro volta.
        $this->assertSame(800.0, $this->inv->fresh()->aplicado);
        $this->assertSame(0, $this->resgateLigado());
        $this->assertNull($this->despesa->fresh()->funding_source);
        $this->assertStringContainsString(
            'voltaram para o investimento',
            (string) $resposta->getSession()->get('status'),
        );
    }

    public function test_mudar_a_conta_continua_reconciliando(): void
    {
        $outra = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'name' => 'Poupança',
            'initial_balance' => 2000,
            'overdraft_limit' => 0,
        ]);

        $this->editar(['account_id' => $outra->id])->assertSessionHasNoErrors();

        // Na outra conta os 500 cabem: o resgate da corrente é desfeito.
        $this->assertSame(800.0, $this->inv->fresh()->aplicado);
        $this->assertSame(0, $this->resgateLigado());
        $this->assertNull($this->despesa->fresh()->funding_source);
        $this->assertSame(1000.0, $this->conta->fresh()->balance);
        $this->assertSame(1500.0, $outra->fresh()->balance);
    }

    /**
     * Registra as queries de "conta por id" que a reconciliação dispara ANTES do
     * estorno (o `delete` em `investment_contributions` é o marcador). Em sqlite
     * o `lockForUpdate` compila para nada (`SQLiteGrammar::compileLock` devolve
     * ''), então não dá para procurar "for update" no SQL — o que se assegura é
     * a ORDEM das queries de lock, pelos bindings de `where accounts.id = ?` (com as aspas
     * da gramática do banco em uso).
     *
     * @return list<int> ids das contas na ordem em que foram travadas
     */
    private function contasTravadasAntesDoEstorno(callable $agir): array
    {
        $queries = [];
        DB::listen(function (QueryExecuted $q) use (&$queries) {
            $queries[] = ['sql' => $q->sql, 'bindings' => $q->bindings];
        });

        $agir();

        // Os marcadores escritos pela gramática do banco em uso: aspas no sqlite, crase no
        // MySQL. Com as aspas do sqlite fixas, no MySQL o estorno nunca era achado (M-3).
        $gramatica = DB::connection()->getQueryGrammar();
        $marcadorDoEstorno = 'delete from '.$gramatica->wrapTable('investment_contributions');
        $contaPorId = 'from '.$gramatica->wrapTable('accounts').' where '.$gramatica->wrap('accounts.id').' = ?';

        $estorno = null;
        foreach ($queries as $i => $q) {
            if (str_starts_with($q['sql'], $marcadorDoEstorno)) {
                $estorno = $i;
                break;
            }
        }
        $this->assertNotNull($estorno, 'a reconciliação precisa ter estornado a fonte');

        // Do estorno para trás, colhe a sequência contígua de "conta por id".
        $ids = [];
        for ($i = $estorno - 1; $i >= 0; $i--) {
            if (! str_contains($queries[$i]['sql'], $contaPorId)) {
                break;
            }
            array_unshift($ids, (int) $queries[$i]['bindings'][0]);
        }

        return $ids;
    }

    /**
     * Trocar de conta envolve DUAS contas: a antiga (onde vive o resgate que o
     * estorno desfaz) e a nova (que o `spend` debita). Antes só a nova era
     * travada. Agora as duas, em id CRESCENTE — sem ordem fixa, duas edições
     * cruzadas (A→B e B→A) em paralelo seriam um deadlock ABBA.
     */
    public function test_trocar_de_conta_trava_as_duas_contas_em_ordem_crescente(): void
    {
        $outra = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'name' => 'Outra', 'initial_balance' => 2000, 'overdraft_limit' => 0,
        ]);
        $this->assertGreaterThan($this->conta->id, $outra->id);

        $ids = $this->contasTravadasAntesDoEstorno(
            fn () => $this->editar(['account_id' => $outra->id])->assertSessionHasNoErrors(),
        );

        $this->assertSame([$this->conta->id, $outra->id], $ids, 'antiga (menor) e nova (maior), nesta ordem');

        // E a reconciliação em si continua certa.
        $this->assertSame(800.0, $this->inv->fresh()->aplicado);
        $this->assertSame(0, $this->resgateLigado());
        $this->assertSame(1000.0, $this->conta->fresh()->balance);
        $this->assertSame(1500.0, $outra->fresh()->balance);
    }

    /** Sentido inverso (da conta de id MAIOR para a de id MENOR): a ordem continua crescente. */
    public function test_trocar_de_conta_no_sentido_inverso_mantem_a_ordem_crescente(): void
    {
        $outra = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'name' => 'Outra', 'initial_balance' => 1000, 'overdraft_limit' => 0,
        ]);
        // R$ 800 aplicados a partir de "Outra" → 200 disponíveis lá.
        $this->inv->contributions()->create([
            'account_id' => $outra->id, 'made_by_user_id' => $this->user->id,
            'type' => 'aporte', 'amount' => 800, 'date' => '2026-08-01',
        ]);

        // Despesa de 500 em "Outra", que só coube resgatando 300.
        $this->actingAs($this->user)->post(route('transactions.store'), [
            'type' => 'expense', 'amount' => '500,00', 'account_id' => $outra->id,
            'date' => '2026-08-05', 'description' => 'Despesa na Outra',
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO, 'funding_investment_id' => $this->inv->id,
        ])->assertSessionHasNoErrors();
        $despesa = Transaction::where('description', 'Despesa na Outra')->firstOrFail();
        $this->assertSame('300.00', (string) $despesa->funding_amount);

        // Move para a conta do setUp (id MENOR). Ela está com disponível 0
        // (1000 − 500 de saldo, 800 − 300 = 500 reservados), então os 500 só cabem
        // resgatando do investimento — a fonte já vai no payload. O que se afere
        // aqui é a ORDEM dos locks: a nova (menor) tem de vir antes da antiga.
        $ids = $this->contasTravadasAntesDoEstorno(
            fn () => $this->actingAs($this->user)->from(route('transactions.index'))
                ->patch(route('transactions.update', $despesa), [
                    'type' => 'expense', 'amount' => '500,00', 'account_id' => $this->conta->id,
                    'date' => '2026-08-05', 'description' => 'Despesa na Outra',
                    'funding_source' => FundingSource::RESGATE_INVESTIMENTO, 'funding_investment_id' => $this->inv->id,
                ])->assertSessionHasNoErrors(),
        );

        $this->assertSame([$this->conta->id, $outra->id], $ids, 'nova (menor) antes da antiga (maior)');
        $this->assertSame($this->conta->id, $despesa->fresh()->account_id);
        // A despesa saiu da Outra e o resgate de 300 dela foi desfeito: 1000 de
        // saldo, 800 reservados de novo → 200 disponíveis.
        $this->assertSame(1000.0, $outra->fresh()->balance);
        $this->assertSame(200.0, $outra->fresh()->available);
    }

    /** Sem troca de conta, só UMA conta é travada (a própria) — nada de lock a mais. */
    public function test_mudar_so_o_valor_trava_uma_conta_so(): void
    {
        $ids = $this->contasTravadasAntesDoEstorno(
            fn () => $this->editar(['amount' => '100,00'])->assertSessionHasNoErrors(),
        );

        $this->assertSame([$this->conta->id], $ids);
    }

    public function test_virar_receita_continua_reconciliando(): void
    {
        $this->editar(['type' => 'income'])->assertSessionHasNoErrors();

        $this->assertSame(800.0, $this->inv->fresh()->aplicado);
        $this->assertSame(0, $this->resgateLigado());
        $this->assertNull($this->despesa->fresh()->funding_source);
        $this->assertSame(1500.0, $this->conta->fresh()->balance);
    }
}
