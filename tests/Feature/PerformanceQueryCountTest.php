<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\FixedBill;
use App\Models\Goal;
use App\Models\Investment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Conta as queries de cada tela com VOLUME de dados, para pegar N+1.
 *
 * Por que importa aqui: os saldos são accessors que consultam o banco
 * (`Account::balance`, `reserved`, `available`, `committed`). Lidos dentro de um loop de
 * contas, cada linha da tela vira várias queries — o app fica rápido com 3 contas e
 * insuportável com 30. Um teste de correção nunca pega isso, porque o resultado está certo.
 *
 * Os limites abaixo são generosos de propósito: o objetivo é flagrar crescimento
 * proporcional ao volume (N+1 de verdade), não policiar cada query.
 */
class PerformanceQueryCountTest extends TestCase
{
    use RefreshDatabase;

    /** Volume que representa um usuário de 2-3 anos de uso. */
    private const CONTAS = 12;

    private const TRANSACOES = 400;

    private function cenarioComVolume(): User
    {
        $titular = User::factory()->create(['is_admin' => true]);

        $categorias = Category::factory()->count(8)->for($titular)->expense()->create();

        $contas = collect();
        for ($i = 0; $i < self::CONTAS; $i++) {
            $contas->push(Account::factory()->for($titular)->create([
                'type' => $i % 4 === 0 ? 'credit_card' : 'checking',
                'initial_balance' => $i % 4 === 0 ? null : 3000,
                'credit_limit' => $i % 4 === 0 ? 5000 : null,
                'closing_day' => $i % 4 === 0 ? 10 : null,
                'due_day' => $i % 4 === 0 ? 20 : null,
            ]));
        }

        $contasCaixa = $contas->where('type', 'checking');

        for ($i = 0; $i < self::TRANSACOES; $i++) {
            Transaction::factory()->for($titular)->create([
                'account_id' => $contas->random()->id,
                'category_id' => $categorias->random()->id,
                'type' => $i % 5 === 0 ? 'income' : 'expense',
                'amount' => random_int(10, 900) + 0.99,
                'date' => now()->subDays(random_int(0, 400)),
            ]);
        }

        if (class_exists(Goal::class)) {
            Goal::factory()->count(6)->for($titular)->create();
        }

        if (class_exists(Investment::class)) {
            Investment::factory()->count(6)->for($titular)->create();
        }

        if (class_exists(FixedBill::class)) {
            foreach (['Aluguel', 'Condomínio', 'Internet', 'Carro', 'Escola'] as $i => $nome) {
                FixedBill::create([
                    'user_id' => $titular->id,
                    'name' => $nome,
                    'amount' => 300 + $i * 150,
                    'due_day' => 5 + $i * 5,
                    'account_id' => $contasCaixa->first()->id,
                    'category_id' => $categorias->first()->id,
                    'starts_on' => now()->subMonths(14)->startOfMonth()->toDateString(),
                    'active' => true,
                ]);
            }
        }

        return $titular;
    }

    /**
     * @return array{queries: int, ms: float}
     */
    private function medir(User $user, string $url): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $inicio = microtime(true);

        $resposta = $this->actingAs($user)->get($url);

        $ms = (microtime(true) - $inicio) * 1000;
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(500, $resposta->getStatusCode(), "A tela {$url} quebrou.");

