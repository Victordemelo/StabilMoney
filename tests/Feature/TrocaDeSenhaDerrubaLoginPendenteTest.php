<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Models\User;
use App\Support\RecoveryCodes;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * O login pela metade — senha certa, esperando o código do 2FA — não pode sobreviver a
 * uma troca de senha.
 *
 * O cenário que isto fecha: quem tem a senha (vazada, "pescada") passa pela primeira
 * etapa e fica na tela do código, esperando conseguir o número. O dono percebe e redefine
 * a senha. Até aqui, o login pendente — que é a prova de que a senha ANTIGA foi digitada —
 * seguia valendo pelos 5 minutos inteiros, e o código completava a entrada com uma senha
 * que já não existia.
 *
 * A checagem compara o HASH da senha, e não `password_changed_at`: a data é um carimbo que
 * cada caminho precisa lembrar de gravar — e já houve caminho que não gravava (a edição de
 * dependente, achado A-6 da auditoria de 05/09/2026) —, enquanto o hash muda em todos. Por
 * isso há um teste por caminho real e um por escrita direta no banco, que não carimba data
 * nenhuma: vale para qualquer caminho, inclusive os que ainda não existem.
 *
 * "Outro aparelho" é simulado com `actingAs` na requisição que troca a senha, seguido de
 * `forgetGuards()`, que devolve este navegador ao estado de visitante com o login pendente.
 * O teste de controle (mudar só o nome) prova que essa simulação, sozinha, não derruba
 * pendência nenhuma — senão os outros passariam pelo motivo errado.
 */
