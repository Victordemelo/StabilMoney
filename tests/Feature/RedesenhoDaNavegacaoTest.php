<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Redesenho da navegação (out/2026): menu em "Seu dinheiro" / "Organização", item
 * "Família" no lugar do card de Dependentes, saldo em destaque no dashboard.
 *
 * Foi terminado depois de fotografado (antes × depois), e as fotos mostraram três defeitos
 * que a suíte inteira deixava passar:
 *
 *  1. A troca de nomes ficou pela metade: o menu dizia "Contas a pagar" e a tela abria com
 *     "Pagar despesas" (o mesmo em Movimentações/Histórico, Contas e cartões/Métodos de
 *     Pagamento, Família/Dependentes).
 *  2. O CSS escondia "Guardado em metas", "Investido" e o cheque especial do card
 *     Patrimônio — a linha vermelha do cheque em uso inclusive.
 *  3. No celular, o selo da variação ficava POR CIMA do valor dos cards compactos
 *     ("R$ 1.332,90" aparecia como "2,90"): rótulo e selo eram `position: absolute` e o
 *     card encolhia.
 *
 * (2) e (3) são CSS, que um teste de feature não renderiza; as sentinelas leem a folha de
 * estilo e barram a regra que produziu cada defeito.
 */
class RedesenhoDaNavegacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_cada_item_do_menu_abre_uma_tela_com_o_mesmo_nome(): void
    {
        $titular = User::factory()->create();
        User::factory()->create(['account_owner_id' => $titular->id]);
        Account::factory()->for($titular)->create(['type' => 'checking', 'initial_balance' => 100]);

        $html = $this->actingAs($titular)->get('/')->assertOk()->getContent();
        preg_match('#<aside class="sidebar[^"]*"[^>]*>.*?</aside>#s', $html, $sidebar);
        $this->assertNotEmpty($sidebar, 'A sidebar não foi encontrada no HTML do dashboard.');

        preg_match_all('#<a data-pjax class="nav-item[^"]*" href="([^"]+)">.*?<span class="nav-label">([^<]+)</span>#s',
            $sidebar[0], $itens, PREG_SET_ORDER);

        $rotulos = array_column($itens, 2);
        $this->assertSame([
            'Visão geral', 'Movimentações', 'Contas e cartões', 'Metas', 'Investimentos',
            'Contas a pagar', 'Categorias', 'Família',
        ], $rotulos);

        foreach ($itens as [, $href, $rotulo]) {
            $pagina = $this->actingAs($titular)->get($href)->assertOk()->getContent();

            $this->assertStringContainsString('<title>'.e($rotulo).' · StabilMoney</title>', $pagina,
                "O menu diz \"{$rotulo}\", e a aba do navegador ({$href}) diz outra coisa.");
            $this->assertMatchesRegularExpression('#<h2>\s*'.preg_quote(e($rotulo), '#').'\s*</h2>#', $pagina,
                "O menu diz \"{$rotulo}\", e o título da tela ({$href}) diz outra coisa.");
        }
    }

    public function test_o_css_nao_esconde_nenhuma_linha_de_dinheiro_do_card_patrimonio(): void
    {
        foreach ($this->regras() as [$seletor, $corpo]) {
            if (str_contains($seletor, 'sb-subline') || str_contains($seletor, 'side-balance .sb-value')) {
                $this->assertDoesNotMatchRegularExpression('/display\s*:\s*none|visibility\s*:\s*hidden/', $corpo,
                    "A regra \"{$seletor}\" esconde dinheiro do card Patrimônio (guardado, investido ou cheque especial).");
            }
        }
    }

    public function test_os_cards_do_painel_nao_posicionam_rotulo_valor_ou_variacao_por_cima_de_nada(): void
    {
        $vistos = 0;
        foreach ($this->regras() as [$seletor, $corpo]) {
            if (! str_contains($seletor, 'dashboard-overview-grid')) {
                continue;
            }
            $vistos++;
            if (preg_match('/\.(label|value|trend)\b/', $seletor)) {
                $this->assertDoesNotMatchRegularExpression('/position\s*:\s*(absolute|fixed)/', $corpo,
                    "A regra \"{$seletor}\" tira rótulo, valor ou variação do fluxo: num card mais baixo (celular) "
                    .'um passa por cima do outro.');
            }
        }

        $this->assertGreaterThan(0, $vistos, 'Nenhuma regra de .dashboard-overview-grid encontrada: o CSS mudou de lugar?');
    }

    /** @return list<array{0: string, 1: string}> seletor e corpo de cada regra (inclusive dentro de @media) */
    private function regras(): array
    {
        $css = preg_replace('#/\*.*?\*/#s', '', file_get_contents(resource_path('css/design-system.css')));
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER);

        return array_map(fn ($r) => [trim(preg_replace('/\s+/', ' ', $r[1])), $r[2]], $m);
    }
}
