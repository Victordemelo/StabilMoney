<?php

namespace Tests\Feature;

use App\Mail\AlertaDoPainel;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\PendingCommand;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `php artisan admin:zerar-2fa {email}` — a volta do admin que perdeu o celular E os códigos
 * de recuperação (decisão de 23/09/2026).
 *
 * O defeito: no painel o 2FA é obrigatório e não se desliga pela web. Sem celular e sem
 * códigos, a única saída era o tinker — colunas apagadas à mão, sem histórico, sem aviso e
 * sem derrubar as sessões abertas daquele admin.
 *
 * O comportamento certo:
 *  - zera o 2FA e o próximo login cai na tela de configurar o app autenticador;
 *  - encerra as sessões daquele admin — senão quem tivesse uma cairia na configuração, que
 *    não pede a senha, e cadastraria o PRÓPRIO celular;
 *  - acha essas sessões pelo PAYLOAD: a coluna `user_id` de `sessions` é preenchida pelo
 *    guard `web` (numa sessão só de painel ela fica nula), e o admin 1 e o cliente 1 têm o
 *    mesmo número — apagar por `user_id` derrubaria o cliente e pouparia o admin;
 *  - registra no histórico e avisa por e-mail;
 *  - pede confirmação (ou `--force`) e recusa, sem mudar nada, o que não sabe fazer direito.
 *
 * Os navegadores entram de verdade (POST de login, com o driver `database`, o de produção):
 * as linhas de `sessions` são as que o app grava, cifradas como em produção.
 */
