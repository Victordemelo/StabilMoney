<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FaturaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Próximas faturas" do cartão em Contas a pagar (out/2026 — pedido do Victor: numa compra
 * parcelada não dava para ver as faturas dos próximos meses). As linhas em aberto datadas
 * depois do ciclo aberto, uma fatura por vencimento, como no app do banco.
 */
class ProximasFaturasDoCartaoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $cartao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(new \DateTimeImmutable('2026-10-08 10:00:00'));
        $this->user = User::factory()->create();
        // Fecha dia 2, vence dia 9: o ciclo aberto vai de 02/10 a 02/11 e vence em 09/11.
        $this->cartao = Account::factory()->for($this->user)->creditCard()->create([
            'name' => 'Crédito Itaú', 'closing_day' => 2, 'due_day' => 9, 'credit_limit' => 2000,
        ]);
    }

    private function parcelar(string $descricao, string $valor, int $n): void
    {
        $this->actingAs($this->user)->post(route('faturas.lancar'), [
            'description' => $descricao, 'amount' => $valor, 'account_id' => $this->cartao->id,
            'date' => '2026-10-08', 'mode' => 'parcelado', 'installments' => $n,
        ])->assertSessionHasNoErrors();
    }

    private function proximas()
    {
        return app(FaturaService::class)->build($this->user->id)['cards']->first()->proximas;
    }

    public function test_compra_em_4x_mostra_as_tres_faturas_seguintes_com_o_vencimento_de_cada_uma(): void
    {
        $this->parcelar('Compra na Havan', '79,96', 4);

        $proximas = $this->proximas();
        $this->assertCount(3, $proximas);
        $this->assertSame(['2026-12-09', '2027-01-09', '2027-02-09'], $proximas->map(fn ($p) => $p['vencimento']->toDateString())->all());
        $this->assertSame([19.99, 19.99, 19.99], $proximas->pluck('total')->all());
        $this->assertSame(['2/4', '3/4', '4/4'], $proximas->map(fn ($p) => $p['itens']->first()->badge)->all());
    }

    public function test_duas_compras_no_mesmo_ciclo_somam_na_mesma_fatura_e_estorno_futuro_abate(): void
    {
        $this->parcelar('Geladeira', '300,00', 3);
        $this->parcelar('Fone', '100,00', 2);
        Transaction::create([
            'user_id' => $this->user->id, 'account_id' => $this->cartao->id, 'type' => 'income',
            'amount' => 20, 'date' => '2026-11-10', 'description' => 'Estorno do fone',
        ]);

        $proximas = $this->proximas();
        $this->assertSame([130.0, 100.0], $proximas->pluck('total')->all());
        $this->assertCount(3, $proximas->first()['itens']);
    }

    public function test_parcela_ja_paga_e_compra_do_ciclo_atual_nao_entram(): void
    {
        $this->parcelar('Bicicleta', '200,00', 2);
        Transaction::where('installment_no', 2)->update(['paid_at' => now()]);

        $this->assertCount(0, $this->proximas());
    }

    public function test_a_tela_mostra_o_botao_e_o_aviso_no_cabecalho(): void
    {
        $this->parcelar('Compra na Havan', '79,96', 4);

        $html = $this->actingAs($this->user)->get(route('faturas.index'))->assertOk()->getContent();
        $this->assertStringContainsString('<small class="fh-proximas">+ 3 próximas faturas</small>', $html);
        $this->assertStringContainsString('<details class="fatura-proximas">', $html);
        $this->assertStringContainsString('Vence em 09 de dez de 2026', $html);
        $this->assertStringContainsString('<em class="fi-badge parcelado">4/4</em>', $html);
    }

    public function test_sem_compra_futura_nao_aparece_nada(): void
    {
        $html = $this->actingAs($this->user)->get(route('faturas.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('fatura-proximas', $html);
    }
}