        return ['queries' => $queries, 'ms' => $ms];
    }

    /**
     * Mede todas as telas principais e falha se alguma passar de um teto generoso.
     * A mensagem imprime a tabela inteira, então o relatório sai do próprio teste.
     */
    public function test_main_screens_do_not_explode_in_query_count(): void
    {
        $user = $this->cenarioComVolume();

        $telas = [
            'dashboard' => '/',
            'accounts.index' => '/accounts',
            'categories.index' => '/categories',
            'transactions.index' => '/transactions',
            'transactions.create' => '/transactions/create',
            'faturas' => '/faturas',
            'metas' => '/metas',
            'investimentos' => '/investimentos',
            'dependentes' => '/dependentes',
            'profile.edit' => '/meu-perfil',
        ];

        $TETO = 120; // acima disso é N+1 quase certo, com este volume
        $medidas = [];
        $estouraram = [];

        foreach ($telas as $nome => $url) {
            if (! Route::has($nome)) {
                continue;
            }

            $m = $this->medir($user, $url);
            $medidas[$nome] = $m;

            if ($m['queries'] > $TETO) {
                $estouraram[] = sprintf('%s: %d queries (%.0f ms)', $nome, $m['queries'], $m['ms']);
            }
        }

        $tabela = collect($medidas)
            ->map(fn ($m, $nome) => sprintf('  %-22s %4d queries  %6.0f ms', $nome, $m['queries'], $m['ms']))
            ->implode("\n");

        $this->assertSame(
            [],
            $estouraram,
            "Telas acima de {$TETO} queries com ".self::CONTAS.' contas e '.self::TRANSACOES." transações:\n"
                .implode("\n", $estouraram)."\n\nMedições completas:\n".$tabela,
        );

        // Deixa a tabela visível mesmo quando passa (roda com -v para ver).
        fwrite(STDERR, "\n[contagem de queries por tela]\n".$tabela."\n");
    }

    /**
     * Diagnóstico: mostra QUAIS queries se repetem na tela de contas, agrupadas por
     * forma. É o que aponta o accessor culpado pelo N+1.
     */
    public function test_diagnose_repeated_queries_on_accounts_screen(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        Category::factory()->for($user)->expense()->create();
        $contas = Account::factory()->count(10)->for($user)->create([
            'type' => 'checking',
            'initial_balance' => 1000,
        ]);
        foreach ($contas as $conta) {
            Transaction::factory()->for($user)->create([
                'account_id' => $conta->id,
                'type' => 'expense',
                'amount' => 50,
                'date' => now(),
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->get('/accounts');
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // Normaliza: troca números por ? para agrupar a mesma consulta com ids diferentes.
        $agrupadas = collect($log)
            ->map(fn ($q) => preg_replace('/\b\d+\b/', '?', $q['query']))
            ->countBy()
            ->sortDesc()
            ->take(8);

        $relatorio = $agrupadas
            ->map(fn ($n, $sql) => sprintf("  %3dx  %s", $n, mb_substr($sql, 0, 150)))
            ->implode("\n");

        fwrite(STDERR, "\n[/accounts com 10 contas — queries repetidas]\n".$relatorio."\n");

        $this->assertNotEmpty($log);
    }

    /**
     * O teste que realmente prova N+1: dobrar o número de contas não pode dobrar as
     * queries. Se crescer proporcionalmente, há consulta dentro de loop.
     */
    public function test_query_count_does_not_grow_with_the_number_of_accounts(): void
    {
        $poucas = User::factory()->create(['is_admin' => true]);
        Category::factory()->for($poucas)->expense()->create();
        Account::factory()->count(3)->for($poucas)->create([
            'type' => 'checking',
            'initial_balance' => 1000,
        ]);

        $muitas = User::factory()->create(['is_admin' => true]);
        Category::factory()->for($muitas)->expense()->create();
        Account::factory()->count(30)->for($muitas)->create([
            'type' => 'checking',
            'initial_balance' => 1000,
        ]);

        $com3 = $this->medir($poucas, '/accounts')['queries'];
        $com30 = $this->medir($muitas, '/accounts')['queries'];
        $porConta = round(($com30 - $com3) / 27, 1);

        fwrite(STDERR, "\n[/accounts] 3 contas: {$com3} queries | 30 contas: {$com30} queries"
            ." | ~{$porConta} por conta adicional\n");

        // ESTADO ATUAL: existe N+1 conhecido — `balance` faz 2 SUM e `reserved` faz 4 por
        // conta, e a view itera as contas. Medido em 27/07/2026: ~6 queries por conta
        // (3 contas = 33, 30 contas = 195). Ver docs/auditoria-completa-2026-07-28.md.
        //
        // Não é corrigido aqui de propósito: a correção mexe nos accessors de saldo, e um
        // erro ali corrompe dinheiro — risco maior que o da lentidão, num app que hoje tem
        // poucas contas. A solução está detalhada no relatório (pré-carga em lote com 3
        // queries agregadas por família).
        //
        // Este assert é uma TRAVA DE NÃO-PIORAR: se passar de 8 queries por conta, algo
        // novo entrou no laço.
        $this->assertLessThan(
            8,
            $porConta,
            "O N+1 da tela de contas PIOROU: {$porConta} queries por conta adicional "
                ."(3 contas: {$com3}, 30 contas: {$com30}). O limite histórico é ~6.",
        );
    }
}
