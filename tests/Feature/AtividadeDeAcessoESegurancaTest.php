<?php

namespace Tests\Feature;

use App\Models\Atividade;
use App\Models\User;
use App\Support\RecoveryCodes;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Registro de atividade — ACESSO E SEGURANÇA (out/2026).
 *
 * Entrar (com senha, com 2FA, pelo "lembrar de mim"), sair, errar o código do 2FA com a
 * senha certa, trocar e redefinir a senha, ligar/desligar o 2FA, encerrar sessões, pedir
 * troca de e-mail e ligar/desligar lembretes. É a trilha que a pessoa lê quando desconfia
 * de que alguém entrou na conta dela — e NADA dela pode carregar segredo.
 */
class AtividadeDeAcessoESegurancaTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'password';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    private function comDoisFatores(): User
    {
        $user = User::factory()->create(['name' => 'Victor']);
        $user->forceFill([
            'two_factor_secret' => Totp::gerarSegredo(),
            'two_factor_recovery_codes' => RecoveryCodes::gerar(),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user->fresh();
    }

    public function test_cadastro_registra_a_conta_criada_e_mais_nada(): void
    {
        $this->post('/register', [
            'name' => 'Ana', 'email' => 'ana@exemplo.test', 'password' => 'senha-forte-123', 'terms' => '1',
        ])->assertRedirect();

        $ana = User::where('email', 'ana@exemplo.test')->sole();
        $linha = Atividade::sole();

        // Nem as 14 categorias padrão, nem um "entrou" logo depois: o cadastro é UMA linha.
        $this->assertSame('app.conta_criada', $linha->acao);
        $this->assertSame('Ana criou a conta no Stabil Money', $linha->descricao);
        $this->assertSame($ana->id, $linha->owner_id);
        $this->assertSame($ana->id, $linha->user_id, 'Sem sessão ainda: o autor é a própria pessoa, não o "Sistema".');
    }

    public function test_entrar_e_sair_com_e_mail_e_senha(): void
    {
        $user = User::factory()->create(['name' => 'Victor']);

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0')
            ->post('/login', ['email' => $user->email, 'password' => self::SENHA])->assertRedirect();

        $entrou = Atividade::where('acao', 'acesso.entrou')->sole();
        $this->assertSame('Victor entrou no app com e-mail e senha', $entrou->descricao);
        $this->assertSame('acesso', $entrou->grupo);
        $this->assertSame('127.0.0.1', $entrou->ip);
        $this->assertStringContainsString('Chrome', (string) $entrou->aparelho);

        $this->post('/logout')->assertRedirect();
        $this->assertSame('Victor saiu do app', Atividade::where('acao', 'acesso.saiu')->sole()->descricao);
    }

    public function test_codigo_errado_do_2fa_com_a_senha_certa_fica_registrado(): void
    {
        $user = $this->comDoisFatores();

        $this->post('/login', ['email' => $user->email, 'password' => self::SENHA])
            ->assertRedirect(route('two-factor.login'));
        $this->assertSame(0, Atividade::count(), 'Senha certa sem o código ainda não é entrada.');

        $this->from(route('two-factor.login'))
            ->post(route('two-factor.login'), ['codigo' => '000000'])
            ->assertSessionHasErrors('codigo');

        $errou = Atividade::where('acao', 'acesso.codigo_2fa_errado')->sole();
        $this->assertSame('Victor errou o código da verificação em duas etapas ao entrar (a senha estava certa)', $errou->descricao);
        $this->assertSame($user->id, $errou->user_id);

        $this->post(route('two-factor.login'), [
            'codigo' => Totp::codigo($user->two_factor_secret, Totp::passoAtual()),
        ])->assertRedirect();

        $this->assertSame(
            'Victor entrou no app com a verificação em duas etapas',
            Atividade::where('acao', 'acesso.entrou_2fa')->sole()->descricao,
        );
    }

    public function test_voltar_pelo_lembrar_de_mim_fica_registrado(): void
    {
        $user = User::factory()->create(['name' => 'Victor']);
        $nome = auth()->guard('web')->getRecallerName();

        $cookie = $this->post('/login', ['email' => $user->email, 'password' => self::SENHA, 'remember' => 'on'])
            ->assertRedirect()
            ->getCookie($nome);
        $this->assertNotNull($cookie);

        // Outro aparelho: sem sessão, só com o cookie de "lembrar de mim" (ver
        // LembrarDeMimAntigoNaoEntraMaisTest::outroAparelho — o app de teste não é recriado).
        $this->app['auth']->forgetGuards();
        $this->app['session']->driver()->flush();
        $this->defaultCookies = [];

        $this->withCookie($nome, $cookie->getValue())->get(route('dashboard'))->assertOk();

        $this->assertSame(
            'Victor voltou ao app pelo “Lembrar de mim”, sem digitar a senha',
            Atividade::where('acao', 'acesso.lembrado')->sole()->descricao,
        );
    }

    public function test_trocar_a_senha_encerrar_sessoes_e_lembretes(): void
    {
        $user = User::factory()->create(['name' => 'Victor', 'is_admin' => true]);

        $this->actingAs($user)->put(route('password.update'), [
            'current_password' => self::SENHA,
            'password' => 'nova-senha-bem-forte-1',
            'password_confirmation' => 'nova-senha-bem-forte-1',
        ])->assertSessionHasNoErrors();

        $this->actingAs($user)->delete(route('settings.sessions.destroy'), ['password' => 'nova-senha-bem-forte-1'])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)->patch(route('settings.lembretes'), ['reminder_emails' => '0'])->assertRedirect();
        // Reenviar o MESMO estado não é ação nova.
        $this->actingAs($user)->patch(route('settings.lembretes'), ['reminder_emails' => '0'])->assertRedirect();

        $this->assertSame(
            ['senha.trocada', 'sessoes.encerradas', 'lembretes.desligados'],
            Atividade::orderBy('id')->pluck('acao')->all(),
        );
        $this->assertSame('Victor trocou a senha (os outros aparelhos foram desconectados)', Atividade::where('acao', 'senha.trocada')->value('descricao'));
    }

    public function test_redefinir_a_senha_pelo_link_registra_a_propria_pessoa_como_autora(): void
    {
        $user = User::factory()->create(['name' => 'Victor']);

        $this->post(route('password.store'), [
            'token' => app('auth.password.broker')->createToken($user),
            'email' => $user->email,
            'password' => 'senha-nova-forte-789',
            'password_confirmation' => 'senha-nova-forte-789',
        ])->assertSessionHasNoErrors();

        $linha = Atividade::where('acao', 'senha.redefinida')->sole();
        $this->assertSame($user->id, $linha->user_id);
        $this->assertStringStartsWith('Victor redefiniu a senha pelo link', $linha->descricao);
    }

    public function test_ligar_e_desligar_o_2fa(): void
    {
        $user = User::factory()->create(['name' => 'Victor']);

        $this->actingAs($user)->post(route('settings.2fa.ativar'), ['password' => self::SENHA]);
        $user->refresh();
        $this->actingAs($user)->post(route('settings.2fa.confirmar'), [
            'codigo' => Totp::codigo($user->two_factor_secret, Totp::passoAtual()),
        ])->assertSessionHasNoErrors();

        // Confirmar o setup chama `login()` só para reemitir o cookie: não é uma "entrada".
        $this->assertSame(['dois_fatores.ligado'], Atividade::orderBy('id')->pluck('acao')->all());

        $user->refresh();
        $this->actingAs($user)->delete(route('settings.2fa.desativar'), [
            'password' => self::SENHA,
            'codigo' => Totp::codigo($user->two_factor_secret, Totp::passoAtual() + 1),
        ])->assertSessionHasNoErrors();

        $this->assertSame('Victor desligou a verificação em duas etapas', Atividade::where('acao', 'dois_fatores.desligado')->sole()->descricao);
    }

    public function test_nenhum_segredo_vai_para_o_registro_e_o_ip_fica_cifrado(): void
    {
        $user = User::factory()->create(['name' => 'Victor', 'is_admin' => true]);

        // Trocas que mexem em senha, token e 2FA — tudo junto, pelo caminho do model.
        $this->actingAs($user);
        $user->forceFill([
            'password' => 'senha-que-nao-pode-aparecer',
            'remember_token' => 'token-que-nao-pode-aparecer',
            'two_factor_secret' => 'SEGREDOQUENAOPODEAPARECER',
            'name' => 'Victor Rosa',
        ])->save();

        $this->actingAs($user)->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'senha-que-nao-pode-aparecer'])->assertRedirect();

        $linhas = Atividade::all();
        $this->assertNotEmpty($linhas);

        $tudo = json_encode(Atividade::query()->toBase()->get());
        foreach (['senha-que-nao-pode-aparecer', 'token-que-nao-pode-aparecer', 'SEGREDOQUENAOPODEAPARECER', $user->fresh()->password] as $segredo) {
            $this->assertStringNotContainsString($segredo, $tudo);
        }

        // A edição registrou SÓ o nome — senha, token e 2FA ficaram fora da lista de permissão.
        $this->assertSame(['name'], array_column(Atividade::where('acao', 'perfil.editado')->sole()->mudancas, 'campo'));

        // IP é dado pessoal: no banco, cifrado; na leitura pelo model, legível.
        $entrada = Atividade::where('acao', 'acesso.entrou')->sole();
        $this->assertSame('127.0.0.1', $entrada->ip);
        $this->assertNotSame('127.0.0.1', $entrada->getRawOriginal('ip'));
    }
}
