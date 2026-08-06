<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use App\Support\DefaultCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Ordem das categorias na tela.
 *
 * Três mudanças de uma rodada só:
 *  1. RECEITA à esquerda, DESPESA à direita (era o contrário);
 *  2. o modal de nova categoria nasce em RECEITA, como o modal de "Lançar";
 *  3. o usuário ORDENA os chips dentro da coluna arrastando — o que exigiu a
 *     coluna `position`, a rota `categories.ordenar` e a listagem ordenada
 *     por ela.
 *
 * O que este teste amarra:
 *  • a listagem obedece a `position` e o desempate é ESTÁVEL (sem ele, duas
 *    categorias empatadas alternam de lugar entre um refresh e outro);
 *  • reordenar grava e a tela reflete;
 *  • 🔒 id de outra família no payload é IGNORADO (IDOR) e id inexistente não
 *    estoura — o servidor não confia na lista que recebe;
 *  • mover entre colunas troca o tipo E grava a posição no destino;
 *  • a ordem das colunas e o tipo padrão do modal, olhando a ORDEM no HTML;
 *  • o `down()` da migration remove mesmo a coluna.
 */
class OrdemDasCategoriasTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_08_06_000200_add_position_to_categories.php';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /** Cria uma categoria com posição fixada (o hook só age quando ela vem nula). */
    private function categoria(string $nome, string $tipo, ?int $posicao = null, bool $fixa = false): Category
    {
        $atributos = ['name' => $nome, 'type' => $tipo, 'is_locked' => $fixa];

        if ($posicao !== null) {
            $atributos['position'] = $posicao;
        }

        return Category::factory()->for($this->user)->create($atributos);
    }

    /** @return list<string> Nomes da coluna, na ordem em que a tela vai desenhá-los. */
    private function colunaNaTela(string $tipo): array
    {
        $chave = $tipo === 'income' ? 'incomeCategories' : 'expenseCategories';

        return $this->actingAs($this->user)->get(route('categories.index'))
            ->assertOk()
            ->viewData($chave)
            ->pluck('name')
            ->values()
            ->all();
    }

    // ================= A listagem obedece a `position` =================

    public function test_a_listagem_sai_na_ordem_de_position_e_nao_na_alfabetica(): void
    {
        // De propósito ao contrário do alfabeto: se a tela ordenasse por nome,
        // sairia Abacate, Manga, Salário.
        $this->categoria('Salário', 'income', 0);
        $this->categoria('Manga', 'income', 1);
        $this->categoria('Abacate', 'income', 2);

        $this->assertSame(['Salário', 'Manga', 'Abacate'], $this->colunaNaTela('income'));
    }

    public function test_o_desempate_e_estavel_quando_duas_categorias_empatam_na_posicao(): void
    {
        // Empate acontece se o PATCH de ordenar falhar no meio de um arraste
        // entre colunas. Sem desempate declarado, a ordem viria do humor do
        // banco e a lista trocaria de lugar entre um refresh e outro.
        $this->categoria('Zebra', 'income', 5);
        $this->categoria('Abacaxi', 'income', 5);

        $this->assertSame(['Abacaxi', 'Zebra'], $this->colunaNaTela('income'));
        // Duas leituras seguidas dão o mesmo resultado — é isso que "estável" quer dizer.
        $this->assertSame(['Abacaxi', 'Zebra'], $this->colunaNaTela('income'));
    }

    public function test_categoria_nova_entra_no_fim_da_coluna(): void
    {
        $this->categoria('Salário', 'income', 0);
        $this->categoria('Freelance', 'income', 1);

        // Sem posição explícita: quem decide é o hook do model.
        $this->actingAs($this->user)->post(route('categories.store'), [
            'name' => 'Aluguel recebido',
            'type' => 'income',
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(
            ['Salário', 'Freelance', 'Aluguel recebido'],
            $this->colunaNaTela('income'),
            'categoria nova não pode furar a fila da coluna',
        );
    }

    public function test_as_categorias_fixas_estreiam_antes_das_livres(): void
    {
        // Regra antiga da tela, agora expressa em `position` (trilho de estreia):
        // as fixas são as de uso recorrente e nascem no topo — mas o usuário
        // pode arrastar uma livre para cima delas depois.
        $novo = User::factory()->create();
        DefaultCategories::seedFor($novo);

        $despesas = $this->actingAs($novo)->get(route('categories.index'))
            ->viewData('expenseCategories')
            ->values();

        $this->assertTrue(
            $despesas->take(5)->every(fn ($c) => $c->isLocked()),
            'as fixas precisam estrear no topo da coluna de despesas',
        );
        $this->assertTrue($despesas->skip(5)->every(fn ($c) => ! $c->isLocked()));
    }

    // ================= Reordenar =================

    public function test_reordenar_grava_a_nova_ordem_e_a_listagem_reflete(): void
    {
        $salario = $this->categoria('Salário', 'income', 0);
        $freela = $this->categoria('Freelance', 'income', 1);
        $presente = $this->categoria('Presente', 'income', 2);

        $this->actingAs($this->user)->patchJson(route('categories.ordenar'), [
            'ids' => [$presente->id, $salario->id, $freela->id],
        ])->assertOk()->assertJson(['ok' => true, 'ordenadas' => 3]);

        $this->assertSame(0, $presente->fresh()->position);
        $this->assertSame(1, $salario->fresh()->position);
        $this->assertSame(2, $freela->fresh()->position);

        $this->assertSame(['Presente', 'Salário', 'Freelance'], $this->colunaNaTela('income'));
    }

    public function test_reordenar_pode_por_uma_categoria_livre_acima_de_uma_fixa(): void
    {
        // É o ponto da feature: a posição escolhida pelo usuário manda mais do
        // que o "fixas primeiro", que só vale como ordem de estreia.
        $fixa = $this->categoria('Alimentação', 'expense', 0, fixa: true);
        $livre = $this->categoria('Lazer', 'expense', 1);

        $this->actingAs($this->user)->patchJson(route('categories.ordenar'), [
            'ids' => [$livre->id, $fixa->id],
        ])->assertOk();

        $this->assertSame(['Lazer', 'Alimentação'], $this->colunaNaTela('expense'));
    }

    public function test_reordenar_exige_a_lista_de_ids(): void
    {
        $this->actingAs($this->user)->patchJson(route('categories.ordenar'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');
    }

    public function test_reordenar_exige_login(): void
    {
        $this->patch(route('categories.ordenar'), ['ids' => [1]])->assertRedirect(route('login'));
    }

    // ================= 🔒 Isolamento (IDOR) =================

    public function test_id_de_outra_familia_e_ignorado_e_nada_dela_muda(): void
    {
        $estranho = User::factory()->create();
        $alheia = Category::factory()->income()->for($estranho)->create([
            'name' => 'Sigilosa',
            'position' => 7,
        ]);

        $meuPrimeiro = $this->categoria('Salário', 'income', 0);
        $meuSegundo = $this->categoria('Freelance', 'income', 1);

        // O payload manda o id do vizinho no MEIO da lista.
        $this->actingAs($this->user)->patchJson(route('categories.ordenar'), [
            'ids' => [$meuSegundo->id, $alheia->id, $meuPrimeiro->id],
        ])->assertOk()->assertJson(['ordenadas' => 2]); // só as duas da família

        // Nada da outra família se mexeu — nem a posição, nem o dono.
        $alheia->refresh();
        $this->assertSame(7, $alheia->position);
        $this->assertSame($estranho->id, $alheia->user_id);

        // E as minhas foram numeradas ignorando o intruso (0 e 1, sem buraco).
        $this->assertSame(0, $meuSegundo->fresh()->position);
        $this->assertSame(1, $meuPrimeiro->fresh()->position);
    }

    public function test_id_inexistente_no_payload_nao_estoura(): void
    {
        $categoria = $this->categoria('Salário', 'income', 3);

        $this->actingAs($this->user)->patchJson(route('categories.ordenar'), [
            'ids' => [999999, $categoria->id, 123456],
        ])->assertOk()->assertJson(['ordenadas' => 1]);

        $this->assertSame(0, $categoria->fresh()->position);
    }

    public function test_id_repetido_nao_duplica_posicao(): void
    {
        $a = $this->categoria('Salário', 'income', 0);
        $b = $this->categoria('Freelance', 'income', 1);

        $this->actingAs($this->user)->patchJson(route('categories.ordenar'), [
            'ids' => [$b->id, $b->id, $a->id],
        ])->assertOk();

        $this->assertSame(0, $b->fresh()->position);
        $this->assertSame(1, $a->fresh()->position, 'o id repetido não pode consumir uma posição');
    }

    public function test_dependente_reordena_as_categorias_da_familia(): void
    {
        // Conta-família: categoria é da família, então o dependente também ordena.
        $dependente = User::factory()->create([
            'account_owner_id' => $this->user->id,
            'is_admin' => false,
        ]);

        $a = $this->categoria('Salário', 'income', 0);
        $b = $this->categoria('Freelance', 'income', 1);

        $this->actingAs($dependente)->patchJson(route('categories.ordenar'), [
            'ids' => [$b->id, $a->id],
        ])->assertOk();

        $this->assertSame(0, $b->fresh()->position);
    }

    // ================= Entre colunas =================

    public function test_mover_entre_colunas_troca_o_tipo_e_grava_a_posicao_no_destino(): void
    {
        // O caminho EXATO do JS: primeiro o PATCH que troca o tipo, depois o
        // PATCH que grava a ordem da coluna de destino.
        $destino1 = $this->categoria('Salário', 'income', 0);
        $destino2 = $this->categoria('Freelance', 'income', 1);
        $viajante = $this->categoria('Bicos', 'expense', 4);

        $this->actingAs($this->user)->patchJson(route('categories.update', $viajante), [
            'name' => 'Bicos',
            'type' => 'income',
            'color' => $viajante->color,
            'icon' => $viajante->icon,
        ])->assertOk();

        // Soltou entre Salário e Freelance.
        $this->actingAs($this->user)->patchJson(route('categories.ordenar'), [
            'ids' => [$destino1->id, $viajante->id, $destino2->id],
        ])->assertOk();

        $this->assertSame('income', $viajante->fresh()->type);
        $this->assertSame(1, $viajante->fresh()->position, 'a posição precisa ser a do destino, não a antiga');

        $this->assertSame(['Salário', 'Bicos', 'Freelance'], $this->colunaNaTela('income'));
        $this->assertSame([], $this->colunaNaTela('expense'));
    }

    // ================= A tela =================

    public function test_a_tela_mostra_receitas_na_primeira_coluna_e_despesas_na_segunda(): void
    {
        $html = $this->actingAs($this->user)->get(route('categories.index'))->getContent();

        $receitas = strpos($html, '<div class="cat-drop" data-type="income"');
        $despesas = strpos($html, '<div class="cat-drop" data-type="expense"');

        $this->assertIsInt($receitas, 'a coluna de receitas precisa existir');
        $this->assertIsInt($despesas, 'a coluna de despesas precisa existir');
        $this->assertLessThan(
            $despesas,
            $receitas,
            'RECEITA vem primeiro (à esquerda) e DESPESA depois — a ordem do HTML é a ordem da tela',
        );

        // E os títulos acompanham (o cabeçalho de cada coluna).
        $this->assertLessThan(
            strpos($html, '<h3>Despesas</h3>'),
            strpos($html, '<h3>Receitas</h3>'),
        );
    }

    public function test_a_tela_entrega_a_url_de_ordenar_para_o_javascript(): void
    {
        // Sem isto o categories.js não teria para onde mandar a nova ordem — e
        // pôr a URL num <script> inline exigiria nonce de CSP.
        $this->actingAs($this->user)->get(route('categories.index'))
            ->assertOk()
            ->assertSee('data-ordenar-url="'.route('categories.ordenar').'"', false);
    }

    public function test_o_modal_de_nova_categoria_abre_em_receita(): void
    {
        $html = $this->actingAs($this->user)->get(route('categories.index'))->getContent();

        // O radio de receita nasce marcado…
        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="cm-tt-income"[^>]*\bchecked\b[^>]*>/',
            $html,
            'o modal precisa abrir com RECEITA marcada',
        );
        // …e o de despesa, não (dois marcados deixariam o toggle indefinido).
        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]*id="cm-tt-expense"[^>]*\bchecked\b[^>]*>/',
            $html,
        );
        // O [data-type] é o que move a pílula do toggle e pinta o modal.
        $this->assertMatchesRegularExpression('/<form[^>]*data-cat-form[^>]*data-type="income"/', $html);
    }

    public function test_o_criar_agora_de_cada_coluna_continua_mandando_o_tipo_dela(): void
    {
        $html = $this->actingAs($this->user)->get(route('categories.index'))->getContent();

        // Quem clica no "Criar agora" de uma coluna vazia já disse o que quer —
        // esse tipo tem precedência sobre o padrão do modal.
        $this->assertStringContainsString('data-cat-type="expense"', $html);
        $this->assertStringContainsString('data-cat-type="income"', $html);

        // E o href de fallback (sem JS) leva para a tela cheia com o mesmo tipo.
        $this->assertStringContainsString(
            'href="'.route('categories.create', ['type' => 'expense']).'"',
            $html,
        );
    }

    // ================= A migration =================

    public function test_a_migration_e_reversivel(): void
    {
        $this->assertTrue(Schema::hasColumn('categories', 'position'));

        $migration = require base_path(self::MIGRATION);

        $migration->down();
        $this->assertFalse(
            Schema::hasColumn('categories', 'position'),
            'o down() precisa remover a coluna de verdade',
        );

        // Devolve o schema para os demais testes do processo.
        $migration->up();
        $this->assertTrue(Schema::hasColumn('categories', 'position'));
    }

    public function test_o_backfill_numera_cada_coluna_na_ordem_que_a_tela_ja_mostrava(): void
    {
        // Cenário "banco antigo": todas empatadas em 0, como nasceriam sem backfill.
        $this->categoria('Zebra', 'expense', 0);
        $this->categoria('Alimentação', 'expense', 0, fixa: true);
        $this->categoria('Abacaxi', 'expense', 0);

        (require base_path(self::MIGRATION))->up();

        // Fixa primeiro, depois alfabética — exatamente a ordem de antes da coluna.
        $this->assertSame(['Alimentação', 'Abacaxi', 'Zebra'], $this->colunaNaTela('expense'));
        $this->assertSame(
            [0, 1, 2],
            Category::where('user_id', $this->user->id)->orderBy('position')->pluck('position')->all(),
            'nenhuma linha pode sair do backfill empatada com outra',
        );
    }
}
