<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Mailer;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Verificação de e-mail PRONTA PARA LIGAR + IP do aceite em repouso.
 *
 * O `User` passou a implementar `MustVerifyEmail`, mas o app ainda não entrega e-mail
 * (`MAIL_MAILER=log`). O risco central desta mudança é trancar gente fora do próprio
 * app: quem exigir confirmação sem conseguir enviá-la trancou todo mundo, inclusive
 * quem já estava dentro. Por isso os testes daqui são, antes de tudo, testes de
 * NÃO-TRANCAMENTO — usuário novo, usuário antigo e dependente têm de continuar entrando.
 *
 * A regra: quem cria usuário decide o `email_verified_at`.
 *   - sem mailer  → nasce verificado (não há o que confirmar);
 *   - com mailer  → nasce NÃO verificado e recebe o link (só o usuário novo);
 *   - dependente  → nasce verificado SEMPRE (ninguém lhe manda link nenhum).
 */
class VerificacaoDeEmailTest extends TestCase
{
    use RefreshDatabase;

    /** Simula a fase de testes de hoje: o app "envia" para o log, ninguém recebe nada. */
    private function semMailer(): void
    {
        config()->set('mail.default', 'log');

        $this->assertFalse(Mailer::entrega(), 'Cenário inválido: o mailer deveria estar sem entrega.');
    }

    /** Simula o dia em que o SMTP entra no `.env`. */
    private function comMailer(): void
    {
        config()->set('mail.default', 'smtp');

        $this->assertTrue(Mailer::entrega(), 'Cenário inválido: o mailer deveria entregar.');
    }

    private function cadastrar(string $email = 'novo@example.com'): User
    {
        $this->post('/register', [
            'name' => 'Usuário Novo',
            'email' => $email,
            'password' => 'senha-bem-comprida-123',
            'terms' => '1',
        ])->assertRedirect(route('dashboard', absolute: false));

        return User::where('email', $email)->firstOrFail();
    }

    private function criarDependente(User $titular, string $email = 'dep@familia.test'): User
    {
        $this->actingAs($titular)->post('/dependentes', [
            'name' => 'Dependente',
            'email' => $email,
            'password' => 'senha-bem-comprida-123',
        ])->assertRedirect();

        return User::where('email', $email)->firstOrFail();
    }

    /**
     * O contrato precisa estar implementado, senão as rotas `verification.*` e o
     * middleware `verified` nunca passam a valer — é o que torna a coisa "ligável".
     */
    public function test_o_model_implementa_o_contrato_de_verificacao(): void
    {
        $this->assertInstanceOf(MustVerifyEmail::class, new User);
    }

    // ---------------------------------------------------------------- sem mailer

