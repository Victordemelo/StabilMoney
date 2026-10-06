<?php

namespace App\Models;

use App\Models\Concerns\EscopoDaFamiliaNaRota;
use App\Models\Concerns\RegistraAtividade;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    // Na URL, categoria de outra família responde como categoria que não existe (ver o trait).
    use EscopoDaFamiliaNaRota, HasFactory, RegistraAtividade;

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'color',
        'icon',
        'is_locked',
        'position',
    ];

    /**
     * Trilho das categorias fixas: 0,1,2… — o topo da coluna, SEMPRE.
     */
    public const TRILHO_FIXA = 0;

    /**
     * Trilho das categorias livres: 1000,1001,1002…, sempre depois de qualquer fixa.
     *
     * Até out/2026 o trilho só valia na estreia: reordenar renumerava a coluna em 0..n-1
     * e uma livre podia subir acima de uma fixa — que descia sozinha, sem ninguém ter
     * mexido nela (o defeito que o Victor achou). Hoje `CategoryController::ordenar`
     * numera as fixas no trilho delas e as livres neste, e a tela não oferece subir uma
     * livre acima de uma fixa (`OrdemDasCategoriasTest`).
     */
    public const TRILHO_LIVRE = 1000;

    protected function casts(): array
    {
        return [
            'is_locked' => 'boolean',
            'position' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Categoria nova entra no FIM da própria coluna. Fica no model (e não
        // no controller) porque nem todo caminho de criação passa por lá:
        // `DefaultCategories::seedFor` e as factories criam direto pelo
        // Eloquent, e sem isto nasceriam todas em `position = 0` (o default do
        // banco) — ou seja, empatadas e à frente de quem já estava na tela.
        static::creating(function (self $categoria): void {
            if ($categoria->getAttribute('position') === null) {
                $categoria->position = static::proximaPosicao(
                    (int) $categoria->user_id,
                    (string) ($categoria->type ?: 'expense'),
                    (bool) $categoria->is_locked,
                );
            }
        });
    }

    /**
     * Próxima posição livre no fim da coluna (user_id + type), dentro do
     * trilho da categoria — ver TRILHO_FIXA / TRILHO_LIVRE.
     */
    public static function proximaPosicao(int $userId, string $type, bool $fixa = false): int
    {
        $ultima = static::query()
            ->where('user_id', $userId)
            ->where('type', $type)
            ->when(
                $fixa,
                fn ($q) => $q->where('position', '<', self::TRILHO_LIVRE),
                fn ($q) => $q->where('position', '>=', self::TRILHO_LIVRE),
            )
            ->max('position');

        return $ultima === null
            ? ($fixa ? self::TRILHO_FIXA : self::TRILHO_LIVRE)
            : ((int) $ultima) + 1;
    }

    /**
     * Categoria fixa do sistema (uso recorrente): não pode ser excluída
     * nem mudar de tipo. Renomear/trocar cor e ícone continua liberado.
     */
    public function isLocked(): bool
    {
        return (bool) $this->is_locked;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
