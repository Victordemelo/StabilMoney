<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Histórico: filtro por categoria, agrupado por tipo no select.
 *
 * O agrupamento não é enfeite: "Outros" existe nas duas listas padrão, e sem o
 * <optgroup> a mesma palavra apareceria duas vezes sem nada dizendo qual é qual.
 *
 * O id chega pela URL, então vale a mesma regra dos outros filtros: categoria de
 * outra família é IGNORADA, nunca aplicada. Aceitá-la transformaria o filtro numa
 * sonda — pelo que a lista devolve (ou deixa de devolver) daria para inferir a
 * categoria dos outros.
 */
class FiltroDeCategoriaNoHistoricoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    private Category $mercado;

    private Category $salario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'initial_balance' => 50000,
        ]);

        $this->mercado = Category::factory()->for($this->user)->create([
            'name' => 'Mercado', 'type' => 'expense', 'icon' => '🛒',
        ]);
        $this->salario = Category::factory()->for($this->user)->create([
            'name' => 'Salário', 'type' => 'income', 'icon' => '💰',
        ]);

        Transaction::factory()->for($this->user)->for($this->conta)->for($this->mercado)
            ->expense()->create(['amount' => 100, 'description' => 'Compra do mês']);
        Transaction::factory()->for($this->user)->for($this->salario)->for($this->conta)
            ->income()->create(['amount' => 3000, 'description' => 'Salário de agosto']);
        Transaction::factory()->for($this->user)->for($this->conta)
            ->expense()->create(['amount' => 50, 'category_id' => null, 'description' => 'Sem categoria']);
    }

    private function listar(array $filtros = []): Collection
    {
        return $this->actingAs($this->user)
            ->get(route('transactions.index', $filtros))
            ->assertOk()
            ->viewData('transactions')
            ->pluck('description');
    }

    public function test_filtra_por_categoria(): void
    {
        $itens = $this->listar(['category' => $this->mercado->id]);

        $this->assertCount(1, $itens);
        $this->assertTrue($itens->contains('Compra do mês'));
    }

    public function test_sem_filtro_vem_tudo_inclusive_o_que_nao_tem_categoria(): void
    {
        $this->assertCount(3, $this->listar());
    }

    public function test_categoria_de_outra_familia_e_ignorada(): void
    {
        $estranho = User::factory()->create();
        $daOutraFamilia = Category::factory()->for($estranho)->create([
            'name' => 'Segredo', 'type' => 'expense',
        ]);

        // Ignorado ⇒ a lista volta INTEIRA. Se o id fosse aplicado, viria vazia — e
        // essa diferença já contaria ao curioso que a categoria existe.
        $this->assertCount(3, $this->listar(['category' => $daOutraFamilia->id]));
    }

    public function test_id_invalido_nao_derruba_a_lista(): void
    {
        foreach (['abc', '0', '-1', '999999', '<script>'] as $lixo) {
            $this->assertCount(3, $this->listar(['category' => $lixo]), "o filtro '{$lixo}' não pode derrubar a lista");
        }
    }

    public function test_combina_com_os_outros_filtros(): void
    {
        // Categoria de receita + tipo despesa: combinação legítima que não casa
        // com nada. O importante é não estourar.
        $this->assertCount(0, $this->listar(['category' => $this->salario->id, 'type' => 'expense']));
        $this->assertCount(1, $this->listar(['category' => $this->salario->id, 'type' => 'income']));
    }

    public function test_o_select_agrupa_por_tipo(): void
    {
        $html = $this->actingAs($this->user)->get(route('transactions.index'))->assertOk()->getContent();

        $this->assertStringContainsString('name="category"', $html);
        $this->assertStringContainsString('<optgroup label="Receitas">', $html);
        $this->assertStringContainsString('<optgroup label="Despesas">', $html);

        // Receitas vêm antes de Despesas — mesma ordem das colunas da tela de
        // Categorias, para a pessoa não ter de reaprender a leitura aqui.
        $this->assertLessThan(
            strpos($html, '<optgroup label="Despesas">'),
            strpos($html, '<optgroup label="Receitas">'),
        );
    }

    public function test_o_select_so_mostra_categorias_da_propria_familia(): void
    {
        $estranho = User::factory()->create();
        Category::factory()->for($estranho)->create(['name' => 'Categoria alheia', 'type' => 'expense']);

        $this->actingAs($this->user)->get(route('transactions.index'))
            ->assertOk()
            ->assertSee('Mercado')
            ->assertDontSee('Categoria alheia');
    }

    public function test_a_familia_inteira_ve_as_mesmas_categorias(): void
    {
        $dependente = User::factory()->create(['account_owner_id' => $this->user->id, 'is_admin' => false]);

        // Escopo por ownerId(), não por auth()->id(): dependente e titular
        // compartilham categorias, então o filtro tem de valer para os dois.
        $this->actingAs($dependente)->get(route('transactions.index'))
            ->assertOk()
            ->assertSee('Mercado');

        $itens = $this->actingAs($dependente)
            ->get(route('transactions.index', ['category' => $this->mercado->id]))
            ->assertOk()
            ->viewData('transactions');

        $this->assertCount(1, $itens);
    }

    public function test_o_filtro_ativo_mostra_o_botao_limpar(): void
    {
        $this->actingAs($this->user)
            ->get(route('transactions.index', ['category' => $this->mercado->id]))
            ->assertOk()
            ->assertSee('filtro-limpar', escape: false);

        $this->actingAs($this->user)->get(route('transactions.index'))
            ->assertOk()
            ->assertDontSee('filtro-limpar', escape: false);
    }

    public function test_a_ordem_do_select_segue_a_da_tela_de_categorias(): void
    {
        // `position` é a ordem que o usuário arrumou arrastando os chips.
        // Ordenar por nome aqui faria o filtro discordar do que ele vê lá.
        $this->salario->update(['position' => 50]);
        $outra = Category::factory()->for($this->user)->create([
            'name' => 'Aluguel recebido', 'type' => 'income', 'position' => 1,
        ]);

        $html = $this->actingAs($this->user)->get(route('transactions.index'))->assertOk()->getContent();

        $this->assertLessThan(
            strpos($html, 'value="'.$this->salario->id.'"'),
            strpos($html, 'value="'.$outra->id.'"'),
        );
    }
}
