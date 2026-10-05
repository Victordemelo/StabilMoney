<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cards da tela Família alinhados (out/2026). O card do titular não tem os botões de
 * editar/remover, então o cabeçalho dele era mais baixo; nos dependentes o selo de
 * parentesco caía para outra linha e o e-mail quebrava no meio ("lucas.demo@sta" /
 * "bilmoney.test"). Cada card tinha a altura do próprio conteúdo, e "Gastou no mês", a
 * barra e o rodapé ficavam em alturas diferentes lado a lado.
 *
 * Layout é CSS, que um teste de feature não renderiza: as sentinelas leem a folha de estilo
 * (cards da linha com a mesma altura, bloco de gasto preso ao pé, e-mail numa linha só).
 */
class CardsDaFamiliaAlinhadosTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_css_estica_os_cards_e_prende_o_gasto_ao_pe(): void
    {
        $regras = $this->regras();

        $this->assertMatchesRegularExpression('/align-items\s*:\s*stretch/', $regras['.dep-grid'] ?? '',
            'Com align-items: start, cada card fica com a altura do próprio conteúdo.');
        $this->assertMatchesRegularExpression('/flex-direction\s*:\s*column/', $regras['.dep-person:not(.pm-add)'] ?? '');
        $this->assertMatchesRegularExpression('/margin-top\s*:\s*auto/', $regras['.dep-person:not(.pm-add) .dp-spent'] ?? '',
            'Sem margin-top: auto, "Gastou no mês" fica logo abaixo do cabeçalho, que tem alturas diferentes.');
        $this->assertMatchesRegularExpression('/white-space\s*:\s*nowrap/', $regras['.dp-id .dp-rel'] ?? '',
            'O e-mail quebrado em duas linhas empurrava o card para baixo.');
    }

    public function test_o_email_inteiro_fica_no_title_quando_e_cortado(): void
    {
        $titular = User::factory()->create(['email' => 'titular.com.nome.comprido@exemplo.test']);
        User::factory()->create(['account_owner_id' => $titular->id, 'email' => 'lucas.demo@stabilmoney.test']);

        $this->actingAs($titular)->get(route('dependentes'))->assertOk()
            ->assertSee('<div class="dp-rel" title="titular.com.nome.comprido@exemplo.test">', false)
            ->assertSee('<div class="dp-rel" title="lucas.demo@stabilmoney.test">', false);
    }

    /** @return array<string, string> seletor => corpo (a última regra de cada seletor vence) */
    private function regras(): array
    {
        $css = preg_replace('#/\*.*?\*/#s', '', file_get_contents(resource_path('css/design-system.css')));
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER);

        $regras = [];
        foreach ($m as [, $seletor, $corpo]) {
            $regras[trim(preg_replace('/\s+/', ' ', $seletor))] = $corpo;
        }

        return $regras;
    }
}
