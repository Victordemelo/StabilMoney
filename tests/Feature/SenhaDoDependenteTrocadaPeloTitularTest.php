<?php

namespace Tests\Feature;

use App\Mail\AlertaDeSeguranca;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A-6 da auditoria de 05/09/2026: o titular trocar a senha do dependente tem de fazer o que
 * toda troca de senha do app já faz.
 *
 * Era o único caminho que não fazia nada: as sessões do dependente sobreviviam (o celular
 * perdido, que costuma ser o motivo da troca, continuava dentro), o cookie de "lembrar de
 * mim" seguia valendo, a idade da senha não mudava e o dependente não ficava sabendo — logo
 * ele, a pessoa cuja senha outra pessoa acabou de definir.
 *
 * O caso negativo importa tanto quanto: editar nome, foto ou parentesco com a senha em
 * branco não pode derrubar sessão nem avisar ninguém.
 *
 * Sessões conferidas pelas linhas de `sessions` (driver `database`, o de produção), como no
 * RedefinirSenhaDerrubaSessoesTest: com `AuthenticateSession` desligado, é apagar a linha que
 * desconecta o navegador.
 */
class SenhaDoDependenteTrocadaPeloTitularTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA_NOVA = 'senha-nova-do-dependente-456';

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
    }

    private function sessao(string $id, ?int $userId): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '203.0.113.7',
            'user_agent' => 'Mozilla/5.0 (Teste)',
            'payload' => '',
            'last_activity' => now()->getTimestamp(),
        ]);
    }

    /** @return array{0: User, 1: User} titular e dependente */
    private function familia(): array
    {
        $titular = User::factory()->create(['name' => 'Victor Titular', 'email' => 'titular@familia.test']);
        $dependente = User::factory()->create([
            'account_owner_id' => $titular->id,
            'name' => 'Maria',
            'email' => 'maria@familia.test',
            'password' => Hash::make('senha-antiga-da-maria'),
            'password_changed_at' => null,
            'remember_token' => 'token-do-celular-perdido',
        ]);

        return [$titular, $dependente];
    }

    /** @param  array<string, mixed>  $campos */
    private function editar(User $titular, User $dependente, array $campos): TestResponse
    {
        return $this->actingAs($titular)->patch(route('dependentes.update', $dependente), [
            '_form' => 'edit-'.$dependente->id,
            'name' => $dependente->name,
            'email' => $dependente->email,
            ...$campos,
        ]);
    }

    public function test_trocar_a_senha_do_dependente_derruba_todas_as_sessoes_dele(): void
    {
        [$titular, $dependente] = $this->familia();
        $outraPessoa = User::factory()->create();

        $this->sessao('celular-perdido-da-maria', $dependente->id);
        $this->sessao('notebook-da-maria', $dependente->id);
        $this->sessao('sessao-do-titular-em-outro-aparelho', $titular->id);
        $this->sessao('sessao-de-outra-familia', $outraPessoa->id);

        $this->editar($titular, $dependente, ['password' => self::SENHA_NOVA])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dependentes'));

        $this->assertSame(0, DB::table('sessions')->where('user_id', $dependente->id)->count());

        // Só as dele: o titular, que fez a troca, e as outras famílias não caem.
        $this->assertDatabaseHas('sessions', ['id' => 'sessao-do-titular-em-outro-aparelho']);
        $this->assertDatabaseHas('sessions', ['id' => 'sessao-de-outra-familia']);
    }

    /**
     * O cookie de "lembrar de mim" re-autentica SEM sessão nenhuma — apagar as linhas de
     * `sessions` não o alcança. Quem o invalida é o `remember_token` novo: o guard só aceita
     * o cookie se o token dele casar com o gravado (`retrieveByToken`).
     */
    public function test_trocar_a_senha_do_dependente_invalida_o_lembrar_de_mim_dele(): void
    {
        [$titular, $dependente] = $this->familia();
        $provider = Auth::guard('web')->getProvider();

        // Controle: antes da troca, o cookie do celular perdido ainda abriria a conta.
        $this->assertNotNull($provider->retrieveByToken($dependente->id, 'token-do-celular-perdido'));

        $this->editar($titular, $dependente, ['password' => self::SENHA_NOVA])->assertSessionHasNoErrors();

        $this->assertNull(
            $provider->retrieveByToken($dependente->id, 'token-do-celular-perdido'),
            'O cookie de "lembrar de mim" emitido antes da troca não pode mais autenticar.',
        );
    }

    /** A aba Segurança do dependente mostra a idade da senha a partir deste carimbo. */
    public function test_trocar_a_senha_do_dependente_grava_password_changed_at(): void
    {
        $this->freezeSecond();
        [$titular, $dependente] = $this->familia();

        $this->editar($titular, $dependente, ['password' => self::SENHA_NOVA])->assertSessionHasNoErrors();

        $dependente->refresh();
        $this->assertTrue(Hash::check(self::SENHA_NOVA, $dependente->password));
        $this->assertSame(now()->toDateTimeString(), $dependente->password_changed_at?->toDateTimeString());
    }

    public function test_o_dependente_e_avisado_por_email_e_a_senha_nao_vai_junto(): void
    {
        Mail::fake();
        [$titular, $dependente] = $this->familia();

        $this->editar($titular, $dependente, ['password' => self::SENHA_NOVA])->assertSessionHasNoErrors();

        Mail::assertSent(AlertaDeSeguranca::class, 1);
        Mail::assertSent(AlertaDeSeguranca::class, function (AlertaDeSeguranca $mail) use ($titular) {
            $html = $mail->render();

            return $mail->hasTo('maria@familia.test')
                // O aviso é para quem teve a senha trocada, não para quem trocou.
                && ! $mail->hasTo($titular->email)
                && str_contains($mail->assunto, 'Victor Titular alterou a senha')
                // 🚨 A senha nunca viaja por e-mail.
                && ! str_contains($html, self::SENHA_NOVA)
                // A saída para ter uma senha que o titular não conhece.
                && str_contains($html, 'Esqueci a senha')
                // IP e aparelho seriam do TITULAR — dado de outra pessoa.
                && ! str_contains($html, '127.0.0.1')
                && ! str_contains($html, 'Endereço IP');
        });
    }

    /**
     * Senha e e-mail trocados no MESMO envio: o aviso vai para o endereço que o dependente
     * tinha. O novo foi o titular que digitou — mandar para lá tornaria o aviso inútil
     * justamente contra quem tomou a conta do titular.
     */
    public function test_senha_e_email_trocados_juntos_o_aviso_vai_para_o_endereco_antigo(): void
    {
        Mail::fake();
        [$titular, $dependente] = $this->familia();

        $this->editar($titular, $dependente, [
            'email' => 'endereco-escolhido@outro.test',
            'password' => self::SENHA_NOVA,
        ])->assertSessionHasNoErrors();

        $this->assertSame('endereco-escolhido@outro.test', $dependente->fresh()->email);

        Mail::assertSent(AlertaDeSeguranca::class, 1);
        Mail::assertSent(AlertaDeSeguranca::class, function (AlertaDeSeguranca $mail) {
            $html = $mail->render();

            return $mail->hasTo('maria@familia.test')
                && ! $mail->hasTo('endereco-escolhido@outro.test')
                // Conta que o e-mail também mudou, mascarado...
                && str_contains($html, 'en***@outro.test')
                && ! str_contains($html, 'endereco-escolhido@outro.test')
                // ...e não manda usar o "Esqueci a senha": o link iria para o endereço novo.
                && ! str_contains($html, 'Esqueci a senha');
        });
    }

    /** Falha de SMTP não pode desfazer a troca nem deixar as sessões de pé. */
    public function test_falha_no_envio_nao_desfaz_a_troca_nem_poupa_as_sessoes(): void
    {
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('SMTP fora do ar'));

        [$titular, $dependente] = $this->familia();
        $this->sessao('celular-perdido-da-maria', $dependente->id);

        $this->editar($titular, $dependente, ['password' => self::SENHA_NOVA])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dependentes'));

        $this->assertTrue(Hash::check(self::SENHA_NOVA, $dependente->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'celular-perdido-da-maria']);
    }

    /** O titular fica sabendo do efeito colateral que acabou de causar. */
    public function test_o_titular_e_avisado_de_que_o_dependente_vai_precisar_entrar_de_novo(): void
    {
        [$titular, $dependente] = $this->familia();

        $this->editar($titular, $dependente, ['password' => self::SENHA_NOVA])
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'Maria vai precisar entrar de novo'));
    }

    // ══════════════════════════════════════════════════ sem trocar a senha

    public function test_editar_o_dependente_sem_trocar_a_senha_nao_derruba_nem_avisa_ninguem(): void
    {
        Mail::fake();
        [$titular, $dependente] = $this->familia();
        $hashAntes = $dependente->password;
        $this->sessao('celular-da-maria', $dependente->id);

        // Senha em branco (o campo existe no formulário e vai vazio) e senha ausente.
        $this->editar($titular, $dependente, ['name' => 'Maria Clara', 'relationship' => 'filho', 'password' => ''])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Dependente atualizado.');
        $this->editar($titular, $dependente->fresh(), ['relationship' => 'conjuge'])
            ->assertSessionHasNoErrors();

        $dependente->refresh();
        $this->assertSame('Maria Clara', $dependente->name);
        $this->assertSame($hashAntes, $dependente->password);
        $this->assertDatabaseHas('sessions', ['id' => 'celular-da-maria']);
        $this->assertSame('token-do-celular-perdido', $dependente->remember_token);
        $this->assertNull($dependente->password_changed_at);
        Mail::assertNothingSent();
    }
}
