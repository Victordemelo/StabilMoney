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

    public function test_security_tab_shows_sessions_and_2fa_sections(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/configuracoes')
            ->assertOk()
            ->assertSee('Sessões ativas')
            ->assertSee('Encerrar outras sessões')
            ->assertSee('Verificação em duas etapas');
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
