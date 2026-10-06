<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Responsividade (out/2026): de 1920×1080 até 200px de largura, e o PWA instalado.
 *
 * A varredura de verdade roda no navegador (`tests/e2e/responsividade.mjs`, contra a prévia
 * descartável): mede rolagem lateral, texto sobreposto, texto cortado, alvos de toque e a
 * área segura do iPhone. Antes dela eram 3.046 achados em 1.332 combinações; depois, zero.
 *
 * Um teste de feature não renderiza CSS, então estas sentinelas leem as folhas de estilo e
 * barram a REGRA que causou cada defeito que a varredura achou:
 *
 *  - a barra de baixo (.bn-item com 14px fixos de cada lado) e o topo do celular mediam
 *    329px: abaixo disso a página inteira rolava para o lado e, no celular, o navegador
 *    afastava o zoom — a barra de baixo saía cortada;
 *  - grades com `minmax(270px, 1fr)` e campos com `min-width: 150px` vazavam em telas
 *    menores que a medida (Família, filtros, códigos de recuperação);
 *  - `grid-template-columns: 1fr` (= minmax(auto, 1fr)) nas telas de entrada e na edição
 *    de transação não encolhia abaixo da palavra mais longa;
 *  - o rodapé dos modais (Cancelar/Salvar) ficava sob a barra de gestos do iPhone no PWA,
 *    e um `.sidebar { padding }` do redesenho apagava o respiro da área segura do drawer.
 */
class ResponsividadeDoCssTest extends TestCase
{
    private const FOLHAS = ['design-system.css', 'forms.css', 'auth.css', 'acessibilidade.css'];

    public function test_a_barra_de_baixo_divide_a_largura_em_vez_de_padding_fixo(): void
    {
        $regra = $this->regra('(max-width: 920px)', '.bn-item');

        $this->assertMatchesRegularExpression('/min-width\s*:\s*0/', $regra,
            'Sem `min-width: 0` os itens da bottom-nav não encolhem e a barra passa de 320px.');
        $this->assertMatchesRegularExpression('/flex\s*:\s*1 1 0/', $regra,
            'Os itens da bottom-nav precisam DIVIDIR a largura (flex: 1 1 0).');
        $this->assertDoesNotMatchRegularExpression('/padding\s*:\s*\d+px\s+(1[0-9]|[2-9][0-9])px/', $regra,
            'Padding lateral fixo grande nos itens da bottom-nav fez a barra medir 329px.');
    }

    public function test_o_topo_do_celular_esconde_a_marca_escrita_antes_de_vazar(): void
    {
        $this->assertMatchesRegularExpression('/display\s*:\s*none/', $this->regra('(max-width: 340px)', '.mobile-top .mbrand'),
            'Abaixo de 340px "StabilMoney" escrito empurrava o botão de tema para fora da tela.');
    }

    public function test_nenhuma_grade_ou_campo_exige_uma_largura_minima_maior_que_a_tela(): void
    {
        foreach (self::FOLHAS as $folha) {
            foreach ($this->regras($folha) as [, $seletor, $corpo]) {
                $this->assertDoesNotMatchRegularExpression('/minmax\(\s*\d{3,}px/', $corpo,
                    "{$folha} \"{$seletor}\": `minmax(NNNpx, …)` sem `min(NNNpx, 100%)` vaza em tela menor que NNN px.");
                $this->assertDoesNotMatchRegularExpression('/min-width\s*:\s*(1[5-9]\d|[2-9]\d\d)px/', $corpo,
                    "{$folha} \"{$seletor}\": `min-width` fixo de 150px ou mais vaza em tela estreita — use min(Npx, 100%).");
            }
        }
    }

    public function test_trilhas_unicas_encolhem_abaixo_da_palavra_mais_longa(): void
    {
        $this->assertMatchesRegularExpression('/grid-template-columns\s*:\s*minmax\(0,\s*1fr\)/',
            $this->regra('(max-width: 980px)', '.auth', 'auth.css'),
            'Com `1fr` a tela de login não encolhia abaixo de ~240px e rolava para o lado.');
        $this->assertMatchesRegularExpression('/grid-template-columns\s*:\s*minmax\(0,\s*1fr\)/',
            $this->regra('(max-width: 1000px)', '.tx-edit'),
            'Com `1fr` a edição de transação vazava em 200px.');
    }

    public function test_o_drawer_cabe_na_tela_e_respeita_a_area_segura(): void
    {
        // A ÚLTIMA regra `.sidebar` do celular é a que vale: a de cima era apagada por um
        // `.sidebar { padding }` global mais abaixo no arquivo.
        $regras = $this->regras('design-system.css');
        $ultima = null;
        foreach ($regras as [$media, $seletor, $corpo]) {
            if ($seletor === '.sidebar' && ($media === '' || $media === '(max-width: 920px)') && str_contains($corpo, 'padding')) {
                $ultima = [$media, $corpo];
            }
        }

        $this->assertNotNull($ultima);
        $this->assertSame('(max-width: 920px)', $ultima[0],
            'A última regra de padding da .sidebar não é a do celular: o respiro do notch/barra de gestos se perde.');
        $this->assertStringContainsString('env(safe-area-inset-bottom', $ultima[1]);
        $this->assertStringContainsString('min(280px', $ultima[1], 'O drawer de 280px passava da tela abaixo de 300px.');
    }

