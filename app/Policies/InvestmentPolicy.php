<?php

namespace App\Policies;

use App\Models\Investment;
use App\Models\User;

/**
 * Descoberta automaticamente pelo Laravel (App\Policies\{Model}Policy).
 * Investimentos são compartilhados na família: qualquer membro (titular ou
 * dependente) pode alterá-lo, excluí-lo e movimentá-lo (aporte/resgate).
 */
class InvestmentPolicy
{
    public function update(User $user, Investment $investment): bool
    {
        return $investment->user_id === $user->ownerId();
    }

    public function delete(User $user, Investment $investment): bool
    {
        return $investment->user_id === $user->ownerId();
    }
}
