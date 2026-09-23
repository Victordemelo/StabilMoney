<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Quitação de uma fatura de cartão PELO CRÉDITO de um estorno — sem saída de
 * caixa (R2-3, R2-4 e R2-5 da auditoria financeira, rodada 2).
 *
 * Nasce quando o estorno cobre EXATAMENTE as compras em aberto (líquido zero) e
 * alguém encerra a fatura ("Quitar pelo crédito", ou "Marcar como paga" numa
 * fatura de líquido zero): as linhas do lote ganham `paid_at` e apontam para ela
 * em `credit_settlement_id`. Nenhuma transação é criada e nenhum saldo muda — as
 * linhas se anulam, então nem o limite do cartão se mexe.
 *
 * É o "comprovante" que a quitação em caixa sempre teve (a linha com
 * `settles_account_id`): é por ela que o "Desfazer quitação" sabe exatamente quais
 * linhas voltam a ficar em aberto, e é ela que diz à tela que a fatura foi quitada
 * pelo estorno — e não paga com dinheiro de uma conta.
 */
class CreditSettlement extends Model
{
    protected $fillable = [
        'user_id',
        'account_id',
        'made_by_user_id',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** O cartão cuja fatura foi quitada. */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** Quem quitou (titular ou dependente). */
    public function madeBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'made_by_user_id');
    }

    /** As linhas do cartão (compras e estornos) que ela quitou. */
    public function linhas(): HasMany
    {
        return $this->hasMany(Transaction::class, 'credit_settlement_id');
    }

    /**
     * Por que uma linha quitada pelo crédito não pode ser excluída (ou editada) — e o
     * caminho que resolve. A MESMA frase nas duas portas que recusam: a tela Pagar
     * despesas e o Histórico.
     *
     * A recusa em si é a de toda linha de cartão já quitada, e o motivo é de dinheiro:
     * compra e estorno se anularam na quitação. Apagar a compra deixaria o estorno
     * "gasto" cobrindo nada (o crédito some); apagar o estorno deixaria a compra
     * quitada sem nada que a pagasse (a dívida some). O defeito era só o caminho: a
     * mensagem mandava "estornar o pagamento", e não existe pagamento a estornar numa
     * quitação pelo crédito (R2-4 da auditoria financeira, rodada 2).
     *
     * @param  'excluir'|'editar'  $acao
     */
    public static function recusaParaLinha(Transaction $linha, string $acao): string
    {
        $excluir = $acao === 'excluir';
        $noCartao = $linha->account?->name ? ' no cartão '.$linha->account->name : '';

        $abertura = $linha->type === 'income'
            ? 'Este estorno quitou compras'.$noCartao.' pelo crédito, sem sair dinheiro de conta nenhuma, e não pode ser '
                .($excluir ? 'excluído' : 'editado')
            : 'Esta compra foi quitada pelo crédito de um estorno'.$noCartao.', sem sair dinheiro de conta nenhuma, e não pode ser '
                .($excluir ? 'excluída' : 'editada');

        return $abertura.': compra e estorno foram quitados juntos, e mexer num lado só deixaria o outro quitado sem '
            .'contrapartida. Use "Desfazer quitação" no cartão, na tela Pagar despesas — as linhas voltam a ficar em '
            .'aberto e aí sim podem ser '.($excluir ? 'removidas' : 'corrigidas').'.';
    }
}
