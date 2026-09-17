<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `PATCH /meu-perfil` tem limite de tentativas (17/09/2026).
 *
 * Trocar o e-mail exige a SENHA ATUAL. Sem teto, a rota era um oráculo de força bruta
 * para quem tivesse uma sessão sequestrada: 12 senhas erradas seguidas passavam sem
 * nenhum 429 — e cada tentativa custa um argon2id de 64 MiB no servidor. A regra do
 * projeto é que toda rota que confere senha leva `throttle:senha` (6 por minuto por
 * usuário); esta tinha ficado de fora.
 */
class PerfilComLimiteDeTentativasTest extends TestCase
{
    use RefreshDatabase;

    private function trocarEmailComSenhaErrada(User $user): TestResponse
    {
        return $this->actingAs($user)->from(route('profile.edit'))->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'outro-endereco@example.com',
            'current_password' => 'nao-e-a-senha',
        ]);
    }

    public function test_senha_errada_repetida_esbarra_no_limite(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-senha-certa-123')]);

        for ($i = 1; $i <= 6; $i++) {
            $this->trocarEmailComSenhaErrada($user)
                ->assertSessionHasErrors('current_password');
        }

        // A 7ª tentativa no mesmo minuto nem chega a conferir a senha.
        $this->trocarEmailComSenhaErrada($user)->assertStatus(429);

        $this->assertNotSame('outro-endereco@example.com', $user->fresh()->email);
    }

    public function test_a_cota_e_por_usuario_e_nao_trava_os_outros(): void
    {
        $atacado = User::factory()->create();
        $vizinho = User::factory()->create();

        for ($i = 1; $i <= 7; $i++) {
            $this->trocarEmailComSenhaErrada($atacado);
        }

        $this->actingAs($vizinho)->from(route('profile.edit'))->patch(route('profile.update'), [
            'name' => 'Nome Novo',
            'email' => $vizinho->email,
        ])->assertRedirect();

        $this->assertSame('Nome Novo', $vizinho->fresh()->name);
    }

    public function test_salvar_o_perfil_normalmente_continua_funcionando(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->from(route('profile.edit'))->patch(route('profile.update'), [
            'name' => 'Victor de Melo',
            'email' => $user->email,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('Victor de Melo', $user->fresh()->name);
    }
}
