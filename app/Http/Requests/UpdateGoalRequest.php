<?php

namespace App\Http\Requests;

use App\Models\Goal;

/**
 * Mesmas regras da criação — a posse da meta em si
 * é verificada pela GoalPolicy no controller.
 *
 * Com UMA diferença: o prazo que já está gravado é aceito mesmo que tenha
 * passado. O modal de edição pré-preenche a data antiga e a envia de volta
 * junto com o resto do formulário; exigir "de hoje em diante" ali deixava
 * uma meta com prazo vencido ineditável — nem o nome dava para trocar sem
 * antes inventar um prazo novo (T-6 da auditoria de 02/09/2026).
 *
 * Só a data INALTERADA é perdoada. Trocar para outra data no passado continua
 * sendo recusado, igual à criação.
 */
class UpdateGoalRequest extends StoreGoalRequest
{
    protected function prazoJaGravado(): bool
    {
        $meta = $this->route('meta');

        if (! $meta instanceof Goal || ! $meta->target_date) {
            return false;
        }

        // Os dois lados no mesmo formato: o `prepareForValidation` já converteu
        // "AAAA-MM" em "AAAA-MM-01", e a coluna guarda sempre o dia 01.
        return $this->input('target_date') === $meta->target_date->toDateString();
    }
}
