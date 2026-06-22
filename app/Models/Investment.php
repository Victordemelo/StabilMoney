<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Investimento (modelo "cofrinho", igual à meta): o usuário reserva dinheiro
 * de uma conta via aportes e o aplicado é a soma dos aportes menos os resgates
 * (PRINCIPAL, sem simular crescimento). Carrega metadados (classe, indexador,
 * taxa) usados só para PROJETAR rentabilidade/IR na exibição — nada disso é
 * armazenado como saldo.
 */
class Investment extends Model
{
    use HasFactory;

    /** Rótulos PT-BR das classes de ativo (usados na view). */
    public const CLASSES = [
        'renda_fixa' => 'Renda fixa',
        'renda_variavel' => 'Renda variável',
        'fundos' => 'Fundos',
        'cripto' => 'Cripto',
    ];

    /** Cor de cada classe (hex), para o donut/legendas. */
    public const CLASS_COLORS = [
        'renda_fixa' => '#1C9A70',
        'renda_variavel' => '#18B6BE',
        'fundos' => '#9078D8',
        'cripto' => '#C77F2A',
    ];

    /**
     * Bases anuais (% a.a.) projetadas por indexador — porte de
     * design/project/finance.js (IDX_BASE). Sem indexador => 0.
     */
    public const INDEX_BASE = [
        'CDI' => 10.65,
        'Selic' => 10.5,
        'IPCA+' => 4.5,
        'Prefixado' => 0.0,
    ];

    protected $fillable = [
        'user_id',
        'made_by_user_id',
        'name',
        'classe',
        'indexador',
        'taxa',
    ];

    protected function casts(): array
    {
        return [
            'taxa' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Quem criou o investimento (titular ou dependente da família). */
    public function madeBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'made_by_user_id');
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(InvestmentContribution::class);
    }

    // Cache por-instância do aplicado (dispara query). Memoiza na 1ª chamada —
    // evita N+1 quando a view o consulta várias vezes. Cada instância recém-
    // consultada nasce com null, então não há valor velho entre requests.
    private ?float $aplicadoCache = null;

    /** Aplicado = Σ aportes − Σ resgates deste investimento (principal, sem juros). */
    public function getAplicadoAttribute(): float
    {
        return $this->aplicadoCache ??= (function (): float {
            $aportes = $this->contributions()->where('type', 'aporte')->sum('amount');
            $resgates = $this->contributions()->where('type', 'resgate')->sum('amount');

            return round((float) $aportes - (float) $resgates, 2);
        })();
    }

    /**
     * Taxa bruta anual projetada (% a.a.) — porte de finance.js `grossRate`:
     * CDI/Selic → base × taxa/100; IPCA+ → 4.5 + taxa; Prefixado → taxa;
     * sem indexador (null) → 0. Só exibição (não armazenado).
     */
    public function getGrossRateAttribute(): float
    {
        $taxa = (float) $this->taxa;

        return match ($this->indexador) {
            'CDI', 'Selic' => round(self::INDEX_BASE[$this->indexador] * $taxa / 100, 2),
            'IPCA+' => round(self::INDEX_BASE['IPCA+'] + $taxa, 2),
            'Prefixado' => round($taxa, 2),
            default => 0.0,
        };
    }

    /** Rótulo PT-BR da classe (Renda fixa / Renda variável / Fundos / Cripto). */
    public function getClasseLabelAttribute(): string
    {
        return self::CLASSES[$this->classe] ?? $this->classe;
    }

    /** Cor (hex) da classe, para o donut/legendas. */
    public function getClasseColorAttribute(): string
    {
        return self::CLASS_COLORS[$this->classe] ?? '#9078D8';
    }
}
