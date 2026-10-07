<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Investment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Investimento só pode ser excluído ZERADO (out/2026 — decisão do Victor: a mesma regra das
 * metas, `ExclusaoDeMetaSoZeradaTest`).
 *
 * Antes só barrava com a conta de origem no vermelho. Com ela no azul, excluir um investimento
 * com dinheiro devolvia o valor à conta em silêncio e o histórico de aportes sumia junto
 * (cascade) — inclusive de Movimentações. Agora o caminho é resgatar tudo (fica registrado e
 * aparece no extrato) e só então excluir.
 */
class ExclusaoDeInvestimentoSoZeradoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->conta = Account::factory()->for($this->user)->create([
            'name' => 'Corrente', 'type' => 'checking', 'bank' => 'nubank', 'initial_balance' => 1000, 'overdraft_limit' => 0,
        ]);
    }

    private function hoje(): string
    {
        return CarbonImmutable::today()->toDateString();
    }

    private function investimento(string $nome, ?string $aporte = null): Investment
    {
        $inv = Investment::create([
            'user_id' => $this->user->id, 'name' => $nome, 'classe' => 'renda_fixa', 'indexador' => 'cdi', 'taxa' => 100,
        ]);
        if ($aporte !== null) {
            $this->actingAs($this->user)->post(route('investimentos.aportes.store', $inv), [
                'account_id' => $this->conta->id, 'amount' => $aporte, 'date' => $this->hoje(),
            ])->assertSessionHasNoErrors();
        }

        return $inv;
    }

    public function test_com_dinheiro_aplicado_a_exclusao_e_recusada_e_nada_muda(): void
    {
        $inv = $this->investimento('CDB', '300,00');

        $this->actingAs($this->user)->from(route('investimentos.index'))
            ->delete(route('investimentos.destroy', $inv))
            ->assertRedirect(route('investimentos.index'))
            ->assertSessionHasErrors(['investimento' => 'O investimento “CDB” ainda tem R$ 300,00 aplicados. Resgate todo o dinheiro dele (botão Resgatar) antes de excluí-lo.']);

        $this->assertModelExists($inv);
        $this->assertSame(300.0, (float) $inv->fresh()->aplicado);
        $this->assertSame(700.0, $this->conta->fresh()->available);
    }

    public function test_depois_de_resgatar_tudo_o_investimento_pode_ser_excluido(): void
    {
        $inv = $this->investimento('CDB', '300,00');
        $this->actingAs($this->user)->post(route('investimentos.resgates.store', $inv), [
            'account_id' => $this->conta->id, 'amount' => '300,00', 'date' => $this->hoje(),
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->user)->delete(route('investimentos.destroy', $inv))
            ->assertSessionHasNoErrors()->assertRedirect(route('investimentos.index'));

        $this->assertModelMissing($inv);
        $this->assertSame(1000.0, $this->conta->fresh()->available);
    }

    public function test_a_tela_explica_e_desliga_o_botao_so_do_investimento_com_dinheiro(): void
    {
        $cheio = $this->investimento('CDB', '300,00');
        $vazio = $this->investimento('Tesouro');

        $html = $this->actingAs($this->user)->get(route('investimentos.index'))->assertOk()->getContent();

        $this->assertStringContainsString('“CDB” ainda tem <b>R$ 300,00</b> aplicados. Para excluir, resgate todo o dinheiro dele primeiro (botão Resgatar).', $html);
        $this->assertMatchesRegularExpression('#investimentos/'.$cheio->id.'"[^>]*>.*?<button class="btn-danger" type="submit" disabled>Excluir investimento</button>#s', $html);
        $this->assertMatchesRegularExpression('#investimentos/'.$vazio->id.'"[^>]*>.*?<button class="btn-danger" type="submit" >Excluir investimento</button>#s', $html);
    }
}
