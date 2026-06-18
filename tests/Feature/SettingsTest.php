<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_defaults_to_security_tab(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/configuracoes')
            ->assertOk()
            ->assertSee('Segurança')
            ->assertSee('Senha'); // form de alterar senha
    }

    public function test_settings_account_tab_shows_delete(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/configuracoes/conta')
            ->assertOk()
            ->assertSee('Excluir conta');
    }

    public function test_invalid_settings_tab_returns_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/configuracoes/inexistente')->assertNotFound();
    }
}
