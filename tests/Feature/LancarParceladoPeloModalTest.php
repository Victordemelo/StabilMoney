<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Parcelar pelo modal "Lançar" (out/2026 — pedido do Victor: escolhia o cartão de crédito e
 * só dava para lançar em 1x). `POST /transactions` com `installments` 2..24 grava uma linha
 * por ciclo de fatura, pelas MESMAS regras do "Lançar despesa" de Contas a pagar
 * (`App\Support\Parcelamento`). Parcelar só vale em despesa no cartão de crédito.
 */
class LancarParceladoPeloModalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $cartao;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-01-15');
        $this->user = User::factory()->create();
        $this->cartao = Account::factory()->for($this->user)->creditCard()->create(['credit_limit' => 10000]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lancar(array $dados)
    {
        return $this->actingAs($this->user)->postJson(route('transactions.store'), $dados + [
            'type' => 'expense', 'amount' => '500,00', 'account_id' => $this->cartao->id,
            'date' => '2027-01-15', 'description' => 'Geladeira',
        ]);
    }

    public function test_despesa_no_cartao_em_12x_grava_doze_parcelas_uma_por_ciclo(): void
    {
        $uuid = (string) Str::uuid();
        $this->lancar(['installments' => 12, 'client_uuid' => $uuid])->assertSuccessful();

        $parcelas = Transaction::where('account_id', $this->cartao->id)->orderBy('installment_no')->get();
        $this->assertCount(12, $parcelas);
        $this->assertSame(range(1, 12), $parcelas->pluck('installment_no')->all());
        $this->assertCount(1, $parcelas->pluck('group_id')->unique());
        $this->assertSame(12, (int) $parcelas->first()->installments);
        // Rateio em centavos: soma exata, diferença de no máximo um centavo.
        $this->assertSame(50000, (int) round($parcelas->sum(fn ($p) => (float) $p->amount) * 100));
        $this->assertSame(['41.67', '41.66'], $parcelas->pluck('amount')->map(fn ($v) => number_format((float) $v, 2, '.', ''))->unique()->values()->all());
        // O uuid identifica a COMPRA: só na primeira linha.
        $this->assertSame($uuid, $parcelas->first()->client_uuid);
        $this->assertSame(1, Transaction::whereNotNull('client_uuid')->count());
        // Uma por ciclo: doze meses diferentes.
        $this->assertCount(12, $parcelas->map(fn ($p) => $p->date->format('Y-m'))->unique());
        $this->assertSame('Geladeira', $parcelas->last()->description);
    }

    public function test_reenviar_o_mesmo_parcelado_nao_duplica(): void
    {
        $uuid = (string) Str::uuid();
        $this->lancar(['installments' => 3, 'client_uuid' => $uuid])->assertSuccessful();
        $this->lancar(['installments' => 3, 'client_uuid' => $uuid])->assertSuccessful();

        $this->assertSame(3, Transaction::where('account_id', $this->cartao->id)->count());
    }

    public function test_a_vista_no_cartao_grava_uma_linha_sem_parcelas(): void
    {
        $this->lancar(['installments' => 1])->assertSuccessful();

        $linha = Transaction::sole();
        $this->assertNull($linha->installments);
        $this->assertNull($linha->group_id);
        $this->assertSame('500.00', number_format((float) $linha->amount, 2, '.', ''));
    }

    public function test_parcelar_fora_do_cartao_de_credito_e_recusado(): void
    {
        $corrente = Account::factory()->for($this->user)->create(['type' => 'checking', 'initial_balance' => 5000]);

        $this->lancar(['installments' => 3, 'account_id' => $corrente->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['installments']);
        $this->lancar(['installments' => 3, 'type' => 'income', 'account_id' => $corrente->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['installments']);

        $this->assertSame(0, Transaction::count());
    }

    public function test_limites_do_parcelamento(): void
    {
        $this->lancar(['installments' => 25])->assertUnprocessable()->assertJsonValidationErrors(['installments']);
        $this->lancar(['installments' => 24, 'amount' => '0,10'])->assertUnprocessable()->assertJsonValidationErrors(['amount']);

        $this->assertSame(0, Transaction::count());
    }

    public function test_o_limite_do_cartao_e_conferido_pelo_total_da_compra(): void
    {
        $this->cartao->update(['credit_limit' => 400]);

        $this->lancar(['installments' => 10])->assertUnprocessable();
        $this->assertSame(0, Transaction::count());
    }

    public function test_a_edicao_nao_parcela(): void
    {
        $linha = Transaction::factory()->for($this->user)->create([
            'account_id' => $this->cartao->id, 'type' => 'expense', 'amount' => 90, 'date' => '2027-01-20',
        ]);

        $this->actingAs($this->user)->put(route('transactions.update', $linha), [
            'type' => 'expense', 'amount' => '90,00', 'account_id' => $this->cartao->id,
            'date' => '2027-01-20', 'installments' => 6, 'description' => 'Fone',
        ]);

        $this->assertSame(1, Transaction::count());
        $this->assertNull($linha->fresh()->installments);
    }

    public function test_o_modal_traz_o_select_de_parcelas_de_1x_a_24x(): void
    {
        $html = $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('name="installments" aria-label="Parcelas" data-lm-parcelas hidden disabled>', $html);
        $this->assertStringContainsString('<option value="1">À vista</option>', $html);
        $this->assertStringContainsString('<option value="24">24x</option>', $html);
    }
}
