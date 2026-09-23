<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Série recorrente ENCERRADA: a recorrência (`transactions.group_id`) não gera
 * mais ocorrência nenhuma (R2-2 da auditoria financeira, rodada 2).
 *
 * Nasce quando a série é excluída em /faturas (`FaturaController::destroy`) e
 * sobra ocorrência já paga — é ela que, sem o marcador, voltava em "Lançar neste
 * ciclo" e deixava um clique recriar a sucessora.
 *
 * O marcador NÃO toca nas transações da série: valor, `paid_at`, `settled_by_id`
 * e o selo "Recorrente" das já pagas ficam como estavam, e nenhum saldo, limite
 * ou fatura muda. Encerrar é só "não gere a próxima".
 */
class EndedRecurrence extends Model
{
    protected $fillable = [
        'user_id',
        'group_id',
        'ended_by_user_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Quem encerrou (titular ou dependente). */
    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_user_id');
    }

    /**
     * A série desta ocorrência foi encerrada?
     *
     * Com `$travar`, a leitura é com `lockForUpdate`: é a forma de enxergar o que já
     * foi gravado por outra transação mesmo depois de a leitura consistente (o
     * "snapshot" do REPEATABLE READ do MySQL) ter sido aberta — a checagem de dentro
     * do `$write` do FundingService precisa disso para não gerar a sucessora de uma
     * série que acabou de ser encerrada.
     */
    public static function daSerie(Transaction $ocorrencia, bool $travar = false): bool
    {
        if ($ocorrencia->group_id === null) {
            return false;
        }

        return static::where('user_id', $ocorrencia->user_id)
            ->where('group_id', $ocorrencia->group_id)
            ->when($travar, fn ($q) => $q->lockForUpdate())
            ->exists();
    }
}