class PainelAdminDoisFatoresZeradoPeloTerminalTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'senha-de-teste-bem-comprida';

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.enabled' => true, 'session.driver' => 'database']);
        Mail::fake();

        $this->admin = Admin::factory()->comDoisFatores()->create([
            'name' => 'Chefe',
            'email' => 'chefe@exemplo.com',
            'password' => Hash::make(self::SENHA),
        ])->fresh();
    }

    private function zerar(array $opcoes = []): PendingCommand
    {
        return $this->artisan('admin:zerar-2fa', ['email' => 'chefe@exemplo.com', ...$opcoes]);
    }

    // ══════════════════════════════════════════════════ navegadores

    /**
     * Passa a agir de OUTRO navegador: sem usuário na memória dos guards, sem os atributos
     * da sessão anterior e sem cookie. Entre requisições o app de teste não é recriado — sem
     * zerar os três, um navegador herdaria o login do outro.
     *
     * O `auth.driver` também: é o guard que o `DatabaseSessionHandler` consulta para
     * preencher `user_id`, e ele é um singleton que o `forgetGuards` não alcança. Sem isto, a
     * linha do navegador do painel sairia com o id do cliente do navegador ANTERIOR — coisa
     * que em produção, com um app novo por requisição, não acontece. Pelo mesmo motivo, a
     * fila do `CookieJar` é esvaziada.
     */
    private function navegadorNovo(): static
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['cookie']->flushQueuedCookies();
        $this->app['session']->driver()->flush();
        $this->defaultCookies = [];

        return $this;
    }

    /** Volta ao navegador que guarda o cookie desta sessão. */
    private function comASessao(string $id): static
    {
        return $this->navegadorNovo()->withCookie(config('session.cookie'), $id);
    }

    /** O id de sessão que a resposta mandou o navegador guardar. */
    private function sessaoDa(TestResponse $resposta): string
    {
        $cookie = $resposta->getCookie(config('session.cookie'));
        $this->assertNotNull($cookie, 'A resposta não mandou o cookie de sessão.');

        return $cookie->getValue();
    }

    /** Login completo no painel (senha + código). Devolve a sessão com que o navegador ficou. */
    private function entrarNoPainel(?string $sessao = null): string
    {
        $sessao === null ? $this->navegadorNovo() : $this->comASessao($sessao);

        $sessao = $this->sessaoDa($this->post(route('painel.autenticar'), [
            'email' => 'chefe@exemplo.com',
            'password' => self::SENHA,
        ])->assertRedirect(route('painel.home')));

        return $this->sessaoDa($this->comASessao($sessao)->post(route('painel.2fa.verificar'), [
            'codigo' => Totp::codigo($this->admin->fresh()->two_factor_secret, Totp::passoAtual()),
        ])->assertRedirect(route('painel.home')));
    }

    /** Login no app. Devolve a sessão com que o navegador ficou. */
    private function entrarNoApp(User $user, ?string $sessao = null): string
    {
        $sessao === null ? $this->navegadorNovo() : $this->comASessao($sessao);

        return $this->sessaoDa($this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false)));
    }

    // ══════════════════════════════════════════════════ o caminho feliz

    public function test_zerar_apaga_o_2fa_e_o_proximo_login_cai_na_tela_de_configurar(): void
    {
        $this->zerar(['--force' => true])
            ->expectsOutputToContain('2FA de chefe@exemplo.com zerado.')
            ->assertSuccessful();

        $admin = $this->admin->fresh();
        $this->assertFalse($admin->temDoisFatores());
        $this->assertNull($admin->two_factor_secret);
        $this->assertNull($admin->two_factor_recovery_codes);
        $this->assertNull($admin->two_factor_confirmed_at);
        $this->assertNull($admin->two_factor_last_step);

        // A senha continua a mesma e leva à configuração — não ao painel.
        $sessao = $this->sessaoDa($this->navegadorNovo()->post(route('painel.autenticar'), [
            'email' => 'chefe@exemplo.com',
            'password' => self::SENHA,
        ])->assertRedirect(route('painel.home')));

        $this->comASessao($sessao)->get(route('painel.home'))->assertRedirect(route('painel.2fa.setup'));
        $this->comASessao($sessao)->get(route('painel.2fa.setup'))
            ->assertOk()
            ->assertSee('<svg', escape: false);

        // O segredo novo nasce na tela de configuração, ainda não confirmado.
        $this->assertNotNull($this->admin->fresh()->two_factor_secret);
        $this->assertFalse($this->admin->fresh()->temDoisFatores());
    }

    /**
     * Com o painel desligado o comando funciona igual (zerar com a superfície fora do ar é até
     * o momento mais seguro) e avisa que a configuração fica para quando ele for ligado.
     */
    public function test_com_o_painel_desligado_zera_e_avisa(): void
    {
        config(['admin.enabled' => false]);

        $this->zerar(['--force' => true])
            ->expectsOutputToContain('O painel está DESLIGADO')
            ->assertSuccessful();

        $this->assertFalse($this->admin->fresh()->temDoisFatores());
    }

    public function test_confirmando_na_pergunta_tambem_zera(): void
    {
        $this->zerar()
            ->expectsConfirmation('Zerar o 2FA de chefe@exemplo.com?', 'yes')
            ->assertSuccessful();

        $this->assertFalse($this->admin->fresh()->temDoisFatores());
    }

    /**
     * 🚨 O ponto delicado: as sessões do admin caem, e a do cliente com o MESMO id fica.
     *
     * Admin e cliente com o MESMO id. A sessão do painel é gravada com `user_id` nulo (o
     * guard que preenche a coluna é o `web`), e a do cliente com o id dele: um
     * `purgeForUser(id)` pouparia o admin e derrubaria o cliente — o avesso do pedido.
     */
    public function test_derruba_as_sessoes_do_admin_e_poupa_a_do_cliente_com_o_mesmo_id(): void
    {
        // O id é FORÇADO igual ao do admin. Contar com os dois "1" só funcionava no sqlite em
        // memória: no MySQL o auto-incremento não volta com o rollback de cada teste, e as duas
        // tabelas andam em ritmos diferentes (a suíte do CI deu admin 7 × cliente 24).
        $cliente = User::factory()->create(['id' => $this->admin->id]);
        $this->assertSame($this->admin->id, $cliente->id, 'O cenário precisa de ids iguais nas duas tabelas.');

        $sessaoDoAdmin = $this->entrarNoPainel();
        $sessaoDoCliente = $this->entrarNoApp($cliente);

        // Controle: antes de zerar, os dois navegadores entram.
        $this->comASessao($sessaoDoAdmin)->get(route('painel.home'))->assertOk();
        $this->comASessao($sessaoDoCliente)->get(route('dashboard'))->assertOk();
        $this->assertNull(DB::table('sessions')->where('id', $sessaoDoAdmin)->value('user_id'));
        $this->assertSame($cliente->id, (int) DB::table('sessions')->where('id', $sessaoDoCliente)->value('user_id'));

        $this->zerar(['--force' => true])->assertSuccessful();

        $this->assertDatabaseMissing('sessions', ['id' => $sessaoDoAdmin]);
        $this->assertDatabaseHas('sessions', ['id' => $sessaoDoCliente]);

        // E na prática: o navegador do admin volta para o login; o do cliente segue dentro.
        $this->comASessao($sessaoDoAdmin)->get(route('painel.home'))->assertRedirect(route('painel.login'));
        $this->comASessao($sessaoDoCliente)->get(route('dashboard'))->assertOk();
        $this->assertAuthenticatedAs($cliente);
    }

    /**
     * Um navegador logado no painel E no app tem UMA sessão (um cookie) — e ela sai inteira.
     * Tirar só o login do admin e regravar o resto seria desfeito pela primeira requisição em
     * andamento naquela sessão, que grava de volta tudo o que leu. Quem estava no app entra
     * de novo; os outros navegadores do cliente não são tocados.
     */
    public function test_a_sessao_do_navegador_logado_no_painel_e_no_app_sai_inteira(): void
    {
        $cliente = User::factory()->create();

        $sessaoDosDois = $this->entrarNoApp($cliente, $this->entrarNoPainel());
        $outroNavegadorDoCliente = $this->entrarNoApp($cliente);

        $this->zerar(['--force' => true])
            ->expectsOutputToContain('sessões do painel foram encerradas')
            ->assertSuccessful();

        $this->assertDatabaseMissing('sessions', ['id' => $sessaoDosDois]);
        $this->assertDatabaseHas('sessions', ['id' => $outroNavegadorDoCliente]);
    }

    public function test_registra_no_historico_e_avisa_o_proprio_admin(): void
    {
        $this->zerar(['--force' => true])->assertSuccessful();

        $registro = AdminAuditLog::where('acao', AdminAuditLog::ZEROU_2FA)->sole();

        // A conta de painel envolvida — como no LOGIN — e nenhum cliente como alvo: o id de um
        // admin em `target_user_id` puxaria a linha para a ficha do cliente de mesmo número.
        $this->assertSame($this->admin->id, $registro->admin_id);
        $this->assertNull($registro->target_user_id);
        $this->assertSame('Chefe <chefe@exemplo.com>', $registro->alvo_descricao);
        $this->assertStringContainsString('admin:zerar-2fa', (string) $registro->motivo);
        // Não houve requisição: nada de IP inventado.
        $this->assertNull($registro->ip);
        $this->assertTrue($registro->ehAlerta(), 'O 2FA zerado precisa saltar aos olhos no histórico.');

        Mail::assertSent(AlertaDoPainel::class, function (AlertaDoPainel $mail) {
            $html = $mail->render();

            return $mail->acao === AdminAuditLog::ZEROU_2FA
                // Sem ADMIN_ALERT_EMAIL, vai para o próprio admin.
                && $mail->hasTo('chefe@exemplo.com')
                && str_contains($mail->envelope()->subject, '2FA zerado pelo servidor')
                && str_contains($html, 'Terminal do servidor')
                && str_contains($html, 'chefe@exemplo.com')
                // O `request()` de um comando é de mentira: nada dele vai para o alerta.
                && ! str_contains($html, '127.0.0.1')
                && ! str_contains($html, 'Navegador desconhecido');
        });
    }

    public function test_com_admin_alert_email_o_aviso_vai_para_la(): void
    {
        config(['admin.alert_email' => 'seguranca@exemplo.com']);

        $this->zerar(['--force' => true])->assertSuccessful();

        Mail::assertSent(AlertaDoPainel::class, fn (AlertaDoPainel $mail) => $mail->acao === AdminAuditLog::ZEROU_2FA
            && $mail->hasTo('seguranca@exemplo.com'));
    }

    /** O histórico do painel mostra a linha — com o nome do admin, não "não autenticado". */
    public function test_o_historico_do_painel_mostra_o_2fa_zerado(): void
    {
        $this->zerar(['--force' => true])->assertSuccessful();

        $outro = Admin::factory()->comDoisFatores()->create();

        $this->actingAs($outro, 'admin')
            ->withSession(['admin_2fa_ok' => true])
            ->get(route('painel.historico'))
            ->assertOk()
            ->assertSee('2FA zerado pelo servidor')
            ->assertSee('Chefe &lt;chefe@exemplo.com&gt;', escape: false)
            ->assertSee('admin:zerar-2fa');
    }

    // ══════════════════════════════════════════════════ o que não muda nada

    public function test_confirmacao_recusada_nao_muda_nada(): void
    {
        $sessaoDoAdmin = $this->entrarNoPainel();
        Mail::fake(); // esquece o alerta do login acima

        $this->zerar()
            ->expectsConfirmation('Zerar o 2FA de chefe@exemplo.com?', 'no')
            ->expectsOutputToContain('Nada foi alterado.')
            ->assertFailed();

        $this->assertNadaMudou();
        $this->assertDatabaseHas('sessions', ['id' => $sessaoDoAdmin]);
        $this->comASessao($sessaoDoAdmin)->get(route('painel.home'))->assertOk();
    }

    public function test_email_que_nao_e_de_admin_sai_com_erro(): void
    {
        // O e-mail de um CLIENTE também não serve: o comando só mexe em `admins`.
        User::factory()->create(['email' => 'cliente@exemplo.com']);

        $this->artisan('admin:zerar-2fa', ['email' => 'cliente@exemplo.com', '--force' => true])
            ->expectsOutputToContain('Nenhum administrador com o e-mail cliente@exemplo.com')
            ->assertFailed();

        $this->assertNadaMudou();
    }

    /** Sem terminal (cron, script) não há a quem perguntar — e o silêncio não vale como "sim". */
    public function test_sem_terminal_interativo_exige_force(): void
    {
        $this->zerar(['--no-interaction' => true])
            ->expectsOutputToContain('--force')
            ->assertFailed();

        $this->assertNadaMudou();
    }

    /**
     * Com outro driver de sessão o comando não sabe encerrar as sessões do painel — e zerar
     * sem encerrá-las deixaria quem tem uma cadastrar o próprio celular sem a senha. Pior que
     * não zerar: recusa.
     */
    public function test_driver_de_sessao_que_nao_e_database_recusa_sem_mudar_nada(): void
    {
        config(['session.driver' => 'file']);

        $this->zerar(['--force' => true])
            ->expectsOutputToContain('driver de sessão é "file"')
            ->assertFailed();

        $this->assertNadaMudou();
    }

    private function assertNadaMudou(): void
    {
        $this->assertTrue($this->admin->fresh()->temDoisFatores(), 'O 2FA foi zerado mesmo sem confirmação.');
        $this->assertSame(
            $this->admin->two_factor_recovery_codes,
            $this->admin->fresh()->two_factor_recovery_codes,
        );
        $this->assertDatabaseMissing('admin_audit_logs', ['acao' => AdminAuditLog::ZEROU_2FA]);
        Mail::assertNotSent(AlertaDoPainel::class, fn (AlertaDoPainel $mail) => $mail->acao === AdminAuditLog::ZEROU_2FA);
    }
}
