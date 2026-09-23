<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;

/**
 * Recurso de OUTRA família, na URL, não existe para quem pede.
 *
 * Vale para os models do dinheiro amarrados a rota (conta, categoria, lançamento, meta,
 * investimento, conta fixa): o route model binding só encontra a linha cujo `user_id` é a
 * família de quem está logado (`ownerId()`, o mesmo escopo de todas as telas).
 *
 * ## O defeito (decisão de 23/09/2026)
 *
 * Antes o binding achava QUALQUER linha e quem barrava era a policy (ou o `authorize()` do
 * Form Request): id de outra família → 403; id que não existe → 404. A diferença entre os
 * dois bastava para varrer ids e descobrir quais existem — quantas contas, lançamentos e
 * metas o app guarda, e em que ritmo crescem. Nenhum dado aparecia na tela; quem contava
 * era a diferença entre as respostas (a mesma lição do `EditarMetaAlheiaNaoVazaPrazoTest`).
 *
 * ## Por que no binding, e não um 404 na policy
 *
 * Porque só aqui o recurso alheio cai no MESMO caminho de código do inexistente. Um
 * `denyAsNotFound()` na policy (ou um `abort(404)`) ainda seria distinguível por dois lados:
 *
 *  - o JSON: o 404 do binding diz "No query results for model [App\Models\Account] 999";
 *    o de uma policy diz outra coisa;
 *  - os CABEÇALHOS: o 404 do binding nasce no `SubstituteBindings`, que roda ANTES do
 *    `SecurityHeaders` na fila e por isso sai com a CSP estática
 *    (`SecurityHeaders::completarRespostaSemScript`, pelo handler de exceções); um 404
 *    lançado no controller, na policy ou no Form Request atravessa o `SecurityHeaders` na
 *    volta e sai com a CSP COM nonce e o `X-Csp-Nonce`. Estrutura diferente, oráculo de novo.
 *
 * Aqui a linha alheia simplesmente não é encontrada, e o framework lança a exceção que lança
 * para qualquer id que não existe — mesma mensagem, no mesmo ponto da fila.
 *
 * ## Detalhes que importam
 *
 *  - Sobrescreve `resolveRouteBindingQuery`, e não `resolveRouteBinding`: é por ela que
 *    passam os três caminhos do framework — o binding comum, o que aceita linha apagada
 *    (`withTrashed`) e o aninhado (`scopeBindings`, em que esta classe é a FILHA). Um escopo
 *    posto só no `resolveRouteBinding` deixaria os outros dois abertos.
 *  - Guard `web` EXPLÍCITO: a família é de quem usa o APP. Não depender do guard padrão
 *    (hoje `web` em toda requisição, painel inclusive — o `AutenticaNoPainel` não o troca)
 *    é o que mantém isto certo se um dia ele mudar; o admin não tem família.
 *  - Sem usuário do app, nada é encontrado: uma rota que um dia nasça fora do `auth` não
 *    passa a entregar o recurso de ninguém. (No app o `auth` roda antes do binding — o
 *    framework ordena `Authenticate` antes do `SubstituteBindings` —, então quem chega
 *    aqui está logado.)
 *  - Policies, `authorize()` dos Form Requests e `abort_unless` de posse CONTINUAM onde
 *    estavam, como segunda linha (com o 403 de sempre). Para a família eles não disparam
 *    mais — se um dia dispararem, o escopo daqui sumiu, e o
 *    `IdAlheioNaRotaIgualAIdInexistenteTest` fica vermelho. Os testes que prendem essas
 *    linhas de trás desligam este escopo com `Tests\Concerns\DesligaEscopoDaFamiliaNaRota`.
 *
 * Os parâmetros de PESSOA (`{dependent}`, `{membro}`) têm o mesmo escopo, com a regra
 * própria de cada um — ver `User::daFamiliaNaRota()`.
 */
trait EscopoDaFamiliaNaRota
{
    /**
     * A consulta do route model binding, restrita à família de quem pede.
     *
     * @param  Model|Builder|Relation  $query
     * @param  mixed  $value
     * @param  string|null  $field
     * @return Builder
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $consulta = parent::resolveRouteBindingQuery($query, $value, $field);

        $usuario = Auth::guard('web')->user();

        // Explícito, e não um `where('user_id', null)`: o Eloquent transforma esse em
        // `whereNull`, que aqui não acharia nada por sorte (a coluna é NOT NULL) — e,
        // copiado para uma coluna que aceite nulo, acharia justamente o que não devia.
        if (! $usuario instanceof User) {
            return $consulta->whereRaw('1 = 0');
        }

        return $consulta->where($this->qualifyColumn('user_id'), $usuario->ownerId());
    }
}
