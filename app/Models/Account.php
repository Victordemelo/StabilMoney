<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Fluent;

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
        // Cheque especial: quanto o saldo pode ficar negativo (só conta corrente).
        'overdraft_limit',
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
            'overdraft_limit' => 'decimal:2',
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

    /** É conta de caixa — tem saldo próprio e pode ter cheque especial. */
    public function isCash(): bool
    {
        return in_array($this->type, ['checking', 'savings'], true);
    }

    /**
     * Métodos de pagamento para os selects de lançamento, escopados na família.
     *
     * Contas de caixa e cartões de crédito entram com o próprio id. O cartão de
     * DÉBITO entra com o rótulo dele, mas o `id` submetido é o da conta que ele
     * espelha — é de lá que o dinheiro sai de verdade. Sem isso, escolher o
     * cartão de débito gravava a despesa numa conta sem saldo próprio e o
     * dinheiro não descontava de lugar nenhum.
     *
     * Cartão de débito sem vínculo é omitido: não há de onde tirar o dinheiro.
     *
     * @return \Illuminate\Support\Collection<int, \Illuminate\Support\Fluent>
     */
    public static function paymentOptions(int $ownerId): Collection
    {
        $contas = self::where('user_id', $ownerId)->orderBy('name')->get();

        return $contas
            ->map(function (Account $conta) use ($contas) {
                if (! $conta->isDebit()) {
                    return new Fluent([
                        'id' => $conta->id,
                        'name' => $conta->name,
                        'icon' => $conta->icon,
                        'type' => $conta->type,
                        'isCard' => $conta->isCard(),
                    ]);
                }

                $destinoId = $conta->checking_account_id ?? $conta->savings_account_id;
                $destino = $destinoId ? $contas->firstWhere('id', $destinoId) : null;

                if (! $destino) {
                    return null; // cartão órfão: nada a debitar
                }

                return new Fluent([
                    'id' => $destino->id,
                    'name' => $conta->name . ' → ' . $destino->name,
                    'icon' => $conta->icon,
                    'type' => $conta->type,
                    'isCard' => false,
                ]);
            })
            ->filter()
            ->values();
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

    /**
     * Disponível = saldo cru − reservado (metas + investimentos).
     *
     * É ISTO que o usuário chama de "meu saldo": o dinheiro livre, já fora o que
     * está guardado em metas e aplicado em investimentos. É este número que pode
     * ficar negativo (até o limite do cheque especial) e que aparece em vermelho.
     */
    public function getAvailableAttribute(): float
    {
        return round($this->balance - $this->reserved, 2);
    }

    // ======================= Cheque especial =======================

    /**
     * Limite efetivo do cheque especial. Zero fora de conta corrente: poupança
     * não tem cheque especial no Brasil, cartão de crédito tem `credit_limit` e
     * cartão de débito não tem saldo próprio.
     */
    public function getOverdraftLimitValueAttribute(): float
    {
        return $this->type === 'checking' ? round((float) $this->overdraft_limit, 2) : 0.0;
    }

    /** Quanto do cheque especial já está sendo usado (0 enquanto o disponível é positivo). */
    public function getOverdraftUsedAttribute(): float
    {
        return round(max(0.0, -$this->available), 2);
    }

    /** Quanto ainda resta do cheque especial. */
    public function getOverdraftAvailableAttribute(): float
    {
        return round(max(0.0, $this->overdraftLimitValue - $this->overdraftUsed), 2);
    }

    /**
     * Teto de uma despesa nesta conta SEM tocar em investimento: o disponível
     * que ainda existe mais o cheque especial que ainda resta.
     */
    public function getSpendableAttribute(): float
    {
        return round(max(0.0, $this->available) + $this->overdraftAvailable, 2);
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
     * Comprometido: tudo que o cartão deve e AINDA NÃO FOI PAGO — inclusive as
     * parcelas futuras já agendadas. É o que está "preso" do limite.
     *
     * A liberação é dirigida pelo PAGAMENTO (paid_at), não pela passagem do
     * tempo: numa compra em 6x, o comprometido cai R$ 1 parcela a cada fatura
     * paga. Antes o filtro era por data (date ≥ início do ciclo), o que
     * devolvia limite a quem NUNCA pagava — bastava o ciclo virar — e não
     * devolvia nada a quem pagava.
     *
     * Zero se não for cartão de crédito.
     */
    public function getCommittedAttribute(): float
    {
        return $this->committedCache ??= (function (): float {
            if (! $this->isCard()) {
                return 0.0;
            }

            $total = $this->transactions()
                ->where('type', 'expense')
                ->whereNull('paid_at')
                ->sum('amount');

            return round((float) $total, 2);
        })();
    }

    /**
     * Limite disponível REAL — pode ser negativo quando o cartão está estourado.
     * Quem exibe é que clampa em zero; esconder o estouro aqui impedia validar
     * o limite no lançamento.
     */
    public function getAvailableLimitAttribute(): float
    {
        return round((float) $this->credit_limit - $this->committed, 2);
    }

    /** Limite disponível para EXIBIÇÃO (nunca negativo). */
    public function getAvailableLimitDisplayAttribute(): float
    {
        return max(0.0, $this->availableLimit);
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
     * Vencimento DERIVADO do fechamento de um ciclo: a fatura que fecha em
     * `$cycleEnd` vence no próximo dia `due_day` DEPOIS dela.
     *
     * Se `due_day > closing_day`, o vencimento cai no mesmo mês do fechamento
     * (fecha 10, vence 20); senão, no mês seguinte (fecha 20, vence 5).
     *
     * Antes o vencimento era calculado ignorando o ciclo, o que exibia datas
     * ANTERIORES ao fechamento da fatura que elas deveriam pagar.
     */
    public function dueDateForCycle(CarbonImmutable $cycleEnd): ?CarbonImmutable
    {
        if (! $this->due_day) {
            return null;
        }

        $day = max(1, (int) $this->due_day);

        return $day > (int) $this->closing_day
            ? $this->dayInMonth($cycleEnd, $day)
            : $this->dayInMonth($cycleEnd->startOfMonth()->addMonth(), $day);
    }

    /**
     * Vencimento da fatura do ciclo ABERTO (a próxima a fechar). Null se não
     * for cartão ou não tiver dia de vencimento.
     */
    public function getDueDateAttribute(): ?CarbonImmutable
    {
        $cycle = $this->billingCycle();

        return $cycle ? $this->dueDateForCycle($cycle[1]) : null;
    }

    /**
     * Ciclo imediatamente ANTERIOR ao aberto — a fatura que já fechou e deveria
     * ter sido paga. Calculado a partir do startOfMonth, nunca com subMonth()
     * sobre uma data já posicionada no dia X (estouraria mês curto).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function closedCycle(?CarbonImmutable $today = null): ?array
    {
        $cycle = $this->billingCycle($today);
        if (! $cycle) {
            return null;
        }

        [$start] = $cycle;
        $day = max(1, (int) $this->closing_day);

        return [$this->dayInMonth($start->startOfMonth()->subMonth(), $day), $start];
    }

    private ?float $closedInvoiceDueCache = null;

    /**
     * Fatura do ciclo JÁ FECHADO que continua sem pagamento. Sem isto, a dívida
     * do mês anterior sumia da tela no dia em que o ciclo virava: o
     * `openInvoiceDue` só enxerga o ciclo aberto.
     */
    public function getClosedInvoiceDueAttribute(): float
    {
        return $this->closedInvoiceDueCache ??= (function (): float {
            $cycle = $this->closedCycle();
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
     * Fatura VENCIDA: existe dívida do ciclo fechado e o vencimento dela já
     * passou. Null quando está tudo em dia.
     *
     * @return array{valor: float, vencimento: CarbonImmutable, diasAtraso: int}|null
     */
    public function getOverdueInvoiceAttribute(): ?array
    {
        $valor = $this->closedInvoiceDue;
        if ($valor <= 0.001) {
            return null;
        }

        $cycle = $this->closedCycle();
        $vencimento = $cycle ? $this->dueDateForCycle($cycle[1]) : null;
        $hoje = CarbonImmutable::today();

        if (! $vencimento || $vencimento->greaterThanOrEqualTo($hoje)) {
            return null; // fechou, mas ainda está no prazo
        }

        return [
            'valor' => $valor,
            'vencimento' => $vencimento,
            'diasAtraso' => (int) $vencimento->diffInDays($hoje),
        ];
    }
}
