<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use App\Support\DefaultCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Receitas fixas (out/2026 — pedido do Victor): Salário, Vale alimentação e Vale transporte
 * "vão sempre estar ali e não podem ser apagados". Mesmas regras das despesas fixas: topo
 * da coluna, sem excluir, sem trocar de tipo. As famílias que já existiam ganham as três
 * pela migration, que também põe toda coluna nos trilhos (fixas antes das livres).
 */
class ReceitasFixasNasCategoriasTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_05_000000_receitas_fixas_e_trilhos_das_categorias.php';

    private function migration(): object
    {
        return require base_path(self::MIGRATION);
    }

    /** @return list<string> */
    private function coluna(User $user, string $tipo): array
    {
        return $this->actingAs($user)->get(route('categories.index'))->assertOk()
            ->viewData($tipo === 'income' ? 'incomeCategories' : 'expenseCategories')
            ->pluck('name')->values()->all();
    }

    public function test_conta_nova_nasce_com_as_tres_receitas_fixas_no_topo(): void
    {
        $user = User::factory()->create();
        DefaultCategories::seedFor($user);

        $this->assertSame(
            ['Salário', 'Vale alimentação', 'Vale transporte', 'Freelance', 'Investimentos', 'Presente', 'Outros'],
            $this->coluna($user, 'income'),
        );
        $this->assertSame(16, Category::where('user_id', $user->id)->count());
    }

    public function test_receita_fixa_nao_pode_ser_excluida_nem_virar_despesa(): void
    {
        $user = User::factory()->create();
        DefaultCategories::seedFor($user);
        $vale = Category::where('user_id', $user->id)->where('name', 'Vale transporte')->firstOrFail();

        $this->actingAs($user)->delete(route('categories.destroy', $vale))->assertSessionHasErrors('category');
        $this->assertModelExists($vale);

        $this->actingAs($user)->patchJson(route('categories.update', $vale), [
            'name' => 'Vale transporte', 'type' => 'expense', 'color' => $vale->color, 'icon' => $vale->icon,
        ])->assertStatus(422)->assertJsonValidationErrors('type');
        $this->assertSame('income', $vale->fresh()->type);

        $html = $this->actingAs($user)->get(route('categories.index'))->getContent();
        $this->assertStringNotContainsString('aria-label="Excluir Vale transporte"', $html);
        $this->assertStringContainsString('Vale transporte é uma categoria fixa e não pode ser excluída', $html);
    }

    public function test_a_migration_da_o_cadeado_a_quem_ja_tinha_e_cria_o_que_faltava(): void
    {
        $titular = User::factory()->create();
        $dependente = User::factory()->create(['account_owner_id' => $titular->id, 'is_admin' => false]);

        // Uma família de antes: Salário livre e já abaixo de outra livre; uma livre
        // reordenada para cima de uma fixa de despesa (o defeito de antes).
        $freela = Category::factory()->income()->for($titular)->create(['name' => 'Freelance', 'position' => 0]);
        $salario = Category::factory()->income()->for($titular)->create(['name' => 'Salário', 'position' => 1, 'color' => '#6366F1']);
        $lazer = Category::factory()->expense()->for($titular)->create(['name' => 'Lazer', 'position' => 0]);
        $moradia = Category::factory()->expense()->for($titular)->create(['name' => 'Moradia', 'position' => 1, 'is_locked' => true]);

        $this->migration()->up();

        // O Salário que existia ganhou o cadeado e ficou com a cor que a pessoa escolheu.
        $this->assertTrue($salario->fresh()->isLocked());
        $this->assertSame('#6366F1', $salario->fresh()->color);
        $this->assertSame(['Salário', 'Vale alimentação', 'Vale transporte', 'Freelance'], $this->coluna($titular, 'income'));
        $this->assertSame(['Moradia', 'Lazer'], $this->coluna($titular, 'expense'));
        $this->assertSame(Category::TRILHO_LIVRE, $freela->fresh()->position);
        $this->assertSame(Category::TRILHO_LIVRE, $lazer->fresh()->position);
        $this->assertSame(0, $moradia->fresh()->position);

        // Categoria é da família: o dependente não ganha cópia.
        $this->assertSame(0, Category::where('user_id', $dependente->id)->count());

        // Rodar de novo não duplica nem muda nada.
        $this->migration()->up();
        $this->assertSame(1, Category::where('user_id', $titular->id)->where('name', 'Vale alimentação')->count());
        $this->assertSame(['Salário', 'Vale alimentação', 'Vale transporte', 'Freelance'], $this->coluna($titular, 'income'));
    }

    public function test_o_down_tira_o_cadeado_das_tres_receitas(): void
    {
        $user = User::factory()->create();
        DefaultCategories::seedFor($user);

        $this->migration()->down();

        $this->assertFalse(Category::where('user_id', $user->id)->where('type', 'income')->where('is_locked', true)->exists());
        $this->assertTrue(Category::where('user_id', $user->id)->where('name', 'Moradia')->value('is_locked'));
    }

    public function test_no_mysql_o_salario_escrito_diferente_ganha_o_cadeado_e_vai_para_o_topo(): void
    {
        // O MySQL (utf8mb4_unicode_ci) acha "salário" ao procurar "Salário"; o sqlite, não.
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Comparação sem caixa/acento é do MySQL (job `mysql` do CI).');
        }

        $titular = User::factory()->create();
        $salario = Category::factory()->income()->for($titular)->create(['name' => 'salário', 'position' => 5]);

        $this->migration()->up();

        $this->assertTrue($salario->fresh()->isLocked());
        $this->assertSame(0, $salario->fresh()->position);
        $this->assertSame(1, Category::where('user_id', $titular->id)->where('type', 'income')->where('name', 'Salário')->count());
    }
}
