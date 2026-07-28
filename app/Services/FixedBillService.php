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
            ->keyBy(fn ($t) => $t->fixed_bill_id . ':' . CarbonImmutable::parse($t->competence)->format('Y-m'));

        $hoje = CarbonImmutable::today();
        $itens = collect();

        foreach ($bills as $bill) {
            $inicio = CarbonImmutable::parse($bill->starts_on)->startOfMonth();
            $fim = $bill->ends_on ? CarbonImmutable::parse($bill->ends_on)->startOfMonth() : null;

            $competencia = $de->startOfMonth()->greaterThan($inicio) ? $de->startOfMonth() : $inicio;
            $ultima = $ate->startOfMonth();
            if ($fim && $fim->lessThan($ultima)) {
                $ultima = $fim;
            }

            while ($competencia->lessThanOrEqualTo($ultima)) {
                $chave = $bill->id . ':' . $competencia->format('Y-m');
                $pagamento = $pagos->get($chave);
                $vencimento = $bill->dueDateFor($competencia);

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
     * O que interessa para o sino e para a tela: a competência do mês corrente
     * e todas as passadas ainda em aberto (limitadas a `$maxMesesAtras` para
     * uma conta criada em 2020 não gerar 70 linhas).
     *
     * @return Collection<int, Fluent>
     */
    public function currentAndOverdue(int $ownerId, int $maxMesesAtras = self::MAX_MESES_ATRAS): Collection
    {
        $hoje = CarbonImmutable::today();

        return $this->occurrences($ownerId, $hoje->subMonthsNoOverflow($maxMesesAtras), $hoje)
            ->filter(fn ($o) => ! $o['paga'] || $o['competence']->isSameMonth($hoje))
            ->values();
    }

    /** Só as vencidas (não pagas com vencimento no passado). */
    public function overdue(int $ownerId): Collection
    {
        return $this->currentAndOverdue($ownerId)->filter(fn ($o) => $o['vencida'])->values();
    }
}
