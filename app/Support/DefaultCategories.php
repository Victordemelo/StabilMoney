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
     * Cores que o SISTEMA já atribuiu automaticamente em alguma versão — a
     * paleta antiga (6 tons, quase todos verdes) mais as das rodadas seguintes.
     *
     * As migrations de recoloração só substituem cores desta lista: se a cor
     * atual não está aqui, foi o usuário que escolheu e a mantemos.
     *
     * @var list<string>
     */
    public const LEGACY_COLORS = [
        // paleta original (ciclava e repetia)
        '#0F6B47', '#1FA06E', '#59C497', '#18B6BE', '#F0A93B', '#9FB0A7',
        // 1ª rodada de cores distintas (o coral saiu: vermelho é só p/ dívida)
        '#E5604D', '#3B82C4', '#8B5CF6', '#EC4899', '#6366F1', '#0EA5B5',
    ];

    /** Cor de quem não está na lista (categoria criada à mão sem cor). */
    private const FALLBACK_COLOR = '#9FB0A7';

    /**
     * Categorias padrão de despesa: [nome, ícone, cor].
     *
     * Cada uma tem cor PRÓPRIA e bem distinta — antes a paleta de 6 tons
     * (4 deles verdes) ciclava e repetia, deixando o donut e os chips
     * praticamente da mesma cor (ex.: Alimentação e Compras iguais).
     *
     * REGRA: **vermelho (--neg, #E5604D) é reservado** para "está devendo" —
     * saldo negativo, valor a pagar, fatura vencida. Nenhuma categoria usa,
     * senão a leitura financeira fica ambígua.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const EXPENSES = [
        ['Alimentação', '🍽️', '#F0A93B'], // âmbar
        ['Transporte', '🚗', '#3B82C4'],   // azul
        ['Moradia', '🏠', '#8B5CF6'],      // roxo
        ['Saúde', '💊', '#18B6BE'],        // turquesa
        ['Lazer', '🎮', '#EC4899'],        // rosa
        ['Educação', '📚', '#6366F1'],     // índigo
        ['Compras', '🛒', '#64748B'],      // azul-acinzentado
        ['Contas', '🧾', '#0F6B47'],       // verde escuro
        ['Outros', '📦', '#78716C'],       // marrom acinzentado
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
     * Categorias padrão de receita: [nome, ícone, cor].
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const INCOMES = [
        ['Salário', '💰', '#1FA06E'],       // verde
        ['Freelance', '💼', '#59C497'],     // verde claro
        ['Investimentos', '📈', '#0EA5B5'], // ciano
        ['Presente', '🎁', '#EC4899'],      // rosa
        ['Outros', '📦', '#78716C'],        // marrom acinzentado
    ];

    /**
     * Cor padrão de uma categoria pelo nome+tipo (null se não for uma das padrão).
     * Usado pela migration que recolore as categorias já existentes.
     */
    public static function defaultColorFor(string $name, string $type): ?string
    {
        foreach ($type === 'income' ? self::INCOMES : self::EXPENSES as [$n, , $cor]) {
            if ($n === $name) {
                return $cor;
            }
        }

        return null;
    }

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
     * Cria as categorias de um tipo, cada uma com a sua cor própria.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $items
     */
    private static function seedType(User $user, string $type, array $items): void
    {
        foreach ($items as [$name, $icon, $color]) {
            $fixa = $type === 'expense' && in_array($name, self::LOCKED_EXPENSES, true);

            $categoria = $user->categories()->firstOrCreate(
                ['name' => $name, 'type' => $type],
                [
                    'icon' => $icon,
                    'color' => $color ?: self::FALLBACK_COLOR,
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