    public function test_sem_mailer_usuario_novo_nasce_verificado_e_entra_no_app(): void
    {
        $this->semMailer();
        Notification::fake();

        $user = $this->cadastrar();

        $this->assertNotNull($user->email_verified_at, 'Sem mailer, ninguém pode nascer trancado.');
        $this->assertTrue($user->hasVerifiedEmail());

        // Nenhum link de confirmação foi disparado — não haveria para onde enviar.
        Notification::assertNothingSent();

        // E o app abre normalmente.
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    public function test_sem_mailer_dependente_criado_pelo_titular_entra_no_app(): void
    {
        $this->semMailer();
        Notification::fake();

        $titular = User::factory()->create();
        $dependente = $this->criarDependente($titular);

        $this->assertNotNull($dependente->email_verified_at);
        $this->assertSame($titular->id, $dependente->account_owner_id);

        $this->actingAs($dependente)->get(route('dashboard'))->assertOk();
    }

    /**
     * O middleware `verified` ENTROU em 06/08/2026 (`routes/web.php`), e este teste mudou
     * de forma junto — mas não de intenção.
     *
     * Antes ele dizia "usuário sem verificação continua entrando, porque nenhuma rota
     * exige `verified`", e avisava: *se este teste quebrar, alguém ligou o middleware sem
     * antes preencher o campo dos usuários existentes*. Foi exatamente o que aconteceu, e
     * o aviso foi cumprido em vez de contornado: a migration
     * `2026_08_06_000000_backfill_email_verified_at_antes_do_middleware` roda no MESMO
     * deploy que liga o middleware e não deixa ninguém para trás.
     *
     * O que se garante agora, e que continua sendo a mesma coisa que importa: **nenhum
     * caminho do app produz um usuário trancado quando não há como enviar e-mail.**
     */
    public function test_sem_mailer_nenhum_caminho_do_app_deixa_usuario_por_confirmar(): void
    {
        $this->semMailer();

        $titular = $this->cadastrar('titular@example.com');
        $dependente = $this->criarDependente($titular);

        // Cadastro e criação de dependente: os dois nascem com a data preenchida...
        $this->assertNotNull($titular->email_verified_at);
        $this->assertNotNull($dependente->email_verified_at);

        // ...e trocar o e-mail no perfil TAMBÉM não pode devolver alguém ao limbo.
        // Este era o furo: o ProfileController zerava a coluna, e sem mailer não existe
        // link capaz de destravar — o middleware transformaria isso em conta perdida.
        $this->actingAs($titular)->patch(route('profile.update'), [
            'name' => $titular->name,
            'email' => 'outro@example.com',
            'current_password' => 'senha-bem-comprida-123',
        ])->assertRedirect();

        $titular->refresh();

        $this->assertSame('outro@example.com', $titular->email);
        $this->assertNotNull(
            $titular->email_verified_at,
            'Trocar o e-mail sem mailer deixou a conta por confirmar — com `verified` ligado, isso é conta trancada sem saída.'
        );

        // E, na prática: os dois entram no app.
        $this->actingAs($titular)->get(route('dashboard'))->assertOk();
        $this->actingAs($dependente)->get(route('dashboard'))->assertOk();
    }

    /**
     * O outro lado da moeda: com o middleware no ar, quem de fato ainda não confirmou é
     * mandado para a tela de confirmação — e não entra no app. É este barramento que
     * fecha o buraco do item 13: cadastrar-se com o e-mail de outra pessoa deixa de dar
     * acesso ao app enquanto o dono do endereço não clicar no link.
     */
    public function test_com_mailer_quem_nao_confirmou_e_barrado_nas_rotas_do_app(): void
    {
        $this->comMailer();

        $porConfirmar = User::factory()->unverified()->create();

        $this->actingAs($porConfirmar)->get(route('dashboard'))
            ->assertRedirect(route('verification.notice'));

        // A saída existe e fica FORA do grupo protegido — senão a tela que destrava a
        // conta estaria ela própria trancada.
        $this->actingAs($porConfirmar)->get(route('verification.notice'))->assertOk();

        // E depois de confirmar, entra.
        $porConfirmar->markEmailAsVerified();

        $this->actingAs($porConfirmar->fresh())->get(route('dashboard'))->assertOk();
    }

    // ---------------------------------------------------------------- com mailer

    public function test_com_mailer_usuario_novo_nasce_nao_verificado_e_recebe_o_link(): void
    {
        $this->comMailer();
        Notification::fake();

        $user = $this->cadastrar();

        $this->assertNull($user->email_verified_at, 'Com mailer, o usuário novo confirma o e-mail.');
        $this->assertFalse($user->hasVerifiedEmail());

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    /**
     * Dependente nasce verificado mesmo com SMTP no ar: ele não passa pelo `/register`,
     * não dispara `Registered` e portanto não recebe link nenhum. Se ficasse nulo aqui,
     * ligar o mailer trancaria todo dependente futuro fora do app.
     */
    public function test_com_mailer_dependente_continua_nascendo_verificado(): void
    {
        $this->comMailer();
        Notification::fake();

        $titular = User::factory()->create();
        $dependente = $this->criarDependente($titular);

        $this->assertNotNull($dependente->email_verified_at);
        Notification::assertNotSentTo($dependente, VerifyEmail::class);

        $this->actingAs($dependente)->get(route('dashboard'))->assertOk();
    }

    /**
     * O fluxo existente continua funcionando de ponta a ponta: quem nasceu sem
     * verificação (com mailer) consegue confirmar pelo link assinado.
     */
    public function test_usuario_nao_verificado_consegue_confirmar_pelo_link(): void
    {
        $this->comMailer();

        $user = User::factory()->unverified()->create();

        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $this->actingAs($user)->get($url);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    // ------------------------------------------------- IP do aceite (LGPD art. 8º)

    /**
     * O IP do aceite é PROVA do consentimento: precisa voltar legível pelo model.
     * Por isso ele nunca pode virar hash — só cifra reversível. Esta invariante vale
     * hoje (texto puro) e tem de continuar valendo depois do cast `encrypted`.
     */
    public function test_ip_do_aceite_volta_legivel_pelo_model(): void
    {
        $user = $this->cadastrar();

        $this->assertSame('127.0.0.1', $user->terms_accepted_ip);
    }

    /**
     * O IP não pode ficar em texto puro no banco (dado pessoal em repouso).
     *
     * BLOQUEADO POR MIGRATION: o cast `encrypted` produz de 200 (IPv4) a 256 (IPv6
     * completo) caracteres, e `users.terms_accepted_ip` é `varchar(45)`. Em MySQL
     * (STRICT_TRANS_TABLES) gravar isso é erro 1406 — o cadastro quebraria. A coluna
     * precisa virar `text` ANTES de o cast entrar; em sqlite o limite não é aplicado,
     * então a suíte sozinha não pegaria a regressão.
     *
     * O teste se liga sozinho no dia em que o cast for aplicado.
     */
    public function test_ip_do_aceite_fica_cifrado_em_repouso(): void
    {
        if (((new User)->getCasts()['terms_accepted_ip'] ?? null) !== 'encrypted') {
            $this->markTestSkipped(
                'Cast `encrypted` ainda não aplicado em terms_accepted_ip: depende da migration '
                .'que alarga a coluna de varchar(45) para text (o cifrado ocupa 200 a 256 chars).'
            );
        }

        $user = $this->cadastrar();

        $bruto = DB::table('users')->where('id', $user->id)->value('terms_accepted_ip');

        $this->assertNotSame('127.0.0.1', $bruto, 'O IP não pode ficar em texto puro no banco.');
        $this->assertGreaterThan(45, strlen((string) $bruto), 'Cifrado não cabe em varchar(45).');
        $this->assertSame('127.0.0.1', $user->fresh()->terms_accepted_ip, 'A prova precisa voltar legível.');
    }
}
