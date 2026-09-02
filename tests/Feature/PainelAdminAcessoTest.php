<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * As camadas de acesso do painel administrativo.
 *
 * A ordem em que uma requisição as atravessa: interruptor do `.env` → throttle →
 * sessão do guard `admin` → segundo fator obrigatório. Cada teste aqui prende uma
 * dessas camadas no lugar.
 */
class PainelAdminAcessoTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'senha-de-teste-bem-comprida';

    protected function setUp(): void
    {
        parent::setUp();
        config(['admin.enabled' => true]);
    }

    private function admin(bool $com2fa = true): Admin
    {
        $factory = Admin::factory();

        return ($com2fa ? $factory->comDoisFatores() : $factory)
            ->create(['email' => 'chefe@exemplo.com', 'password' => Hash::make(self::SENHA)]);
    }

    // ── Camada 1: o interruptor ──────────────────────────────────────────────

    /**
     * Desligado, o painel é 404 — não 403.
     *
     * A diferença não é estética: 403 confirma que existe um painel ali e convida a
     * insistir. 404 é indistinguível de uma URL que nunca existiu.
     */
    public function test_desligado_o_painel_inteiro_responde_404(): void
    {
        config(['admin.enabled' => false]);

        $this->get(route('painel.login'))->assertNotFound();
        $this->get(route('painel.home'))->assertNotFound();
        $this->get(route('painel.pessoas'))->assertNotFound();
        $this->post(route('painel.autenticar'), ['email' => 'a@b.c', 'password' => 'x'])->assertNotFound();
    }

    public function test_ligado_a_tela_de_login_aparece(): void
    {
        $this->get(route('painel.login'))->assertOk()->assertSee('Painel administrativo');
    }

    /** O painel não pode ser indexado por buscador. */
    public function test_a_tela_de_login_pede_noindex(): void
    {
        $this->get(route('painel.login'))->assertSee('noindex', false);
    }

    // ── Camada 2: sessão do guard próprio ────────────────────────────────────

    public function test_sem_sessao_o_painel_redireciona_para_o_login(): void
    {
        $this->get(route('painel.home'))->assertRedirect(route('painel.login'));
        $this->get(route('painel.pessoas'))->assertRedirect(route('painel.login'));
    }

    /**
     * 🚨 O ponto central da arquitetura: sessão do APP não vale no painel.
     *
     * Se um dia isto falhar, qualquer usuário logado do app abre o painel.
     */
    public function test_usuario_do_app_nao_entra_no_painel(): void
    {
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)->get(route('painel.home'))->assertRedirect(route('painel.login'));
        $this->actingAs($user)->get(route('painel.pessoas'))->assertRedirect(route('painel.login'));
    }

    /**
     * E o contrário também: sessão de admin não abre o app.
     *
     * O login é feito pelo fluxo REAL (POST + sessão), não por `actingAs`: aquele
     * troca o guard padrão do processo de teste, e aí o `auth` do app passaria a
     * enxergar o Admin — provando algo que não acontece num navegador, onde os dois
     * guards guardam chaves diferentes na mesma sessão.
     */
    public function test_admin_nao_entra_no_app_com_a_sessao_do_painel(): void
    {
        $this->admin();

        $this->post(route('painel.autenticar'), [
            'email' => 'chefe@exemplo.com',
            'password' => self::SENHA,
        ])->assertRedirect(route('painel.home'));

        $this->assertAuthenticated('admin');

        // Mesma sessão, rota do app: o guard `web` continua sem ninguém.
        $this->get('/')->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_senha_errada_nao_autentica_e_fica_registrada(): void
    {
        $this->admin();

        $this->post(route('painel.autenticar'), [
            'email' => 'chefe@exemplo.com',
            'password' => 'errada',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('admin');
        $this->assertDatabaseHas('admin_audit_logs', ['acao' => AdminAuditLog::LOGIN_FALHOU]);
    }

    /** Mensagem única: o painel não diz quais e-mails existem. */
    public function test_e_mail_inexistente_devolve_a_mesma_mensagem(): void
    {
        $this->admin();

        $resposta = $this->post(route('painel.autenticar'), [
            'email' => 'ninguem@exemplo.com',
            'password' => self::SENHA,
        ]);

        $resposta->assertSessionHasErrors(['email' => 'Credenciais inválidas.']);
    }

    // ── Camada 3: segundo fator obrigatório ──────────────────────────────────

    /**
     * Senha certa NÃO abre o painel: sem 2FA confirmado, o admin fica preso na tela
     * de configuração.
     */
    public function test_senha_certa_sem_2fa_para_na_tela_de_configuracao(): void
    {
        $this->admin(com2fa: false);

        $this->post(route('painel.autenticar'), [
            'email' => 'chefe@exemplo.com',
            'password' => self::SENHA,
        ])->assertRedirect(route('painel.home'));

        // Autenticado, sim — mas o painel empurra para o setup.
        $this->get(route('painel.home'))->assertRedirect(route('painel.2fa.setup'));
        $this->get(route('painel.pessoas'))->assertRedirect(route('painel.2fa.setup'));
    }

    /** Com 2FA configurado mas não provado NESTA sessão: vai para o desafio. */
    public function test_com_2fa_configurado_a_sessao_ainda_precisa_do_codigo(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get(route('painel.home'))
            ->assertRedirect(route('painel.2fa.desafio'));
    }

    public function test_codigo_correto_abre_o_painel_e_registra_o_login(): void
    {
        $admin = $this->admin();
        $codigo = Totp::codigo($admin->two_factor_secret, Totp::passoAtual());

        $this->actingAs($admin, 'admin')
            ->post(route('painel.2fa.verificar'), ['codigo' => $codigo])
            ->assertRedirect(route('painel.home'));

        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_ok' => true])
            ->get(route('painel.home'))->assertOk();

        $this->assertDatabaseHas('admin_audit_logs', ['acao' => AdminAuditLog::LOGIN]);
        $this->assertNotNull($admin->fresh()->last_login_at);
    }

    public function test_codigo_errado_e_recusado_e_registrado(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post(route('painel.2fa.verificar'), ['codigo' => '000000'])
            ->assertSessionHasErrors('codigo');

        $this->assertDatabaseHas('admin_audit_logs', ['acao' => AdminAuditLog::TOTP_FALHOU]);
    }

    /**
     * O MESMO código não serve duas vezes, mesmo dentro da janela de 30 s em que ele
     * ainda é válido pelo relógio — senão um código espiado vale uma segunda entrada.
     */
    public function test_o_mesmo_codigo_nao_entra_duas_vezes(): void
    {
        $admin = $this->admin();
        $codigo = Totp::codigo($admin->two_factor_secret, Totp::passoAtual());

        $this->actingAs($admin, 'admin')->post(route('painel.2fa.verificar'), ['codigo' => $codigo])
            ->assertRedirect(route('painel.home'));

        $this->actingAs($admin->fresh(), 'admin')
            ->post(route('painel.2fa.verificar'), ['codigo' => $codigo])
            ->assertSessionHasErrors('codigo');
    }

    /** Perder o celular não pode trancar o painel para sempre. */
    public function test_codigo_de_recuperacao_tambem_entra(): void
    {
        $admin = $this->admin();
        $codigo = $admin->two_factor_recovery_codes[0];

        $this->actingAs($admin, 'admin')->post(route('painel.2fa.verificar'), ['codigo' => $codigo])
            ->assertRedirect(route('painel.home'));

        // Uso único: some da lista.
        $this->assertNotContains($codigo, $admin->fresh()->two_factor_recovery_codes);
    }

    public function test_o_codigo_de_recuperacao_nao_serve_duas_vezes(): void
    {
        $admin = $this->admin();
        $codigo = $admin->two_factor_recovery_codes[0];

        $this->actingAs($admin, 'admin')->post(route('painel.2fa.verificar'), ['codigo' => $codigo]);

        $this->actingAs($admin->fresh(), 'admin')
            ->post(route('painel.2fa.verificar'), ['codigo' => $codigo])
            ->assertSessionHasErrors('codigo');
    }

    /** O setup só vira 2FA de verdade quando um código correto é digitado. */
    public function test_o_setup_exige_provar_o_codigo(): void
    {
        $admin = $this->admin(com2fa: false);

        $this->actingAs($admin, 'admin')->get(route('painel.2fa.setup'))->assertOk();

        $admin->refresh();
        $this->assertNotNull($admin->two_factor_secret, 'O setup precisa gerar o segredo.');
        $this->assertFalse($admin->temDoisFatores(), 'Segredo gerado não é 2FA confirmado.');

        $this->actingAs($admin, 'admin')
            ->post(route('painel.2fa.confirmar'), ['codigo' => '000000'])
            ->assertSessionHasErrors('codigo');
        $this->assertFalse($admin->fresh()->temDoisFatores());

        $codigo = Totp::codigo($admin->two_factor_secret, Totp::passoAtual());
        $this->actingAs($admin, 'admin')
            ->post(route('painel.2fa.confirmar'), ['codigo' => $codigo])
            ->assertRedirect(route('painel.2fa.recuperacao'));

        $this->assertTrue($admin->fresh()->temDoisFatores());
    }

    /** Não existe rota para desligar o segundo fator do painel. */
    public function test_nao_existe_caminho_para_desligar_o_2fa(): void
    {
        $rotas = collect(app('router')->getRoutes())
            ->filter(fn ($r) => str_starts_with((string) $r->getName(), 'painel.'))
            ->map(fn ($r) => $r->getName())
            ->values();

        foreach ($rotas as $nome) {
            $this->assertStringNotContainsString('desligar', $nome);
            $this->assertStringNotContainsString('remover', $nome);
        }
    }

    // ── Sessão ───────────────────────────────────────────────────────────────

    public function test_sair_encerra_a_sessao_do_painel(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_ok' => true])
            ->post(route('painel.logout'))->assertRedirect(route('painel.login'));

        $this->assertGuest('admin');
    }
}
