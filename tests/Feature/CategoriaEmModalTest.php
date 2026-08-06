<?php

namespace Tests\Feature;

use App\Http\Controllers\CategoryController;
use App\Models\Category;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Criar/editar categoria em MODAL, na própria listagem.
 *
 * Antes, os dois caminhos navegavam para uma tela cheia (`categories.create` /
 * `categories.edit`). Agora a listagem traz um modal compartilhado que envia por
 * AJAX — e as telas cheias continuam de pé como FALLBACK sem JS, que é o padrão
 * do app (o botão "Lançar" da topbar faz igual).
 *
 * O que este teste amarra:
 *  • a listagem entrega o modal E os gatilhos, sem perder o href de fallback;
 *  • o modal nasce FORA de qualquer `.card` — dentro dela o `position: fixed`
 *    colapsa (a `.card` tem overflow:hidden + animação com transform, e
 *    transform em ancestral vira o bloco de contenção do fixed);
 *  • criar e editar por AJAX gravam de verdade e devolvem JSON (não redirect);
 *  • validação volta 422 em PT-BR sem criar nada;
 *  • categoria de outra família continua fora de alcance;
 *  • o drag & drop (PATCH JSON) e as telas cheias não regrediram.
 */
class CategoriaEmModalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /** Cabeçalhos do fetch do modal (categories.js). */
    private function comoModal(): array
    {
        return ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];
    }

    // ================= A tela =================

    public function test_a_listagem_entrega_o_modal_e_o_gatilho_sem_perder_o_fallback(): void
    {
        $response = $this->actingAs($this->user)->get(route('categories.index'));

        $response->assertOk();
        // O modal em si, com os primitivos do design system.
        $response->assertSee('id="catModal"', false);
        $response->assertSee('class="modal-scrim"', false);
        $response->assertSee('data-cat-form', false);
        // Gatilho no botão do topo + href preservado (fallback sem JS).
        $response->assertSee('data-cat-open="new"', false);
        $response->assertSee('href="'.route('categories.create').'"', false);
    }

    public function test_o_botao_nova_categoria_abre_o_modal_e_ainda_linka_a_tela_cheia(): void
    {
        $html = $this->actingAs($this->user)->get(route('categories.index'))->getContent();

        // A MESMA tag precisa ter o gatilho e o href: um gatilho sem href
        // deixaria quem está sem JS sem caminho nenhum para criar categoria.
        $this->assertMatchesRegularExpression(
            '/<a[^>]*href="'.preg_quote(route('categories.create'), '/').'"[^>]*data-cat-open="new"[^>]*>/',
            $html,
            'o botão "Nova categoria" precisa abrir o modal E manter o href de fallback',
        );
    }

    public function test_o_lapis_de_cada_chip_abre_o_modal_com_os_dados_da_categoria(): void
    {
        $categoria = Category::factory()->expense()->for($this->user)->create([
            'name' => 'Padaria',
            'color' => '#F0A93B',
            'icon' => '🍽️',
        ]);

        $html = $this->actingAs($this->user)->get(route('categories.index'))->getContent();

        // O modal é preenchido a partir dos data-* do chip — sem eles ele abriria vazio.
        $this->assertStringContainsString('data-name="Padaria"', $html);
        $this->assertStringContainsString('data-color="#F0A93B"', $html);
        $this->assertStringContainsString('data-icon="🍽️"', $html);
        $this->assertStringContainsString('data-type="expense"', $html);
        $this->assertStringContainsString('data-update-url="'.route('categories.update', $categoria).'"', $html);

        // E o lápis: gatilho + href da tela cheia (fallback).
        $this->assertMatchesRegularExpression(
            '/<a[^>]*href="'.preg_quote(route('categories.edit', $categoria), '/').'"[^>]*data-cat-open="edit"[^>]*>/',
            $html,
        );
    }

    public function test_o_chip_marca_a_categoria_fixa_para_o_modal_travar_o_tipo(): void
    {
        Category::factory()->expense()->for($this->user)->create([
            'name' => 'Moradia',
            'is_locked' => true,
        ]);

        $this->actingAs($this->user)->get(route('categories.index'))
            ->assertOk()
            ->assertSee('data-locked="1"', false)
            // O aviso que o modal revela quando o tipo está travado.
            ->assertSee('data-cat-locked-hint', false)
            ->assertSee('categoria fixa: não pode mudar de tipo');
    }

    /**
     * O modal NÃO pode nascer dentro de uma `.card`: ela tem overflow:hidden e
     * animação com transform, e transform em ancestral vira o bloco de contenção
     * de qualquer `position: fixed` descendente — o painel encolhe para a largura
     * do card e sai recortado. Já custou uma rodada no modal de excluir conta.
     */
    public function test_o_modal_nao_nasce_dentro_de_uma_card(): void
    {
        $html = $this->actingAs($this->user)->get(route('categories.index'))->getContent();

        $doc = new DOMDocument;
        $anterior = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_use_internal_errors($anterior);

        $xpath = new DOMXPath($doc);
        $modal = $xpath->query('//*[@id="catModal"]');
        $this->assertSame(1, $modal->length, 'o modal precisa existir na listagem');

        $ancestraisCard = $xpath->query('//*[@id="catModal"]/ancestor::*[contains(concat(" ", normalize-space(@class), " "), " card ")]');
        $this->assertSame(0, $ancestraisCard->length, 'o modal não pode ter uma .card como ancestral');
    }

    // ================= Criar por AJAX =================

    public function test_criar_por_ajax_grava_a_categoria_e_devolve_json(): void
    {
        $response = $this->actingAs($this->user)->post(route('categories.store'), [
            'name' => 'Assinaturas',
            'type' => 'expense',
            'color' => '#8B5CF6',
            'icon' => '📺',
        ], $this->comoModal());

        // JSON, não redirect: o modal só precisa do OK — ele fecha e manda a
        // página recarregar por pjax.
        $response->assertCreated();
        $response->assertJson(['ok' => true]);

        $this->assertDatabaseHas('categories', [
            'user_id' => $this->user->id,
            'name' => 'Assinaturas',
            'type' => 'expense',
            'color' => '#8B5CF6',
            'icon' => '📺',
        ]);
    }

    public function test_criar_receita_por_ajax_respeita_o_tipo_escolhido_no_toggle(): void
    {
        $this->actingAs($this->user)->post(route('categories.store'), [
            'name' => 'Aluguel recebido',
            'type' => 'income',
            'color' => '#1FA06E',
            'icon' => '💰',
        ], $this->comoModal())->assertCreated();

        $categoria = Category::where('name', 'Aluguel recebido')->firstOrFail();
        $this->assertSame('income', $categoria->type);
        $this->assertSame('#1FA06E', $categoria->color);
    }

    public function test_criar_pelo_formulario_cheio_continua_redirecionando(): void
    {
        // Sem Accept JSON o fluxo antigo não muda — é o caminho de quem está sem JS.
        $this->actingAs($this->user)->post(route('categories.store'), [
            'name' => 'Pets',
            'type' => 'expense',
        ])->assertRedirect(route('categories.index'));

        $this->assertDatabaseHas('categories', ['name' => 'Pets']);
    }

    // ================= Editar por AJAX =================

    public function test_editar_por_ajax_altera_a_categoria(): void
    {
        $categoria = Category::factory()->expense()->for($this->user)->create([
            'name' => 'Nome Antigo',
            'color' => '#0F6B47',
            'icon' => '📦',
        ]);

        // O caminho EXATO do modal: POST + _method=PUT (multipart com PUT real
        // não é parseado pelo PHP), com Accept JSON.
        $response = $this->actingAs($this->user)->post(route('categories.update', $categoria), [
            '_method' => 'PUT',
            'name' => 'Nome Novo',
            'type' => 'expense',
            'color' => '#EC4899',
            'icon' => '🎁',
        ], $this->comoModal());

        $response->assertOk();
        $response->assertExactJson(['ok' => true]);

        $categoria->refresh();
        $this->assertSame('Nome Novo', $categoria->name);
        $this->assertSame('#EC4899', $categoria->color);
        $this->assertSame('🎁', $categoria->icon);
    }

    public function test_editar_por_ajax_pode_trocar_o_tipo_de_categoria_livre(): void
    {
        $categoria = Category::factory()->expense()->for($this->user)->create(['name' => 'Freelas']);

        $this->actingAs($this->user)->putJson(route('categories.update', $categoria), [
            'name' => 'Freelas',
            'type' => 'income',
            'color' => '#1FA06E',
            'icon' => '💼',
        ])->assertOk();

        $this->assertSame('income', $categoria->fresh()->type);
    }

    public function test_editar_categoria_fixa_pelo_modal_renomeia_mas_nao_troca_o_tipo(): void
    {
        $categoria = Category::factory()->expense()->for($this->user)->create([
            'name' => 'Saúde',
            'is_locked' => true,
        ]);

        // Renomear/repintar passa…
        $this->actingAs($this->user)->post(route('categories.update', $categoria), [
            '_method' => 'PUT',
            'name' => 'Saúde e bem-estar',
            'type' => 'expense',
            'color' => '#18B6BE',
            'icon' => '💊',
        ], $this->comoModal())->assertOk();

        $this->assertSame('Saúde e bem-estar', $categoria->fresh()->name);

        // …mudar de tipo, não (o modal trava o toggle; o servidor é a palavra final).
        $this->actingAs($this->user)->post(route('categories.update', $categoria), [
            '_method' => 'PUT',
            'name' => 'Saúde e bem-estar',
            'type' => 'income',
        ], $this->comoModal())
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');

        $this->assertSame('expense', $categoria->fresh()->type);
    }

    // ================= Validação =================

    public function test_nome_vazio_devolve_422_em_portugues_e_nao_cria_nada(): void
    {
        $response = $this->actingAs($this->user)->post(route('categories.store'), [
            'name' => '',
            'type' => 'expense',
        ], $this->comoModal());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
        // A mensagem vai direto para o banner do modal — precisa estar em PT-BR.
        $this->assertSame(
            'Informe o nome da categoria.',
            $response->json('errors.name.0'),
        );

        $this->assertSame(0, Category::where('user_id', $this->user->id)->count());
    }

    public function test_cor_invalida_devolve_422_e_nao_cria_nada(): void
    {
        $this->actingAs($this->user)->post(route('categories.store'), [
            'name' => 'Qualquer',
            'type' => 'expense',
            'color' => 'roxo',
        ], $this->comoModal())
            ->assertStatus(422)
            ->assertJsonValidationErrors('color');

        $this->assertSame(0, Category::where('user_id', $this->user->id)->count());
    }

    // ================= Isolamento =================

    public function test_categoria_de_outra_familia_nao_pode_ser_editada_pelo_modal(): void
    {
        $estranho = User::factory()->create();
        $alheia = Category::factory()->expense()->for($estranho)->create(['name' => 'Sigilosa']);

        $this->actingAs($this->user)->post(route('categories.update', $alheia), [
            '_method' => 'PUT',
            'name' => 'Invadida',
            'type' => 'expense',
        ], $this->comoModal())->assertForbidden();

        $this->assertSame('Sigilosa', $alheia->fresh()->name);
    }

    public function test_dependente_edita_categoria_da_familia_pelo_modal(): void
    {
        // Conta-família: a categoria é da família, então o dependente também mexe.
        $dependente = User::factory()->create([
            'account_owner_id' => $this->user->id,
            'is_admin' => false,
        ]);
        $categoria = Category::factory()->expense()->for($this->user)->create(['name' => 'Mercado']);

        $this->actingAs($dependente)->post(route('categories.update', $categoria), [
            '_method' => 'PUT',
            'name' => 'Supermercado',
            'type' => 'expense',
        ], $this->comoModal())->assertOk();

        $this->assertSame('Supermercado', $categoria->fresh()->name);
    }

    // ================= Fallback sem JS =================

    public function test_as_telas_cheias_continuam_de_pe(): void
    {
        $categoria = Category::factory()->expense()->for($this->user)->create();

        // Elas são o caminho de quem está sem JS — e os pickers vêm do
        // controller agora (constantes ICONES/CORES), então uma tela que
        // renderize sem eles é regressão.
        $this->actingAs($this->user)->get(route('categories.create'))
            ->assertOk()
            ->assertSee('icon-picker', false)
            ->assertSee('color-picker', false);

        $this->actingAs($this->user)->get(route('categories.edit', $categoria))
            ->assertOk()
            ->assertSee('icon-picker', false)
            ->assertSee('color-picker', false);
    }

    public function test_o_modal_e_o_formulario_cheio_oferecem_os_mesmos_pickers(): void
    {
        // Fonte única (CategoryController::ICONES/CORES): se um dia alguém
        // acrescentar um emoji só num lado, este teste cai.
        $modal = $this->actingAs($this->user)->get(route('categories.index'))->getContent();
        $cheio = $this->actingAs($this->user)->get(route('categories.create'))->getContent();

        foreach (CategoryController::ICONES as $emoji) {
            $this->assertStringContainsString('value="'.$emoji.'"', $modal);
            $this->assertStringContainsString('value="'.$emoji.'"', $cheio);
        }
        foreach (CategoryController::CORES as $cor) {
            $this->assertStringContainsString('value="'.$cor.'"', $modal);
            $this->assertStringContainsString('value="'.$cor.'"', $cheio);
        }
    }

    /** O drag & drop entre colunas (PATCH JSON) não pode ter regredido. */
    public function test_o_drag_and_drop_continua_respondendo_ok(): void
    {
        $categoria = Category::factory()->expense()->for($this->user)->create([
            'name' => 'Bicos',
            'color' => '#59C497',
            'icon' => '💼',
        ]);

        $this->actingAs($this->user)->patchJson(route('categories.update', $categoria), [
            'name' => 'Bicos',
            'type' => 'income',
            'color' => '#59C497',
            'icon' => '💼',
        ])
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        $this->assertSame('income', $categoria->fresh()->type);
    }
}
