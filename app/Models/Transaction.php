<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasFactory;

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
        'installment_no',
        'installments',
        'recurring',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'date' => 'date',
            'installment_no' => 'integer',
            'installments' => 'integer',
            'recurring' => 'boolean',
            'paid_at' => 'datetime',
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
            return $this->installment_no . '/' . $this->installments;
        }

        if ($this->recurring) {
            return 'Recorrente';
        }

        return 'À vista';
    }
}
