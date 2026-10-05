<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ajustes de tela pedidos pelo Victor (out/2026): meta sem prazo alinhada às vizinhas, lápis
 * no card do titular e e-mail por extenso na Família, Meu perfil com um card só de dados, e
 * os cartões de Contas e cartões do mesmo tamanho e alinhados.
 *
 * Layout é CSS, que um teste de feature não renderiza: onde é visual, as sentinelas leem a
 * folha de estilo.
 */
class AjustesDeTelaMetasFamiliaPerfilCartoesTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_sem_prazo_mantem_as_duas_linhas_do_cabecalho(): void
    {
        $user = User::factory()->create();
        Goal::create(['user_id' => $user->id, 'name' => 'teste', 'emoji' => '🛡️', 'color' => '#0F6B47', 'target_amount' => 12]);

        $this->actingAs($user)->get(route('metas.index'))->assertOk()
            ->assertSee('<span class="meta-prazo meta-sem-prazo">Sem prazo</span>', false)
            ->assertSee('<span class="meta-by" aria-hidden="true">&nbsp;</span>', false);
    }

    public function test_familia_lapis_do_titular_leva_as_configuracoes_e_email_por_extenso(): void
    {
        $titular = User::factory()->create(['email' => 'titular@exemplo.test']);
        User::factory()->create(['account_owner_id' => $titular->id, 'name' => 'Lucas', 'email' => 'lucas.demo@stabilmoney.test']);

        $html = $this->actingAs($titular)->get(route('dependentes'))->assertOk()->getContent();

        $this->assertStringContainsString('<a class="dp-edit" href="'.route('settings').'" aria-label="Abrir as Configurações da sua conta"', $html);
        $this->assertStringContainsString('aria-label="Editar Lucas"', $html);
        // O e-mail mora fora do cabeçalho (que divide a linha com os botões).
        $this->assertDoesNotMatchRegularExpression('#<div class="dp-id">(?:(?!</div>\s*</div>).)*dp-rel#s', $html);

        $css = file_get_contents(resource_path('css/design-system.css'));
        $this->assertMatchesRegularExpression('/\.dp-email\s*\{[^}]*margin:\s*-6px 0 14px 58px;[^}]*text-overflow:\s*ellipsis;/', $css);
    }

    public function test_meu_perfil_tem_um_card_de_dados_com_um_salvar_e_os_numeros_centralizados(): void
    {
        $user = User::factory()->create();
        $html = $this->actingAs($user)->get(route('profile.edit'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'class="card sec-card span12 perfil-dados-card"'));
        $this->assertStringContainsString('<h4>Quem é você</h4>', $html);
        $this->assertStringContainsString('<h4>Como falamos com você</h4>', $html);
        $this->assertSame(1, substr_count($html, '>Salvar alterações</button>'));
        // Resumo num painel só, três colunas iguais, cada uma com uma linha de contexto.
        $this->assertSame(3, substr_count($html, '<div class="ph-stat">'));
        $this->assertStringContainsString('Só você por enquanto', $html);

        $css = file_get_contents(resource_path('css/design-system.css'));
        $this->assertMatchesRegularExpression('/\.ph-stat \+ \.ph-stat \{ border-left: 1px solid var\(--line\); \}/', $css);
        $this->assertMatchesRegularExpression('/\.ph-stat strong \{[^}]*font-size: 32px;/', $css);
        $this->assertMatchesRegularExpression('/grid-template-areas:\s*"quem contato" "nome email" "nasc tel"/', $css);
    }

    public function test_a_sidebar_nao_tem_mais_o_novo_lancamento(): void
    {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk()
            ->assertDontSee('Novo lançamento</span>', false);
    }

    public function test_cartoes_do_mesmo_tamanho_e_alinhados(): void
    {
        $css = file_get_contents(resource_path('css/forms.css'));

        // A imagem do banco fica absoluta na caixa com proporção fixa: a do Santander (695×441)
        // não estica mais o cartão.
        $this->assertMatchesRegularExpression('/\.bankcard \{[^}]*position: relative;[^}]*aspect-ratio: 1\.586;[^}]*min-height: 0;/', $css);
        $this->assertMatchesRegularExpression('/\.bankcard img \{[^}]*position: absolute; inset: 0;/', $css);
        // Nome e tipo numa linha cada; Editar/Excluir presos ao pé.
        $this->assertMatchesRegularExpression('/\.acct-name \{[^}]*white-space: nowrap;[^}]*text-overflow: ellipsis;/', $css);
        $this->assertMatchesRegularExpression('/\.acct-card \{ display: flex; flex-direction: column; \}/', $css);
        $this->assertMatchesRegularExpression('/\.acct-card-actions \{[^}]*margin-top: auto;/', $css);

        $user = User::factory()->create();
        Account::factory()->for($user)->creditCard()->create(['name' => 'teste banco do brasil com nome comprido', 'bank' => 'banco_do_brasil']);
        $this->actingAs($user)->get(route('accounts.index'))->assertOk()
            ->assertSee('<span class="acct-name" title="teste banco do brasil com nome comprido">', false);
    }
}
