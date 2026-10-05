<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FixedBill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editar e excluir a conta fixa aparecem em TODAS as linhas dela (out/2026). Antes só a
 * primeira linha de cada conta tinha os botões: com a energia de setembro vencida, a de
 * outubro aparecia sem lápis, e parecia que a conta só podia ser alterada depois de pagar
 * a vencida.
 */
class ContaFixaEditavelEmTodasAsLinhasTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_competencia_a_vencer_tambem_tem_editar_e_excluir_com_vencida_antes(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-10-05 10:00:00'));
        $user = User::factory()->create();
        $conta = Account::factory()->for($user)->create(['type' => 'checking', 'initial_balance' => 1000]);
        $energia = FixedBill::factory()->create([
            'user_id' => $user->id, 'name' => 'Energia elétrica', 'amount' => 236, 'due_day' => 20,
            'account_id' => $conta->id, 'starts_on' => '2026-09-01', 'active' => true,
        ]);

        $html = $this->actingAs($user)->get(route('faturas.index'))->assertOk()->getContent();

        $this->assertStringContainsString('setembro/2026', $html);
        $this->assertStringContainsString('outubro/2026', $html);
        $this->assertSame(2, substr_count($html, 'aria-label="Editar a conta fixa Energia elétrica"'));
        $this->assertSame(2, substr_count($html, 'aria-label="Excluir a conta fixa Energia elétrica"'));
        $this->assertSame(2, substr_count($html, 'data-action="'.route('contas-fixas.update', $energia).'"'));
    }

    public function test_o_modal_explica_que_o_valor_do_mes_se_ajusta_no_pagamento(): void
    {
        $user = User::factory()->create();
        Account::factory()->for($user)->create(['type' => 'checking', 'initial_balance' => 0]);

        $this->actingAs($user)->get(route('faturas.index'))->assertOk()
            ->assertSee('O valor novo vale deste mês em diante')
            ->assertSee('Se só a conta deste mês veio diferente, ajuste o valor na hora de pagar.');
    }
}
