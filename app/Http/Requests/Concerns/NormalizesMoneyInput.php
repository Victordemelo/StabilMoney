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
     * Teto de QUALQUER campo de dinheiro do app: R$ 999.999.999.999,99.
     *
     * O banco é `decimal(15,2)`, que em tese caberia até 9.999.999.999.999,99 —
     * e era esse o `max` de todos os Form Requests. Só que esse número NÃO
     * sobrevive à viagem em PHP: com o `precision=14` padrão,
     * `(string) (float) '9999999999999.99'` devolve **"10000000000000"**, e é
     * essa string que o PDO manda no bind. O MySQL responde
     * `SQLSTATE[22003] Out of range value` — ou seja, o valor EXATAMENTE no teto
     * da validação passava pelo Form Request e explodia em **erro 500**, em vez
     * de virar um erro de validação em PT-BR. (Verificado no container em
     * 02/08/2026, tanto no bind cru quanto via Eloquent.)
     *
     * Com 14 dígitos significativos (12 inteiros + 2 centavos) o float volta a
     * virar string exata, e o valor no teto grava certinho. Quase um trilhão de
     * reais continua sendo teto de sobra para finanças pessoais.
     */
    public const TETO_MONETARIO = '999999999999.99';

    /**
     * Regras padrão de um campo de dinheiro. Use SEMPRE que criar um campo
     * monetário novo — são quatro travas e esquecer qualquer uma abre um buraco:
     *
     *  - `numeric`      → tem de ser número;
     *  - `decimal:0,2`  → mata a notação científica ("1e12" é `numeric` para o
     *                     PHP!) e a terceira casa decimal, que o banco
     *                     arredondaria em silêncio (sqlite chegava a gravar);
     *  - `min`          → dinheiro nunca é negativo (o sinal vem do `type`);
     *  - `max`          → ver TETO_MONETARIO.
     *
     * @param  bool    $obrigatorio  false = `nullable` no lugar de `required`.
     * @param  string  $min          "0.01" (valor que precisa existir) ou "0".
     * @return list<string>
     */
    protected function regrasDeDinheiro(bool $obrigatorio = true, string $min = '0.01'): array
    {
        return [
            $obrigatorio ? 'required' : 'nullable',
            'numeric',
            'decimal:0,2',
            'min:' . $min,
            'max:' . self::TETO_MONETARIO,
        ];
    }

    /**
     * Converte um campo no padrão monetário pt-BR ("1.234,56") para decimal
     * ("1234.56"), in-place via merge(). Só atua se o campo for string.
     *
     * ⚠️ Ponto seguido de EXATAMENTE 3 dígitos é lido como separador de MILHAR,
     * não como decimal: "800.123" vira "800123" (oitocentos mil), que é o que o
     * brasileiro quis dizer. Quem quer a terceira casa decimal escreve
     * "800,123" — e aí o `decimal:0,2` recusa, como tem de ser.
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
