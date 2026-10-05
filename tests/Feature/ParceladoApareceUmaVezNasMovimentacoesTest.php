<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Compra parcelada aparece UMA vez nas Movimentações (out/2026), com o valor da compra inteira
 * e "em 12x" — como nas recentes do painel. Antes uma compra em 12x virava doze linhas
 * "teste victor − R$ 16,66" e enchia páginas do histórico. As parcelas, uma a uma, seguem na
 * fatura do cartão e no "Detalhes" da edição.
 */
class ParceladoApareceUmaVezNasMovimentacoesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $cartao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(new \DateTimeImmutable('2026-10-04 10:00:00'));
        $this->user = User::factory()->create();
        $this->cartao = Account::factory()->for($this->user)->creditCard()->create(['name' => 'Roxinho']);
    }

    private function parcelar(string $descricao, string $valor, int $vezes): void
    {
        $this->actingAs($this->user)->post(route('faturas.lancar'), [
            'description' => $descricao, 'amount' => $valor, 'date' => '2026-10-04',
            'account_id' => $this->cartao->id, 'mode' => 'parcelado', 'installments' => $vezes,
        ])->assertSessionHasNoErrors();
    }

    private function linhas(array $filtros = []): int
    {
        return substr_count($this->actingAs($this->user)->get(route('transactions.index', $filtros))->assertOk()->getContent(), 'class="tx" href=');
    }

    public function test_a_compra_parcelada_e_uma_linha_com_o_total_e_em_quantas_vezes(): void
    {
        $this->parcelar('Geladeira', '200,00', 12);
        $this->assertSame(12, Transaction::where('description', 'Geladeira')->count(), 'Pré-condição: 12 parcelas gravadas.');

        $html = $this->actingAs($this->user)->get(route('transactions.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'Geladeira'));
        $this->assertStringContainsString('<span class="tx-tag">em 12x</span>', $html);
        $this->assertStringContainsString('− R$ 200,00', $html);
        $this->assertStringNotContainsString('R$ 16,66', $html);
        // A linha leva à edição da 1ª parcela, cujo "Detalhes" lista as doze.
        $primeira = Transaction::where('description', 'Geladeira')->where('installment_no', 1)->sole();
        $this->assertStringContainsString(route('transactions.edit', $primeira), $html);
    }

    public function test_os_filtros_e_a_paginacao_contam_compras_e_nao_parcelas(): void
    {
        foreach (range(1, 16) as $n) {
            $this->parcelar("Compra {$n}", '120,00', 12);
        }

        // 16 compras × 12 parcelas = 192 linhas no banco; 15 compras na 1ª página, 1 na 2ª.
        $this->assertSame(15, $this->linhas());
        $this->assertSame(1, $this->linhas(['page' => 2]));
        $this->assertSame(15, $this->linhas(['type' => 'expense', 'account' => $this->cartao->id]));
    }

    public function test_compra_sem_a_primeira_parcela_continua_no_historico(): void
    {
        $this->parcelar('Sofá', '300,00', 3);
        Transaction::where('description', 'Sofá')->where('installment_no', 1)->delete();

        $html = $this->actingAs($this->user)->get(route('transactions.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'Sofá'));
        $this->assertStringContainsString('− R$ 200,00', $html, 'O total é a soma das parcelas que existem.');
    }

    public function test_recorrencia_e_compra_a_vista_continuam_linha_a_linha(): void
    {
        $conta = Account::factory()->for($this->user)->create(['type' => 'checking', 'initial_balance' => 1000]);
        Transaction::factory()->for($this->user)->expense()->create([
            'account_id' => $conta->id, 'amount' => 42.5, 'description' => 'Padaria', 'date' => '2026-10-03',
        ]);
        $grupo = (string) Str::uuid();
        foreach (['2026-08-04', '2026-09-04', '2026-10-04'] as $data) {
            Transaction::factory()->for($this->user)->expense()->create([
                'account_id' => $this->cartao->id, 'amount' => 39.9, 'description' => 'Streaming',
                'date' => $data, 'group_id' => $grupo, 'recurring' => true,
            ]);
        }

        $html = $this->actingAs($this->user)->get(route('transactions.index'))->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, 'Streaming'));
        $this->assertStringContainsString('− R$ 42,50', $html);
        $this->assertStringNotContainsString('<span class="tx-tag">em', $html);
    }
}
