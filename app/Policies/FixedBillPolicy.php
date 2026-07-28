<?php

namespace App\Policies;

use App\Models\FixedBill;
use App\Models\User;

/**
 * Escopo de família: titular e dependentes compartilham as contas fixas
 * (mesma regra da AccountPolicy).
 */
class FixedBillPolicy
{
    public function update(User $user, FixedBill $bill): bool
    {
        return $bill->user_id === $user->ownerId();
    }

    public function delete(User $user, FixedBill $bill): bool
    {
        return $this->update($user, $bill);
    }
}
