<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quem JÁ tinha se cadastrado fica liberado da confirmação do e-mail (08/10/2026 — decisão do
 * Victor); só os cadastros novos seguem a regra. A migration roda uma vez, no deploy.
 */
class QuemJaSeCadastrouFicaLiberadoTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_08_000000_libera_quem_ja_se_cadastrou.php');
    }

    public function test_conta_sem_confirmacao_passa_a_entrar_no_painel(): void
    {
        $pendente = User::factory()->create(['email_verified_at' => null, 'created_at' => '2026-10-08 07:52:00']);
        $confirmada = User::factory()->create(['email_verified_at' => '2026-09-01 10:00:00']);

        $this->actingAs($pendente)->get(route('dashboard'))->assertRedirect(route('verification.notice'));

        $this->migration()->up();

        $this->assertSame('2026-10-08 07:52:00', $pendente->fresh()->email_verified_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-01 10:00:00', $confirmada->fresh()->email_verified_at->format('Y-m-d H:i:s'));
        $this->actingAs($pendente->fresh())->get(route('dashboard'))->assertOk();
    }

    public function test_rodar_de_novo_e_desfazer_nao_mudam_nada(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $this->migration()->up();
        $marcada = $user->fresh()->email_verified_at;

        $this->migration()->up();
        $this->migration()->down();

        $this->assertEquals($marcada, $user->fresh()->email_verified_at);
    }
}
