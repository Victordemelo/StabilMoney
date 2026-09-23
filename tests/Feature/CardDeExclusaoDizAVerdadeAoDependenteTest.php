<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * O card "Excluir conta" diz a verdade a cada papel (item 5 da segunda rodada de 22/09/2026).
 *
 * O defeito: `profile/partials/delete-user-form` era o mesmo para todo mundo e dizia a um
 * DEPENDENTE que ele perderia "Contas e cartões", "Todo o histórico" e "Os dependentes". É
 * falso: o dinheiro é da família (`user_id` = titular), e o dependente que exclui a própria
 * conta leva só o login e os dados pessoais. O texto assustava à toa — e, pior, fazia parecer
 * que um dependente consegue apagar as finanças da família.
 *
 * O comportamento certo: para o dependente, o que ele perde (login e dados pessoais) e o que
 * FICA com a família; para o titular, o card de sempre. O último teste confere a promessa
 * contra o que o app faz de verdade.
 */
class CardDeExclusaoDizAVerdadeAoDependenteTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'senha-de-teste-1234';

    private User $titular;

    private User $dependente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->titular = User::factory()->create(['name' => 'Carla Titular', 'is_admin' => true]);
        $this->dependente = User::factory()->create([
            'name' => 'Bruno Dependente',
            'password' => Hash::make(self::SENHA),
            'account_owner_id' => $this->titular->id,
            'is_admin' => false,
        ]);
    }

    /**
     * Só o card: o menu da sidebar também tem "Contas e cartões", e não é dele que se fala. Com
     * os espaços normalizados, porque o texto quebra linha no Blade onde a frase não quebra.
     */
    private function card(User $quem): string
    {
        return (string) preg_replace('/\s+/', ' ', view('profile.partials.delete-user-form', ['user' => $quem])->render());
    }

    public function test_o_dependente_nao_le_que_perde_as_contas_o_historico_e_os_dependentes(): void
    {
        $card = $this->card($this->dependente);

        $this->assertStringNotContainsString('Contas e cartões', $card);
        $this->assertStringNotContainsString('Todo o histórico', $card);
        $this->assertStringNotContainsString('Os dependentes', $card);
    }

    public function test_o_dependente_le_o_que_perde_e_o_que_fica_com_a_familia(): void
    {
        $card = $this->card($this->dependente);

        $this->assertStringContainsString('O seu login', $card);
        $this->assertStringContainsString('Os seus dados pessoais', $card);
        $this->assertStringContainsString('O dinheiro da família não é apagado', $card);
        $this->assertStringContainsString('continua com Carla Titular', $card);
        $this->assertStringContainsString('Os lançamentos que você fez ficam no histórico, sem o seu nome.', $card);
        $this->assertStringContainsString('Excluir minha conta', $card);
    }

    public function test_o_titular_continua_vendo_o_card_de_sempre(): void
    {
        $card = $this->card($this->titular);

        $this->assertStringContainsString('Contas e cartões', $card);
        $this->assertStringContainsString('Todo o histórico', $card);
        $this->assertStringContainsString('Os dependentes', $card);
        $this->assertStringNotContainsString('O dinheiro da família não é apagado', $card);
    }

    /** A tela inteira abre para o dependente (o card depende do `$user` da aba Conta). */
    public function test_a_aba_conta_abre_para_o_dependente_com_o_card_certo(): void
    {
        $this->actingAs($this->dependente)->get(route('settings', 'conta'))
            ->assertOk()
            ->assertSee('Os seus dados pessoais')
            ->assertSee('continua com Carla Titular');
    }

    /**
     * O card promete; aqui se confere a promessa. O dependente exclui a própria conta: a conta
     * da família e o lançamento que ELE fez continuam lá — só que sem o nome dele.
     */
    public function test_o_que_o_card_promete_ao_dependente_e_o_que_acontece(): void
    {
        Mail::fake();

        $conta = Account::factory()->for($this->titular)->create();
        $lancamento = Transaction::factory()->create([
            'user_id' => $this->titular->id,
            'account_id' => $conta->id,
            'made_by_user_id' => $this->dependente->id,
            'type' => 'income',
        ]);

        $this->actingAs($this->dependente)
            ->delete(route('profile.destroy'), ['password' => self::SENHA])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertModelMissing($this->dependente);
        $this->assertModelExists($this->titular);
        $this->assertModelExists($conta);
        $this->assertModelExists($lancamento);
        $this->assertNull($lancamento->fresh()->made_by_user_id, 'O lançamento continua com o nome de quem saiu.');
    }
}
