<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'initial_balance',
        // Campos exclusivos de cartão de crédito (nullable nas demais contas).
        'credit_limit',
        'closing_day',
        'due_day',
        'color',
        'icon',
    ];

    protected function casts(): array
    {
        return [
            'initial_balance' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'closing_day' => 'integer',
            'due_day' => 'integer',
        ];
    }

    /** É um cartão de crédito? (Cartão NÃO é caixa no modelo de dinheiro.) */
    public function isCard(): bool
    {
        return $this->type === 'credit_card';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function goalContributions(): HasMany
    {
        return $this->hasMany(GoalContribution::class);
    }

    public function investmentContributions(): HasMany
    {
        return $this->hasMany(InvestmentContribution::class);
    }

    /**
     * Saldo atual = saldo inicial + receitas - despesas.
     */
    public function getBalanceAttribute(): float
    {
        $income = $this->transactions()->where('type', 'income')->sum('amount');
        $expense = $this->transactions()->where('type', 'expense')->sum('amount');

        return (float) $this->initial_balance + (float) $income - (float) $expense;
    }

    /**
     * Reservado (modelo "cofrinho"): Σ aportes − Σ resgates feitos a partir
     * desta conta, somando metas E investimentos. É dinheiro "earmarked" —
     * ainda no saldo cru da conta, mas indisponível para gastar.
     */
    public function getReservedAttribute(): float
    {
        $metasAportes = $this->goalContributions()->where('type', 'aporte')->sum('amount');
        $metasResgates = $this->goalContributions()->where('type', 'resgate')->sum('amount');

        $investAportes = $this->investmentContributions()->where('type', 'aporte')->sum('amount');
        $investResgates = $this->investmentContributions()->where('type', 'resgate')->sum('amount');

        return round(
            ((float) $metasAportes - (float) $metasResgates)
            + ((float) $investAportes - (float) $investResgates),
            2,
        );
    }

    /** Disponível = saldo cru − reservado (metas + investimentos): o que sobra para gastar. */
    public function getAvailableAttribute(): float
    {
        return round($this->balance - $this->reserved, 2);
    }

    // ======================= Cartão de crédito =======================

    /**
     * Janela do ciclo de fatura ABERTO, baseada no dia de fechamento
     * (closing_day). O ciclo vai do último fechamento ≤ hoje (exclusivo) até
     * o próximo fechamento (inclusivo): (cycleStart, cycleEnd].
     *
     * Ex.: closing_day=10, hoje=18/06 → (10/06, 10/07].
     * Retorna [start, end] como CarbonImmutable (datas), ou null se não houver
     * dia de fechamento (conta que não é cartão).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function billingCycle(?CarbonImmutable $today = null): ?array
    {
        if (! $this->closing_day) {
            return null;
        }

        $today ??= CarbonImmutable::today();
        $day = max(1, min(28, (int) $this->closing_day)); // garante 1..28

        // Fechamento deste mês (sempre válido por limitar o dia a 1..28).
        $thisMonthClose = $today->startOfMonth()->day($day);

        if ($today->lessThanOrEqualTo($thisMonthClose)) {
            // Ainda não fechou neste mês: ciclo aberto = (fechamento anterior, este fechamento].
            $start = $thisMonthClose->subMonth();
            $end = $thisMonthClose;
        } else {
            // Já fechou neste mês: ciclo aberto = (este fechamento, próximo fechamento].
            $start = $thisMonthClose;
            $end = $thisMonthClose->addMonth();
        }

        return [$start, $end];
    }

    /**
     * Fatura atual: soma das despesas do cartão lançadas dentro do ciclo
     * aberto — date em (cycleStart, cycleEnd]. Zero se não for cartão.
     */
    public function getCurrentInvoiceAttribute(): float
    {
        $cycle = $this->billingCycle();
        if (! $cycle) {
            return 0.0;
        }

        [$start, $end] = $cycle;

        $total = $this->transactions()
            ->where('type', 'expense')
            ->whereDate('date', '>', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->sum('amount');

        return round((float) $total, 2);
    }

    /**
     * Comprometido: soma das parcelas em aberto do cartão — toda despesa com
     * date ≥ início do ciclo atual (inclui parcelas futuras já agendadas).
     * É o que está "preso" do limite. Zero se não for cartão.
     */
    public function getCommittedAttribute(): float
    {
        $cycle = $this->billingCycle();
        if (! $cycle) {
            return 0.0;
        }

        [$start] = $cycle;

        $total = $this->transactions()
            ->where('type', 'expense')
            ->whereDate('date', '>=', $start->toDateString())
            ->sum('amount');

        return round((float) $total, 2);
    }

    /** Limite disponível = limite total − comprometido (nunca abaixo de zero na exibição). */
    public function getAvailableLimitAttribute(): float
    {
        return round((float) $this->credit_limit - $this->committed, 2);
    }

    /**
     * Próximo vencimento da fatura, a partir do due_day. Retorna a próxima
     * data com esse dia ≥ hoje, ou null se não for cartão / sem due_day.
     */
    public function getDueDateAttribute(): ?CarbonImmutable
    {
        if (! $this->due_day) {
            return null;
        }

        $today = CarbonImmutable::today();
        $day = max(1, min(28, (int) $this->due_day));
        $thisMonthDue = $today->startOfMonth()->day($day);

        return $today->lessThanOrEqualTo($thisMonthDue)
            ? $thisMonthDue
            : $thisMonthDue->addMonth();
    }
}
