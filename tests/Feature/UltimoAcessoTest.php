<?php

namespace Tests\Feature;

use App\Http\Middleware\RegistraUltimaVisita;
use App\Models\Atividade;
use App\Models\User;
use App\Services\AdminPanelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Último login e última visita (08/10/2026 — pedido do Victor, "só para entender o fluxo"):
 * dois carimbos na conta. O painel lia o último acesso das SESSÕES, que somem no logout.
 */
class UltimoAcessoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_o_login_com_senha_grava_o_ultimo_login(): void
    {
        Carbon::setTestNow('2026-10-08 10:00:00');
        $user = User::factory()->create(['email' => 'eu@exemplo.test']);

        $this->post(route('login'), ['email' => 'eu@exemplo.test', 'password' => 'password'])->assertRedirect();

        $user->refresh();
        $this->assertSame('2026-10-08 10:00:00', $user->last_login_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-08 10:00:00', $user->last_seen_at->format('Y-m-d H:i:s'));
    }

    public function test_a_visita_grava_no_maximo_uma_vez_por_hora(): void
    {
        Carbon::setTestNow('2026-10-08 10:00:00');
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->assertSame('10:00', $user->fresh()->last_seen_at->format('H:i'));

        Carbon::setTestNow('2026-10-08 10:'.(RegistraUltimaVisita::INTERVALO_EM_MINUTOS - 1).':00');
        $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk();
        $this->assertSame('10:00', $user->fresh()->last_seen_at->format('H:i'), 'Dentro da hora, não regrava.');

        Carbon::setTestNow('2026-10-08 11:05:00');
        $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk();
        $this->assertSame('11:05', $user->fresh()->last_seen_at->format('H:i'));
    }

    public function test_o_carimbo_nao_entra_no_registro_de_atividade_nem_muda_o_updated_at(): void
    {
        Carbon::setTestNow('2026-10-08 10:00:00');
        $user = User::factory()->create(['updated_at' => '2026-01-01 00:00:00']);
        $antes = Atividade::count();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $this->assertSame($antes, Atividade::count());
        $this->assertSame('2026-01-01 00:00:00', $user->fresh()->updated_at->format('Y-m-d H:i:s'));
    }

    public function test_visitante_nao_grava_nada(): void
    {
        $this->get(route('login'))->assertOk();
        $this->assertSame(0, User::whereNotNull('last_seen_at')->count());
    }

    public function test_o_painel_admin_mostra_o_ultimo_acesso_mesmo_sem_sessao_aberta(): void
    {
        Carbon::setTestNow('2026-10-08 10:00:00');
        $user = User::factory()->create(['last_seen_at' => '2026-10-06 09:00:00', 'last_login_at' => '2026-10-05 08:30:00']);

        $acessos = app(AdminPanelService::class)->ultimosAcessos([$user->id]);
        $this->assertSame('2026-10-06 09:00', $acessos[$user->id]->format('Y-m-d H:i'));
    }

    public function test_a_politica_cita_as_datas(): void
    {
        $this->get(route('privacidade'))->assertOk()
            ->assertSee('Data do último login e da última visita ao aplicativo')
            ->assertSee('Versão 3.7');
    }
}