    public function test_modais_e_conteudo_respeitam_a_barra_de_gestos_do_pwa(): void
    {
        $this->assertStringContainsString('env(safe-area-inset-bottom', $this->regra('', '.modal-scrim'),
            'Sem a área segura, Cancelar/Salvar dos modais ficam sob a barra de gestos do iPhone.');
        $this->assertStringContainsString('env(safe-area-inset-bottom', $this->regra('', '.modal'),
            'A altura máxima do modal precisa descontar a área segura.');

        $content = '';
        foreach ($this->regras('design-system.css') as [$media, $seletor, $corpo]) {
            if ($media === '(max-width: 920px)' && $seletor === '.content') {
                $content = $corpo; // a última vence
            }
        }
        $this->assertStringContainsString('env(safe-area-inset-bottom', $content,
            'O fim da página no celular precisa somar a barra de gestos ao espaço da bottom-nav.');
        $this->assertStringContainsString('env(safe-area-inset-left', $content,
            'Com o iPhone deitado no PWA, o notch vira margem lateral.');
    }

    public function test_menu_do_perfil_e_marca_do_login_respeitam_a_tela_e_o_notch(): void
    {
        // Achados da varredura de out/2026: a 200px o "Informações do sistema" saía pela borda
        // (largura fixa de 256px), e no app instalado deitado o notch cobria o menu e a marca.
        $ds = $this->semComentarios('design-system.css');
        $this->assertMatchesRegularExpression('/\.profile-pop \{[^}]*width: min\(256px, calc\(100vw - 24px\)\)[^}]*max-height:/', $ds);
        $shell = file_get_contents(resource_path('js/sm/shell.js'));
        $this->assertStringContainsString("areaSegura('left')", $shell);
        $this->assertStringContainsString("areaSegura('bottom')", $shell);

        $auth = $this->semComentarios('auth.css');
        $this->assertSame(2, preg_match_all('/\.auth \.auth-visual \{[^}]*env\(safe-area-inset-left/', $auth));
    }

    public function test_todo_layout_pede_viewport_fit_cover(): void
    {
        foreach (glob(resource_path('views/layouts/*.blade.php')) as $layout) {
            $this->assertStringContainsString('viewport-fit=cover', file_get_contents($layout),
                basename($layout).' sem viewport-fit=cover: env(safe-area-inset-*) vale 0 e o PWA não respeita o notch.');
        }
    }

    // ------------------------------------------------------------------ CSS

    private function semComentarios(string $folha): string
    {
        return preg_replace('#/\*.*?\*/#s', '', file_get_contents(resource_path('css/'.$folha)));
    }

    /**
     * Regras de uma folha, com a media query em que estão ('' = fora de @media).
     *
     * @return list<array{0: string, 1: string, 2: string}> [media, seletor, corpo]
     */
    private function regras(string $folha): array
    {
        $css = $this->semComentarios($folha);
        $saida = [];
        $i = 0;
        $n = strlen($css);
        $media = '';
        $profundidade = 0;
        while ($i < $n) {
            $abre = strpos($css, '{', $i);
            $fecha = strpos($css, '}', $i);
            if ($fecha !== false && ($abre === false || $fecha < $abre)) {
                // fim de um @media
                $profundidade = max(0, $profundidade - 1);
                if ($profundidade === 0) {
                    $media = '';
                }
                $i = $fecha + 1;

                continue;
            }
            if ($abre === false) {
                break;
            }
            $prelude = trim(preg_replace('/\s+/', ' ', substr($css, $i, $abre - $i)));
            if (str_starts_with($prelude, '@media')) {
                $media = trim(substr($prelude, 6));
                $profundidade = 1;
                $i = $abre + 1;

                continue;
            }
            if (str_starts_with($prelude, '@')) {
                // @keyframes/@supports etc.: pula o bloco inteiro
                $nivel = 0;
                for ($j = $abre; $j < $n; $j++) {
                    $nivel += $css[$j] === '{' ? 1 : ($css[$j] === '}' ? -1 : 0);
                    if ($nivel === 0) {
                        break;
                    }
                }
                $i = $j + 1;

                continue;
            }
            $fim = strpos($css, '}', $abre);
            foreach (array_map('trim', explode(',', $prelude)) as $seletor) {
                $saida[] = [$media, $seletor, substr($css, $abre + 1, $fim - $abre - 1)];
            }
            $i = $fim + 1;
        }

        return $saida;
    }

    /** Corpo concatenado de todas as regras com este seletor exato nesta media query. */
    private function regra(string $media, string $seletor, string $folha = 'design-system.css'): string
    {
        $corpo = '';
        foreach ($this->regras($folha) as [$m, $s, $c]) {
            if ($m === $media && $s === $seletor) {
                $corpo .= $c."\n";
            }
        }
        $this->assertNotSame('', $corpo, "Regra \"{$seletor}\" em \"@media {$media}\" não encontrada em {$folha}: o CSS mudou de lugar?");

        return $corpo;
    }
}
