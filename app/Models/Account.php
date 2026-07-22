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
        'bank',
        // Cartão de débito: contas que ele espelha (saldo = soma das vinculadas).
        'checking_account_id',
        'savings_account_id',
        'initial_balance',
        // Campos exclusivos de cartão de crédito (nullable nas demais contas).
        'credit_limit',
        'closing_day',
        'due_day',
        'color',
        'icon',
    ];

    /** Tipos oferecidos no cadastro (valor no banco => rótulo PT-BR). */
    public const TYPES = [
        'checking' => 'Conta Corrente',
        'savings' => 'Conta Poupança',
        'debit_card' => 'Cartão de Débito',
        'credit_card' => 'Cartão de Crédito',
    ];

    /** Bancos suportados (valor => rótulo). A imagem é `public/assets/banks/{valor}.png`. */
    public const BANKS = [
        'banco_do_brasil' => 'Banco do Brasil',
        'bradesco' => 'Bradesco',
        'caixa' => 'Caixa',
        'inter' => 'Inter',
        'itau' => 'Itaú',
        'mercado_pago' => 'Mercado Pago',
        'nubank' => 'Nubank',
        'santander' => 'Santander',
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

    /** É um cartão de débito? (Espelha o saldo das contas vinculadas.) */
    public function isDebit(): bool
    {
        return $this->type === 'debit_card';
    }

    /** Rótulo PT-BR do tipo (ex.: "Conta Corrente"). */
    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /** Rótulo PT-BR do banco, ou null se não houver. */
    public function bankLabel(): ?string
    {
        return self::BANKS[$this->bank] ?? null;
    }

    /** URL pública da imagem do cartão do banco, ou null se sem banco. */
    public function bankImageUrl(): ?string
    {
        return isset(self::BANKS[$this->bank]) ? asset("assets/banks/{$this->bank}.png") : null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Conta corrente vinculada (cartão de débito). */
    public function linkedChecking(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'checking_account_id');
    }

    /** Conta poupança vinculada (cartão de débito). */
    public function linkedSavings(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'savings_account_id');
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

    // Caches por-instância dos accessors que disparam queries (sum). Memoizam
    // o resultado na 1ª chamada — evita N+1 quando a view chama o mesmo accessor
    // várias vezes. Como cada instância recém-consultada nasce com null, não há
    // risco de valor velho entre requests.
    private ?float $balanceCache = null;

    private ?float $reservedCache = null;

    private ?float $currentInvoiceCache = null;

    private ?float $committedCache = null;

    /**
     * Saldo atual. Cartão de débito ESPELHA as contas vinculadas (corrente +
     * poupança) — não tem saldo próprio. As demais: saldo inicial + receitas − despesas.
     */
    public function getBalanceAttribute(): float
    {
        return $this->balanceCache ??= (function (): float {
            if ($this->isDebit()) {
                return round($this->checkingBalance + $this->savingsBalance, 2);
            }

            $income = $this->transactions()->where('type', 'income')->sum('amount');
            $expense = $this->transactions()->where('type', 'expense')->sum('amount');

            return round((float) $this->initial_balance + (float) $income - (float) $expense, 2);
        })();
    }

    /** Saldo da conta corrente vinculada (0 se não houver) — para o cartão de débito. */
    public function getCheckingBalanceAttribute(): float
    {
        return $this->linkedChecking ? $this->linkedChecking->balance : 0.0;
    }

    /** Saldo da conta poupança vinculada (0 se não houver) — para o cartão de débito. */
    public function getSavingsBalanceAttribute(): float
    {
        return $this->linkedSavings ? $this->linkedSavings->balance : 0.0;
    }

    /**
     * Reservado (modelo "cofrinho"): Σ aportes − Σ resgates feitos a partir
     * desta conta, somando metas E investimentos. É dinheiro "earmarked" —
     * ainda no saldo cru da conta, mas indisponível para gastar.
     */
    public function getReservedAttribute(): float
    {
        return $this->reservedCache ??= (function (): float {
            $metasAportes = $this->goalContributions()->where('type', 'aporte')->sum('amount');
            $metasResgates = $this->goalContributions()->where('type', 'resgate')->sum('amount');

            $investAportes = $this->investmentContributions()->where('type', 'aporte')->sum('amount');
            $investResgates = $this->investmentContributions()->where('type', 'resgate')->sum('amount');

            return round(
                ((float) $metasAportes - (float) $metasResgates)
                + ((float) $investAportes - (float) $investResgates),
                2,
            );
        })();
    }

    /** Disponível = saldo cru − reservado (metas + investimentos): o que sobra para gastar. */
    public function getAvailableAttribute(): float
    {
        return round($this->balance - $this->reserved, 2);
    }

    // ======================= Cartão de crédito =======================

    /**
     * Retorna a data do dia `$day` no mês de `$monthAnchor`, clampando ao último
     * dia do mês (ex.: dia 31 em fevereiro vira 28/29). Evita o estouro do Carbon
     * ao posicionar um dia inexistente num mês curto.
     */
    private function dayInMonth(CarbonImmutable $monthAnchor, int $day): CarbonImmutable
    {
        $start = $monthAnchor->startOfMonth();
        $d = max(1, min($day, $start->daysInMonth));

        return $start->day($d);
    }

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
        $day = max(1, (int) $this->closing_day);

        // Fechamento deste mês, clampado ao último dia do mês quando o dia não existe
        // (ex.: dia 31 em fevereiro). Cada borda é calculada a partir do startOfMonth
        // do mês-alvo — nunca via addMonth()/subMonth() sobre uma data já no dia X,
        // pois isso estouraria o mês curto.
        $thisMonthClose = $this->dayInMonth($today, $day);

        if ($today->lessThanOrEqualTo($thisMonthClose)) {
            // Ainda não fechou neste mês: ciclo aberto = (fechamento anterior, este fechamento].
            $start = $this->dayInMonth($today->startOfMonth()->subMonth(), $day);
            $end = $thisMonthClose;
        } else {
            // Já fechou neste mês: ciclo aberto = (este fechamento, próximo fechamento].
            $start = $thisMonthClose;
            $end = $this->dayInMonth($today->startOfMonth()->addMonth(), $day);
        }

        return [$start, $end];
    }

    /**
     * Fatura atual: soma das despesas do cartão lançadas dentro do ciclo
     * aberto — date em (cycleStart, cycleEnd]. Zero se não for cartão.
     */
    public function getCurrentInvoiceAttribute(): float
    {
        return $this->currentInvoiceCache ??= (function (): float {
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
        })();
    }

    /**
     * Comprometido: soma das parcelas em aberto do cartão — toda despesa com
     * date ≥ início do ciclo atual (inclui parcelas futuras já agendadas).
     * É o que está "preso" do limite. Zero se não for cartão.
     */
    public function getCommittedAttribute(): float
    {
        return $this->committedCache ??= (function (): float {
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
        })();
    }

    /** Limite disponível = limite total − comprometido (nunca abaixo de zero na exibição). */
    public function getAvailableLimitAttribute(): float
    {
        return round(max(0.0, (float) $this->credit_limit - $this->committed), 2);
    }

    private ?float $openInvoiceDueCache = null;

    /**
     * Fatura EM ABERTO (a pagar) do ciclo atual: soma das despesas do ciclo que
     * ainda NÃO foram pagas (paid_at null). Só cartão de crédito. Ao "pagar a
     * fatura", essas despesas ganham paid_at e este valor zera.
     */
    public function getOpenInvoiceDueAttribute(): float
    {
        return $this->openInvoiceDueCache ??= (function (): float {
            $cycle = $this->billingCycle();
            if (! $cycle) {
                return 0.0;
            }

            [$start, $end] = $cycle;

            $total = $this->transactions()
                ->where('type', 'expense')
                ->whereNull('paid_at')
                ->whereDate('date', '>', $start->toDateString())
                ->whereDate('date', '<=', $end->toDateString())
                ->sum('amount');

            return round((float) $total, 2);
        })();
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
        $day = max(1, (int) $this->due_day);

        // Vencimento deste mês, clampado ao último dia do mês quando o dia não existe
        // (ex.: dia 31 em fevereiro). O ramo do próximo mês parte do startOfMonth,
        // nunca de uma data já no dia X (evita estouro de mês curto).
        $thisMonthDue = $this->dayInMonth($today, $day);

        return $today->lessThanOrEqualTo($thisMonthDue)
            ? $thisMonthDue
            : $this->dayInMonth($today->startOfMonth()->addMonth(), $day);
    }
}
