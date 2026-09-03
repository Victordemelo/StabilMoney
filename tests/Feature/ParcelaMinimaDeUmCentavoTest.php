<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Parcelas de R$ 0,00 (auditoria de 02/09/2026): `amount` só exigia `min:0.01`
 * e `installments` vai até 24, então R$ 0,10 em 24x gerava 14 parcelas
 * zeradas. Cada parcela precisa valer pelo menos R$ 0,01.
 */
class ParcelaMinimaDeUmCentavoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $cartao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->cartao = Account::factory()->for($this->user)->creditCard()->create();
    }

    private function lancar(string $valor, int $parcelas)
    {
        return $this->actingAs($this->user)->postJson(route('faturas.lancar'), [
            'description' => 'Bala', 'amount' => $valor, 'account_id' => $this->cartao->id,
            'date' => now()->toDateString(), 'mode' => 'parcelado', 'installments' => $parcelas,
        ]);
    }

    public function test_dez_centavos_em_24x_e_recusado(): void
    {
        $this->lancar('0,10', 24)
            ->assertStatus(422)
            ->assertJsonPath('errors.amount.0', 'Com 24 parcelas, o valor precisa ser de pelo menos R$ 0,24.');

        $this->assertSame(0, Transaction::count());
    }

    public function test_vinte_e_quatro_centavos_em_24x_gera_24_parcelas_de_um_centavo(): void
    {
        $this->lancar('0,24', 24)->assertStatus(302);

        $parcelas = Transaction::where('account_id', $this->cartao->id)->get();
        $this->assertCount(24, $parcelas);
        $this->assertTrue($parcelas->every(fn ($p) => (string) $p->amount === '0.01'));
        $this->assertSame(0.24, round((float) $parcelas->sum('amount'), 2));
    }

    /** À vista não tem parcela: o mínimo continua R$ 0,01. */
    public function test_avista_nao_e_afetado(): void
    {
        $this->actingAs($this->user)->postJson(route('faturas.lancar'), [
            'description' => 'Bala', 'amount' => '0,01', 'account_id' => $this->cartao->id,
            'date' => now()->toDateString(), 'mode' => 'avista',
        ])->assertStatus(302);

        $this->assertSame(1, Transaction::count());
    }
}
