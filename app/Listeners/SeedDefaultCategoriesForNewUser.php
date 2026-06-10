<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\DefaultCategories;
use Illuminate\Auth\Events\Registered;

/**
 * Ao registrar um usuário novo (evento Registered do fluxo de cadastro),
 * cria o conjunto de categorias padrão para ele já começar organizado.
 *
 * O Laravel 12 descobre listeners em app/Listeners automaticamente —
 * não é preciso registrar nada (confira com `php artisan event:list`).
 */
class SeedDefaultCategoriesForNewUser
{
    /**
     * Handle the event.
     */
    public function handle(Registered $event): void
    {
        if ($event->user instanceof User) {
            DefaultCategories::seedFor($event->user);
        }
    }
}
