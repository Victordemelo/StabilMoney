<?php

namespace Tests\Feature;

use App\Models\User;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O "olho" no campo de senha dos modais de Família (out/2026 — Victor: "na senha aqui pode
 * adicionar um olho para ver qual senha estamos botando"). Adicionar e editar dependente
 * levam o mesmo botão do card de senha das Configurações (`.pw-toggle`, `sm/mostrar-senha.js`),
 * apontando para o campo certo — com N modais na página, cada um para o SEU.
 */
class OlhoDaSenhaNaFamiliaTest extends TestCase
{
    use RefreshDatabase;

    public function test_adicionar_e_editar_dependente_tem_o_olho_apontando_para_o_proprio_campo(): void
    {
        $titular = User::factory()->create();
        $ana = User::factory()->create(['account_owner_id' => $titular->id, 'is_admin' => false]);
        $bia = User::factory()->create(['account_owner_id' => $titular->id, 'is_admin' => false]);

        $html = $this->actingAs($titular)->get(route('dependentes'))->assertOk()->getContent();
        $doc = HTMLDocument::createFromString($html, LIBXML_NOERROR);

        foreach (['dep-password', "dep-edit-password-{$ana->id}", "dep-edit-password-{$bia->id}"] as $id) {
            $botao = $doc->querySelector(".pw-toggle[data-toggle=\"{$id}\"]");
            $this->assertNotNull($botao, "O campo #{$id} não tem o olho.");
            $this->assertSame('button', $botao->getAttribute('type'), 'Sem type="button", o olho enviaria o formulário.');
            $this->assertSame('Mostrar senha', $botao->getAttribute('aria-label'));

            $campo = $doc->getElementById($id);
            $this->assertNotNull($campo);
            $this->assertSame('password', $campo->getAttribute('type'), 'A senha nasce oculta.');
            $this->assertTrue($botao->parentElement->classList->contains('input-pw'));
            $this->assertSame($botao->parentElement, $campo->parentElement, 'O olho fica no mesmo invólucro do campo.');
        }
    }
}
