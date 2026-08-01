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
     * Bases anuais (% a.a.) **projetadas** por indexador — porte de
     * design/project/finance.js (IDX_BASE).
     *
     * ⚠️ São constantes de código, sem data de referência: uma PREMISSA de
     * projeção, não a taxa de hoje. Tudo que sai daqui é estimativa e a UI
     * precisa dizer isso (ver `investimentos/index.blade.php` e
     * `resources/js/sm/investimentos.js`).
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
     * Quanto DESTA conta está aplicado neste investimento (Σ aportes −
     * Σ resgates feitos a partir dela). Nunca negativo.
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
     * MESMA no time-of-check (WithdrawInvestmentContributionRequest) e no
     * time-of-use (recheque sob lock do HandlesContributions).
     */
    public function mensagemResgateAcimaDoReservado(Account $conta, float $reservado): string
    {
        if ($reservado <= 0) {
            return 'A conta “' . $conta->name . '” não tem nada aplicado neste investimento'
                . ' — só é possível resgatar para a conta de onde o dinheiro saiu.';
        }

        return 'O valor do resgate é maior que o aplicado neste investimento a partir da conta “'
            . $conta->name . '” (R$ ' . number_format($reservado, 2, ',', '.')
            . '). Só volta para a conta o que saiu dela.';
    }

    /**
     * Taxa bruta anual **estimada** (% a.a.) — porte de finance.js `grossRate`:
     * CDI/Selic → base × taxa/100; IPCA+ → 4.5 + taxa; Prefixado → taxa;
     * **sem indexador → a própria taxa informada** (renda variável/cripto/
     * fundos: o usuário digita a rentabilidade que espera/observa).
     *
     * Antes devolvia 0 quando não havia indexador, enquanto o JS da prévia já
     * usava a taxa: o card mostrava "0,0% a.a." e o modal, "30%". Só exibição
     * (nada disso é armazenado nem entra em saldo).
     */
    public function getGrossRateAttribute(): float
    {
        $taxa = (float) $this->taxa;

        return match ($this->indexador) {
            'CDI', 'Selic' => round(self::INDEX_BASE[$this->indexador] * $taxa / 100, 2),
            'IPCA+' => round(self::INDEX_BASE['IPCA+'] + $taxa, 2),
            'Prefixado' => round($taxa, 2),
            default => round($taxa, 2),
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
