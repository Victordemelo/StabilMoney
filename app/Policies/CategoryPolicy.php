<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

/**
 * Descoberta automaticamente pelo Laravel (App\Policies\{Model}Policy).
 * Só o dono da categoria pode alterá-la ou excluí-la.
 */
class CategoryPolicy
{
    public function update(User $user, Category $category): bool
    {
        return $category->user_id === $user->id;
    }

    public function delete(User $user, Category $category): bool
    {
        return $category->user_id === $user->id;
    }
}
