<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Compra parcelada no cartão de crédito: as N linhas, uma por ciclo de fatura. Era privada do
 * FaturaController ("Lançar despesa" de Contas a pagar); saiu para cá em out/2026 quando o
 * modal "Lançar" também passou a parcelar — as duas portas gravam pelas MESMAS regras.
 *
 * Sempre chamada DENTRO do `write` do `FundingService::spend()` (o limite do cartão é
 * conferido pelo TOTAL da compra, sob a trava da conta).
 */
final class Parcelamento
{
    /**
     * Parcelado em N: uma transação por CICLO de fatura (não por mês-calendário).
     *
     * A parcela N cai no N-ésimo ciclo a partir do ciclo da compra. A 1ª mantém
     * a data da compra; as demais partem do candidato "mesmo dia, N−1 meses
     * depois" e, se ele ainda cair no ciclo da parcela anterior, são empurradas
     * para o primeiro dia do ciclo seguinte (fechamento + 1).
     *
     * Antes era só `addMonthsNoOverflow`: cartão que fecha dia 28 com compra em
     * 30/01 datava a 2ª parcela em 28/02 — dentro do MESMO ciclo (28/01..28/02]
     * da 1ª. Fevereiro cobrava duas parcelas e março nenhuma (F-1, auditoria
     * de 02/09/2026). Ocorria com compra em 29, 30 e 31/01.
     */
    public static function criar(array $common, float $total, CarbonImmutable $base, int $n, Account $card, bool $faturasVencidasPagas = false): Transaction
    {
        $groupId = (string) Str::uuid();
        $primeira = null;

        // Rateio em CENTAVOS INTEIROS. O jeito anterior — dividir, arredondar e jogar a
        // sobra na última parcela — produzia parcela NEGATIVA quando o arredondamento
        // subia: R$ 0,36 em 24x dava 0,02 por parcela (0,46 no total) e a última virava
        // −R$ 0,10. Linha negativa devolve limite do cartão e viola "dinheiro nunca é
        // negativo, o sinal vem do type".
        //
        // Aqui o resto é distribuído um centavo por vez nas PRIMEIRAS parcelas: a soma
        // fecha exata, nenhuma parcela fica negativa, e a diferença entre a maior e a
        // menor nunca passa de um centavo.
        $centavos = (int) round($total * 100);
        $porParcela = intdiv($centavos, $n);
        $sobra = $centavos - ($porParcela * $n);   // 0 .. n-1

        // Atômico: as N parcelas entram juntas ou nenhuma — sem fatura "pela metade".
        // (Já roda dentro da transação do FundingService; aninhar é seguro.)
        // Da 2ª parcela em diante o uuid sai: ele identifica a COMPRA, e o índice
        // único (user_id, client_uuid) só admite uma linha por uuid.
        $semUuid = Arr::except($common, 'client_uuid');

        $datas = self::datas($base, $n, $card);

        DB::transaction(function () use ($common, $semUuid, $datas, $n, $groupId, $porParcela, $sobra, $card, $faturasVencidasPagas, &$primeira) {
            for ($i = 1; $i <= $n; $i++) {
                // As `$sobra` primeiras parcelas levam 1 centavo a mais.
                $amount = ($porParcela + ($i <= $sobra ? 1 : 0)) / 100;

                $linha = Transaction::create(($i === 1 ? $common : $semUuid) + [
                    'amount' => $amount,
                    'date' => $datas[$i - 1]->toDateString(),
                    'group_id' => $groupId,
                    'installment_no' => $i,
                    'installments' => $n,
                ] + ($faturasVencidasPagas ? self::pagaForaDoApp($card, $datas[$i - 1]) : []));

                $primeira ??= $linha;
            }
        });

        return $primeira;
    }

    /**
     * Datas das N parcelas, uma por ciclo de fatura (ver `criar`).
     * Sem dia de fechamento (não deveria acontecer: parcelado é só cartão),
     * cai no mês-calendário de antes.
     *
     * @return list<CarbonImmutable>
     */
    public static function datas(CarbonImmutable $base, int $n, Account $card): array
    {
        $datas = [$base];
        // Fim do ciclo em que a parcela anterior caiu.
        $fechamentoAnterior = $card->billingCycle($base)[1] ?? null;

        for ($i = 2; $i <= $n; $i++) {
            $datas[] = $data = self::dataNoCicloSeguinte($base, $i - 1, $fechamentoAnterior, $card);
            $fechamentoAnterior = $card->billingCycle($data)[1] ?? null;
        }

        return $datas;
    }

    /**
     * A data da ocorrência que vem `$meses` meses depois da compra original,
     * garantindo que ela caia num ciclo DEPOIS do ciclo cujo fechamento é
     * `$fechamentoAnterior` (a regra "uma por ciclo" das parcelas, reaproveitada
     * pela recorrência de cartão).
     *
     * NoOverflow: compra em 31/01 gera 28/02, não 03/03. Com o addMonths puro do
     * Carbon, fevereiro ficava sem parcela e março levava duas. E se o candidato
     * ainda cair no ciclo anterior (cartão que fecha dia 28, compra em 30/01 →
     * 28/02 está no mesmo ciclo), ele é empurrado para o primeiro dia do ciclo
     * seguinte (fechamento + 1).
     */
    public static function dataNoCicloSeguinte(CarbonImmutable $base, int $meses, ?CarbonImmutable $fechamentoAnterior, Account $card): CarbonImmutable
    {
        $candidata = $base->addMonthsNoOverflow($meses);

        if ($fechamentoAnterior === null) {
            return $candidata;
        }

        $fechamento = $card->billingCycle($candidata)[1];
        if ($fechamento->lessThanOrEqualTo($fechamentoAnterior)) {
            return $fechamentoAnterior->addDay();
        }

        return $candidata;
    }

    /**
     * Compra lançada com data no PASSADO (out/2026 — pedido do Victor): a linha que cai numa
     * fatura que já venceu foi paga no mundo real, antes do app. Devolve os atributos que a
     * gravam assim — paga no vencimento daquela fatura, sem saída de caixa — ou [] quando a
     * fatura dela ainda não venceu (essa segue em aberto, para a pessoa pagar pelo app).
     */
    public static function pagaForaDoApp(Account $card, CarbonImmutable $data, ?CarbonImmutable $hoje = null): array
    {
        $corte = $card->isCard() ? $card->corteDasFaturasVencidas($hoje) : null;
        if (! $corte || $data->greaterThan($corte)) {
            return [];
        }

        $vencimento = $card->dueDateForCycle($card->billingCycle($data)[1]);

        return ['paid_at' => $vencimento->startOfDay(), 'pago_fora_do_app' => true];
    }

    /**
     * Quanto da compra ainda pesa no limite do cartão: o total menos as parcelas que entram
     * pagas fora do app. É o valor que o `FundingService` confere — uma compra de um ano atrás
     * já paga não pode ser recusada por falta de limite.
     */
    public static function valorEmAberto(float $total, CarbonImmutable $base, int $n, Account $card, bool $faturasVencidasPagas): float
    {
        if (! $faturasVencidasPagas) {
            return $total;
        }

        $centavos = (int) round($total * 100);
        $porParcela = intdiv($centavos, $n);
        $sobra = $centavos - ($porParcela * $n);
        $emAberto = 0;

        foreach (self::datas($base, $n, $card) as $i => $data) {
            if (self::pagaForaDoApp($card, $data) === []) {
                $emAberto += $porParcela + ($i + 1 <= $sobra ? 1 : 0);
            }
        }

        return $emAberto / 100;
    }
}
