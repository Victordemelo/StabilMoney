<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Remover um dependente pede a SENHA do titular (out/2026 — pedido do Victor): o botão da
 * lixeira abre um modal com o campo de senha. Remover tira o acesso de uma pessoa de vez.
 */
class RemoverDependentePedeASenhaTest extends TestCase
{
    use RefreshDatabase;

    private User $titular;

    private User $dependente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->titular = User::factory()->create(['is_admin' => true]);
        $this->dependente = User::factory()->create([
            'name' => 'Elienara Branco', 'account_owner_id' => $this->titular->id, 'is_admin' => false,
        ]);
    }

    public function test_com_a_senha_certa_remove(): void
    {
        $this->actingAs($this->titular)
            ->delete(route('dependentes.destroy', $this->dependente), ['password' => 'password'])
            ->assertRedirect(route('dependentes'))
            ->assertSessionHas('status', 'Dependente removido.');

        $this->assertNull($this->dependente->fresh());
    }

    public function test_senha_errada_ou_vazia_nao_remove_e_volta_com_o_modal_aberto(): void
    {
        foreach (['', 'errada'] as $senha) {
            $this->actingAs($this->titular)
                ->from(route('dependentes'))
                ->delete(route('dependentes.destroy', $this->dependente), [
                    'password' => $senha, '_form' => 'remover-'.$this->dependente->id,
                ])
                ->assertRedirect(route('dependentes'))
                ->assertSessionHasErrorsIn('remocao', ['password']);
        }

        $this->assertNotNull($this->dependente->fresh());

        // A tela volta com o modal certo já aberto (vale sem JS) e a mensagem nele.
        $html = $this->actingAs($this->titular)
            ->withSession(['_old_input' => ['_form' => 'remover-'.$this->dependente->id]])
            ->withViewErrors(['password' => 'Senha incorreta.'], 'remocao')
            ->get(route('dependentes'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<div class="modal-scrim open" id="depRemoverModal-'.$this->dependente->id.'"#', $html);
        $this->assertStringContainsString('Senha incorreta.', $html);
    }

    public function test_a_rota_tem_limite_de_tentativas(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($this->titular)->delete(route('dependentes.destroy', $this->dependente), ['password' => 'errada']);
        }

        $this->actingAs($this->titular)
            ->delete(route('dependentes.destroy', $this->dependente), ['password' => 'password'])
            ->assertStatus(429);
        $this->assertNotNull($this->dependente->fresh());
    }
}
