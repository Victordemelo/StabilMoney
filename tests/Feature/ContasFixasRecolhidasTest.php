<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FixedBill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contas fixas recolhidas em Contas a pagar (out/2026 — pedido do Victor): o bloco abre
 * sozinho só com conta vencida ou vencendo em até 7 dias, e mostra essas primeiro; as outras
 * ficam em "Ver as outras".
 */
class ContasFixasRecolhidasTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(new \DateTimeImmutable('2026-10-08 10:00:00'));
        $this->user = User::factory()->create();
        $this->conta = Account::factory()->for($this->user)->create(['type' => 'checking', 'initial_balance' => 5000]);
    }

    private function fixa(string $nome, int $dia, string $desde = '2026-10-01'): FixedBill
    {
        return FixedBill::factory()->create([
            'user_id' => $this->user->id, 'name' => $nome, 'amount' => 100, 'due_day' => $dia,
            'account_id' => $this->conta->id, 'starts_on' => $desde, 'active' => true,
        ]);
    }

    private function tela(): string
    {
        return $this->actingAs($this->user)->get(route('faturas.index'))->assertOk()->getContent();
    }

    public function test_sem_nada_perto_de_vencer_o_bloco_vem_fechado(): void
    {
        $this->fixa('Aluguel', 28);

        $html = $this->tela();
        $this->assertMatchesRegularExpression('#<details class="card fatura-card span12 fatura-recolhe fixas-recolhe" style="margin-top:18px"\s*>#', $html);
        $this->assertStringContainsString('Nada vencendo nos próximos 7 dias', $html);
        // Sem nada urgente, a lista inteira já vem aberta dentro do bloco.
        $this->assertMatchesRegularExpression('#<details class="fixas-outras" data-fixas-outras\s+open\s*>#', $html);
    }

    public function test_com_conta_vencendo_em_7_dias_abre_e_ela_vem_primeiro(): void
    {
        $this->fixa('Internet', 12);
        $this->fixa('Aluguel', 28);

        $html = $this->tela();
        $this->assertMatchesRegularExpression('#fixas-recolhe" style="margin-top:18px"\s+open\s*>#', $html);
        $this->assertStringContainsString('1 vence nos próximos 7 dias', $html);
        $urgentes = substr($html, strpos($html, 'data-fixas-urgentes'), strpos($html, 'data-fixas-outras') - strpos($html, 'data-fixas-urgentes'));
        $this->assertStringContainsString('<strong>Internet</strong>', $urgentes);
        $this->assertStringNotContainsString('<strong>Aluguel</strong>', $urgentes);
        $this->assertMatchesRegularExpression('#<details class="fixas-outras" data-fixas-outras\s*>#', $html);
        $this->assertStringContainsString('Ver as outras', $html);
    }

    public function test_vencida_tambem_abre(): void
    {
        $this->fixa('Condomínio', 5);

        $html = $this->tela();
        $this->assertMatchesRegularExpression('#fixas-recolhe" style="margin-top:18px"\s+open\s*>#', $html);
        $this->assertStringContainsString('1 vencida', $html);
    }

    public function test_sem_conta_fixa_o_bloco_fica_fechado_e_diz_como_adicionar(): void
    {
        $html = $this->tela();
        $this->assertStringContainsString('Nenhuma cadastrada — abra para adicionar', $html);
        $this->assertStringContainsString('id="novaContaFixaBtn"', $html);
    }
}
