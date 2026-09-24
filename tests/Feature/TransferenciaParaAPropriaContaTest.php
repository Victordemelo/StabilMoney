<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Transferência para a MESMA conta é recusada qualquer que seja o jeito de escrever o id
 * (24/09/2026).
 *
 * A regra `different:account_id` compara o TEXTO do corpo: "5" e "05" são diferentes para
 * ela, mas o `exists` (e o `whereKey` do controller) resolvem os dois para a mesma conta.
 * Nasciam duas pontas que se anulam na própria conta — o que o comentário da regra promete
 * impedir — e, sem disponível, a "saída" ainda pedia cheque especial ou resgate de
 * investimento para cobrir um dinheiro que não ia para lugar nenhum.
 */
class TransferenciaParaAPropriaContaTest extends TestCase
{
    use RefreshDatabase;

    private User $titular;

    private Account $corrente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->titular = User::factory()->create(['is_admin' => true, 'account_owner_id' => null]);
        $this->corrente = Account::factory()->for($this->titular)->create([
            'name' => 'Corrente', 'type' => 'checking', 'initial_balance' => 1000, 'overdraft_limit' => 0,
        ]);
    }

    /** @return array<string, mixed> */
    private function corpo(string $destino): array
    {
        return [
            'amount' => '300,00',
            'account_id' => (string) $this->corrente->id,
            'to_account_id' => $destino,
            'date' => CarbonImmutable::today()->toDateString(),
        ];
    }

    public function test_id_da_origem_com_zero_a_esquerda_nao_vira_outra_conta(): void
    {
        $mesmaConta = '0'.$this->corrente->id;

        // Pela rota própria (modal) e pela rota comum com type=transfer (fila offline).
        $this->actingAs($this->titular)
            ->postJson(route('transactions.transfer'), $this->corpo($mesmaConta))
            ->assertStatus(422)
            ->assertJsonValidationErrors('to_account_id');

        $this->actingAs($this->titular)
            ->postJson(route('transactions.store'), [...$this->corpo($mesmaConta), 'type' => 'transfer'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('to_account_id');

        $this->assertSame(0, Transaction::count(), 'Nenhuma ponta podia ter sido gravada.');
        $this->assertSame(1000.0, $this->corrente->fresh()->available);
    }

    public function test_pela_pagina_cheia_volta_com_o_erro_no_campo(): void
    {
        $this->actingAs($this->titular)
            ->from(route('transactions.create'))
            ->post(route('transactions.transfer'), $this->corpo('0'.$this->corrente->id))
            ->assertRedirect(route('transactions.create'))
            ->assertSessionHasErrors('to_account_id');

        $this->assertSame(0, Transaction::count());
    }

    /** Controle: entre duas contas diferentes, tudo como sempre. */
    public function test_entre_contas_diferentes_continua_transferindo(): void
    {
        $poupanca = Account::factory()->for($this->titular)->create([
            'name' => 'Poupança', 'type' => 'savings', 'initial_balance' => 0,
        ]);

        $this->actingAs($this->titular)
            ->postJson(route('transactions.transfer'), $this->corpo('0'.$poupanca->id))
            ->assertCreated();

        $this->assertSame(700.0, $this->corrente->fresh()->available);
        $this->assertSame(300.0, $poupanca->fresh()->available);
    }
}
