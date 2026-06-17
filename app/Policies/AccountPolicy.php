<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

/**
 * Descoberta automaticamente pelo Laravel (App\Policies\{Model}Policy).
 * Qualquer membro da família (titular ou dependente) pode alterá-la ou excluí-la.
 */
class AccountPolicy
{
    public function update(User $user, Account $account): bool
    {
        return $account->user_id === $user->ownerId();
    }

    public function delete(User $user, Account $account): bool
    {
        return $account->user_id === $user->ownerId();
    }
}
