<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * A-12 da auditoria de 05/09/2026: o link que confirma a troca de e-mail da conta A, aberto
 * num navegador em que B estava conectado, gravava o e-mail novo em A e mostrava "e-mail
 * atualizado" para B.
 *
 * Além da confusão (B acha que o e-mail DELE mudou), era um atalho para quem perdeu o acesso
 * a A: depois que o dono troca a senha e derruba as sessões, bastava entrar na própria conta
 * e abrir o link para concluir a troca pendente.
 *
 * Agora o link só vale na sessão da própria conta. Para B: nada muda em A — nem a pendência
 * é descartada, o dono ainda pode confirmar — e um recado diz o que fazer.
 *
 * E quem abre deslogado cai no login e volta ao link depois de entrar (`url.intended`):
 * com a conta certa confirma, com outra recebe o mesmo recado.
 */
class LinkDeTrocaDeEmailSoValeNaPropriaContaTest extends TestCase
{
    use RefreshDatabase;

    private const ANTIGO_DE_A = 'ana@antigo.test';

    private const NOVO_DE_A = 'ana@novo.test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'smtp']);
        Mail::fake();
    }

    /**
     * A com uma troca pendente, e o link que chegou ao endereço novo.
     *
     * @return array{0: User, 1: string}
     */
    private function contaAComTrocaPendente(): array
    {
        $a = User::factory()->create(['name' => 'Ana Quintela', 'email' => self::ANTIGO_DE_A, 'password' => 'senha-da-ana']);

        $this->actingAs($a)->patch(route('profile.update'), [
            'name' => $a->name,
            'email' => self::NOVO_DE_A,
            'current_password' => 'senha-da-ana',
        ])->assertSessionHasNoErrors();

        $link = URL::temporarySignedRoute('profile.email.confirm', now()->addHours(2), [
            'user' => $a->id,
            'hash' => sha1(self::NOVO_DE_A),
        ]);

        // Sai da conta de A: o próximo passo acontece em outra sessão.
        $this->app['auth']->forgetGuards();
        Mail::fake();

        return [$a->fresh(), $link];
    }

    public function test_link_de_a_aberto_por_b_logado_nao_altera_nada_em_a(): void
    {
        [$a, $link] = $this->contaAComTrocaPendente();
        $b = User::factory()->create(['name' => 'Bruno', 'email' => 'bruno@familia.test']);

        $this->actingAs($b)->get($link)
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHasErrors('confirmacao_email');

        $a->refresh();
        $this->assertSame(self::ANTIGO_DE_A, $a->email, 'O link de A, aberto por B, trocou o e-mail de A.');
        // A pendência continua: o dono ainda confirma com o mesmo link, na sessão certa.
        $this->assertSame(self::NOVO_DE_A, $a->pending_email);
        $this->assertSame('bruno@familia.test', $b->fresh()->email);

        // Nada mudou, nada é anunciado.
        Mail::assertNothingSent();
    }

    /** O recado diz a B o que fazer — e não conta nada de A. */
    public function test_b_ve_o_que_fazer_sem_descobrir_nada_sobre_a(): void
    {
        [, $link] = $this->contaAComTrocaPendente();
        $b = User::factory()->create(['email' => 'bruno@familia.test']);

        $this->actingAs($b)->get($link);

        $this->actingAs($b)->get(route('profile.edit'))
            ->assertSee('Este link confirma a troca de e-mail de outra conta')
            ->assertSee('bruno@familia.test')
            ->assertSee('saia desta conta e abra o link de novo')
            ->assertDontSee('E-mail confirmado')
            ->assertDontSee(self::ANTIGO_DE_A)
            ->assertDontSee(self::NOVO_DE_A)
            ->assertDontSee('Quintela');
    }

    /** Depois do engano de B, o dono ainda confirma com o mesmo link. */
    public function test_a_ainda_confirma_com_o_mesmo_link_depois_que_b_o_abriu(): void
    {
        [$a, $link] = $this->contaAComTrocaPendente();
        $b = User::factory()->create();

        $this->actingAs($b)->get($link);
        $this->app['auth']->forgetGuards();

        // Quem confirma é o clique do dono, não o de B.
        $this->assertSame(self::ANTIGO_DE_A, $a->fresh()->email);

        $this->actingAs($a)->get($link)->assertRedirect(route('profile.edit'));

        $this->assertSame(self::NOVO_DE_A, $a->fresh()->email);
    }

    // ══════════════════════════════════════════════════ quem abre deslogado

    /**
     * O caminho legítimo de quem abre o link no celular, sem sessão, tem de continuar
     * funcionando com a trava nova: login e volta automática para o link.
     */
    public function test_deslogado_o_link_pede_login_e_confirma_ao_entrar_com_a_conta_certa(): void
    {
        [$a, $link] = $this->contaAComTrocaPendente();

        $this->get($link)->assertRedirect(route('login'));

        // Entra com o e-mail que a conta AINDA tem (o antigo) e volta para o link.
        $this->post(route('login'), ['email' => self::ANTIGO_DE_A, 'password' => 'senha-da-ana'])
            ->assertRedirect($link);

        $this->get($link)
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHasNoErrors();

        $this->assertSame(self::NOVO_DE_A, $a->fresh()->email);
    }

    public function test_deslogado_entrando_com_outra_conta_nao_altera_a(): void
    {
        [$a, $link] = $this->contaAComTrocaPendente();
        User::factory()->create(['email' => 'bruno@familia.test', 'password' => 'senha-do-bruno']);

        $this->get($link)->assertRedirect(route('login'));

        $this->post(route('login'), ['email' => 'bruno@familia.test', 'password' => 'senha-do-bruno'])
            ->assertRedirect($link);

        $this->get($link)
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHasErrors('confirmacao_email');

        $a->refresh();
        $this->assertSame(self::ANTIGO_DE_A, $a->email);
        $this->assertSame(self::NOVO_DE_A, $a->pending_email);
        Mail::assertNothingSent();
    }
}
