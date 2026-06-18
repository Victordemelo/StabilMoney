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

    /** Guardado = Σ aportes − Σ resgates desta meta. */
    public function getSavedAttribute(): float
    {
        $aportes = $this->contributions()->where('type', 'aporte')->sum('amount');
        $resgates = $this->contributions()->where('type', 'resgate')->sum('amount');

        return round((float) $aportes - (float) $resgates, 2);
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
