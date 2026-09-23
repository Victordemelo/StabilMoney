<?php

namespace Tests\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

/**
 * Tira o escopo de família do binding de um parâmetro de rota — para testar a SEGUNDA
 * linha de defesa sozinha.
 *
 * Desde 23/09/2026 a primeira porta contra recurso de outra família é o próprio route model
 * binding (`App\Models\Concerns\EscopoDaFamiliaNaRota` e `User::daFamiliaNaRota`): o id
 * alheio recebe o mesmo 404 de um id que não existe, e nem chega ao Form Request, à policy
 * ou ao controller. Quem cobre essa porta, em toda rota, é o
 * `IdAlheioNaRotaIgualAIdInexistenteTest`.
 *
 * Mas as linhas de trás continuam no código — o `authorize()` dos Form Requests, as guardas
 * de posse das regras que leem o model da rota, as policies e os `abort_unless` —, e uma
 * defesa que nenhum teste alcança apodrece no primeiro refactor. Os testes que existem para
 * prender uma delas desligam a porta da frente com isto e continuam exigindo o 403 dela.
 *
 * Como funciona: um binding explícito (`Route::bind`) roda antes do implícito e, quando
 * devolve um model, o implícito nem olha o parâmetro. Este aqui acha QUALQUER linha — o
 * binding como era antes do escopo. Vale só para a aplicação do teste que o chama.
 */
trait DesligaEscopoDaFamiliaNaRota
{
    /** @param  class-string<Model>  $classe */
    protected function desligarEscopoDaFamiliaNaRota(string $parametro, string $classe): void
    {
        Route::bind($parametro, fn (string $valor) => $classe::query()->findOrFail($valor));
    }
}
