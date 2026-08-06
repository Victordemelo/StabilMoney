<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `php artisan sessoes:limpar` — retenção da tabela `sessions`.
 *
 * A tabela guarda **IP e user-agent** de cada login: é dado pessoal, e dado
 * pessoal tem prazo (LGPD). O Laravel 12 **não tem** `session:prune` (só
 * `make:session-table`); a limpeza é o `gc()` do handler, chamado por LOTERIA
 * pelo middleware `StartSession` — `session.lottery` é `[2, 100]`, ou seja 2%
 * das requisições. Num app de pouco tráfego a tabela acumula por meses.
 *
 * Daí o comando explícito, agendado para 03:10.
 */
class LimpezaDeSessoesTest extends TestCase
{
    use RefreshDatabase;

    /** Grava uma linha na tabela `sessions` com a última atividade em $diasAtras. */
    private function sessao(string $id, int $diasAtras, ?int $userId = null): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '203.0.113.10',
            'user_agent' => 'Mozilla/5.0 (Teste)',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->subDays($diasAtras)->getTimestamp(),
        ]);
    }

    public function test_apaga_as_expiradas_e_mantem_as_ativas(): void
    {
        config()->set('session.driver', 'database');
        // lifetime em MINUTOS: 120 = 2 horas. Uma sessão de ontem já expirou.
        config()->set('session.lifetime', 120);

        $user = User::factory()->create();

        $this->sessao('sessao-de-hoje', 0, $user->id);
        $this->sessao('sessao-de-ontem', 1, $user->id);
        $this->sessao('sessao-antiga', 40, $user->id);

        $this->assertSame(3, DB::table('sessions')->count());

        $this->artisan('sessoes:limpar')->assertSuccessful();

        // A de hoje fica: ninguém é desconectado por causa da faxina.
        $this->assertSame(1, DB::table('sessions')->count());
        $this->assertNotNull(DB::table('sessions')->find('sessao-de-hoje'));
        $this->assertNull(DB::table('sessions')->find('sessao-antiga'));
    }

    public function test_o_ip_e_o_user_agent_somem_junto_com_a_sessao(): void
    {
        config()->set('session.driver', 'database');
        config()->set('session.lifetime', 120);

        $this->sessao('sessao-velha', 200);

        $this->artisan('sessoes:limpar')->assertSuccessful();

        // É este o ponto do comando: o dado pessoal não fica parado para sempre.
        $this->assertSame(0, DB::table('sessions')->where('ip_address', '203.0.113.10')->count());
    }

    public function test_relata_quantas_removeu(): void
    {
        config()->set('session.driver', 'database');
        config()->set('session.lifetime', 120);

        $this->sessao('a', 30);
        $this->sessao('b', 30);
        $this->sessao('c', 0);

        $this->artisan('sessoes:limpar')
            ->expectsOutputToContain('Sessões: 3 → 1 (2 expiradas removidas).')
            ->assertSuccessful();
    }

    public function test_sem_nada_a_limpar_nao_quebra(): void
    {
        config()->set('session.driver', 'database');
        config()->set('session.lifetime', 120);

        $this->artisan('sessoes:limpar')->assertSuccessful();

        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_driver_que_nao_e_banco_nao_quebra(): void
    {
        // Em `array`/`file` não há tabela para contar — o comando precisa sair
        // limpo mesmo assim, senão o agendamento vira erro diário no log.
        config()->set('session.driver', 'array');

        $this->artisan('sessoes:limpar')->assertSuccessful();
    }

    public function test_o_comando_esta_agendado(): void
    {
        $agendados = collect(app(Schedule::class)->events())
            ->map(fn ($e) => $e->command ?? '')
            ->filter(fn ($c) => str_contains($c, 'sessoes:limpar'));

        $this->assertNotEmpty($agendados, 'sessoes:limpar precisa estar no agendador — senão nada limpa sozinho.');
    }
}
