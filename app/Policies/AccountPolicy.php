<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

/**
 * Descoberta automaticamente pelo Laravel (App\Policies\{Model}Policy).
 * Só o dono da conta pode alterá-la ou excluí-la.
 */
class AccountPolicy
{
    public function update(User $user, Account $account): bool
    {
        return $account->user_id === $user->id;
    }

    public function delete(User $user, Account $account): bool
    {
        return $account->user_id === $user->id;
    }
}
