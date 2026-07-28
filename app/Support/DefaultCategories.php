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
     * Categorias FIXAS (is_locked): as de uso recorrente, sempre de despesa.
     * Não podem ser excluídas nem mudar de tipo — só renomeadas/repintadas.
     * "Farmácia" está coberta por Saúde, por isso não existe categoria própria.
     *
     * @var list<string>
     */
    private const LOCKED_EXPENSES = ['Alimentação', 'Moradia', 'Saúde', 'Transporte', 'Contas'];

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
     * Nomes das categorias fixas (despesa) — usado pelos testes e por quem
     * precisar checar a lista sem duplicar as strings.
     *
     * @return list<string>
     */
    public static function lockedExpenseNames(): array
    {
        return self::LOCKED_EXPENSES;
    }

    /**
     * Cria as categorias de um tipo, atribuindo cores da paleta em ciclo.
     *
     * @param  list<array{0: string, 1: string}>  $items
     */
    private static function seedType(User $user, string $type, array $items): void
    {
        foreach ($items as $i => [$name, $icon]) {
            $fixa = $type === 'expense' && in_array($name, self::LOCKED_EXPENSES, true);

            $categoria = $user->categories()->firstOrCreate(
                ['name' => $name, 'type' => $type],
                [
                    'icon' => $icon,
                    'color' => self::COLORS[$i % count(self::COLORS)],
                    'is_locked' => $fixa,
                ],
            );

            // Idempotência do cadeado: se a categoria já existia (criada antes
            // de is_locked existir), rodar de novo marca as fixas como fixas.
            if ($fixa && ! $categoria->is_locked) {
                $categoria->update(['is_locked' => true]);
            }
        }
    }
}
