<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Goal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Meta só pode ser excluída ZERADA (out/2026 — decisão do Victor: "não vai dar pra excluir
 * meta se tiver aporte nela, tem que tirar todo dinheiro pra excluir").
 *
 * Antes, excluir uma meta com dinheiro devolvia o valor à conta em silêncio e o histórico
 * de aportes sumia junto (cascade) — inclusive de Movimentações. Agora o caminho é resgatar
 * tudo (fica registrado e aparece no extrato) e só então excluir.
 */
class ExclusaoDeMetaSoZeradaTest extends TestCase
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

    private function metaComAporte(string $valor): Goal
    {
        $meta = Goal::factory()->for($this->user)->create(['name' => 'Viagem', 'target_amount' => 5000]);
        $this->actingAs($this->user)->post(route('metas.aportes.store', $meta), [
            'account_id' => $this->conta->id, 'amount' => $valor, 'date' => $this->hoje(),
        ])->assertSessionHasNoErrors();

        return $meta;
    }

    public function test_com_dinheiro_guardado_a_exclusao_e_recusada_e_nada_muda(): void
    {
        $meta = $this->metaComAporte('300,00');

        $this->actingAs($this->user)->from(route('metas.index'))
            ->delete(route('metas.destroy', $meta))
            ->assertRedirect(route('metas.index'))
            ->assertSessionHasErrors(['meta' => 'A meta “Viagem” ainda tem R$ 300,00 guardados. Resgate todo o dinheiro dela antes de excluí-la.']);

        $this->assertModelExists($meta);
        $this->assertSame(300.0, (float) $meta->fresh()->saved);
        $this->assertSame(700.0, $this->conta->fresh()->available);
    }

    public function test_depois_de_resgatar_tudo_a_meta_pode_ser_excluida(): void
    {
        $meta = $this->metaComAporte('300,00');
        $this->actingAs($this->user)->post(route('metas.resgates.store', $meta), [
            'account_id' => $this->conta->id, 'amount' => '300,00', 'date' => $this->hoje(),
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->user)->delete(route('metas.destroy', $meta))
            ->assertSessionHasNoErrors()->assertRedirect(route('metas.index'));

        $this->assertModelMissing($meta);
        $this->assertSame(1000.0, $this->conta->fresh()->available);
    }

    public function test_a_tela_explica_e_desliga_o_botao_so_da_meta_com_dinheiro(): void
    {
        $cheia = $this->metaComAporte('300,00');
        $vazia = Goal::factory()->for($this->user)->create(['name' => 'Carro']);

        $html = $this->actingAs($this->user)->get(route('metas.index'))->assertOk()->getContent();

        $this->assertStringContainsString('“Viagem” ainda tem <b>R$ 300,00</b> guardados. Para excluir, resgate todo o dinheiro dela primeiro.', $html);
        $this->assertMatchesRegularExpression('#metas/'.$cheia->id.'"[^>]*>.*?<button class="btn-danger" type="submit" disabled>Excluir meta</button>#s', $html);
        $this->assertMatchesRegularExpression('#metas/'.$vazia->id.'"[^>]*>.*?<button class="btn-danger" type="submit" >Excluir meta</button>#s', $html);
    }
}
