<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Goal;
use App\Models\Investment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 🚨 A parede do painel: administrar NÃO é ver o dinheiro dos outros.
 *
 * O painel mostra a estrutura da conta (existe, tem tantos dependentes, tantos
 * lançamentos, entrou tal dia) e nunca uma quantia. Esta suíte é a guarda dessa regra —
 * e ela é dupla:
 *
 *  1. Nenhum valor aparece no HTML das telas.
 *  2. Nenhuma QUERY do painel toca coluna de dinheiro. É a metade que importa mais: uma
 *     parede que existe só no Blade cai no dia em que alguém acrescentar um campo na
 *     tela ou der um `dd()` no objeto.
 */
class PainelAdminNaoVeValoresTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private User $titular;

    /** Valores fáceis de achar num HTML — nenhum pode vazar. */
    private const SALDO = 987654.32;

    private const DESPESA = 4321.99;

    private const META = 55555.11;

    private const INVESTIMENTO = 77777.44;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.enabled' => true]);

        $this->admin = Admin::factory()->comDoisFatores()->create();

        $this->titular = User::factory()->create(['name' => 'Fulano Titular', 'is_admin' => true]);
        User::factory()->create(['account_owner_id' => $this->titular->id, 'name' => 'Dependente Um']);

        $conta = Account::factory()->for($this->titular)->create([
            'type' => 'checking',
            'initial_balance' => self::SALDO,
            'overdraft_limit' => 0,
        ]);

        $categoria = Category::factory()->for($this->titular)->expense()->create();

        Transaction::factory()->for($this->titular)->create([
            'account_id' => $conta->id,
            'category_id' => $categoria->id,
            'type' => 'expense',
            'amount' => self::DESPESA,
            'date' => now()->toDateString(),
        ]);

        $meta = Goal::create([
            'user_id' => $this->titular->id,
            'name' => 'Viagem',
            'emoji' => '✈️',
            'color' => '#0F6B47',
            'target_amount' => self::META,
        ]);
        $meta->contributions()->create([
            'account_id' => $conta->id,
            'type' => 'aporte',
            'amount' => self::META,
            'date' => now()->toDateString(),
        ]);

        $inv = Investment::create([
            'user_id' => $this->titular->id,
            'name' => 'CDB',
            'classe' => 'renda_fixa',
            'indexador' => 'cdi',
            'taxa' => 100,
        ]);
        $inv->contributions()->create([
            'account_id' => $conta->id,
            'type' => 'aporte',
            'amount' => self::INVESTIMENTO,
            'date' => now()->toDateString(),
        ]);
    }

    /** Autentica no painel já com o segundo fator provado. */
    private function comoAdmin(): static
    {
        return $this->actingAs($this->admin, 'admin')->withSession(['admin_2fa_ok' => true]);
    }

    /** Todos os números que não podem escapar, em todos os formatos plausíveis. */
    private function numerosProibidos(): array
    {
        $valores = [self::SALDO, self::DESPESA, self::META, self::INVESTIMENTO];
        $formas = [];

        foreach ($valores as $v) {
            $formas[] = number_format($v, 2, ',', '.');  // 987.654,32 (pt-BR)
            $formas[] = number_format($v, 2, '.', '');   // 987654.32  (cru do banco)
            $formas[] = (string) (int) $v;               // 987654     (inteiro)
        }

        return $formas;
    }

    private function assertSemValores(string $html, string $tela): void
    {
        foreach ($this->numerosProibidos() as $numero) {
            $this->assertStringNotContainsString(
                $numero,
                $html,
                "A tela {$tela} vazou um valor financeiro ({$numero}). O painel mostra estrutura, nunca quantia.",
            );
        }
    }

    public function test_a_lista_de_pessoas_nao_mostra_valor_nenhum(): void
    {
        $resposta = $this->comoAdmin()->get(route('painel.pessoas'));

        $resposta->assertOk()->assertSee('Fulano Titular');
        $this->assertSemValores($resposta->getContent(), 'pessoas');
    }

    public function test_a_ficha_da_pessoa_nao_mostra_valor_nenhum(): void
    {
        $resposta = $this->comoAdmin()->get(route('painel.pessoa', $this->titular->id));

        $resposta->assertOk()
            ->assertSee('Fulano Titular')
            ->assertSee('Dependente Um');

        $this->assertSemValores($resposta->getContent(), 'ficha');
    }

    public function test_a_visao_geral_nao_mostra_valor_nenhum(): void
    {
        $resposta = $this->comoAdmin()->get(route('painel.home'));

        $resposta->assertOk();
        $this->assertSemValores($resposta->getContent(), 'visão geral');
    }

    /**
     * A metade que importa mais: as colunas de dinheiro não são nem SELECIONADAS.
     *
     * Sem isto, bastaria alguém acrescentar `{{ $u->accounts->sum('balance') }}` numa
     * view para o painel virar um raio-x financeiro — e nenhum teste de HTML pegaria
     * o dia em que a coluna passou a estar disponível no objeto.
     */
    public function test_nenhuma_query_do_painel_le_coluna_de_dinheiro(): void
    {
        $proibidas = ['initial_balance', 'amount', 'credit_limit', 'overdraft_limit', 'target_amount', 'funding_amount'];

        $consultas = [];
        DB::listen(function ($q) use (&$consultas) {
            $consultas[] = $q->sql;
        });

        $this->comoAdmin()->get(route('painel.pessoas'))->assertOk();
        $this->comoAdmin()->get(route('painel.pessoa', $this->titular->id))->assertOk();
        $this->comoAdmin()->get(route('painel.home'))->assertOk();

        $this->assertNotEmpty($consultas, 'Nenhuma query capturada — o teste não estaria provando nada.');

        // Procurar pelo NOME da coluna não basta: `select *` a traz sem nomeá-la, e um
        // `->with('accounts')` passava por aqui carregando saldo e limite de todo mundo
        // (conferido por mutação em 23/09/2026). O painel só CONTA linhas destas tabelas.
        $tabelasDeDinheiro = 'accounts|transactions|goals|goal_contributions|investments|investment_contributions|fixed_bills';

        foreach ($consultas as $sql) {
            foreach ($proibidas as $coluna) {
                $this->assertStringNotContainsString(
                    $coluna,
                    $sql,
                    "Uma query do painel tocou a coluna monetária `{$coluna}`:\n{$sql}",
                );
            }

            $this->assertDoesNotMatchRegularExpression(
                '/select\s+\*\s+from\s+"('.$tabelasDeDinheiro.')"|"('.$tabelasDeDinheiro.')"\.\*/i',
                $sql,
                "Uma query do painel carregou linhas inteiras de uma tabela de dinheiro:\n{$sql}",
            );
        }
    }

    /** Contar registros é permitido; somar valores, não. */
    public function test_o_painel_conta_registros_sem_somar_valores(): void
    {
        $this->comoAdmin()->get(route('painel.pessoa', $this->titular->id))
            ->assertOk()
            ->assertSee('Lançamentos')
            ->assertSee('Dependentes');
    }
}
