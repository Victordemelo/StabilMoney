<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Support\DefaultCategories;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Categoria criada em Categorias não aparecia no modal Lançar (out/2026 — Victor): o modal
 * mora no SHELL, e o salvar da tela de Categorias recarrega só o #content pelo pjax. O select
 * de categoria agora leva `data-pjax-atualizar` (o `nav.js` traz as opções da página nova),
 * e a ordem é a da tela de Categorias — fixas no topo, depois a ordem que a pessoa arrumou.
 */
class CategoriaNovaApareceNoLancarTest extends TestCase
{
    use RefreshDatabase;

    private function selectDeCategoria(User $user, ?string $url = null, string $id = 'lm-category'): Element
    {
        $html = $this->actingAs($user)->get($url ?? route('transactions.index'))->assertOk()->getContent();
        $select = HTMLDocument::createFromString($html, LIBXML_NOERROR)->getElementById($id);
        $this->assertNotNull($select, "A página não tem o select #{$id}.");

        return $select;
    }

    /** @return list<string> */
    private function opcoes(Element $select, string $tipo): array
    {
        $nomes = [];
        foreach ($select->querySelectorAll("optgroup[data-type=\"{$tipo}\"] option") as $opt) {
            $nomes[] = trim(preg_replace('/^\S+\s/u', '', trim($opt->textContent)));
        }

        return $nomes;
    }

    public function test_o_select_de_categoria_do_modal_acompanha_o_pjax(): void
    {
        $user = User::factory()->create();
        Account::factory()->for($user)->create(['type' => 'checking', 'initial_balance' => 100]);

        $select = $this->selectDeCategoria($user);
        $this->assertTrue($select->hasAttribute('data-pjax-atualizar'));
        $this->assertSame('', $select->getAttribute('data-pjax-atualizar'), 'Só os filhos: o valor escolhido é do JS.');
    }

    public function test_categoria_criada_chega_no_modal_na_ordem_da_tela_de_categorias(): void
    {
        $user = User::factory()->create();
        DefaultCategories::seedFor($user);
        Account::factory()->for($user)->create(['type' => 'checking', 'initial_balance' => 100]);

        $this->actingAs($user)->postJson(route('categories.store'), [
            'name' => 'Academia', 'type' => 'expense', 'color' => '#1FA06E', 'icon' => '🏋️',
        ])->assertSuccessful();

        $despesas = $this->opcoes($this->selectDeCategoria($user), 'expense');
        $this->assertContains('Academia', $despesas);

        $tela = $this->actingAs($user)->get(route('categories.index'))
            ->viewData('expenseCategories')->pluck('name')->values()->all();
        $this->assertSame($tela, $despesas, 'O modal segue a ordem da tela de Categorias.');
        $this->assertSame('Alimentação', $despesas[0], 'As fixas vêm no topo.');
        $this->assertTrue(Category::where('user_id', $user->id)->where('name', 'Academia')->exists());
    }

    public function test_a_pagina_cheia_de_lancamento_usa_a_mesma_ordem(): void
    {
        // A página cheia (fallback sem JS) e a edição ordenavam por nome: a mesma lista
        // aparecia em outra sequência conforme o caminho.
        $user = User::factory()->create();
        DefaultCategories::seedFor($user);
        $conta = Account::factory()->for($user)->create(['type' => 'checking', 'initial_balance' => 100]);
        Category::factory()->expense()->for($user)->create(['name' => 'Academia', 'position' => Category::TRILHO_LIVRE + 50]);

        $tela = $this->actingAs($user)->get(route('categories.index'))
            ->viewData('expenseCategories')->pluck('name')->values()->all();

        $this->assertSame($tela, $this->opcoes($this->selectDeCategoria($user, route('transactions.create'), 'category_id'), 'expense'));

        $lancamento = Transaction::factory()->for($user)->create([
            'account_id' => $conta->id, 'type' => 'income', 'amount' => 10, 'date' => now()->addDay()->toDateString(),
        ]);
        $this->assertSame($tela, $this->opcoes(
            $this->selectDeCategoria($user, route('transactions.edit', $lancamento), 'category_id'), 'expense'));
    }
}
