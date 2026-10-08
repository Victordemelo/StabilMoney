<?php

namespace Tests\Feature;

use App\Models\Account;
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
            'Cartões de crédito' => ['expense', ['credit_card']],
            'Cartões de débito' => ['expense', ['debit_card']],
            'Pix e TED' => ['expense', ['pix']],
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

    public function test_lancar_despesa_em_contas_a_pagar_so_oferece_metodos(): void
    {
        $html = $this->actingAs($this->user)->get(route('faturas.index'))->assertOk()->getContent();
        $select = $this->select($html, 'lanc-method');

        $this->assertStringNotContainsString('Contas correntes', $select);
        $this->assertStringNotContainsString('Contas poupança', $select);
        $this->assertStringNotContainsString('>Poupança', $select);
        foreach (['Cartões de crédito', 'Cartões de débito', 'Pix e TED'] as $grupo) {
            $this->assertStringContainsString('<optgroup label="'.$grupo.'">', $select);
        }
    }

    public function test_na_pagina_cheia_a_edicao_mantem_a_conta_de_banco_para_despesa_antiga(): void
    {
        $antiga = Transaction::factory()->for($this->user)->expense()->create([
            'account_id' => $this->corrente->id, 'amount' => 10, 'date' => now()->toDateString(),
        ]);

        $criar = $this->select($this->actingAs($this->user)->get(route('transactions.create'))->assertOk()->getContent(), 'account_id');
        $this->assertStringContainsString('<optgroup label="Contas correntes" data-para="income transfer">', $criar);

        // Na edição a conta de banco vale também para despesa: senão o filtro trocaria a conta
        // sozinho, e trocar a conta reconcilia o dinheiro.
        $editar = $this->select($this->actingAs($this->user)->get(route('transactions.edit', $antiga))->assertOk()->getContent(), 'account_id');
        $this->assertStringContainsString('<optgroup label="Contas correntes" data-para="income transfer expense">', $editar);
    }

    public function test_sem_metodo_o_lancar_despesa_explica_o_que_falta(): void
    {
        $sozinho = User::factory()->create();
        Account::factory()->for($sozinho)->create(['type' => 'checking', 'initial_balance' => 100]);

        $this->actingAs($sozinho)->get(route('faturas.index'))->assertOk()
            ->assertSee('Para lançar uma despesa, cadastre um cartão, Pix ou TED em Contas e cartões.');
    }

    private function select(string $html, string $id): string
    {
        $inicio = strpos($html, 'id="'.$id.'"');
        $this->assertNotFalse($inicio, "select #{$id} não encontrado");

        return substr($html, $inicio, strpos($html, '</select>', $inicio) - $inicio);
    }
}
