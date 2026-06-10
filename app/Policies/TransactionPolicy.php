<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;

/**
 * Descoberta automaticamente pelo Laravel (App\Policies\{Model}Policy).
 * Só o dono da transação pode alterá-la ou excluí-la.
 */
class TransactionPolicy
{
    public function update(User $user, Transaction $transaction): bool
    {
        return $transaction->user_id === $user->id;
    }

    public function delete(User $user, Transaction $transaction): bool
    {
        return $transaction->user_id === $user->id;
    }
}
