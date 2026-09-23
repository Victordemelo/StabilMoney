<?php

namespace Tests\Feature;

use App\Http\Middleware\AutenticaNoPainel;
use App\Models\Admin;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * O painel e o app não trocam destino de login (achado A-14 da auditoria de 05/09/2026).
 *
 * O defeito: a sessão é uma só para os dois, e o `AutenticaNoPainel` usava
 * `redirect()->guest()`, que grava o destino em `url.intended` — a chave de onde o login do
 * APP tira o dele. Quem abria `/painel_admin/inicio` sem sessão e depois entrava no app era
 * jogado na tela de login do painel. No sentido contrário, o desafio do painel lia o mesmo
 * `url.intended` e mandava o admin para a página do app que estivesse guardada ali.
 *
 * O comportamento certo: o painel guarda o destino numa chave própria
 * (`AutenticaNoPainel::CHAVE_DESTINO`), só de página que dá para reabrir com GET, e é só dela
 * que o desafio lê. Com o painel desligado nada disso roda — o 404 indistinguível é preso pelo
 * PainelAdminDesligadoNaoSeRevelaTest.
 */
class PainelAdminDestinoNaoContaminaOAppTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA_DO_ADMIN = 'senha-de-teste-bem-comprida';

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.enabled' => true]);
    }

    private function admin(): Admin
    {
        return Admin::factory()->comDoisFatores()->create([
            'email' => 'chefe@exemplo.com',
            'password' => Hash::make(self::SENHA_DO_ADMIN),
        ]);
    }

    /** Senha e código, pelo caminho de verdade. Devolve a resposta do código. */
    private function entrarNoPainel(Admin $admin): TestResponse
    {
        $this->post(route('painel.autenticar'), ['email' => $admin->email, 'password' => self::SENHA_DO_ADMIN])
            ->assertRedirect(route('painel.home'));

        return $this->post(route('painel.2fa.verificar'), [
            'codigo' => Totp::codigo($admin->two_factor_secret, Totp::passoAtual()),
        ]);
    }

    public function test_quem_bateu_no_painel_e_depois_entra_no_app_vai_para_o_dashboard(): void
    {
        $this->get(route('painel.home'))->assertRedirect(route('painel.login'));
        $this->assertNull(session('url.intended'), 'O painel escreveu no destino do app.');

        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_o_painel_leva_o_admin_de_volta_a_pagina_que_ele_tentou_abrir(): void
    {
        $destino = route('painel.pessoas', ['filtro' => 'banidos']);

        $this->get($destino)->assertRedirect(route('painel.login'));
        $this->assertSame($destino, session(AutenticaNoPainel::CHAVE_DESTINO));

        $this->entrarNoPainel($this->admin())->assertRedirect($destino);

        // Usado uma vez, sai da sessão.
        $this->assertNull(session(AutenticaNoPainel::CHAVE_DESTINO));
    }

    public function test_destino_guardado_pelo_app_nao_desvia_o_login_do_painel(): void
    {
        // Visitante do app que caiu no login ao abrir o Histórico: esse destino é do APP.
        $this->get(route('transactions.index'))->assertRedirect(route('login'));
        $destinoDoApp = session('url.intended');
        $this->assertNotNull($destinoDoApp);

        $this->entrarNoPainel($this->admin())->assertRedirect(route('painel.home'));

        // E continua lá, intacto, para quando a pessoa entrar no app.
        $this->assertSame($destinoDoApp, session('url.intended'));
    }

    /**
     * Só página que dá para reabrir: o endereço de um POST, aberto com GET depois do login,
     * cairia num 405 (e o `guest()` do framework, num POST, guardava o endereço ANTERIOR — que
     * podia ser uma tela do app).
     */
    public function test_post_sem_sessao_nao_vira_destino(): void
    {
        $alvo = User::factory()->create();

        $this->post(route('painel.banir', $alvo->id), ['motivo' => 'Motivo qualquer'])
            ->assertRedirect(route('painel.login'));

        $this->assertNull(session(AutenticaNoPainel::CHAVE_DESTINO));

        $this->entrarNoPainel($this->admin())->assertRedirect(route('painel.home'));
        $this->assertNull($alvo->fresh()->banned_at);
    }
}
