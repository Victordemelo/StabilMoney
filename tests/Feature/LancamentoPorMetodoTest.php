<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FixedBill;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Por onde cada lançamento entra e sai (out/2026 — regra do Victor):
 *
 * - RECEITA entra em conta de banco (corrente/poupança) — não num cartão de débito ou Pix;
 * - DESPESA sai por um MÉTODO: cartão de crédito (fatura), de débito, Pix ou TED — não "direto"
 *   da corrente;
 * - TRANSFERÊNCIA é entre conta corrente e poupança.
 *
 * O agrupamento é um só (`Account::gruposDeLancamento`) e alimenta o modal "Lançar", a página
 * cheia e o "Lançar despesa" de Contas a pagar. É regra de TELA: o servidor continua aceitando
 * a despesa na conta (o método espelho submete o id dela, e o histórico tem despesas assim).
 */
class LancamentoPorMetodoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $corrente;

    private Account $poupanca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->corrente = Account::factory()->for($this->user)->create(['type' => 'checking', 'name' => 'Corrente', 'bank' => 'nubank', 'initial_balance' => 1000]);
        $this->poupanca = Account::factory()->for($this->user)->create(['type' => 'savings', 'name' => 'Poupança', 'bank' => 'caixa', 'initial_balance' => 500]);
        Account::factory()->for($this->user)->creditCard()->create(['name' => 'Roxinho', 'bank' => 'nubank']);
        Account::factory()->for($this->user)->debitCard($this->corrente->id)->create(['name' => 'Débito Nubank', 'bank' => 'nubank']);
        Account::factory()->for($this->user)->pix($this->corrente->id)->create(['name' => 'Pix Nubank', 'bank' => 'nubank']);
    }

    public function test_os_grupos_dizem_para_que_tipo_cada_metodo_vale(): void
    {
        $grupos = collect(Account::gruposDeLancamento(Account::paymentOptions($this->user->id)))
            ->mapWithKeys(fn ($g) => [$g['rotulo'] => [$g['para'], $g['opcoes']->pluck('type')->all()]]);

        $this->assertSame([
            // Um grupo por tipo (out/2026): o tipo no título e o nome curto na opção.
            'Contas correntes' => ['income transfer', ['checking']],
            'Contas poupança' => ['income transfer', ['savings']],
            // Despesa: Pix e TED, débito, crédito (14/10/2026 — pedido do Victor).
            'Pix e TED' => ['expense', ['pix']],
            'Cartões de débito' => ['expense', ['debit_card']],
            'Cartões de crédito' => ['expense', ['credit_card']],
        ], $grupos->all());
    }

    public function test_o_modal_lancar_agrupa_e_marca_cada_opcao(): void
    {
        $html = $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->getContent();
        $select = $this->select($html, 'lm-account');

        $this->assertStringContainsString('<optgroup label="Contas correntes" data-para="income transfer">', $select);
        $this->assertStringContainsString('<optgroup label="Pix e TED" data-para="expense">', $select);
        // O Pix aparece no grupo dele, mas submete o id da conta.
        $this->assertMatchesRegularExpression('#<optgroup label="Pix e TED" data-para="expense">\s*<option value="'.$this->corrente->id.'" data-para="expense"#', $select);
    }

    public function test_pagar_conta_fixa_lista_os_metodos_primeiro_e_as_contas_no_fim(): void
    {
        FixedBill::factory()->create([
            'user_id' => $this->user->id, 'name' => 'Aluguel', 'amount' => 100, 'due_day' => 28,
            'account_id' => $this->corrente->id, 'starts_on' => now()->startOfMonth()->toDateString(), 'active' => true,
        ]);
        $html = $this->actingAs($this->user)->get(route('faturas.index'))->assertOk()->getContent();

        foreach (['fixa-conta', 'cf-conta', 'cfe-conta'] as $id) {
            $select = $this->select($html, $id);
            $ordem = array_map(fn ($g) => strpos($select, '<optgroup label="'.$g.'">'), ['Pix e TED', 'Cartões de débito', 'Cartões de crédito', 'Contas correntes']);
            $this->assertNotContains(false, $ordem, "O select {$id} precisa de todos os grupos.");
            $ordenada = $ordem;
            sort($ordenada);
            $this->assertSame($ordenada, $ordem, "O select {$id} vem na ordem Pix e TED, débito, crédito, contas.");
        }
    }

    public function test_lancar_despesa_em_contas_a_pagar_e_o_modal_lancar_ja_em_despesa(): void
    {
        // 14/10/2026 — pedido do Victor: "tem um Lançar despesa diferente". O botão abre o MESMO
        // modal Lançar do topo, já em Despesa; sem JS, a página cheia de nova transação.
        $html = $this->actingAs($this->user)->get(route('faturas.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '#<a class="btn-primary" href="'.preg_quote(route('transactions.create', ['type' => 'expense']), '#').'" id="lancarBtn" data-launch-open data-launch-tipo="expense">#',
            $html,
        );
        $this->assertStringNotContainsString('id="lancarModal"', $html);
        $this->assertStringNotContainsString('id="lanc-method"', $html);
        $this->assertStringContainsString('id="launchModal"', $html);
    }

    public function test_na_pagina_cheia_a_edicao_mantem_a_conta_de_banco_para_despesa_antiga(): void
    {
        $antiga = Transaction::factory()->for($this->user)->expense()->create([
            'account_id' => $this->corrente->id, 'amount' => 10, 'date' => now()->toDateString(),
        ]);

        $criar = $this->select($this->actingAs($this->user)->get(route('transactions.create'))->assertOk()->getContent(), 'account_id');
        $this->assertStringContainsString('<optgroup label="Contas correntes" data-para="income transfer">', $criar);

        // Na edição (13/10/2026) o "Onde" só tem o que vale para o tipo: numa despesa, os métodos.
        // A conta gravada aparece pelo método que a espelha (o débito, o primeiro), marcado — o
        // select nunca troca a conta sozinho, e trocar a conta reconcilia o dinheiro.
        $editar = $this->select($this->actingAs($this->user)->get(route('transactions.edit', $antiga))->assertOk()->getContent(), 'account_id');
        $this->assertStringNotContainsString('Contas correntes', $editar);
        $this->assertSame(1, substr_count($editar, 'selected'), 'Uma opção marcada só.');
        $this->assertMatchesRegularExpression('#value="'.$this->corrente->id.'"[^>]*\s+selected>#', $editar);
    }

    public function test_sem_metodo_o_lancar_despesa_explica_o_que_falta(): void
    {
        $sozinho = User::factory()->create();
        Account::factory()->for($sozinho)->create(['type' => 'checking', 'initial_balance' => 100]);

        // O "Lançar despesa" de Contas a pagar é o modal Lançar (14/10/2026), que diz o que falta
        // quando não há método (`launch.js`, coberto em tests/js/launch-metodos.test.js).
        $html = $this->actingAs($sozinho)->get(route('faturas.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-launch-open data-launch-tipo="expense"', $html);
        $this->assertStringContainsString('id="launchModal"', $html);
        $this->assertStringContainsString(
            'Para lançar uma despesa, cadastre um cartão, Pix ou TED em Contas e cartões.',
            file_get_contents(resource_path('js/sm/launch.js')),
        );
    }

    private function select(string $html, string $id): string
    {
        $inicio = strpos($html, 'id="'.$id.'"');
        $this->assertNotFalse($inicio, "select #{$id} não encontrado");

        return substr($html, $inicio, strpos($html, '</select>', $inicio) - $inicio);
    }
}
