<?php

namespace App\Policies;

use App\Models\Goal;
use App\Models\User;

/**
 * Descoberta automaticamente pelo Laravel (App\Policies\{Model}Policy).
 * Metas são compartilhadas na família: qualquer membro (titular ou dependente)
 * pode alterá-la, excluí-la e movimentá-la (aporte/resgate).
 */
class GoalPolicy
{
    public function update(User $user, Goal $goal): bool
    {
        return $goal->user_id === $user->ownerId();
    }

    public function delete(User $user, Goal $goal): bool
    {
        return $goal->user_id === $user->ownerId();
    }
}
