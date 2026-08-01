<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Meta de poupança (modelo "cofrinho"): o usuário define um objetivo
 * (valor-alvo e, opcionalmente, uma data) e vai reservando dinheiro nele
 * via aportes. O guardado é a soma dos aportes menos os resgates.
 */
class Goal extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'made_by_user_id',
        'name',
        'emoji',
        'color',
        'target_amount',
        'target_date',
    ];

    protected function casts(): array
    {
        return [
            'target_amount' => 'decimal:2',
            'target_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Quem criou a meta (titular ou dependente da família). */
    public function madeBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'made_by_user_id');
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(GoalContribution::class);
    }

    // Cache por-instância do guardado (dispara query). Memoiza na 1ª chamada —
    // os derivados (progress/remaining) reusam o valor sem N+1. Cada instância
    // recém-consultada nasce com null, então não há valor velho entre requests.
    private ?float $savedCache = null;

    /** Guardado = Σ aportes − Σ resgates desta meta. */
    public function getSavedAttribute(): float
    {
        return $this->savedCache ??= (function (): float {
            $aportes = $this->contributions()->where('type', 'aporte')->sum('amount');
            $resgates = $this->contributions()->where('type', 'resgate')->sum('amount');

            return round((float) $aportes - (float) $resgates, 2);
        })();
    }

    /**
     * Quanto DESTA conta está guardado nesta meta (Σ aportes − Σ resgates
     * feitos a partir dela). Nunca negativo.
     *
     * É o teto de um resgate para aquela conta: só volta para a conta o que
     * saiu dela. Sem isso, resgatar para uma conta que nunca aportou deixaria
     * o `reserved` dela NEGATIVO — e o disponível passaria a oferecer dinheiro
     * que a conta não tem (invariante I6). Espelha
     * `App\Services\SpendingGuard::resgatavelDe()`.
     */
    public function reservedFromAccount(int $accountId): float
    {
        $total = $this->contributions()
            ->where('account_id', $accountId)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'aporte' THEN amount ELSE -amount END), 0) AS total")
            ->value('total');

        return round(max(0.0, (float) $total), 2);
    }

    /**
     * Mensagem PT-BR de "esse resgate não cabe". Fica no model para ser a
     * MESMA no time-of-check (WithdrawGoalContributionRequest) e no
     * time-of-use (recheque sob lock do HandlesContributions).
     */
    public function mensagemResgateAcimaDoReservado(Account $conta, float $reservado): string
    {
        if ($reservado <= 0) {
            return 'A conta “' . $conta->name . '” não tem nada guardado nesta meta'
                . ' — só é possível resgatar para a conta de onde o dinheiro saiu.';
        }

        return 'O valor do resgate é maior que o guardado nesta meta a partir da conta “'
            . $conta->name . '” (R$ ' . number_format($reservado, 2, ',', '.')
            . '). Só volta para a conta o que saiu dela.';
    }

    /** Quanto ainda falta para bater o alvo (nunca negativo). */
    public function getRemainingAttribute(): float
    {
        return round(max(0.0, (float) $this->target_amount - $this->saved), 2);
    }

    /** Progresso em % (0..100); alvo zerado evita divisão por zero. */
    public function getProgressAttribute(): int
    {
        $alvo = (float) $this->target_amount;
        if ($alvo <= 0) {
            return 0;
        }

        return (int) min(100, round($this->saved / $alvo * 100));
    }
}
