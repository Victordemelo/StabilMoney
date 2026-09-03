<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Movimentação de um investimento no modelo "cofrinho":
 * - aporte: reserva dinheiro (o disponível da conta cai, o aplicado do invest. sobe);
 * - resgate: devolve dinheiro de volta para uma conta.
 *
 * Não cria transação — o saldo cru das contas (patrimônio total) não muda.
 * O valor é sempre positivo; o sentido vem do `type`.
 */
class InvestmentContribution extends Model
{
    use HasFactory;

    protected $fillable = [
        'investment_id',
        'account_id',
        // Despesa que este resgate cobriu (só quando veio do fluxo de "de onde
        // sai esse dinheiro?"); null nos aportes/resgates feitos direto na tela.
        'transaction_id',
        // Idempotência: uuid gerado pelo cliente a cada abertura do modal. Um
        // duplo clique (ou o reenvio de um POST que já chegou) não grava de novo —
        // índice único em (investment_id, client_uuid), NULL não colide.
        'client_uuid',
        'made_by_user_id',
        'type',
        'amount',
        'date',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'date' => 'date',
        ];
    }

    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** Quem efetivamente aportou/resgatou (titular ou dependente da família). */
    public function madeBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'made_by_user_id');
    }
}
