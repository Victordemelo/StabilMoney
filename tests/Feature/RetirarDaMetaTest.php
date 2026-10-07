<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Goal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retirar dinheiro da meta (out/2026 — Victor: "nas metas temos que ter um jeito de tirar os
 * valores também, eu posso tanto aportar quanto retirar"). O resgate existia, mas só como um
 * ícone ↓ que aparecia passando o mouse. Agora cada card tem "Retirar" ao lado de "Aportar".
 */
class RetirarDaMetaTest extends TestCase
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

    private function meta(string $nome, ?string $aporte = null): Goal
    {
        $meta = Goal::factory()->for($this->user)->create(['name' => $nome, 'target_amount' => 5000]);
        if ($aporte !== null) {
            $this->actingAs($this->user)->post(route('metas.aportes.store', $meta), [
                'account_id' => $this->conta->id, 'amount' => $aporte, 'date' => CarbonImmutable::today()->toDateString(),
            ])->assertSessionHasNoErrors();
        }

        return $meta;
    }

    private function botaoRetirar(HTMLDocument $doc, Goal $meta): Element
    {
        $botao = $doc->querySelector(".meta-botoes [data-meta-resgatar][data-id=\"{$meta->id}\"]");
        $this->assertNotNull($botao, "O card de {$meta->name} não tem o botão Retirar ao lado do Aportar.");

        return $botao;
    }

    public function test_o_card_tem_retirar_a_vista_ao_lado_do_aportar(): void
    {
        $viagem = $this->meta('Viagem', '300,00');
        $vazia = $this->meta('Carro');

        $html = $this->actingAs($this->user)->get(route('metas.index'))->assertOk()->getContent();
        $doc = HTMLDocument::createFromString($html, LIBXML_NOERROR);

        $retirar = $this->botaoRetirar($doc, $viagem);
        $this->assertStringContainsString('Retirar', $retirar->textContent);
        $this->assertFalse($retirar->hasAttribute('disabled'));
        $this->assertSame('R$ 300,00', $retirar->getAttribute('data-saved'));
        $this->assertNotNull($retirar->parentElement->querySelector('[data-meta-aporte]'), 'Aportar e Retirar ficam juntos.');

        // Sem nada guardado não há o que retirar — o botão vem desligado e diz o porquê.
        $semDinheiro = $this->botaoRetirar($doc, $vazia);
        $this->assertTrue($semDinheiro->hasAttribute('disabled'));
        $this->assertSame('Nada guardado nesta meta ainda', $semDinheiro->getAttribute('title'));

        // O ícone escondido no hover saiu: um caminho só, à vista.
        $this->assertNull($doc->querySelector('.meta-actions [data-meta-resgatar]'));
        $this->assertStringContainsString('Retirar de <span data-resgate-name>', $html);
    }

    public function test_retirar_devolve_o_dinheiro_para_a_conta_e_libera_a_exclusao(): void
    {
        $meta = $this->meta('Viagem', '300,00');
        $this->assertEqualsWithDelta(700, $this->conta->fresh()->available, 0.001);

        $this->actingAs($this->user)->post(route('metas.resgates.store', $meta), [
            'account_id' => $this->conta->id, 'amount' => '300,00',
        ])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(0, (float) $meta->fresh()->saved, 0.001);
        $this->assertEqualsWithDelta(1000, $this->conta->fresh()->available, 0.001);

        $this->actingAs($this->user)->delete(route('metas.destroy', $meta))->assertSessionHasNoErrors();
        $this->assertModelMissing($meta);
    }
}
