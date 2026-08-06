<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\RecoveryCodes;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Excluir a conta com 2FA ligado: senha **e** segundo fator.
 *
 * O buraco que isto fecha: desligar a verificação em duas etapas já exigia o
 * segundo fator, mas apagar a conta inteira — que desliga tudo de uma vez e é
 * IRREVERSÍVEL — pedia só a senha. Quem sequestrasse uma sessão pegaria o
 * caminho mais destrutivo justamente por ser o mais barato.
 *
 * A senha é conferida ANTES do código de propósito: código de recuperação é de
 * uso único, e queimar um deles para depois descobrir que a senha estava errada
 * gastaria uma das poucas voltas para casa de quem perdeu o celular.
 */
class ExclusaoDeContaComDoisFatoresTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'senha-de-teste-1234';

    /** Usuário com o 2FA já ligado (confirmado), pronto para o desafio. */
    private function comDoisFatores(): User
    {
        $user = User::factory()->create(['password' => Hash::make(self::SENHA)]);

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

    private function excluir(User $user, array $dados): TestResponse
    {
        return $this->actingAs($user)->from(route('settings', 'conta'))
            ->delete(route('profile.destroy'), $dados);
    }

    public function test_sem_o_codigo_a_conta_nao_e_apagada(): void
    {
        $user = $this->comDoisFatores();

        $this->excluir($user, ['password' => self::SENHA])
            ->assertSessionHasErrors('codigo', errorBag: 'userDeletion');

        $this->assertModelExists($user);
        $this->assertAuthenticated();
    }

    public function test_codigo_errado_nao_apaga_a_conta(): void
    {
        $user = $this->comDoisFatores();

        $this->excluir($user, ['password' => self::SENHA, 'codigo' => '000000'])
            ->assertSessionHasErrors('codigo', errorBag: 'userDeletion');

        $this->assertModelExists($user);
    }

    public function test_com_senha_e_codigo_certos_a_conta_e_apagada(): void
    {
        $user = $this->comDoisFatores();

        $this->excluir($user, [
            'password' => self::SENHA,
            'codigo' => $this->codigoAtual($user),
        ])->assertRedirect('/');

        $this->assertModelMissing($user);
        $this->assertGuest();
    }

    public function test_o_codigo_de_recuperacao_tambem_serve(): void
    {
        // Perder o celular não pode virar uma conta impossível de apagar — seria
        // brigar com o direito de eliminação que a nossa Política promete.
        $user = $this->comDoisFatores();
        $codigo = $user->two_factor_recovery_codes[0];

        $this->excluir($user, [
            'password' => self::SENHA,
            'codigo' => $codigo,
            'recuperacao' => '1',
        ])->assertRedirect('/');

        $this->assertModelMissing($user);
    }

    public function test_codigo_de_recuperacao_invalido_nao_apaga(): void
    {
        $user = $this->comDoisFatores();

        $this->excluir($user, [
            'password' => self::SENHA,
            'codigo' => 'XXXX-XXXX',
            'recuperacao' => '1',
        ])->assertSessionHasErrors('codigo', errorBag: 'userDeletion');

        $this->assertModelExists($user);
    }

    public function test_codigo_certo_com_senha_errada_nao_queima_o_codigo_de_recuperacao(): void
    {
        $user = $this->comDoisFatores();
        $codigo = $user->two_factor_recovery_codes[0];

        $this->excluir($user, [
            'password' => 'nao-e-a-senha',
            'codigo' => $codigo,
            'recuperacao' => '1',
        ])->assertSessionHasErrors('password', errorBag: 'userDeletion');

        // Continua valendo: a senha errada barrou antes de o código ser gasto.
        $this->assertContains($codigo, $user->fresh()->two_factor_recovery_codes);
    }

    public function test_codigo_ja_gasto_no_login_nao_serve_para_apagar(): void
    {
        // O anti-replay vale para esta rota também: quem espiou a tela por cima do
        // ombro durante um login teria 30 segundos para apagar a conta com o mesmo
        // número. `two_factor_last_step` já preenchido = aquele passo foi gasto.
        $user = $this->comDoisFatores();
        $user->forceFill(['two_factor_last_step' => Totp::passoAtual()])->save();

        $this->excluir($user, [
            'password' => self::SENHA,
            'codigo' => $this->codigoAtual($user),
        ])->assertSessionHasErrors('codigo', errorBag: 'userDeletion');

        $this->assertModelExists($user);
    }

    public function test_quem_nao_ligou_o_2fa_nao_ve_nem_precisa_do_campo(): void
    {
        $user = User::factory()->create(['password' => Hash::make(self::SENHA)]);

        $this->actingAs($user)->get(route('settings', 'conta'))
            ->assertOk()
            ->assertDontSee('Código de 6 dígitos');

        // E o fluxo antigo (só a senha) continua funcionando — o 2FA é opcional.
        $this->excluir($user, ['password' => self::SENHA])->assertRedirect('/');

        $this->assertModelMissing($user);
    }

    public function test_a_tela_avisa_que_o_2fa_sera_apagado_junto(): void
    {
        $user = $this->comDoisFatores();

        $this->actingAs($user)->get(route('settings', 'conta'))
            ->assertOk()
            ->assertSee('Código de 6 dígitos')
            // O aviso existe para quem só queria trocar de aparelho não apagar a
            // conta achando que é o caminho.
            ->assertSee('será apagada junto com a conta', escape: false)
            ->assertSee('código de recuperação');
    }
}
