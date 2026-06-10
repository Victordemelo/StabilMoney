<?php

namespace App\Support;

use App\Models\User;

/**
 * Categorias padrão criadas para todo usuário novo (e para o usuário demo do seeder).
 *
 * A criação é idempotente (firstOrCreate): rodar duas vezes não duplica nada,
 * e categorias renomeadas/excluídas pelo usuário não são recriadas à força
 * em outros fluxos — apenas quando o nome/tipo não existir mais.
 */
class DefaultCategories
{
    /**
     * Paleta de cores do design system (cicla pela lista na ordem das categorias).
     *
     * @var list<string>
     */
    private const COLORS = ['#0F6B47', '#1FA06E', '#59C497', '#18B6BE', '#F0A93B', '#9FB0A7'];

    /**
     * Categorias padrão de despesa: [nome, ícone].
     *
     * @var list<array{0: string, 1: string}>
     */
    private const EXPENSES = [
        ['Alimentação', '🍽️'],
        ['Transporte', '🚗'],
        ['Moradia', '🏠'],
        ['Saúde', '💊'],
        ['Lazer', '🎮'],
        ['Educação', '📚'],
        ['Compras', '🛒'],
        ['Contas', '🧾'],
        ['Outros', '📦'],
    ];

    /**
     * Categorias padrão de receita: [nome, ícone].
     *
     * @var list<array{0: string, 1: string}>
     */
    private const INCOMES = [
        ['Salário', '💰'],
        ['Freelance', '💼'],
        ['Investimentos', '📈'],
        ['Presente', '🎁'],
        ['Outros', '📦'],
    ];

    /**
     * Cria as categorias padrão para o usuário informado (idempotente).
     */
    public static function seedFor(User $user): void
    {
        self::seedType($user, 'expense', self::EXPENSES);
        self::seedType($user, 'income', self::INCOMES);
    }

    /**
     * Cria as categorias de um tipo, atribuindo cores da paleta em ciclo.
     *
     * @param  list<array{0: string, 1: string}>  $items
     */
    private static function seedType(User $user, string $type, array $items): void
    {
        foreach ($items as $i => [$name, $icon]) {
            $user->categories()->firstOrCreate(
                ['name' => $name, 'type' => $type],
                [
                    'icon' => $icon,
                    'color' => self::COLORS[$i % count(self::COLORS)],
                ],
            );
        }
    }
}
