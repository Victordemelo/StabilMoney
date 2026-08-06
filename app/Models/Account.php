<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
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
        'pix' => 'Pix',
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
     * "Classe" do tipo — o que define COMO o dinheiro é calculado:
     *
     * - `caixa`   (checking/savings): saldo próprio = inicial + receitas − despesas;
     * - `credito` (credit_card):      não é caixa, tem limite/fatura/ciclo;
     * - `debito`  (debit_card, pix):  não tem saldo próprio, espelha as vinculadas.
     *
     * Pix entra em `debito` de propósito: do ponto de vista de quem paga, os dois
     * são a mesma coisa — o dinheiro sai da conta na hora e, se o saldo zerar, o
     * cheque especial entra automaticamente. A diferença (liquidação instantânea
     * × D+1, rede de cartão × transferência do BC) é do LOJISTA, não de quem
     * controla o próprio dinheiro. Estar na mesma classe também permite converter
     * um método no outro sem a `travaDeClasse` reclamar: nenhum dos dois tem saldo
     * próprio, então não há dinheiro para sumir na troca.
     *
     * Trocar de classe muda a fórmula do dinheiro. Numa conta que já tem
     * histórico isso faz saldo sumir (ou contar em dobro) — ver `travaDeClasse()`.
     */
    public static function classeDoTipo(?string $type): string
    {
        return match ($type) {
            'checking', 'savings' => 'caixa',
            'credit_card' => 'credito',
            'debit_card', 'pix' => 'debito',
            default => 'desconhecida',
        };
    }

    /** Classe desta conta (ver `classeDoTipo`). */
    public function classe(): string
    {
        return self::classeDoTipo($this->type);
    }

    /**
     * A conta já carrega dinheiro? (transações, aportes/resgates de metas ou de
     * investimentos, ou um saldo inicial informado).
     *
     * É a mesma linha de defesa do `destroy` no controller, reaproveitada para
     * proibir a troca de CLASSE de tipo: as duas operações destruiriam dinheiro
     * que já existe.
     */
    public function hasMoneyHistory(): bool
    {
        return (float) $this->initial_balance > 0
            || $this->transactions()->exists()
            || $this->goalContributions()->exists()
            || $this->investmentContributions()->exists();
    }

    /**
     * Mensagem PT-BR quando a troca de tipo é proibida, ou null quando pode.
     *
     * Mudar de "Conta Corrente" para "Conta Poupança" é inofensivo (mesma
     * classe, mesma fórmula). Mudar de conta para cartão (ou vice-versa) numa
     * conta com histórico não é: o `initial_balance` viraria NULL e as
     * transações ficariam órfãs, ou as despesas do ex-cartão passariam a
     * descontar do patrimônio (e a fatura já paga, duas vezes).
     */
    public function travaDeClasse(?string $novoTipo): ?string
    {
        if ($novoTipo === null || self::classeDoTipo($novoTipo) === $this->classe()) {
            return null;
        }

        if (! $this->hasMoneyHistory()) {
            return null;
        }

        return 'Não dá para mudar o tipo de "' . $this->name . '" de ' . $this->typeLabel()
            . ' para ' . (self::TYPES[$novoTipo] ?? $novoTipo) . ': esta conta já tem saldo, '
            . 'lançamentos ou dinheiro guardado, e a troca faria esse dinheiro desaparecer '
            . '(ou ser contado duas vezes). Crie um novo método de pagamento e mova o histórico, '
            . 'se for o caso.';
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
                if (! $conta->espelhaConta()) {
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

    /**
     * É Pix? Uma chave Pix é registrada em UMA conta (CPF, telefone, e-mail ou
     * chave aleatória apontam para uma conta só), ao contrário do cartão de
     * débito, que pode sacar da corrente e da poupança.
     */
    public function isPix(): bool
    {
        return $this->type === 'pix';
    }

    /**
     * Método ESPELHO: não tem saldo próprio, o dinheiro é o da(s) conta(s)
     * vinculada(s). Cartão de débito e Pix.
     *
     * É esta a pergunta que o cálculo de dinheiro precisa fazer — não "é débito?".
     * Todo lugar que somava saldo, montou o select de pagamento ou excluiu do
     * patrimônio usava `isDebit()`; com o Pix, usar isso significaria contar o
     * mesmo dinheiro duas vezes no patrimônio.
     */
    public function espelhaConta(): bool
    {
        return $this->isDebit() || $this->isPix();
    }

    /** A conta que este método espelha, quando é Pix (a chave vive em UMA conta). */
    public function contaDoPix(): ?self
    {
        return $this->linkedChecking ?? $this->linkedSavings;
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
     * `refresh()` recarrega a linha do banco — então os caches de dinheiro
     * também precisam morrer. Sem isto, `$conta->balance; ...; $conta->refresh();`
     * devolvia o valor VELHO (só `fresh()`, que cria outra instância, funcionava)
     * — armadilha silenciosa em qualquer teste ou fluxo que edita e relê.
     */
    public function refresh(): static
    {
        parent::refresh();

        return $this->forgetMoneyCache();
    }

    /**
     * Pré-carrega, em 4 queries agregadas, o dinheiro de uma lista de contas —
     * em vez das ~6 queries POR CONTA que os accessors disparam (2 SUM em
     * `balance`, 4 em `reserved`, 1 em `committed`). Com 30 contas a tela de
     * métodos de pagamento saía de 195 para pouco mais de 10 queries.
     *
     * Só preenche os CACHES dos accessors: a fórmula do dinheiro continua uma
     * só, nos accessors. Quem não for pré-carregado calcula normalmente.
     *
     * As contas vinculadas já carregadas (cartão de débito → corrente/poupança)
     * entram no lote, senão o débito voltaria a consultar uma por uma.
     *
     * @param  iterable<int, Account>  $contas
     */
    public static function preloadMoney(iterable $contas): void
    {
        /** @var array<int, list<Account>> $porId */
        $porId = [];

        foreach ($contas as $conta) {
            if (! $conta instanceof self || ! $conta->exists) {
                continue;
            }

            $porId[$conta->id][] = $conta;

            foreach (['linkedChecking', 'linkedSavings'] as $relacao) {
                $vinculada = $conta->relationLoaded($relacao) ? $conta->getRelation($relacao) : null;
                if ($vinculada instanceof self && $vinculada->exists) {
                    $porId[$vinculada->id][] = $vinculada;
                }
            }
        }

        if ($porId === []) {
            return;
        }

        $ids = array_keys($porId);

        // Receitas − despesas por conta (1 query).
        $deltas = Transaction::whereIn('account_id', $ids)
            ->groupBy('account_id')
            ->selectRaw('account_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE -amount END), 0) AS total")
            ->pluck('total', 'account_id');

        // Comprometido do cartão: tudo que ainda não foi pago, estornos abatendo (1 query).
        $comprometidos = Transaction::whereIn('account_id', $ids)
            ->whereNull('paid_at')
            ->groupBy('account_id')
            ->selectRaw('account_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE -amount END), 0) AS total")
            ->pluck('total', 'account_id');

        // Reservado: metas e investimentos, aportes − resgates (2 queries).
        $metas = GoalContribution::whereIn('account_id', $ids)
            ->groupBy('account_id')
            ->selectRaw('account_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'aporte' THEN amount ELSE -amount END), 0) AS total")
            ->pluck('total', 'account_id');

        $investimentos = InvestmentContribution::whereIn('account_id', $ids)
            ->groupBy('account_id')
            ->selectRaw('account_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'aporte' THEN amount ELSE -amount END), 0) AS total")
            ->pluck('total', 'account_id');

        foreach ($porId as $id => $instancias) {
            foreach ($instancias as $conta) {
                // Método espelho (débito/Pix) não tem saldo próprio: o accessor
                // soma as vinculadas (já com o cache preenchido, sem query).
                if (! $conta->espelhaConta()) {
                    $conta->balanceCache = round(
                        (float) $conta->initial_balance + (float) ($deltas[$id] ?? 0),
                        2,
                    );
                }

                $conta->reservedCache = round(
                    (float) ($metas[$id] ?? 0) + (float) ($investimentos[$id] ?? 0),
                    2,
                );

                $conta->committedCache = $conta->isCard()
                    ? max(0.0, round((float) ($comprometidos[$id] ?? 0), 2))
                    : 0.0;
            }
        }
    }

    /** Esquece os valores de dinheiro memoizados nesta instância. */
    public function forgetMoneyCache(): static
    {
        $this->balanceCache = null;
        $this->reservedCache = null;
        $this->currentInvoiceCache = null;
        $this->committedCache = null;
        $this->openInvoiceDueCache = null;
        $this->closedInvoiceDueCache = null;

        return $this;
    }

    /**
     * Saldo atual. Cartão de débito ESPELHA as contas vinculadas (corrente +
     * poupança) — não tem saldo próprio. As demais: saldo inicial + receitas − despesas.
     */
    public function getBalanceAttribute(): float
    {
        return $this->balanceCache ??= (function (): float {
            // Débito soma as duas vinculadas; no Pix só uma existe, então a outra
            // entra como 0 e a mesma conta serve para os dois.
            if ($this->espelhaConta()) {
                return round($this->checkingBalance + $this->savingsBalance, 2);
            }

            $income = $this->transactions()->where('type', 'income')->sum('amount');
            $expense = $this->transactions()->where('type', 'expense')->sum('amount');

            return round((float) $this->initial_balance + (float) $income - (float) $expense, 2);
        })();
    }

    /** Saldo BRUTO da conta corrente vinculada (0 se não houver) — para o cartão de débito. */
    public function getCheckingBalanceAttribute(): float
    {
        return $this->linkedChecking ? $this->linkedChecking->balance : 0.0;
    }

    /** Saldo BRUTO da conta poupança vinculada (0 se não houver) — para o cartão de débito. */
    public function getSavingsBalanceAttribute(): float
    {
        return $this->linkedSavings ? $this->linkedSavings->balance : 0.0;
    }

    /**
     * DISPONÍVEL da conta corrente vinculada — é este o número que a UI mostra
     * (o bruto inclui o que está guardado em metas/investimentos, que não é
     * para gastar). Ver "regra de ouro da UI" no CLAUDE.md.
     */
    public function getAvailableCheckingAttribute(): float
    {
        return $this->linkedChecking ? $this->linkedChecking->available : 0.0;
    }

    /** DISPONÍVEL da conta poupança vinculada (0 se não houver). */
    public function getAvailableSavingsAttribute(): float
    {
        return $this->linkedSavings ? $this->linkedSavings->available : 0.0;
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
        // Método espelho (débito/Pix) não tem saldo próprio: espelha o DISPONÍVEL
        // das vinculadas. Espelhar o bruto mostrava, no método, dinheiro que já
        // estava aplicado num investimento — R$ 6.000 onde havia R$ 4.000.
        if ($this->espelhaConta()) {
            return round($this->availableChecking + $this->availableSavings, 2);
        }

        return round($this->balance - $this->reserved, 2);
    }

    /**
     * Disponível ignorando `$ignore` — o valor ANTIGO da própria transação numa
     * edição. Sem isso, editar uma despesa de R$ 2.000 para R$ 2.100 era
     * avaliada como se a de R$ 2.000 continuasse ocupando o saldo.
     */
    public function availableWith(float $ignore = 0.0): float
    {
        return round($this->available + $ignore, 2);
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
        return $this->overdraftUsedWith();
    }

    /** Idem, ignorando `$ignore` (valor antigo da transação em edição). */
    public function overdraftUsedWith(float $ignore = 0.0): float
    {
        return round(max(0.0, -$this->availableWith($ignore)), 2);
    }

    /** Quanto ainda resta do cheque especial. */
    public function getOverdraftAvailableAttribute(): float
    {
        return $this->overdraftAvailableWith();
    }

    /**
     * Idem, ignorando `$ignore`. Sem o ignore aqui, editar uma despesa que já
     * usava o cheque especial era recusada indevidamente: a própria despesa
     * sendo editada contava como cheque especial "já gasto".
     */
    public function overdraftAvailableWith(float $ignore = 0.0): float
    {
        return round(max(0.0, $this->overdraftLimitValue - $this->overdraftUsedWith($ignore)), 2);
    }

    /**
     * Teto de uma despesa nesta conta SEM tocar em investimento: o disponível
     * que ainda existe mais o cheque especial que ainda resta.
     */
    public function getSpendableAttribute(): float
    {
        return $this->spendableWith();
    }

    /** Idem, ignorando `$ignore` (valor antigo da transação em edição). */
    public function spendableWith(float $ignore = 0.0): float
    {
        return round(max(0.0, $this->availableWith($ignore)) + $this->overdraftAvailableWith($ignore), 2);
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
     * Soma ASSINADA das transações de um cartão: despesa entra positiva (é
     * dívida com o cartão) e receita entra negativa (é ESTORNO — devolve
     * limite e abate a fatura).
     *
     * Antes somava só `type = 'expense'`: um estorno de R$ 300 no cartão não
     * devolvia limite, não abatia a fatura e não entrava em saldo nenhum — mas
     * aparecia como "receita do mês" no dashboard. Receita que não existia em
     * bolso algum.
     *
     * O piso em 0 fica em quem chama: estorno maior que a dívida zera a fatura,
     * não vira crédito nem aumenta o limite acima do teto do cartão.
     */
    private function somaAssinadaDoCartao(HasMany|EloquentBuilder $query): float
    {
        $total = $query
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE -amount END), 0) AS total")
            ->value('total');

        return round((float) $total, 2);
    }

    /**
     * Fatura atual: soma das despesas do cartão lançadas dentro do ciclo
     * aberto — date em (cycleStart, cycleEnd], abatidos os estornos do mesmo
     * ciclo. Zero se não for cartão.
     */
    public function getCurrentInvoiceAttribute(): float
    {
        return $this->currentInvoiceCache ??= (function (): float {
            $cycle = $this->billingCycle();
            if (! $cycle) {
                return 0.0;
            }

            [$start, $end] = $cycle;

            return max(0.0, $this->somaAssinadaDoCartao(
                $this->transactions()
                    ->where('date', '>', $start->toDateString())
                    ->where('date', '<=', $end->toDateString())
            ));
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

            // Estornos ainda não "usados" (paid_at null) abatem o comprometido:
            // o limite volta na hora, como no cartão de verdade.
            return max(0.0, $this->somaAssinadaDoCartao(
                $this->transactions()->whereNull('paid_at')
            ));
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

            return max(0.0, $this->somaAssinadaDoCartao(
                $this->transactions()
                    ->whereNull('paid_at')
                    ->where('date', '>', $start->toDateString())
                    ->where('date', '<=', $end->toDateString())
            ));
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
     * Piso da janela do "já fechado". Não é um ciclo: é uma data anterior a
     * qualquer lançamento possível, usada como início aberto do intervalo.
     */
    private const INICIO_DOS_TEMPOS = '1970-01-01';

    /**
     * Janela de TUDO que já fechou e continua em aberto — não apenas o mês
     * anterior. Vai do início dos tempos (exclusivo) até o início do ciclo
     * aberto (inclusivo).
     *
     * ⚠️ Apesar do nome, isto NÃO é "um ciclo". Antes era: `[fechamento de dois
     * meses atrás, fechamento anterior]`, exatamente um mês para trás. Com dois
     * ou mais ciclos sem pagar (fecha dia 10, hoje 15/09, compras de 15/06 e
     * 15/07), as compras mais antigas não caíam nem no ciclo aberto nem no
     * fechado: sumiam de `/faturas`, sumiam do sino, não tinham caminho de
     * pagamento — e continuavam comendo o limite para sempre, porque
     * `committed` conta tudo que não foi pago, sem olhar data.
     *
     * No cartão de verdade o saldo não pago ROLA para a fatura seguinte. Esta
     * janela é essa rolagem: a "fatura fechada" é a dívida inteira já fechada.
     * O FIM continua sendo o início do ciclo aberto, então `dueDateForCycle()`
     * segue devolvendo o vencimento da última fatura fechada — o vencimento que
     * de fato interessa a quem vai pagar hoje.
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

        return [CarbonImmutable::parse(self::INICIO_DOS_TEMPOS), $start];
    }

    private ?float $closedInvoiceDueCache = null;

    /**
     * Fatura JÁ FECHADA que continua sem pagamento — a dívida acumulada de
     * todos os ciclos que já viraram, não só a do mês passado (ver
     * `closedCycle()`). Sem isto, a dívida sumia da tela no dia em que o ciclo
     * virava: o `openInvoiceDue` só enxerga o ciclo aberto.
     */
    public function getClosedInvoiceDueAttribute(): float
    {
        return $this->closedInvoiceDueCache ??= (function (): float {
            $cycle = $this->closedCycle();
            if (! $cycle) {
                return 0.0;
            }

            [$start, $end] = $cycle;

            return max(0.0, $this->somaAssinadaDoCartao(
                $this->transactions()
                    ->whereNull('paid_at')
                    ->where('date', '>', $start->toDateString())
                    ->where('date', '<=', $end->toDateString())
            ));
        })();
    }

    /**
     * Vencimento da fatura fechada MAIS ANTIGA que continua sem pagamento —
     * derivado da despesa em aberto mais antiga dentro da janela.
     *
     * É esta data que responde "desde quando estou devendo?". Usar o vencimento
     * da janela consolidada (a última fatura a fechar) dizia que uma dívida de
     * junho estava "em dia" só porque a fatura de setembro fecha dia 10 e vence
     * dia 20 — entre esses dois dias, três meses de atraso apareciam como zero.
     *
     * @param  CarbonImmutable  $fimDaJanela  o fechamento mais recente (fim do `closedCycle`)
     */
    private function vencimentoMaisAntigoEmAberto(CarbonImmutable $fimDaJanela): ?CarbonImmutable
    {
        if (! $this->due_day || ! $this->closing_day) {
            return null;
        }

        $primeira = $this->transactions()
            ->whereNull('paid_at')
            ->where('type', 'expense')
            ->where('date', '<=', $fimDaJanela->toDateString())
            ->min('date');

        if (! $primeira) {
            return null;
        }

        $data = CarbonImmutable::parse($primeira);
        $day = max(1, (int) $this->closing_day);

        // Ciclo é (start, end]: a compra pertence ao PRIMEIRO fechamento >= a data dela.
        $fechamento = $this->dayInMonth($data, $day);
        if ($data->greaterThan($fechamento)) {
            $fechamento = $this->dayInMonth($data->startOfMonth()->addMonth(), $day);
        }

        return $this->dueDateForCycle($fechamento);
    }

    /**
     * Fatura FECHADA em aberto: quanto se deve do que já fechou, quando vence e
     * se o vencimento já passou. Null quando não há dívida fechada.
     *
     * É o que a tela precisa para oferecer "pagar a fatura fechada". Uma fatura
     * que fechou dia 10 e vence dia 20 é pagável desde o dia 10 — esperar ela
     * vencer para dar um botão seria transformar a UI em multa.
     *
     * @return array{valor: float, vencimento: ?CarbonImmutable, vencida: bool, diasAtraso: int}|null
     */
    public function getClosedInvoiceAttribute(): ?array
    {
        $valor = $this->closedInvoiceDue;
        if ($valor <= 0.001) {
            return null;
        }

        $cycle = $this->closedCycle();
        $vencimento = $cycle ? $this->vencimentoMaisAntigoEmAberto($cycle[1]) : null;
        $hoje = CarbonImmutable::today();
        $vencida = $vencimento !== null && $vencimento->lessThan($hoje);

        return [
            'valor' => $valor,
            'vencimento' => $vencimento,
            'vencida' => $vencida,
            'diasAtraso' => $vencida ? (int) $vencimento->diffInDays($hoje) : 0,
        ];
    }

    /**
     * Fatura VENCIDA: a fatura fechada cujo vencimento já passou. Null quando
     * está tudo em dia (ou quando fechou mas ainda está no prazo).
     *
     * @return array{valor: float, vencimento: CarbonImmutable, diasAtraso: int}|null
     */
    public function getOverdueInvoiceAttribute(): ?array
    {
        $fechada = $this->closedInvoice;

        if (! $fechada || ! $fechada['vencida']) {
            return null;
        }

        return [
            'valor' => $fechada['valor'],
            'vencimento' => $fechada['vencimento'],
            'diasAtraso' => $fechada['diasAtraso'],
        ];
    }
}
