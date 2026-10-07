<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Onde fica o aviso de cookies (out/2026 — pedido do Victor, a partir do que a sessão do
 * servidor viu em produção: no celular, no topo do cadastro, ele cobria a marca).
 *
 * - Telas de entrada até 980px (o vídeo em cima do formulário): no FIM da página, no fluxo.
 *   No topo cobria a marca; fixo no rodapé, cairia em cima do "Entrar" (o defeito antigo).
 * - Página inicial e páginas legais até 920px: também no fim da página, no fluxo — fixo, ficava
 *   por cima dos links (o sumário dos Termos) até a pessoa aceitar.
 * - No computador, nessas duas, segue fixo embaixo, e o fim da página reserva o espaço dele.
 *
 * A medição (elementFromPoint sobre cada link/botão/campo, 6 páginas × 10 larguras de 200 a
 * 1920px, no topo e no fim da página) foi feita num Chromium de verdade; aqui ficam as regras.
 */
class AvisoDeCookiesNaoCobreNadaTest extends TestCase
{
    private function css(string $arquivo): string
    {
        return (string) file_get_contents(resource_path("css/{$arquivo}"));
    }

    public function test_nas_telas_de_entrada_ate_980px_o_aviso_fica_no_fim_da_pagina(): void
    {
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 980px\) \{\s*\.auth-body \.cookie-bar \{[^}]*position: static;/',
            $this->css('auth.css'),
        );
    }

    public function test_na_pagina_inicial_e_nas_legais_no_celular_tambem(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.inicio-body \.cookie-bar, \.legal-body \.cookie-bar \{[^}]*position: static;/',
            $this->css('design-system.css'),
        );
    }

    public function test_no_computador_o_fim_da_pagina_reserva_o_espaco_do_aviso(): void
    {
        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 921px\) \{\s*\.tem-aviso-cookie \.inicio-body, \.tem-aviso-cookie \.legal-body \{ padding-bottom: 120px; \}/',
            $this->css('design-system.css'),
        );
    }

    public function test_o_aviso_esta_nas_paginas_publicas(): void
    {
        foreach (['/', route('login'), route('register'), route('termos')] as $url) {
            $this->get($url)->assertOk()->assertSee('id="cookieBar"', false);
        }
    }
}
