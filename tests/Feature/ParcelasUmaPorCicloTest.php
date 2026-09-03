<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * F-1 (auditoria de 02/09/2026): as parcelas são datadas por CICLO de fatura,
 * não por mês-calendário.
 *
 * Cartão que fecha dia 28 com compra em 30/01: `addMonthsNoOverflow` datava a
 * 2ª parcela em 28/02 — dentro do MESMO ciclo (28/01..28/02] da 1ª. Fevereiro
 * cobrava duas parcelas e março nenhuma. A regra passa a ser: a parcela N cai
 * no N-ésimo ciclo a partir do ciclo da compra.
 */
class ParcelasUmaPorCicloTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-01-15');
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cartao(int $fechamento): Account
    {
        return Account::factory()->for($this->user)->creditCard()->create([
            'closing_day' => $fechamento,
            'due_day' => $fechamento + 1 <= 28 ? $fechamento + 1 : 5,
            'credit_limit' => 50000,
        ]);
    }

    /** Lança o parcelado e devolve as datas das parcelas, em ordem. */
    private function parcelar(Account $cartao, string $data, int $n, string $valor = '3.000,00'): array
    {
        $this->actingAs($this->user)->post(route('faturas.lancar'), [
            'description' => 'Geladeira', 'amount' => $valor, 'account_id' => $cartao->id,
            'date' => $data, 'mode' => 'parcelado', 'installments' => $n,
        ])->assertSessionHasNoErrors();

        return Transaction::where('account_id', $cartao->id)->orderBy('installment_no')
            ->pluck('date')->map(fn ($d) => $d->toDateString())->all();
    }

    /** Fim do ciclo (fechamento) em que cada data cai. */
    private function ciclos(Account $cartao, array $datas): array
    {
        return array_map(
            fn (string $d) => $cartao->billingCycle(CarbonImmutable::parse($d))[1]->toDateString(),
            $datas,
        );
    }

    private function assertUmaParcelaPorCiclo(Account $cartao, array $datas): void
    {
        $ciclos = $this->ciclos($cartao, $datas);

        $this->assertSame(
            array_values(array_unique($ciclos)),
            $ciclos,
            'duas parcelas caíram no mesmo ciclo: '.implode(' | ', $ciclos),
        );

        // E os ciclos são CONSECUTIVOS: nenhum mês pulado.
        for ($i = 1; $i < count($ciclos); $i++) {
            $anterior = CarbonImmutable::parse($ciclos[$i - 1]);
            $seguinte = $cartao->billingCycle($anterior->addDay())[1];
            $this->assertSame($seguinte->toDateString(), $ciclos[$i], "a parcela {$i} pulou um ciclo");
        }
    }

    /** O cenário do relatório: fecha 28, compra em 30/01, 3x. */
    public function test_compra_em_30_01_com_fechamento_28_gera_uma_parcela_por_ciclo(): void
    {
        $cartao = $this->cartao(28);
        $datas = $this->parcelar($cartao, '2027-01-30', 3);

        // Antes: 30/01 · 28/02 · 30/03 (fevereiro com duas, março com zero).
        $this->assertSame(['2027-01-30', '2027-03-01', '2027-03-30'], $datas);
        $this->assertUmaParcelaPorCiclo($cartao, $datas);
    }

    public function test_compra_em_29_30_e_31_de_janeiro_com_fechamento_28(): void
    {
        foreach (['2027-01-29', '2027-01-30', '2027-01-31'] as $dia) {
            $cartao = $this->cartao(28);
            $datas = $this->parcelar($cartao, $dia, 6);

            $this->assertCount(6, $datas);
            $this->assertSame($dia, $datas[0], 'a 1ª parcela mantém a data da compra');
            $this->assertUmaParcelaPorCiclo($cartao, $datas);
        }
    }

    /** Cenários que já eram corretos continuam iguais (fechamento 10). */
    public function test_fechamento_10_com_compra_no_dia_31_continua_no_ultimo_dia_do_mes_curto(): void
    {
        $cartao = $this->cartao(10);
        $datas = $this->parcelar($cartao, '2027-01-31', 3);

        $this->assertSame(['2027-01-31', '2027-02-28', '2027-03-31'], $datas);
        $this->assertUmaParcelaPorCiclo($cartao, $datas);
    }

    public function test_fechamento_10_com_compra_no_dia_30(): void
    {
        $cartao = $this->cartao(10);
        $datas = $this->parcelar($cartao, '2027-01-30', 4);

        $this->assertSame(['2027-01-30', '2027-02-28', '2027-03-30', '2027-04-30'], $datas);
        $this->assertUmaParcelaPorCiclo($cartao, $datas);
    }

    public function test_compra_em_29_02_de_ano_bissexto(): void
    {
        Carbon::setTestNow('2028-02-20');
        $cartao = $this->cartao(10);
        $datas = $this->parcelar($cartao, '2028-02-29', 3);

        $this->assertSame(['2028-02-29', '2028-03-29', '2028-04-29'], $datas);
        $this->assertUmaParcelaPorCiclo($cartao, $datas);
    }

    /** Compra exatamente no dia do fechamento: ainda pertence ao ciclo que fecha nele. */
    public function test_compra_no_dia_do_fechamento(): void
    {
        $cartao = $this->cartao(28);
        $datas = $this->parcelar($cartao, '2027-01-28', 3);

        $this->assertSame(['2027-01-28', '2027-02-28', '2027-03-28'], $datas);
        $this->assertUmaParcelaPorCiclo($cartao, $datas);
    }

    /** O uuid fica só na 1ª parcela e o rateio de centavos não mudou. */
    public function test_uuid_so_na_primeira_e_rateio_intacto(): void
    {
        $cartao = $this->cartao(28);
        $uuid = '4b7c2a4e-9d0e-4e5b-8c0f-1f2a3b4c5d6e';

        $this->actingAs($this->user)->post(route('faturas.lancar'), [
            'client_uuid' => $uuid,
            'description' => 'TV', 'amount' => '1.000,00', 'account_id' => $cartao->id,
            'date' => '2027-01-30', 'mode' => 'parcelado', 'installments' => 3,
        ])->assertSessionHasNoErrors();

        $parcelas = Transaction::where('account_id', $cartao->id)->orderBy('installment_no')->get();

        $this->assertSame([$uuid, null, null], $parcelas->pluck('client_uuid')->all());
        $this->assertSame(['333.34', '333.33', '333.33'], $parcelas->pluck('amount')->map(fn ($a) => (string) $a)->all());
        $this->assertSame(1000.0, round((float) $parcelas->sum('amount'), 2));
    }
}
