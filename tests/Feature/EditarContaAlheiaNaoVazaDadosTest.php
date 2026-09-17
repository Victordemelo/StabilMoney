<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A-3 da auditoria de 05/09/2026: editar a conta de OUTRA família não pode devolver
 * nada sobre ela.
 *
 * O `UpdateAccountRequest` valida ANTES de a `AccountPolicy` rodar no controller. Duas
 * das três regras que leem a conta da rota já tinham guarda de posse; a da trava de
 * classe (`travaDeClasse`) não. Um PATCH com o id de uma conta alheia e um `type` de
 * outra classe recebia de volta, antes do 403, o NOME da conta, o TIPO dela e a
 * confirmação de que ela tem dinheiro ("já tem saldo, lançamentos ou dinheiro
 * guardado") — uma sonda para varrer ids.
 */
class EditarContaAlheiaNaoVazaDadosTest extends TestCase
{
    use RefreshDatabase;

    private const NOME_ALHEIO = 'Reserva Secreta do Vizinho';

    private User $vizinho;

    private Account $alheia;

    private User $intruso;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vizinho = User::factory()->create(['is_admin' => true, 'account_owner_id' => null]);
        // Saldo inicial > 0 basta para `hasMoneyHistory()`: é a conta em que a trava
        // de classe dispara e monta a mensagem com o nome.
        $this->alheia = Account::factory()->for($this->vizinho)->create([
            'name' => self::NOME_ALHEIO,
            'type' => 'checking',
            'bank' => 'itau',
            'initial_balance' => 1000,
            'overdraft_limit' => 0,
        ]);

        $this->intruso = User::factory()->create(['is_admin' => true, 'account_owner_id' => null]);
    }

    /** Payload VÁLIDO de cartão de crédito: a única regra que pode recusar é a trava de classe. */
    private function viraCartao(): array
    {
        return [
            'name' => 'Qualquer nome',
            'type' => 'credit_card',
            'bank' => 'nubank',
            'credit_limit' => '5.000,00',
            'closing_day' => 10,
            'due_day' => 20,
        ];
    }

    public function test_patch_json_em_conta_alheia_da_403_sem_revelar_nome_tipo_nem_saldo(): void
    {
        $r = $this->actingAs($this->intruso)
            ->patchJson(route('accounts.update', $this->alheia), $this->viraCartao());

        // O corpo primeiro: é ele que o modal lê, e é nele que o vazamento aparecia.
        // Decodificado e regravado sem escape: o JSON cru traz "já", e a busca
        // por "já tem saldo" passaria mesmo com a mensagem inteira lá dentro.
        $corpo = json_encode($r->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString(self::NOME_ALHEIO, $corpo);
        $this->assertStringNotContainsString('Conta Corrente', $corpo);
        $this->assertStringNotContainsString('já tem saldo', $corpo);

        $r->assertForbidden()->assertJsonMissingValidationErrors();

        $this->assertSame('checking', $this->alheia->fresh()->type);
        $this->assertSame(self::NOME_ALHEIO, $this->alheia->fresh()->name);
    }

    public function test_patch_web_em_conta_alheia_da_403_sem_erro_de_validacao_na_sessao(): void
    {
        $r = $this->actingAs($this->intruso)
            ->from(route('accounts.index'))
            ->patch(route('accounts.update', $this->alheia), $this->viraCartao());

        // Sem JS o vazamento ia pela sessão (redirect com `errors`), não pelo corpo.
        $erros = session('errors')?->getBag('default')->all() ?? [];
        $this->assertStringNotContainsString(self::NOME_ALHEIO, implode(' ', $erros));

        $r->assertForbidden()->assertSessionHasNoErrors();
        $r->assertDontSee(self::NOME_ALHEIO);

        $this->assertSame('checking', $this->alheia->fresh()->type);
    }

    /**
     * A guarda não pode desligar a trava para quem é DA FAMÍLIA — e "da família" é
     * `ownerId()`, não `auth()->id()`: o dependente edita as contas do titular e
     * precisa continuar recebendo a recusa (com o nome, que é da conta dele também).
     */
    public function test_titular_e_dependente_continuam_recebendo_a_trava_de_classe(): void
    {
        $dependente = User::factory()->create(['is_admin' => false, 'account_owner_id' => $this->vizinho->id]);

        foreach ([$this->vizinho, $dependente] as $quem) {
            $this->actingAs($quem)
                ->patchJson(route('accounts.update', $this->alheia), $this->viraCartao())
                ->assertStatus(422)
                ->assertJsonValidationErrors('type')
                ->assertSee(self::NOME_ALHEIO);
        }

        $this->assertSame('checking', $this->alheia->fresh()->type);
    }
}
