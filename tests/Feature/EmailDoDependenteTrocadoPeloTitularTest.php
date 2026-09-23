<?php

namespace Tests\Feature;

use App\Mail\AlertaDeSeguranca;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * O titular trocar SÓ o e-mail do dependente (sem mexer na senha) tem de avisar o dependente,
 * no endereço que ele tinha.
 *
 * Era o caminho de tomada silenciosa do login do dependente: troca-se o e-mail de acesso
 * para um endereço que se controla, pede-se "Esqueci a senha" nele, e pronto — a senha nova
 * derruba o dependente de todos os aparelhos e ele não recebeu um aviso sequer. Quando a
 * edição trocava também a senha, o `senhaAlteradaPeloTitular` já avisava (A-6); trocando só
 * o e-mail, nada saía.
 *
 * O aviso vai para o endereço ANTIGO (o único que quem fez a troca não controla), com o
 * novo mascarado — o antigo pode já não ser da pessoa, e entregar o endereço novo inteiro a
 * quem lê aquela caixa vazaria o contato atual dela.
 *
 * E nenhum alarme falso: nome, foto e parentesco não avisam ninguém, e senha + e-mail no
 * mesmo envio saem num aviso só.
 */
class EmailDoDependenteTrocadoPeloTitularTest extends TestCase
{
    use RefreshDatabase;

    private const ANTIGO = 'maria@familia.test';

    private const NOVO = 'endereco-escolhido@outro.test';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    /** @return array{0: User, 1: User} titular e dependente */
    private function familia(): array
    {
        $titular = User::factory()->create(['name' => 'Victor Titular', 'email' => 'titular@familia.test']);
        $dependente = User::factory()->create([
            'account_owner_id' => $titular->id,
            'name' => 'Maria',
            'email' => self::ANTIGO,
            'password' => Hash::make('senha-da-maria-123'),
            'remember_token' => 'token-do-celular-da-maria',
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

    public function test_trocar_so_o_email_avisa_o_dependente_no_endereco_antigo(): void
    {
        [$titular, $dependente] = $this->familia();

        $this->editar($titular, $dependente, ['email' => self::NOVO, 'password' => ''])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dependentes'))
            ->assertSessionHas('status', 'Dependente atualizado.');

        $this->assertSame(self::NOVO, $dependente->fresh()->email);

        Mail::assertSent(AlertaDeSeguranca::class, 1);
        Mail::assertSent(AlertaDeSeguranca::class, function (AlertaDeSeguranca $mail) use ($titular) {
            $html = $mail->render();

            return $mail->hasTo(self::ANTIGO)
                // Nem o endereço novo (quem o escolheu o controla) nem o titular (foi ele
                // quem agiu) — o aviso é para quem teve o login trocado.
                && ! $mail->hasTo(self::NOVO)
                && ! $mail->hasTo($titular->email)
                // Quem fez.
                && str_contains($mail->assunto, 'Victor Titular trocou o e-mail')
                && str_contains($html, 'Victor Titular')
                // Para qual endereço — mascarado.
                && str_contains($html, 'en***@outro.test')
                && ! str_contains($html, self::NOVO)
                // O que fazer se não foi combinado. O "Esqueci a senha" não serve: com este
                // endereço ele já não acha a conta.
                && str_contains($html, 'Não combinou essa troca?')
                && str_contains($html, (string) config('legal.contact_email'))
                && ! str_contains($html, 'Esqueci a senha')
                // IP e aparelho seriam do TITULAR — dado de outra pessoa.
                && ! str_contains($html, 'Endereço IP');
        });
    }

    /** Trocar o e-mail não é trocar a senha: nada do que a troca de senha faz acontece aqui. */
    public function test_trocar_so_o_email_nao_mexe_na_senha_nem_no_lembrar_de_mim(): void
    {
        [$titular, $dependente] = $this->familia();
        $hashAntes = $dependente->password;

        $this->editar($titular, $dependente, ['email' => self::NOVO])->assertSessionHasNoErrors();

        $dependente->refresh();
        $this->assertSame($hashAntes, $dependente->password);
        $this->assertSame('token-do-celular-da-maria', $dependente->remember_token);
        $this->assertNull($dependente->password_changed_at);
    }

    public function test_nome_foto_e_parentesco_nao_avisam_ninguem(): void
    {
        Storage::fake(User::AVATAR_DISK);
        [$titular, $dependente] = $this->familia();

        $this->editar($titular, $dependente, [
            'name' => 'Maria Clara',
            'relationship' => 'filho',
            'avatar' => UploadedFile::fake()->create('maria.jpg', 100, 'image/jpeg'),
            'password' => '',
        ])->assertSessionHasNoErrors();

        $dependente->refresh();
        $this->assertSame('Maria Clara', $dependente->name);
        $this->assertNotNull($dependente->avatar_path);
        $this->assertSame(self::ANTIGO, $dependente->email);
        Mail::assertNothingSent();
    }

    /**
     * Senha e e-mail no MESMO envio: um aviso só, o de senha — que já conta do e-mail novo
     * (SenhaDoDependenteTrocadaPeloTitularTest confere o texto). Dois e-mails do mesmo
     * clique confundiriam mais do que avisariam.
     */
    public function test_senha_e_email_juntos_saem_num_aviso_so(): void
    {
        [$titular, $dependente] = $this->familia();

        $this->editar($titular, $dependente, [
            'email' => self::NOVO,
            'password' => 'senha-nova-do-dependente-456',
        ])->assertSessionHasNoErrors();

        Mail::assertSent(AlertaDeSeguranca::class, 1);
        Mail::assertSent(AlertaDeSeguranca::class, fn (AlertaDeSeguranca $mail) => $mail->hasTo(self::ANTIGO)
            && str_contains($mail->assunto, 'alterou a senha')
            && str_contains($mail->render(), 'en***@outro.test'));
    }
}
