<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Models\User;
use App\Support\RecoveryCodes;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verificação em duas etapas (2FA por app autenticador).
 *
 * O que estes testes protegem, em ordem de gravidade se quebrar:
 *
 *  1. **Que a senha sozinha não entra** numa conta com 2FA ligado — é o recurso inteiro.
 *  2. **Que ninguém fica trancado fora**: o recurso é opcional, ligar tem duas etapas
 *     (o login só cobra código depois da confirmação) e existe código de recuperação.
 *  3. **Que os códigos são de uso único** (TOTP e recuperação): sem isso, quem espia a
 *     tela por cima do ombro entra depois.
 *  4. **Que ligar e desligar exigem a senha atual**: sem isso, uma sessão sequestrada
 *     desliga a proteção em um clique.
 */
class DoisFatoresTest extends TestCase
{
    // A tela do 2FA saiu da aba Segurança e ganhou aba própria em 06/08/2026
    // (`/configuracoes/2fa`): com senha + sessões + 2FA juntos, a aba Segurança
    // passava de duas telas de rolagem. Ver SettingsController::TABS.

    use RefreshDatabase;

    private const SENHA = 'password';

    /** Usuário com o 2FA já ligado e confirmado. */
    private function comDoisFatores(array $atributos = []): User
    {
        $user = User::factory()->create($atributos);

        $user->forceFill([
            'two_factor_secret' => Totp::gerarSegredo(),
            'two_factor_recovery_codes' => RecoveryCodes::gerar(),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user->fresh();
    }

    /** O código que o autenticador mostraria agora (ou em outro passo). */
    private function codigoAtual(User $user, int $deslocamento = 0): string
    {
        return Totp::codigo($user->two_factor_secret, Totp::passoAtual() + $deslocamento);
    }

    // =========================================================== o card e o estado inicial

    public function test_2fa_nasce_desligado_e_o_card_deixa_de_ser_em_breve(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->temDoisFatores());
        $this->assertNull($user->two_factor_secret);

        $this->actingAs($user)->get('/configuracoes/2fa')
            ->assertOk()
            ->assertSee('Verificação em duas etapas')
            ->assertSee('Ativar verificação em duas etapas')
            ->assertDontSee('Em breve');
    }

    // =========================================================== ligar

    public function test_ativar_exige_a_senha_atual(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/configuracoes/2fa')
            ->post(route('settings.2fa.ativar'), ['password' => 'senha-errada'])
            ->assertSessionHasErrors('password', errorBag: 'twoFactor');

        $this->assertNull($user->fresh()->two_factor_secret, 'Gerou o segredo mesmo com a senha errada.');
    }

    public function test_ativar_gera_o_segredo_mas_ainda_nao_cobra_codigo_no_login(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('settings.2fa.ativar'), ['password' => self::SENHA])
            ->assertRedirect(route('settings', '2fa'));

        $user->refresh();

        $this->assertNotNull($user->two_factor_secret);
        $this->assertTrue($user->doisFatoresPendente());

        // A trava só nasce com a confirmação. Fechar a aba aqui não pode trancar ninguém.
        $this->assertFalse($user->temDoisFatores());

        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => self::SENHA])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_tela_mostra_o_qr_e_a_chave_manual_durante_a_configuracao(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('settings.2fa.ativar'), ['password' => self::SENHA]);

        $resposta = $this->actingAs($user->fresh())->get('/configuracoes/2fa')->assertOk();

        // QR desenhado no servidor (SVG embutido) + a chave em grupos de 4 para quem
        // não consegue escanear.
        $resposta->assertSee('<svg', escape: false);
        $resposta->assertSee(Totp::formatarSegredo($user->fresh()->two_factor_secret));
    }

    /**
     * Quem pede para ligar cai DIRETO na tela com o QR — seguindo o redirect, como o
     * navegador faz. Antes o `voltar()` mandava para a aba Segurança, onde o card do 2FA
     * não existe mais desde que ganhou aba própria: a pessoa digitava a senha e via a tela
     * de senha e sessões, sem QR nenhum, e só o achava clicando de novo em "2FA".
     */
    public function test_depois_de_ativar_o_redirect_leva_a_tela_com_o_qr_e_a_chave(): void
    {
        $user = User::factory()->create();

        $resposta = $this->actingAs($user)
            ->followingRedirects()
            ->post(route('settings.2fa.ativar'), ['password' => self::SENHA])
            ->assertOk();

        $resposta->assertSee(Totp::formatarSegredo($user->fresh()->two_factor_secret));
        $resposta->assertSee('Confirmar e ativar');
    }

    /** Erro do código (vem por `voltar()`, não pelo `back()` da validação) na aba certa. */
    public function test_codigo_errado_na_confirmacao_aparece_na_aba_do_2fa(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('settings.2fa.ativar'), ['password' => self::SENHA]);

        $this->actingAs($user->fresh())
            ->followingRedirects()
            ->post(route('settings.2fa.confirmar'), ['codigo' => '000000'])
            ->assertOk()
            ->assertSee('Código incorreto ou expirado')
            // E a pessoa continua com o QR à frente para tentar de novo.
            ->assertSee(Totp::formatarSegredo($user->fresh()->two_factor_secret));
    }

    public function test_confirmar_com_o_codigo_certo_liga_o_2fa_e_entrega_os_codigos_de_recuperacao(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('settings.2fa.ativar'), ['password' => self::SENHA]);
        $user->refresh();

        $this->actingAs($user)
            ->post(route('settings.2fa.confirmar'), ['codigo' => $this->codigoAtual($user)])
            ->assertRedirect(route('settings', '2fa'))
            ->assertSessionHas('status', 'two-factor-enabled')
            ->assertSessionHas('codigosDeRecuperacao');

        $user->refresh();

        $this->assertTrue($user->temDoisFatores());
        $this->assertCount(RecoveryCodes::QUANTIDADE, $user->two_factor_recovery_codes);
    }

    /**
     * A lista de códigos aparece na tela UMA vez, logo depois de confirmar. Se este
     * caminho quebrar, o usuário liga o 2FA e nunca vê a única saída que tem para o dia
     * em que perder o celular.
     *
     * Segue o redirect de verdade, como o navegador. A versão antiga deste teste fazia um
     * GET direto em `/configuracoes/2fa` e passava — enquanto o redirect real ia para a aba
     * Segurança, que CONSUMIA o flash sem mostrar os códigos. Na tela, eles nunca apareciam.
     */
    public function test_a_lista_de_codigos_aparece_na_tela_depois_de_confirmar(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('settings.2fa.ativar'), ['password' => self::SENHA]);
        $user->refresh();

        $resposta = $this->actingAs($user)
            ->followingRedirects()
            ->post(route('settings.2fa.confirmar'), ['codigo' => $this->codigoAtual($user)])
            ->assertOk();

        foreach ($user->fresh()->two_factor_recovery_codes as $codigo) {
            $resposta->assertSee($codigo);
        }

        // E não fica na tela para sempre: recarregar já não mostra (veio de flash).
        $this->actingAs($user->fresh())->get('/configuracoes/2fa')
            ->assertOk()
            ->assertDontSee($user->fresh()->two_factor_recovery_codes[0]);
    }

    /** O estado "ativada" tem ramos próprios no Blade (data de ativação, ações). */
    public function test_a_tela_no_estado_ativado_abre_e_oferece_desligar(): void
    {
        $user = $this->comDoisFatores();

        $this->actingAs($user)->get('/configuracoes/2fa')
            ->assertOk()
            ->assertSee('Ativada')
            ->assertSee('Desativar verificação em duas etapas')
            ->assertSee('Gerar novos códigos de recuperação')
            // O segredo nunca é reexibido depois de confirmado.
            ->assertDontSee($user->two_factor_secret);
    }

    /** A tela de quem PERDEU o celular precisa abrir sem depender de JavaScript. */
    public function test_o_desafio_oferece_o_modo_codigo_de_recuperacao(): void
    {
        $user = $this->comDoisFatores();

        $this->post('/login', ['email' => $user->email, 'password' => self::SENHA]);

        $this->get(route('two-factor.login'))
            ->assertOk()
            ->assertSee('Não consigo acessar o aplicativo');

        $this->get(route('two-factor.login', ['recuperacao' => 1]))
            ->assertOk()
            ->assertSee('Código de recuperação');
    }

    public function test_confirmar_com_codigo_errado_nao_liga_nada(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('settings.2fa.ativar'), ['password' => self::SENHA]);

        $this->actingAs($user->fresh())
            ->from('/configuracoes/2fa')
            ->post(route('settings.2fa.confirmar'), ['codigo' => '000000'])
            ->assertSessionHasErrors('codigo', errorBag: 'twoFactor');

        $this->assertFalse($user->fresh()->temDoisFatores());
    }

    public function test_ativar_de_novo_nao_regenera_o_segredo_de_quem_ja_esta_protegido(): void
    {
        $user = $this->comDoisFatores();
        $segredo = $user->two_factor_secret;

        $this->actingAs($user)
            ->from('/configuracoes/2fa')
            ->post(route('settings.2fa.ativar'), ['password' => self::SENHA])
            ->assertSessionHasErrors('two_factor', errorBag: 'twoFactor');

        $user->refresh();

        // Regerar aqui derrubaria `two_factor_confirmed_at` e deixaria a conta
        // desprotegida sem que ninguém tivesse pedido para desligar.
        $this->assertSame($segredo, $user->two_factor_secret);
        $this->assertTrue($user->temDoisFatores());
    }

    // =========================================================== o desafio do login

    public function test_a_senha_certa_nao_entra_sozinha_quando_o_2fa_esta_ligado(): void
    {
        $user = $this->comDoisFatores();

        $this->post('/login', ['email' => $user->email, 'password' => self::SENHA])
            ->assertRedirect(route('two-factor.login'));

        $this->assertGuest();

        // E o dashboard continua fechado enquanto o código não vier.
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_o_codigo_certo_conclui_o_login(): void
    {
        $user = $this->comDoisFatores();

        $this->post('/login', ['email' => $user->email, 'password' => self::SENHA]);
        $this->get(route('two-factor.login'))->assertOk()->assertSee('Verificação em duas etapas');

        $this->post(route('two-factor.login'), ['codigo' => $this->codigoAtual($user)])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_o_codigo_errado_nao_entra(): void
    {
        $user = $this->comDoisFatores();

        $this->post('/login', ['email' => $user->email, 'password' => self::SENHA]);

        $this->from(route('two-factor.login'))
            ->post(route('two-factor.login'), ['codigo' => '123456'])
            ->assertSessionHasErrors('codigo');

        $this->assertGuest();
    }

    /** Replay: 30 segundos bastariam para quem viu o código na tela alheia. */
    public function test_o_mesmo_codigo_nao_entra_duas_vezes(): void
    {
        $user = $this->comDoisFatores();
        $codigo = $this->codigoAtual($user);

        $this->post('/login', ['email' => $user->email, 'password' => self::SENHA]);
        $this->post(route('two-factor.login'), ['codigo' => $codigo]);
        $this->assertAuthenticatedAs($user);

        $this->post('/logout');

        $this->post('/login', ['email' => $user->email, 'password' => self::SENHA]);
        $this->from(route('two-factor.login'))
            ->post(route('two-factor.login'), ['codigo' => $codigo])
            ->assertSessionHasErrors('codigo');

        $this->assertGuest();
    }

    public function test_codigo_de_um_passo_vizinho_entra_mas_de_um_passo_distante_nao(): void
    {
        $user = $this->comDoisFatores();

        // Relógio do celular 30 s adiantado: precisa entrar.
        $this->post('/login', ['email' => $user->email, 'password' => self::SENHA]);
        $this->post(route('two-factor.login'), ['codigo' => $this->codigoAtual($user, 1)]);
        $this->assertAuthenticatedAs($user);

        $this->post('/logout');

        // Código de 2,5 minutos atrás: fora da janela.
        $this->post('/login', ['email' => $user->email, 'password' => self::SENHA]);
        $this->from(route('two-factor.login'))
            ->post(route('two-factor.login'), ['codigo' => $this->codigoAtual($user, -5)])
            ->assertSessionHasErrors('codigo');
        $this->assertGuest();
    }

    public function test_o_desafio_expira_e_a_senha_volta_a_ser_exigida(): void
    {
        $user = $this->comDoisFatores();

        $this->post('/login', ['email' => $user->email, 'password' => self::SENHA]);

        // Computador compartilhado: sem prazo, quem sentasse depois só precisaria do código.
        $this->travel(TwoFactorChallengeController::VALIDADE_EM_MINUTOS + 1)->minutes();

        $this->post(route('two-factor.login'), ['codigo' => $this->codigoAtual($user)])
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_desafio_sem_login_pendente_volta_para_o_login(): void
    {
        $this->get(route('two-factor.login'))->assertRedirect(route('login'));

        $this->post(route('two-factor.login'), ['codigo' => '123456'])
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    /** 2FA desligado de outro aparelho no meio do caminho não pode virar entrada franca. */
    public function test_desligar_o_2fa_durante_o_desafio_invalida_a_pendencia(): void
    {
        $user = $this->comDoisFatores();

        $this->post('/login', ['email' => $user->email, 'password' => self::SENHA]);

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        $this->post(route('two-factor.login'), ['codigo' => '123456'])
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_login_por_ajax_devolve_o_endereco_do_desafio(): void
    {
        $user = $this->comDoisFatores();

        $this->postJson('/login', ['email' => $user->email, 'password' => self::SENHA])
            ->assertOk()
            ->assertJson(['redirect' => route('two-factor.login')]);

        $this->assertGuest();
    }

    public function test_entrar_com_outra_conta_descarta_o_login_pendente(): void
    {
        $user = $this->comDoisFatores();

        $this->post('/login', ['email' => $user->email, 'password' => self::SENHA]);
        $this->post(route('two-factor.cancel'))->assertRedirect(route('login'));

        $this->get(route('two-factor.login'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // =========================================================== códigos de recuperação

    public function test_codigo_de_recuperacao_entra_e_e_gasto(): void
    {
        $user = $this->comDoisFatores();
        $codigos = $user->two_factor_recovery_codes;

        $this->post('/login', ['email' => $user->email, 'password' => self::SENHA]);

        $this->post(route('two-factor.login'), ['codigo' => $codigos[0], 'recuperacao' => 1])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertCount(count($codigos) - 1, $user->fresh()->two_factor_recovery_codes);
    }

    public function test_codigo_de_recuperacao_nao_serve_duas_vezes(): void
    {
        $user = $this->comDoisFatores();
        $codigo = $user->two_factor_recovery_codes[0];

        $this->post('/login', ['email' => $user->email, 'password' => self::SENHA]);
        $this->post(route('two-factor.login'), ['codigo' => $codigo, 'recuperacao' => 1]);
        $this->post('/logout');

        $this->post('/login', ['email' => $user->email, 'password' => self::SENHA]);
        $this->from(route('two-factor.login'))
            ->post(route('two-factor.login'), ['codigo' => $codigo, 'recuperacao' => 1])
            ->assertSessionHasErrors('codigo');

        $this->assertGuest();
    }

    public function test_regerar_codigos_exige_senha_e_invalida_os_antigos(): void
    {
        $user = $this->comDoisFatores();
        $antigos = $user->two_factor_recovery_codes;

        $this->actingAs($user)
            ->from('/configuracoes/2fa')
            ->post(route('settings.2fa.codigos'), ['password' => 'senha-errada'])
            ->assertSessionHasErrors('password', errorBag: 'twoFactorCodigos');

        $this->assertSame($antigos, $user->fresh()->two_factor_recovery_codes);

        $this->actingAs($user)
            ->post(route('settings.2fa.codigos'), ['password' => self::SENHA])
            ->assertSessionHas('codigosDeRecuperacao');

        $novos = $user->fresh()->two_factor_recovery_codes;

        $this->assertCount(RecoveryCodes::QUANTIDADE, $novos);
        $this->assertEmpty(array_intersect($antigos, $novos), 'Um código antigo sobreviveu à troca.');
    }

    /**
     * Os códigos novos também vêm em flash, uma vez só: o redirect precisa cair na aba que
     * os mostra. Na aba Segurança, gerar códigos novos invalidava os antigos e escondia os
     * novos — a pessoa terminava sem lista nenhuma.
     */
    public function test_os_codigos_novos_aparecem_seguindo_o_redirect(): void
    {
        $user = $this->comDoisFatores();

        $resposta = $this->actingAs($user)
            ->followingRedirects()
            ->post(route('settings.2fa.codigos'), ['password' => self::SENHA])
            ->assertOk();

        foreach ($user->fresh()->two_factor_recovery_codes as $codigo) {
            $resposta->assertSee($codigo);
        }
    }

    // =========================================================== desligar

    public function test_desativar_exige_a_senha_atual(): void
    {
        $user = $this->comDoisFatores();

        $this->actingAs($user)
            ->from('/configuracoes/2fa')
            ->delete(route('settings.2fa.desativar'), ['password' => 'senha-errada'])
            ->assertSessionHasErrors('password', errorBag: 'twoFactorDesligar');

        $this->assertTrue($user->fresh()->temDoisFatores());
    }

    public function test_desativar_limpa_tudo_e_o_login_volta_ao_normal(): void
    {
        $user = $this->comDoisFatores();

        $this->actingAs($user)
            ->delete(route('settings.2fa.desativar'), ['password' => self::SENHA])
            ->assertSessionHas('status', 'two-factor-disabled');

        $user->refresh();

        $this->assertFalse($user->temDoisFatores());
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_last_step);

        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => self::SENHA])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
    }

    /** Cancelar um setup pendente não baixa proteção nenhuma — não faz sentido pedir a senha. */
    public function test_cancelar_configuracao_pendente_nao_exige_senha(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('settings.2fa.ativar'), ['password' => self::SENHA]);

        $this->actingAs($user->fresh())
            ->delete(route('settings.2fa.desativar'))
            ->assertSessionHas('status', 'two-factor-cancelled');

        $this->assertNull($user->fresh()->two_factor_secret);
    }

    // =========================================================== quem pode, e o que fica no banco

    public function test_login_sem_2fa_continua_indo_direto_para_o_dashboard(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => self::SENHA])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    /** O 2FA protege o LOGIN, que é individual — cada pessoa da família liga o seu. */
    public function test_dependente_tambem_pode_ligar_o_proprio_2fa(): void
    {
        $titular = User::factory()->create(['is_admin' => true]);
        $dependente = User::factory()->create([
            'account_owner_id' => $titular->id,
            'is_admin' => false,
        ]);

        $this->actingAs($dependente)->post(route('settings.2fa.ativar'), ['password' => self::SENHA]);
        $dependente->refresh();

        $this->actingAs($dependente)
            ->post(route('settings.2fa.confirmar'), ['codigo' => $this->codigoAtual($dependente)]);

        $this->assertTrue($dependente->fresh()->temDoisFatores());
        // E o titular segue sem 2FA: ligar o seu não mexe no de mais ninguém.
        $this->assertFalse($titular->fresh()->temDoisFatores());
    }

    public function test_segredo_e_codigos_ficam_cifrados_no_banco(): void
    {
        $user = $this->comDoisFatores();
        $bruto = DB::table('users')->where('id', $user->id)->first();

        $this->assertNotSame($user->two_factor_secret, $bruto->two_factor_secret);
        $this->assertSame($user->two_factor_secret, Crypt::decryptString($bruto->two_factor_secret));

        // Um dump de backup vazado não pode entregar a segunda etapa de todo mundo.
        foreach ($user->two_factor_recovery_codes as $codigo) {
            $this->assertStringNotContainsString($codigo, $bruto->two_factor_recovery_codes);
        }
    }

    public function test_segredo_e_codigos_nao_escapam_na_serializacao(): void
    {
        $json = $this->comDoisFatores()->toJson();

        $this->assertStringNotContainsString('two_factor_secret', $json);
        $this->assertStringNotContainsString('two_factor_recovery_codes', $json);
    }

    /** 6 dígitos sem limite de tentativas é força bruta com hora marcada. */
    public function test_o_desafio_tem_limite_de_tentativas(): void
    {
        $user = $this->comDoisFatores();

        $this->post('/login', ['email' => $user->email, 'password' => self::SENHA]);

        for ($i = 0; $i < 5; $i++) {
            $this->from(route('two-factor.login'))
                ->post(route('two-factor.login'), ['codigo' => '000000'])
                ->assertRedirect(route('two-factor.login'));
        }

        $this->post(route('two-factor.login'), ['codigo' => '000000'])->assertStatus(429);
        $this->assertGuest();
    }
}
