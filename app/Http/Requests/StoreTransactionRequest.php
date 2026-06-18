<?php

namespace App\Http\Requests;

use App\Models\Category;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Dono dos dados é garantido pelas regras (conta/categoria do próprio usuário)
        // e pelas Policies nos controllers.
        return true;
    }

    /**
     * Normaliza o valor digitado no padrão pt-BR (vírgula decimal,
     * ponto de milhar) para o formato decimal aceito pelo banco.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->amount)) {
            $valor = trim(str_replace(['R$', ' '], '', $this->amount));

            if (str_contains($valor, ',')) {
                $valor = str_replace('.', '', $valor);  // remove separador de milhar
                $valor = str_replace(',', '.', $valor); // vírgula decimal -> ponto
            } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $valor)) {
                $valor = str_replace('.', '', $valor);  // só milhares: "1.234" -> "1234"
            }

            $this->merge(['amount' => $valor]);
        }
    }

    public function rules(): array
    {
        // Escopo por família: conta/categoria precisam pertencer ao titular (ownerId).
        $userId = $this->user()->ownerId();

        return [
            // Idempotência da fila offline: gerado no cliente, opcional (web normal não usa).
            'client_uuid' => ['nullable', 'uuid'],
            'type' => ['required', 'in:income,expense'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999999.99'],
            'account_id' => [
                'required',
                // CRÍTICO: a conta precisa pertencer ao usuário logado
                Rule::exists('accounts', 'id')->where('user_id', $userId),
            ],
            'category_id' => [
                'nullable',
                // CRÍTICO: a categoria precisa pertencer ao usuário logado
                Rule::exists('categories', 'id')->where('user_id', $userId),
                // O tipo da categoria deve casar com o tipo da transação
                function (string $attribute, mixed $value, Closure $fail) use ($userId) {
                    $tipo = $this->input('type');
                    if (! $value || ! in_array($tipo, ['income', 'expense'], true)) {
                        return;
                    }

                    $categoria = Category::where('id', $value)
                        ->where('user_id', $userId)
                        ->first();

                    if ($categoria && $categoria->type !== $tipo) {
                        $fail($tipo === 'income'
                            ? 'A categoria escolhida é de despesa — escolha uma categoria de receita.'
                            : 'A categoria escolhida é de receita — escolha uma categoria de despesa.');
                    }
                },
            ],
            // Quem fez a compra: precisa ser membro da família (titular ou dependente).
            'made_by_user_id' => [
                'nullable',
                Rule::exists('users', 'id')->where(function ($q) use ($userId) {
                    $q->where('id', $userId)->orWhere('account_owner_id', $userId);
                }),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'date' => [
                'required',
                'date',
                'after_or_equal:2000-01-01',
                'before_or_equal:' . now()->addYears(10)->toDateString(),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'type' => 'tipo',
            'amount' => 'valor',
            'account_id' => 'conta',
            'category_id' => 'categoria',
            'description' => 'descrição',
            'date' => 'data',
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Escolha o tipo: receita ou despesa.',
            'type.in' => 'Tipo de transação inválido.',
            'amount.required' => 'Informe o valor da transação.',
            'amount.numeric' => 'O valor deve ser um número. Use vírgula para os centavos, ex.: 25,90.',
            'amount.min' => 'O valor mínimo é R$ 0,01.',
            'amount.max' => 'O valor informado é alto demais.',
            'account_id.required' => 'Escolha a conta da transação.',
            'account_id.exists' => 'A conta escolhida não existe ou não pertence a você.',
            'category_id.exists' => 'A categoria escolhida não existe ou não pertence a você.',
            'description.max' => 'A descrição pode ter no máximo 255 caracteres.',
            'date.required' => 'Informe a data da transação.',
            'date.date' => 'Data inválida.',
            'date.after_or_equal' => 'A data deve ser a partir de 01/01/2000.',
            'date.before_or_equal' => 'A data está longe demais no futuro.',
        ];
    }
}
