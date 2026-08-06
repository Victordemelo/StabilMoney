<?php

namespace App\Services;

use App\Models\FixedBill;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Fluent;

/**
 * Ocorrências mensais das contas fixas.
 *
 * DECISÃO CENTRAL: as competências são CALCULADAS na leitura, nunca
 * materializadas. Só existe linha em `transactions` quando a conta é paga.
 *
 * Por quê: uma transação em aberto já reduz o saldo hoje (Account::balance soma
 * tudo, sem olhar `paid_at` nem data). Materializar 12 meses futuros derrubaria
 * o saldo do usuário em 12 aluguéis de uma vez e sabotaria o limite de gasto,
 * que depende de um saldo confiável.
 *
 * Consequência boa: NÃO é preciso agendador para a conta "não vencer" — a
 * competência do mês existe sempre porque é projetada de forma determinística.
 */
class FixedBillService
{
    /** Teto de competências passadas em aberto exibidas individualmente. */
    public const MAX_MESES_ATRAS = 12;

    /**
     * Quantos dias À FRENTE a projeção enxerga por padrão.
     *
     * Por que existe (auditoria 28/07/2026, A-8): a projeção parava em HOJE, e
     * aí uma conta que vence nos dias 1–7 (aluguel e condomínio típicos) NUNCA
     * aparecia na janela de "próximos 7 dias" do sino — a competência de agosto
     * só nascia em 01/08, já vencendo. Com 7 dias de antecedência o aviso volta
     * a ser um aviso, e não um comunicado de atraso.
     */
    public const DIAS_A_FRENTE = 7;

    /**
     * Ocorrências de todas as contas fixas ativas da família num intervalo de
     * competências (o dia é ignorado; conta o mês).
     *
     * Marca o que está pago com UMA query só — nada de N+1.
     *
     * @return Collection<int, Fluent>
     */
    public function occurrences(int $ownerId, CarbonImmutable $de, CarbonImmutable $ate): Collection
    {
        $bills = FixedBill::with(['account', 'category'])
            ->where('user_id', $ownerId)
            ->where('active', true)
            ->orderBy('due_day')
            ->get();

        if ($bills->isEmpty()) {
            return collect();
        }

        // Competências já pagas: "{bill_id}:{Y-m}" => transação.
        $pagos = Transaction::whereIn('fixed_bill_id', $bills->pluck('id'))
            ->whereNotNull('competence')
            ->get(['id', 'fixed_bill_id', 'competence', 'amount', 'paid_at'])
            ->keyBy(fn ($t) => $t->fixed_bill_id.':'.CarbonImmutable::parse($t->competence)->format('Y-m'));

        $hoje = CarbonImmutable::today();
        $itens = collect();

        foreach ($bills as $bill) {
            $comeco = CarbonImmutable::parse($bill->starts_on)->startOfDay();
            $inicio = $comeco->startOfMonth();
            $fim = $bill->ends_on ? CarbonImmutable::parse($bill->ends_on)->startOfMonth() : null;

            $competencia = $de->startOfMonth()->greaterThan($inicio) ? $de->startOfMonth() : $inicio;
            $ultima = $ate->startOfMonth();
            if ($fim && $fim->lessThan($ultima)) {
                $ultima = $fim;
            }

            while ($competencia->lessThanOrEqualTo($ultima)) {
                $chave = $bill->id.':'.$competencia->format('Y-m');
                $pagamento = $pagos->get($chave);
                $vencimento = $bill->dueDateFor($competencia);

                // A PRIMEIRA competência só existe se o vencimento cair em
                // `starts_on` ou depois (auditoria 28/07/2026, A-10). Sem isto,
                // uma conta cadastrada em 20/07 com vencimento dia 5 nascia
                // "vencida em 05/07" — 15 dias antes de existir — com botão
                // Pagar de um mês que o usuário já pagou fora do app: um clique
                // e o dinheiro saía em duplicidade.
                if ($vencimento->lessThan($comeco)) {
                    $competencia = $competencia->addMonthNoOverflow();

                    continue;
                }

                $itens->push(new Fluent([
                    'bill' => $bill,
                    'competence' => $competencia,
                    'vencimento' => $vencimento,
                    'valor' => $pagamento ? (float) $pagamento->amount : (float) $bill->amount,
                    'paga' => (bool) $pagamento,
                    'pagamento' => $pagamento,
                    'vencida' => ! $pagamento && $vencimento->lessThan($hoje),
                    'diasRestantes' => (int) $hoje->diffInDays($vencimento, false),
                ]));

                $competencia = $competencia->addMonthNoOverflow();
            }
        }

        return $itens->sortBy(fn ($i) => $i['vencimento']->timestamp)->values();
    }

    /**
     * O que interessa para o sino e para a tela: a competência do mês corrente,
     * todas as passadas ainda em aberto (limitadas a `$maxMesesAtras` para uma
     * conta criada em 2020 não gerar 70 linhas) e o que vence nos próximos
     * `$diasAFrente` dias — inclusive já no mês seguinte.
     *
     * A janela à frente é o que faz o sino AVISAR (A-8). Quem consome (o
     * `FaturaService::upcomingDue`) corta de novo pela própria janela, então
     * nada aqui aparece "cedo demais" no sino.
     *
     * @return Collection<int, Fluent>
     */
    public function currentAndOverdue(
        int $ownerId,
        int $maxMesesAtras = self::MAX_MESES_ATRAS,
        int $diasAFrente = self::DIAS_A_FRENTE,
    ): Collection {
        $hoje = CarbonImmutable::today();
        $limite = $hoje->addDays(max(0, $diasAFrente));
        $mesCorrente = $hoje->startOfMonth();

        return $this->occurrences($ownerId, $hoje->subMonthsNoOverflow($maxMesesAtras), $limite)
            ->filter(function ($o) use ($mesCorrente, $limite) {
                // Competência FUTURA (mês seguinte): só entra se o vencimento
                // dela couber na janela de aviso — senão a tela "Contas fixas
                // do mês" mostraria o aluguel do dia 25 do mês que vem.
                if ($o['competence']->greaterThan($mesCorrente) && $o['vencimento']->greaterThan($limite)) {
                    return false;
                }

                // Paga: continua visível do mês corrente em diante (inclusive a
                // do mês seguinte adiantada — senão ela sumia logo após pagar,
                // como se o pagamento não tivesse acontecido). As antigas ficam
                // no extrato, não aqui.
                return ! $o['paga'] || $o['competence']->greaterThanOrEqualTo($mesCorrente);
            })
            ->values();
    }

    /** Só as vencidas (não pagas com vencimento no passado). */
    public function overdue(int $ownerId): Collection
    {
        return $this->currentAndOverdue($ownerId)->filter(fn ($o) => $o['vencida'])->values();
    }
}
