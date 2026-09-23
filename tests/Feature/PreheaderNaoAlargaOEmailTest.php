<?php

namespace Tests\Feature;

use App\Mail\AlertaDeSeguranca;
use App\Mail\BemVindoDependente;
use App\Models\User;
use App\Support\ContextoDeSeguranca;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Tests\TestCase;

/**
 * O preheader não alarga o e-mail num celular (achado E-5 da auditoria de 07/09/2026).
 *
 * O defeito: o enchimento invisível depois do texto do preheader (`&#8199;&#65279;&#847;`,
 * quatro vezes) vinha COLADO ao texto e sem um espaço sequer. Os três caracteres não aceitam
 * quebra de linha entre si nem com a palavra anterior, então a última palavra do preheader e o
 * enchimento formavam uma cadeia única — no código, 90 caracteres sem espaço (`alterada.&#8199;
 * &#65279;&#847;…`). Onde o preheader aparece (Outlook antigo, que ignora o `display:none`; uma
 * visualização crua do HTML), essa cadeia alargava para 463 px um e-mail lido a 375 px.
 *
 * O comportamento certo: o enchimento continua lá (é ele que impede a prévia de emendar o
 * resto do e-mail), mas em grupos separados por espaço comum — e separado do texto —, e o
 * bloco ganha `mso-hide:all`, o `display:none` que o Outlook de mesa respeita.
 */
class PreheaderNaoAlargaOEmailTest extends TestCase
{
    use RefreshDatabase;

    /** Espaço de algarismo, espaço de largura zero sem quebra e o sinal que não ocupa lugar. */
    private const ENCHIMENTO = ["\u{2007}", "\u{FEFF}", "\u{034F}"];

    /** @return array{estilo: string, codigo: string} o bloco do preheader, como sai no HTML */
    private function preheader(Mailable $email): array
    {
        $html = $email->render();

        $this->assertSame(
            1,
            preg_match('~<div style="(display:none;[^"]*)">(.*?)</div>~s', $html, $bloco),
            'O preheader sumiu do e-mail.',
        );

        return ['estilo' => $bloco[1], 'codigo' => $bloco[2]];
    }

    /** @return list<Mailable> */
    private function emails(): array
    {
        $user = User::factory()->create(['name' => 'Victor']);
        $contexto = new ContextoDeSeguranca('22 de setembro de 2026, às 10:00', '203.0.113.9', 'Chrome no Windows');

        return [
            AlertaDeSeguranca::senhaAlterada($user, $contexto),
            // Preheader com nome do usuário: o mais longo do app.
            new BemVindoDependente($user, User::factory()->create(['name' => 'Maria Aparecida dos Santos Oliveira'])),
        ];
    }

    /** O que a medição da auditoria viu: nenhum trecho do código do preheader sem espaço longo. */
    public function test_o_codigo_do_preheader_nao_tem_cadeia_longa_sem_espaco(): void
    {
        foreach ($this->emails() as $email) {
            preg_match_all('/\S+/', $this->preheader($email)['codigo'], $trechos);
            $maior = max(array_map('strlen', $trechos[0]));

            $this->assertLessThanOrEqual(
                30,
                $maior,
                'Cadeia de '.$maior.' caracteres sem espaço no preheader: alarga o e-mail onde ele aparece.',
            );
        }
    }

    /** No texto de verdade (entidades decodificadas): o enchimento não gruda na palavra, e cada grupo quebra. */
    public function test_o_enchimento_nao_gruda_no_texto_e_quebra_por_grupo(): void
    {
        foreach ($this->emails() as $email) {
            $texto = html_entity_decode($this->preheader($email)['codigo'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $pedacos = preg_split('/[ \t\r\n]+/', trim($texto));

            $grupos = 0;

            foreach ($pedacos as $pedaco) {
                $enchimento = mb_strlen(str_replace(self::ENCHIMENTO, '', $pedaco)) === 0;

                if (! $enchimento) {
                    foreach (self::ENCHIMENTO as $caractere) {
                        $this->assertStringNotContainsString($caractere, $pedaco, "O enchimento grudou na palavra \"{$pedaco}\".");
                    }

                    continue;
                }

                $grupos++;
                $this->assertLessThanOrEqual(3, mb_strlen($pedaco), 'Grupos do enchimento colados entre si.');
            }

            // O enchimento continua existindo: sem ele a prévia emenda o corpo do e-mail.
            $this->assertGreaterThanOrEqual(4, $grupos, 'O enchimento do preheader sumiu.');
        }
    }

    public function test_o_preheader_continua_escondido_inclusive_no_outlook(): void
    {
        foreach ($this->emails() as $email) {
            $bloco = $this->preheader($email);

            $this->assertStringContainsString('display:none', $bloco['estilo']);
            $this->assertStringContainsString('mso-hide:all', $bloco['estilo']);
        }

        $this->assertStringContainsString(
            'A senha da sua conta acabou de ser alterada.',
            $this->preheader($this->emails()[0])['codigo'],
        );
    }
}
