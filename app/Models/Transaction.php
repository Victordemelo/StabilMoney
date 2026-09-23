<?php

namespace App\Models;

use App\Models\Concerns\EscopoDaFamiliaNaRota;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    // Na URL, lançamento de outra família responde como lançamento que não existe (ver o trait).
    use EscopoDaFamiliaNaRota, HasFactory;

    protected $fillable = [
        'client_uuid',
        'user_id',
        'made_by_user_id',
        'account_id',
        'category_id',
        'type',
        'amount',
        'description',
        'date',
        // Parcelamento/recorrência (feature "Faturas / Despesas").
        'group_id',
        // Transferência entre contas de caixa: liga a saída (origem) à entrada
        // (destino). Ver a migration add_transfer_group_id: as duas linhas contam
        // no saldo, mas nenhuma é receita ou despesa no dashboard.
        'transfer_group_id',
        // Pagamento de conta fixa mensal: qual conta e qual mês foi quitado.
        'fixed_bill_id',
        // Preenchido só na saída de caixa que quita a fatura de um cartão. Ver a
        // migration add_settles_account_id_to_transactions: é o que impede o dashboard
        // de contar a quitação como gasto novo (dobrando a despesa do período).
        'settles_account_id',
        // Aponta para a transação de QUITAÇÃO que pagou esta compra. É o que
        // torna o estorno da fatura exato (ver migration add_settled_by_id).
        'settled_by_id',
        'competence',
        'installment_no',
        'installments',
        'recurring',
        'paid_at',
        // De onde saiu o dinheiro quando o disponível não cobriu sozinho
        // (cheque especial / resgate) e quanto veio de lá. Só auditoria.
        'funding_source',
        'funding_amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            // `date:Y-m-d` (e não só `date`) porque o cast padrão GRAVA "Y-m-d H:i:s".
            // O MySQL trunca — a coluna é DATE —, mas o sqlite guarda a string inteira, e
            // aí `whereBetween('date', [$de, $ate])` compara texto e EXCLUI as linhas
            // datadas exatamente no último dia da janela. Efeito prático: em sqlite (onde
            // a suíte roda) as transações de hoje não entravam nas sparklines, e as do
            // dia 31 sumiam do mês. Os testes validavam número errado.
            'date' => 'date:Y-m-d',
            'competence' => 'date:Y-m-d',
            'installment_no' => 'integer',
            'installments' => 'integer',
            'recurring' => 'boolean',
            'paid_at' => 'datetime',
            'funding_amount' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** Quem efetivamente lançou (titular ou dependente da família). */
    public function madeBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'made_by_user_id');
    }

    /**
     * É uma das duas pontas de uma transferência entre contas?
     *
     * Pergunte por isto, nunca por `type`: as pontas continuam `income`/`expense`
     * (é o que faz o saldo de cada conta fechar sozinho), mas não são receita nem
     * despesa de verdade — o dinheiro só trocou de conta.
     */
    public function isTransferencia(): bool
    {
        return $this->transfer_group_id !== null;
    }

    /**
     * A outra ponta da transferência (a entrada, se esta é a saída, e vice-versa).
     * Null fora de transferência — ou se a outra ponta sumiu, o que não deveria
     * acontecer: as duas nascem e morrem na mesma transação de banco.
     */
    public function contrapartida(): ?self
    {
        if (! $this->isTransferencia()) {
            return null;
        }

        return self::where('transfer_group_id', $this->transfer_group_id)
            ->where('user_id', $this->user_id)
            ->whereKeyNot($this->getKey())
            ->first();
    }

    /**
     * É uma ocorrência de série RECORRENTE — a assinatura no cartão, ou a recorrência
     * legada em conta? A série é o `group_id` com `recurring`. Parcela também tem
     * `group_id`, mas é de compra parcelada, não de recorrência.
     *
     * É esta pergunta que decide se excluir pelo Histórico oferece "Encerrar também a
     * recorrência" (`EndedRecurrence`).
     */
    public function isOcorrenciaRecorrente(): bool
    {
        return $this->group_id !== null && (bool) $this->recurring && ! $this->installments;
    }

    /**
     * Escopo: só receitas e despesas de VERDADE — fora as pontas de transferência.
     * É o filtro das somas de fluxo de caixa (dashboard, gasto por pessoa).
     */
    public function scopeSemTransferencias($query)
    {
        return $query->whereNull($query->qualifyColumn('transfer_group_id'));
    }

    /** Valor com sinal: positivo para receita, negativo para despesa. */
    public function getSignedAmountAttribute(): float
    {
        return $this->type === 'income'
            ? (float) $this->amount
            : -(float) $this->amount;
    }

    /**
     * Selo da despesa para as listas de fatura (mesma regra do protótipo
     * finance.js): "i/N" para parcela, "Recorrente" para recorrente, senão
     * "À vista".
     */
    public function getBadgeAttribute(): string
    {
        if ($this->installments) {
            return $this->installment_no.'/'.$this->installments;
        }

        if ($this->recurring) {
            return 'Recorrente';
        }

        return 'À vista';
    }
}
