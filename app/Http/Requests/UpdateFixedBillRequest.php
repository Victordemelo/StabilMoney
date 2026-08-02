<?php

namespace App\Http\Requests;

use App\Models\FixedBill;

/**
 * Mesmas regras da criação, MAIS a checagem de posse aqui no `authorize()`.
 *
 * Por que a posse não fica só na Policy do controller: o Form Request valida antes, e a
 * validação dos FKs (conta/categoria da própria família) já barrava o estranho — mas com
 * **302 e mensagens de validação** em vez de 403. O dinheiro estava protegido por
 * acidente, pela ordem dos middlewares, e a resposta contava mais do que devia sobre o
 * que existe do outro lado. Com a posse aqui, o 403 vem primeiro e a validação nem roda.
 *
 * A `FixedBillPolicy` continua no controller como segunda linha (defesa em profundidade).
 */
class UpdateFixedBillRequest extends StoreFixedBillRequest
{
    public function authorize(): bool
    {
        $conta = $this->route('conta');

        if (! $conta instanceof FixedBill) {
            return false;
        }

        return $conta->user_id === $this->user()?->ownerId();
    }
}
