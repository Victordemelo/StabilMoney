<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conta fixa mensal (condomínio, aluguel, parcela do carro, escola).
 *
 * As ocorrências de cada mês NÃO ficam no banco: são projetadas de `starts_on`
 * até hoje pelo FixedBillService. Só vira `transaction` quando é paga. Por isso
 * a conta "nunca some" nem depende de agendador para existir.
 */
class FixedBill extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'made_by_user_id',
        'name',
        'amount',
        'due_day',
        'account_id',
        'category_id',
        'starts_on',
        'ends_on',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_day' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Quem cadastrou a conta fixa (titular ou dependente). */
    public function madeBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'made_by_user_id');
    }

    /** Método de pagamento padrão (opcional). */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** Pagamentos já feitos (uma transação por competência quitada). */
    public function payments(): HasMany
    {
        return $this->hasMany(Transaction::class, 'fixed_bill_id');
    }

    /**
     * Vencimento numa competência (mês), com clamp de mês curto: dia 31 em
     * fevereiro vira 28/29. Mesma regra do ciclo de fatura do cartão.
     */
    public function dueDateFor(CarbonImmutable $competence): CarbonImmutable
    {
        $inicio = $competence->startOfMonth();
        $dia = max(1, min((int) $this->due_day, $inicio->daysInMonth));

        return $inicio->day($dia);
    }
}