class TrocaDeSenhaDerrubaLoginPendenteTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'password';

    private const SENHA_NOVA = 'outra-senha-bem-comprida';

    /** Usuário com o 2FA já ligado e confirmado (o mesmo preparo do DoisFatoresTest). */
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

    private function codigoAtual(User $user): string
    {
        return Totp::codigo($user->two_factor_secret, Totp::passoAtual());
    }

    /** Primeira etapa: senha certa numa conta com 2FA — fica o login pendente. */
    private function passarPelaSenha(User $user, string $senha = self::SENHA): void
    {
        $this->post('/login', ['email' => $user->email, 'password' => $senha])
            ->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
    }

    /** Uma ação feita em OUTRO aparelho, por quem já está logado lá. */
    private function emOutroAparelho(User $quem, callable $acao): void
    {
        $acao($this->actingAs($quem));

        // Este navegador volta a ser o do desafio: visitante, com o login pendente.
        $this->app['auth']->forgetGuards();
    }

    private function assertLoginPendenteDescartadoPelaTrocaDeSenha(TestResponse $resposta): void
    {
        $resposta->assertRedirect(route('login'))
            ->assertSessionMissing(TwoFactorChallengeController::CHAVE_ID);

        // Mensagem própria: com um "expirou" genérico a pessoa digitaria a senha ANTIGA
        // de novo e levaria um "credenciais inválidas" sem entender por quê.
        $this->assertStringContainsString(
            'a senha desta conta foi alterada',
            (string) session('errors')?->first('email'),
        );

        $this->assertGuest();
    }

    // =========================================================== os caminhos reais de troca

    public function test_redefinir_a_senha_pelo_link_derruba_o_login_pendente(): void
    {
        $user = $this->comDoisFatores();
        $this->passarPelaSenha($user);

        // O dono, de outro lugar, redefine a senha pelo link do e-mail.
        $this->post(route('password.store'), [
            'token' => app('auth.password.broker')->createToken($user),
            'email' => $user->email,
            'password' => self::SENHA_NOVA,
            'password_confirmation' => self::SENHA_NOVA,
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check(self::SENHA_NOVA, $user->fresh()->password), 'Pré-condição: a senha deveria ter mudado.');

        $this->assertLoginPendenteDescartadoPelaTrocaDeSenha(
            $this->post(route('two-factor.login'), ['codigo' => $this->codigoAtual($user)])
        );
    }

    public function test_trocar_a_senha_nas_configuracoes_derruba_o_login_pendente(): void
    {
        $user = $this->comDoisFatores();
        $this->passarPelaSenha($user);

        $this->emOutroAparelho($user, fn (TestCase $outro) => $outro
            ->put(route('password.update'), [
                'current_password' => self::SENHA,
                'password' => self::SENHA_NOVA,
                'password_confirmation' => self::SENHA_NOVA,
            ])
            ->assertSessionHasNoErrors());

        $this->assertTrue(Hash::check(self::SENHA_NOVA, $user->fresh()->password), 'Pré-condição: a senha deveria ter mudado.');

        $this->assertLoginPendenteDescartadoPelaTrocaDeSenha(
            $this->post(route('two-factor.login'), ['codigo' => $this->codigoAtual($user)])
        );
    }

    /** Quem troca aqui é OUTRA pessoa (o titular), numa sessão que não é a do dependente. */
    public function test_titular_trocar_a_senha_do_dependente_derruba_o_login_pendente_dele(): void
    {
        $titular = User::factory()->create();
        $dependente = $this->comDoisFatores(['account_owner_id' => $titular->id, 'is_admin' => false]);

        $this->passarPelaSenha($dependente);

        $this->emOutroAparelho($titular, fn (TestCase $outro) => $outro
            ->patch(route('dependentes.update', $dependente), [
                'name' => $dependente->name,
                'email' => $dependente->email,
                'password' => self::SENHA_NOVA,
            ])
            ->assertSessionHasNoErrors());

        $this->assertTrue(Hash::check(self::SENHA_NOVA, $dependente->fresh()->password), 'Pré-condição: a senha deveria ter mudado.');

        $this->assertLoginPendenteDescartadoPelaTrocaDeSenha(
            $this->post(route('two-factor.login'), ['codigo' => $this->codigoAtual($dependente)])
        );
    }

    /**
     * Um caminho que ainda não existe (painel, comando, script de suporte) também conta — e
     * sem carimbar `password_changed_at`. É a prova de que a checagem não depende da data.
     */
    public function test_senha_trocada_direto_no_banco_tambem_derruba_o_login_pendente(): void
    {
        $user = $this->comDoisFatores();
        $this->passarPelaSenha($user);

        User::whereKey($user->id)->update(['password' => Hash::make(self::SENHA_NOVA)]);

        $this->assertNull($user->fresh()->password_changed_at, 'Pré-condição: nenhuma data carimbada.');

        $this->assertLoginPendenteDescartadoPelaTrocaDeSenha(
            $this->post(route('two-factor.login'), ['codigo' => $this->codigoAtual($user)])
        );
    }

    /**
     * "Encerrar outras sessões" regrava o hash com a MESMA senha (`logoutOtherDevices`).
     * O login pendente cai junto — e é o que a ação promete: derrubar todo acesso que não
     * seja o de quem clicou.
     */
    public function test_encerrar_outras_sessoes_tambem_derruba_o_login_pendente(): void
    {
        $user = $this->comDoisFatores();
        $this->passarPelaSenha($user);

        $this->emOutroAparelho($user, fn (TestCase $outro) => $outro
            ->delete(route('settings.sessions.destroy'), ['password' => self::SENHA])
            ->assertSessionHasNoErrors());

        $this->assertTrue(Hash::check(self::SENHA, $user->fresh()->password), 'A senha em si não muda nesta ação.');

        $this->assertLoginPendenteDescartadoPelaTrocaDeSenha(
            $this->post(route('two-factor.login'), ['codigo' => $this->codigoAtual($user)])
        );
    }

    // =========================================================== as duas telas do desafio

    public function test_a_tela_do_codigo_percebe_a_troca_ao_ser_aberta(): void
    {
        $user = $this->comDoisFatores();
        $this->passarPelaSenha($user);

        $this->get(route('two-factor.login'))->assertOk();

        $user->forceFill(['password' => Hash::make(self::SENHA_NOVA)])->save();

        $this->assertLoginPendenteDescartadoPelaTrocaDeSenha($this->get(route('two-factor.login')));

        // E não volta a abrir: a pendência foi apagada, não só recusada uma vez.
        $this->get(route('two-factor.login'))->assertRedirect(route('login'));
    }

    /**
     * Código de recuperação é de uso único e é a volta para casa de quem perdeu o celular.
     * Queimá-lo num login que não pode terminar tiraria uma dessas voltas.
     */
    public function test_codigo_de_recuperacao_nao_entra_nem_e_gasto_depois_da_troca(): void
    {
        $user = $this->comDoisFatores();
        $codigos = $user->two_factor_recovery_codes;

        $this->passarPelaSenha($user);

        $user->forceFill(['password' => Hash::make(self::SENHA_NOVA)])->save();

        $this->assertLoginPendenteDescartadoPelaTrocaDeSenha(
            $this->post(route('two-factor.login'), ['codigo' => $codigos[0], 'recuperacao' => 1])
        );

        $this->assertSame($codigos, $user->fresh()->two_factor_recovery_codes, 'Gastou um código de recuperação num login que não podia terminar.');
    }

    /**
     * O passo do TOTP também não pode ser gasto: senão a pessoa legítima, que acabou de
     * trocar a senha, entraria com a senha nova e veria o código que o celular mostra
     * AGORA ser recusado como "já usado".
     */
    public function test_codigo_do_autenticador_nao_e_gasto_e_vale_no_login_com_a_senha_nova(): void
    {
        $user = $this->comDoisFatores();
        $codigo = $this->codigoAtual($user);

        $this->passarPelaSenha($user);

        $user->forceFill(['password' => Hash::make(self::SENHA_NOVA)])->save();

        $this->assertLoginPendenteDescartadoPelaTrocaDeSenha(
            $this->post(route('two-factor.login'), ['codigo' => $codigo])
        );

        $this->assertNull($user->fresh()->two_factor_last_step, 'O passo do TOTP foi gasto num login recusado.');

        $this->passarPelaSenha($user, self::SENHA_NOVA);

        $this->post(route('two-factor.login'), ['codigo' => $codigo])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    // =========================================================== robustez e controle

    /** A sessão vai para a tabela `sessions` e para os backups: o hash não pode ir junto. */
    public function test_a_sessao_guarda_so_uma_impressao_e_nunca_o_hash_da_senha(): void
    {
        $user = $this->comDoisFatores();
        $this->passarPelaSenha($user);

        $impressao = session('login.senha');

        $this->assertIsString($impressao, 'O login pendente deveria guardar a impressão da senha.');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $impressao);
        $this->assertStringNotContainsString($user->password, serialize(session()->all()), 'O hash da senha foi copiado para a sessão.');
    }

    /**
     * Pendência sem a impressão (ex.: começada antes desta checagem existir) não vale.
     * Aceitá-la deixaria qualquer caminho que esquecesse de gravar a impressão pular a
     * checagem em silêncio — o custo do lado seguro é digitar a senha de novo.
     */
    public function test_login_pendente_sem_impressao_da_senha_nao_vale(): void
    {
        $user = $this->comDoisFatores();

        $this->withSession([
            TwoFactorChallengeController::CHAVE_ID => $user->id,
            'login.remember' => false,
            'login.at' => now()->timestamp,
        ])->post(route('two-factor.login'), ['codigo' => $this->codigoAtual($user)])
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($user->fresh()->two_factor_last_step);
    }

    /**
     * CONTROLE: salvar a conta sem mexer na senha (aqui, trocar o nome em outro aparelho)
     * não derruba a pendência. Prova que o que derruba nos outros testes é a troca de
     * senha — e não a simulação de "outro aparelho" nem um `updated_at` que mudou.
     */
    public function test_mudanca_que_nao_mexe_na_senha_nao_derruba_o_login_pendente(): void
    {
        $user = $this->comDoisFatores();
        $this->passarPelaSenha($user);

        $this->emOutroAparelho($user, fn (TestCase $outro) => $outro
            ->patch(route('profile.update'), ['name' => 'Nome Novo', 'email' => $user->email])
            ->assertSessionHasNoErrors());

        $this->assertSame('Nome Novo', $user->fresh()->name, 'Pré-condição: a conta deveria ter sido salva.');

        $this->post(route('two-factor.login'), ['codigo' => $this->codigoAtual($user)])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }
}
