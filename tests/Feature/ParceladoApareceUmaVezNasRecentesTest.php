<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Compra parcelada aparece UMA vez em "Transações recentes" (out/2026), com o valor da
 * compra inteira e "em 12x". Antes cada parcela era uma linha — e as futuras, com data mais
 * nova, abriam a lista: o Victor lançou uma compra em 12x e o dashboard mostrou doze
 * "teste victor − R$ 16,66", começando pela parcela de setembro do ano seguinte.
 */
class ParceladoApareceUmaVezNasRecentesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_compra_parcelada_e_uma_linha_com_o_total(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-10-04 10:00:00'));
        $user = User::factory()->create();
        $cartao = Account::factory()->for($user)->creditCard()->create();

        $this->actingAs($user)->post(route('faturas.lancar'), [
            'description' => 'Geladeira', 'amount' => '1.200,00', 'date' => '2026-10-04',
            'account_id' => $cartao->id, 'mode' => 'parcelado', 'installments' => 12,
        ])->assertSessionHasNoErrors();
        $this->assertSame(12, Transaction::where('description', 'Geladeira')->count(), 'Pré-condição: 12 parcelas gravadas.');

        $recentes = app(DashboardService::class)->build($user->id)['recent'];
        $geladeira = $recentes->where('description', 'Geladeira');

        $this->assertCount(1, $geladeira);
        $this->assertSame(1, (int) $geladeira->first()->installment_no);
        $this->assertEqualsWithDelta(1200.00, $geladeira->first()->valor_exibido, 0.001);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertSee('em 12x')
            ->assertSee('R$ 1.200,00');
    }

    public function test_compra_a_vista_continua_com_o_proprio_valor(): void
    {
        $user = User::factory()->create();
        $conta = Account::factory()->for($user)->create(['type' => 'checking', 'initial_balance' => 500]);
        Transaction::factory()->for($user)->expense()->create([
            'account_id' => $conta->id, 'amount' => 42.5, 'description' => 'Padaria', 'date' => now()->toDateString(),
        ]);

        $padaria = app(DashboardService::class)->build($user->id)['recent']->firstWhere('description', 'Padaria');

        $this->assertEqualsWithDelta(42.5, $padaria->valor_exibido, 0.001);
    }
}
