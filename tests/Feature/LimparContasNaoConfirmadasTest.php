<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * `contas:limpar-nao-confirmadas` (08/10/2026 — decisão do Victor): a conta de titular que nunca
 * confirmou o e-mail sai 30 dias depois do cadastro, pelo mesmo caminho da exclusão de conta.
 * Sem lembrete por e-mail, de propósito (ver o comando em routes/console.php).
 */
class LimparContasNaoConfirmadasTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function conta(string $email, ?string $confirmadaEm, string $criadaEm, array $extra = []): User
    {
        return User::factory()->create(['email' => $email, 'email_verified_at' => $confirmadaEm, 'created_at' => $criadaEm] + $extra);
    }

    public function test_so_sai_quem_nunca_confirmou_ha_mais_de_30_dias(): void
    {
        Carbon::setTestNow('2026-12-01 03:30:00');
        $velha = $this->conta('velha@exemplo.test', null, '2026-10-31 10:00:00');
        $nova = $this->conta('nova@exemplo.test', null, '2026-11-02 10:00:00');
        $confirmada = $this->conta('ok@exemplo.test', '2026-01-02 10:00:00', '2026-01-01 10:00:00');

        $this->artisan('contas:limpar-nao-confirmadas')
            ->expectsOutputToContain('Contas nunca confirmadas há mais de 30 dias: 1 excluída.')
            ->assertSuccessful();

        $this->assertNull($velha->fresh());
        $this->assertNotNull($nova->fresh());
        $this->assertNotNull($confirmada->fresh());
        // O e-mail volta a ficar livre: quem é dono dele consegue se cadastrar.
        $this->post(route('register'), [
            'name' => 'Dona do e-mail', 'email' => 'velha@exemplo.test', 'password' => 'Senha-Forte-2026', 'terms' => '1',
        ])->assertSessionHasNoErrors();
    }

    public function test_conta_com_conta_de_banco_ou_dependente_nao_sai_por_aqui(): void
    {
        Carbon::setTestNow('2026-12-01 03:30:00');
        $comConta = $this->conta('banco@exemplo.test', null, '2026-01-01 10:00:00');
        Account::factory()->for($comConta)->create(['type' => 'checking', 'initial_balance' => 10]);
        $titular = $this->conta('titular@exemplo.test', '2026-01-01 10:00:00', '2026-01-01 10:00:00');
        $dependente = $this->conta('dep@exemplo.test', null, '2026-01-01 10:00:00', ['account_owner_id' => $titular->id, 'is_admin' => false]);

        $this->artisan('contas:limpar-nao-confirmadas')->assertSuccessful();

        $this->assertNotNull($comConta->fresh());
        $this->assertNotNull($dependente->fresh());
    }

    public function test_dry_run_so_lista(): void
    {
        Carbon::setTestNow('2026-12-01 03:30:00');
        $velha = $this->conta('velha@exemplo.test', null, '2026-10-01 10:00:00');

        $this->artisan('contas:limpar-nao-confirmadas', ['--dry-run' => true])
            ->expectsOutputToContain('1 conta(s) seriam excluídas.')
            ->assertSuccessful();
        $this->assertNotNull($velha->fresh());
    }

    public function test_roda_toda_madrugada(): void
    {
        $evento = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'contas:limpar-nao-confirmadas'));

        $this->assertNotNull($evento, 'A limpeza não está agendada.');
        $this->assertSame('30 3 * * *', $evento->expression);
    }

    public function test_a_politica_diz_o_prazo(): void
    {
        $this->get(route('privacidade'))->assertOk()
            ->assertSee('Conta cadastrada que nunca teve o e-mail confirmado')
            ->assertSee('<strong>30 dias</strong>', false);
    }
}
