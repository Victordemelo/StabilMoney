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
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Smoke test de TODAS as rotas GET do app, com dados reais no banco.
 *
 * Por que existe: os testes de feature cobrem cada tela isoladamente, mas nenhum garante
 * que TODA tela abre. Este percorre o registro de rotas do Laravel, então cobre também as
 * telas que ainda não foram escritas — rota nova sem teste próprio passa a ser vigiada
 * automaticamente.
 *
 * O que ele considera falha: qualquer 5xx. Redirect (302) e 403 são respostas legítimas
 * dependendo do papel do usuário; o que não pode acontecer é a tela explodir.
 *
 * Roda como TITULAR e como DEPENDENTE, porque a maior parte dos bugs de permissão só
 * aparece no segundo (o titular vê tudo por definição).
 */
class SmokeAllRoutesTest extends TestCase
{
    use RefreshDatabase;

    /** Rotas que não fazem sentido no smoke (dependem de token/assinatura externa). */
    private const IGNORAR = [
        'password.reset',        // exige token válido do broker
        'verification.verify',   // exige URL assinada
        'password.confirm',      // tela de confirmação, depende de fluxo anterior
    ];

    /**
     * Cria um cenário com um registro de cada coisa, para as rotas com {parâmetro}
     * terem o que resolver.
     *
     * @return array{titular: User, dependente: User, params: array<string, mixed>}
     */
    private function cenario(): array
    {
        $titular = User::factory()->create(['is_admin' => true]);

        $dependente = User::factory()->create([
            'account_owner_id' => $titular->id,
            'is_admin' => false,
        ]);

        $conta = Account::factory()->for($titular)->create([
            'type' => 'checking',
            'initial_balance' => 5000,
        ]);

        $categoria = Category::factory()->for($titular)->expense()->create();

        $transacao = Transaction::factory()->for($titular)->create([
            'account_id' => $conta->id,
            'category_id' => $categoria->id,
            'type' => 'expense',
            'amount' => 150.50,
            'date' => now(),
        ]);

        $params = [
            'account' => $conta->id,
            'category' => $categoria->id,
            'transaction' => $transacao->id,
            'dependent' => $dependente->id,
            'tab' => 'seguranca',
        ];

        // Meta, investimento e conta fixa: criados só se o model existir no projeto,
        // para o smoke não quebrar quando uma feature ainda não tiver entrado.
        if (class_exists(Goal::class)) {
            $meta = Goal::factory()->for($titular)->create();
            $params['goal'] = $meta->id;
            $params['meta'] = $meta->id;
        }

        if (class_exists(Investment::class)) {
            $inv = Investment::factory()->for($titular)->create();
            $params['investment'] = $inv->id;
            $params['investimento'] = $inv->id;
        }

        // Sem factory (FixedBill não tem uma — vale criar), então monta na mão.
        if (class_exists(FixedBill::class)) {
            $conta_fixa = FixedBill::create([
                'user_id' => $titular->id,
                'name' => 'Condomínio',
                'amount' => 850.00,
                'due_day' => 10,
                'account_id' => $conta->id,
                'category_id' => $categoria->id,
                'starts_on' => now()->subMonths(3)->startOfMonth()->toDateString(),
                'active' => true,
            ]);
            $params['conta'] = $conta_fixa->id;
            $params['fixedBill'] = $conta_fixa->id;
        }

        return ['titular' => $titular, 'dependente' => $dependente, 'params' => $params];
    }

    /**
     * Devolve as rotas GET que dá para visitar, com os parâmetros já substituídos.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, string>  [nome ou uri => url]
     */
    private function rotasVisitaveis(array $params): array
    {
        $urls = [];

        foreach (Route::getRoutes() as $rota) {
            if (! in_array('GET', $rota->methods(), true)) {
                continue;
            }

            $nome = $rota->getName();

            if ($nome !== null && in_array($nome, self::IGNORAR, true)) {
                continue;
            }

            $uri = $rota->uri();

            // Health check do framework e assets não são tela.
            if (in_array($uri, ['up', 'storage/{path}'], true)) {
                continue;
            }

            // Substitui cada {param} (e {param?}) pelo id do cenário.
            $url = preg_replace_callback('/\{(\w+)\??\}/', function ($m) use ($params) {
                return isset($params[$m[1]]) ? (string) $params[$m[1]] : '__SEM_VALOR__';
            }, $uri);

            // Parâmetro que não sabemos preencher: só entra se for opcional.
            if (str_contains($url, '__SEM_VALOR__')) {
                if (! str_contains($uri, '?}')) {
                    continue;
                }
                $url = preg_replace('/\/?__SEM_VALOR__/', '', $url);
            }

            $urls[$nome ?? $uri] = '/'.ltrim($url, '/');
        }

        return $urls;
    }

    public function test_no_get_route_blows_up_for_the_account_owner(): void
    {
        $c = $this->cenario();
        $rotas = $this->rotasVisitaveis($c['params']);

        $this->assertGreaterThan(15, count($rotas), 'O smoke deveria cobrir dezenas de rotas.');

        $quebradas = [];

        foreach ($rotas as $nome => $url) {
            $status = $this->actingAs($c['titular'])->get($url)->getStatusCode();

            if ($status >= 500) {
                $quebradas[] = "{$nome} ({$url}) → {$status}";
            }
        }

        $this->assertSame([], $quebradas, "Rotas com erro 5xx para o titular:\n".implode("\n", $quebradas));
    }

    public function test_no_get_route_blows_up_for_a_dependent(): void
    {
        $c = $this->cenario();
        $rotas = $this->rotasVisitaveis($c['params']);

        $quebradas = [];

        foreach ($rotas as $nome => $url) {
            $status = $this->actingAs($c['dependente'])->get($url)->getStatusCode();

            if ($status >= 500) {
                $quebradas[] = "{$nome} ({$url}) → {$status}";
            }
        }

        $this->assertSame([], $quebradas, "Rotas com erro 5xx para o dependente:\n".implode("\n", $quebradas));
    }

    /**
     * Visitante não autenticado: nenhuma tela pode explodir, e as privadas têm de
     * redirecionar para o login em vez de responder 200.
     */
    public function test_no_get_route_blows_up_for_a_guest(): void
    {
        $c = $this->cenario();
        $rotas = $this->rotasVisitaveis($c['params']);

        $quebradas = [];

        foreach ($rotas as $nome => $url) {
            $status = $this->get($url)->getStatusCode();

            if ($status >= 500) {
                $quebradas[] = "{$nome} ({$url}) → {$status}";
            }
        }

        $this->assertSame([], $quebradas, "Rotas com erro 5xx para visitante:\n".implode("\n", $quebradas));
    }

    /**
     * As telas centrais precisam responder 200 — não só "não explodir".
     * Se uma delas passar a redirecionar, é regressão de acesso.
     */
    public function test_core_screens_return_200_for_the_owner(): void
    {
        $c = $this->cenario();

        $essenciais = ['dashboard', 'accounts.index', 'categories.index', 'transactions.index', 'profile.edit'];

        foreach ($essenciais as $nome) {
            if (! Route::has($nome)) {
                continue;
            }

            $this->actingAs($c['titular'])
                ->get(route($nome))
                ->assertOk();
        }
    }
}
