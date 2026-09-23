<?php

namespace App\Models;

use App\Models\Concerns\EscopoDaFamiliaNaRota;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    // Na URL, categoria de outra família responde como categoria que não existe (ver o trait).
    use EscopoDaFamiliaNaRota, HasFactory;

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
     * "Trilho de estreia" das categorias fixas: elas nascem em 0,1,2… e por
     * isso aparecem no topo da coluna, que é como a tela sempre se comportou
     * (as fixas são as de uso recorrente).
     */
    public const TRILHO_FIXA = 0;

    /**
     * "Trilho de estreia" das categorias livres: nascem em 1000,1001,1002…,
     * logo depois de qualquer fixa.
     *
     * O trilho só decide a ordem de ESTREIA. Assim que o usuário arrasta um
     * chip, `CategoryController::ordenar` renumera a coluna inteira em 0..n-1
     * e o trilho deixa de importar — quem manda passa a ser a ordem escolhida
     * por ele, inclusive pondo uma categoria livre acima de uma fixa.
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
