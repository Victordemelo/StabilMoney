<?php

namespace App\Http\Requests\Concerns;

/**
 * Normalização de valores digitados no padrão monetário pt-BR ("1.234,56" /
 * "R$ 1.234" → "1234.56" / "1234"). Centraliza o bloco que antes estava
 * copiado em ~10 Form Requests.
 *
 * Usar dentro de prepareForValidation():
 *   $this->normalizeMoneyField('amount');                       // valor monetário simples
 *   $this->normalizeMoneyField('valor_inicial', emptyToNull: true, stripPercent: true);
 *   $this->normalizePercentField('taxa');                       // atalho p/ taxa/percentual
 */
trait NormalizesMoneyInput
{
    /**
     * Converte um campo no padrão monetário pt-BR ("1.234,56") para decimal
     * ("1234.56"), in-place via merge(). Só atua se o campo for string.
     *
     * @param  string  $field        Nome do campo a normalizar.
     * @param  bool    $emptyToNull   Se true, string vazia vira null (em vez de "").
     * @param  bool    $stripPercent  Se true, remove também o símbolo "%" (campos de taxa).
     */
    protected function normalizeMoneyField(string $field, bool $emptyToNull = false, bool $stripPercent = false): void
    {
        $raw = $this->input($field);

        if (! is_string($raw)) {
            return;
        }

        $remover = $stripPercent ? ['R$', '%', ' '] : ['R$', ' '];
        $valor = trim(str_replace($remover, '', $raw));

        if ($emptyToNull && $valor === '') {
            $this->merge([$field => null]);

            return;
        }

        if (str_contains($valor, ',')) {
            $valor = str_replace('.', '', $valor);  // remove separador de milhar
            $valor = str_replace(',', '.', $valor); // vírgula decimal -> ponto
        } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $valor)) {
            $valor = str_replace('.', '', $valor);  // só milhares: "1.234" -> "1234"
        }

        $this->merge([$field => $valor]);
    }

    /**
     * Atalho para campos de taxa/percentual: remove "%" e converte vazio em null.
     */
    protected function normalizePercentField(string $field): void
    {
        $this->normalizeMoneyField($field, emptyToNull: true, stripPercent: true);
    }
}
