<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_tab_shows_password_and_sessions(): void
    {
        $user = User::factory()->create();

        // O 2FA saiu daqui em 06/08/2026: com senha + sessões + 2FA no mesmo lugar,
        // a aba passava de duas telas de rolagem. Agora tem aba própria (abaixo).
        $this->actingAs($user)->get('/configuracoes')
            ->assertOk()
            ->assertSee('Senha')
            ->assertSee('Sessões ativas')
            ->assertSee('Encerrar outras sessões')
            ->assertDontSee('Verificação em duas etapas');
    }

    public function test_2fa_tem_aba_propria(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/configuracoes/2fa')
            ->assertOk()
            ->assertSee('Verificação em duas etapas')
            ->assertSee('Ativar verificação em duas etapas')
            // Card lateral explicativo: o que é e como se recupera.
            ->assertSee('Como funciona')
            ->assertSee('Códigos de recuperação');
    }

    public function test_as_tres_abas_aparecem_e_a_atual_fica_marcada(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get('/configuracoes/2fa')->assertOk()->getContent();

        // Rótulo curto: "2FA" é mais reconhecível que o nome por extenso na pílula.
        $this->assertStringContainsString('>2FA</a>', $html);
        $this->assertStringContainsString('>Segurança</a>', $html);
        $this->assertStringContainsString('>Conta</a>', $html);
        $this->assertMatchesRegularExpression('/class="settings-tab active"[^>]*>\s*2FA/u', $html);
    }

    public function test_aba_conta_mostra_o_resumo_ao_lado_da_zona_de_perigo(): void
    {
        $user = User::factory()->create(['email' => 'dono@example.com']);

        $this->actingAs($user)->get('/configuracoes/conta')
            ->assertOk()
            ->assertSee('Sua conta')
            ->assertSee('dono@example.com')
            ->assertSee('Nenhum dependente')
            // A zona de perigo continua lá, agora com companhia.
            ->assertSee('Excluir conta');
    }

    public function test_changing_password_stamps_password_changed_at(): void
    {
        $user = User::factory()->create(['password_changed_at' => null]);

        $this->actingAs($user)->from('/configuracoes')->put('/password', [
            'current_password' => 'password',
            'password' => 'nova-senha-bem-forte-123',
            'password_confirmation' => 'nova-senha-bem-forte-123',
        ])->assertRedirect();

        $this->assertNotNull($user->fresh()->password_changed_at);
        $this->assertTrue(Hash::check('nova-senha-bem-forte-123', $user->fresh()->password));
    }

    public function test_destroying_other_sessions_requires_correct_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->from('/configuracoes')
            ->delete(route('settings.sessions.destroy'), ['password' => 'senha-errada'])
            ->assertRedirect('/configuracoes')
            ->assertSessionHasErrors('password', errorBag: 'logoutOtherSessions');
    }

    public function test_destroying_other_sessions_removes_other_rows_but_keeps_current(): void
    {
        config(['session.driver' => 'database']);

        $user = User::factory()->create();
        $other = User::factory()->create();

        // Duas sessões antigas do usuário (ids diferentes da sessão da requisição,
        // então ambas devem cair) + uma sessão de outra pessoa (intocável).
        DB::table('sessions')->insert([
            ['id' => 'device-a', 'user_id' => $user->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'a', 'payload' => 'x', 'last_activity' => time()],
            ['id' => 'device-b', 'user_id' => $user->id, 'ip_address' => '10.0.0.2', 'user_agent' => 'b', 'payload' => 'x', 'last_activity' => time()],
            ['id' => 'device-c', 'user_id' => $other->id, 'ip_address' => '8.8.8.8', 'user_agent' => 'c', 'payload' => 'x', 'last_activity' => time()],
        ]);

        $this->actingAs($user)
            ->delete(route('settings.sessions.destroy'), ['password' => 'password'])
            ->assertRedirect();

        // As outras sessões do próprio usuário saem...
        $this->assertDatabaseMissing('sessions', ['id' => 'device-a']);
        $this->assertDatabaseMissing('sessions', ['id' => 'device-b']);
        // ...mas a de outra pessoa nunca é tocada.
        $this->assertDatabaseHas('sessions', ['id' => 'device-c']);
    }
}
