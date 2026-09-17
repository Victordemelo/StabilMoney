<?php

namespace App\Http\Requests;

use App\Models\Goal;

/**
 * Mesmas regras da criação — a posse da meta em si é verificada pela GoalPolicy,
 * já no `authorize()` deste request (antes de qualquer regra) e de novo no
 * controller.
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
    /**
     * Posse PRIMEIRO, antes de qualquer regra — o mesmo remédio do A-3 no
     * `UpdateAccountRequest`.
     *
     * O Form Request resolve a autorização antes de rodar o validador, enquanto a
     * policy do controller só roda DEPOIS da validação. E aqui uma regra depende
     * da meta da rota: o perdão do prazo vencido (`prazoJaGravado()`). Com o
     * `authorize()` herdado devolvendo `true`, a resposta para a meta de OUTRA
     * família mudava conforme o prazo dela: chute certo do prazo → 403 da policy;
     * chute errado → 422 "de hoje em diante". Mês a mês, dava para descobrir o
     * prazo de qualquer meta vencida alheia sem que mensagem nenhuma o citasse.
     *
     * Mesma ability do `GoalController::update` (`update`): trocar a ability aqui
     * mudaria quem pode editar. Titular e dependente seguem iguais — a policy
     * compara com `ownerId()`.
     */
    public function authorize(): bool
    {
        $meta = $this->route('meta');

        return $meta instanceof Goal && $this->user()->can('update', $meta);
    }

    protected function prazoJaGravado(): bool
    {
        $meta = $this->route('meta');

        // Segunda linha, para o dia em que o `authorize()` mudar: o perdão só
        // existe para a meta DA FAMÍLIA. Meta alheia cai na regra comum ("de hoje
        // em diante"), que não depende de dado nenhum dela — a resposta fica a
        // mesma com chute certo ou errado. Da família é `ownerId()`, não o id de
        // quem está logado: o dependente edita as metas do titular.
        if (! $meta instanceof Goal
            || (int) $meta->user_id !== (int) $this->user()->ownerId()
            || ! $meta->target_date) {
            return false;
        }

        // Os dois lados no mesmo formato: o `prepareForValidation` já converteu
        // "AAAA-MM" em "AAAA-MM-01", e a coluna guarda sempre o dia 01.
        return $this->input('target_date') === $meta->target_date->toDateString();
    }
}
