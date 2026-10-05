<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FixedBill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Excluir uma conta fixa pede a SENHA (out/2026 — pedido do Victor: "igual da fatura"). O "x"
 * da linha abre o MESMO modal do "Remover despesa" (bag `remocao`, `throttle:senha`); depois de
 * uma senha errada o modal reabre para a mesma conta, com a ação remontada pelo id.
 */
class ExcluirContaFixaPedeASenhaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FixedBill $condominio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $conta = Account::factory()->for($this->user)->create(['type' => 'checking', 'initial_balance' => 1000]);
        $this->condominio = FixedBill::factory()->create([
            'user_id' => $this->user->id, 'name' => 'Condomínio', 'amount' => 470, 'due_day' => 5,
            'account_id' => $conta->id, 'starts_on' => now()->startOfMonth()->toDateString(), 'active' => true,
        ]);
    }

    public function test_sem_senha_ou_com_senha_errada_nada_e_excluido(): void
    {
        $this->actingAs($this->user)->from(route('faturas.index'))
            ->delete(route('contas-fixas.destroy', $this->condominio))
            ->assertRedirect(route('faturas.index'))
            ->assertSessionHasErrorsIn('remocao', ['password' => 'Digite a sua senha para excluir.']);

        $this->actingAs($this->user)->from(route('faturas.index'))
            ->delete(route('contas-fixas.destroy', $this->condominio), [
                'password' => 'errada', '_alvo' => (string) $this->condominio->id, '_tipo' => 'fixa',
                '_pergunta' => 'Excluir a conta fixa “Condomínio”?',
            ])
            ->assertSessionHasErrorsIn('remocao', ['password' => 'Senha incorreta. Nada foi excluído.']);

        $this->assertTrue($this->condominio->fresh()->exists);
        $this->assertTrue((bool) $this->condominio->fresh()->active);

        // O modal reabre para a MESMA conta fixa, com o título dela.
        $html = $this->get(route('faturas.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-reabrir-acao="'.route('contas-fixas.destroy', $this->condominio).'"', $html);
        $this->assertStringContainsString('<h3 id="removerDespesaModal-titulo" data-rd-titulo>Excluir conta fixa</h3>', $html);
    }

    public function test_com_a_senha_certa_exclui(): void
    {
        $this->actingAs($this->user)
            ->delete(route('contas-fixas.destroy', $this->condominio), ['password' => 'password'])
            ->assertRedirect(route('faturas.index'))
            ->assertSessionHasNoErrors();

        $this->assertNull(FixedBill::find($this->condominio->id));
    }

    public function test_o_x_da_linha_abre_o_modal_de_senha(): void
    {
        $html = $this->actingAs($this->user)->get(route('faturas.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '#<form method="POST" action="'.preg_quote(route('contas-fixas.destroy', $this->condominio), '#').'"\s*data-remover-despesa data-remover-tipo="fixa" data-titulo="Excluir conta fixa"#',
            $html,
        );
    }
}
