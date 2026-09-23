<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conta fixa mensal (condomínio, aluguel, parcela do carro, escola).
 *
 * As ocorrências de cada mês NÃO ficam no banco: são projetadas de `starts_on`
 * até hoje pelo FixedBillService. Só vira `transaction` quando é paga. Por isso
 * a conta "nunca some" nem depende de agendador para existir.
 *
 * O VALOR PREVISTO tem histórico (`amount_history`): `amount` é o atual, e cada
 * competência usa o valor que valia no mês dela — ver `valorPrevistoEm()` e a
 * regra do reajuste em `definirValorPrevisto()`.
 */
class FixedBill extends Model
{
    use HasFactory;

    /**
     * `amount_history` fica FORA de propósito: só `definirValorPrevisto()` escreve
     * nele. Aceito em massa, um `update($request->validated())` com o campo
     * reescreveria o passado — o defeito que o histórico existe para impedir.
     */
    protected $fillable = [
        'user_id',
        'made_by_user_id',
        'name',
        'amount',
        'due_day',
        'account_id',
        'category_id',
        'starts_on',
        'ends_on',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_history' => 'array',
            'due_day' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Quem cadastrou a conta fixa (titular ou dependente). */
    public function madeBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'made_by_user_id');
    }

    /** Método de pagamento padrão (opcional). */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** Pagamentos já feitos (uma transação por competência quitada). */
    public function payments(): HasMany
    {
        return $this->hasMany(Transaction::class, 'fixed_bill_id');
    }

    /**
     * Vencimento numa competência (mês), com clamp de mês curto: dia 31 em
     * fevereiro vira 28/29. Mesma regra do ciclo de fatura do cartão.
     */
    public function dueDateFor(CarbonImmutable $competence): CarbonImmutable
    {
        $inicio = $competence->startOfMonth();
        $dia = max(1, min((int) $this->due_day, $inicio->daysInMonth));

        return $inicio->day($dia);
    }

    /**
     * Valor PREVISTO de uma competência: o que a conta valia naquele mês.
     *
     * É o número de toda competência ainda não paga — no bloco de /faturas, no sino,
     * nos lembretes por e-mail e no teto de 3× do pagamento. (A competência paga
     * mostra o valor da transação do pagamento, não este.)
     *
     * O histórico está em ordem crescente de `until`, e cada período cobre
     * (`until` anterior, `until`]: o primeiro período que alcança o mês é o dele.
     * Depois do último, vale o `amount` atual — sem histórico, vale para tudo.
     */
    public function valorPrevistoEm(CarbonImmutable $competencia): float
    {
        $mes = $competencia->format('Y-m');

        foreach ($this->amount_history ?? [] as $periodo) {
            if ($mes <= $periodo['until']) {
                return round((float) $periodo['amount'], 2);
            }
        }

        return round((float) $this->amount, 2);
    }

    /**
     * Troca o valor previsto aplicando a REGRA DO REAJUSTE. Não salva.
     *
     * A regra: **o valor de cada competência é o último salvo até o mês dela.**
     *
     *  - Reajuste (o valor atual foi salvo num mês ANTERIOR ao de hoje): o valor
     *    novo vale a partir da competência do mês corrente; as competências
     *    anteriores guardam o valor que tinham (vira um período do histórico, com
     *    `until` = mês passado). Reajustar o aluguel em julho não mexe em maio e
     *    junho ainda em aberto.
     *  - Correção (o valor atual foi salvo NESTE mês — na criação ou num reajuste
     *    deste mês): só troca o valor, sem histórico novo. Dentro do mesmo mês a
     *    última edição vale, porque o reajuste tem a granularidade da competência:
     *    duas edições no mesmo mês não podem ser dois reajustes de meses diferentes.
     *    É o que faz a CORREÇÃO DE DIGITAÇÃO LOGO DEPOIS DE CRIAR valer para todas
     *    as competências, inclusive as de antes da criação (conta cadastrada em
     *    julho com início em maio e 18.000 no lugar de 1.800): o valor digitado
     *    errado nunca chegou a ser o de mês fechado nenhum. Sem isso, o teto de 3×
     *    travaria para sempre o pagamento de maio e junho com o valor certo.
     *  - Consequência aceita: a correção que cruza a virada do mês (criou dia 31,
     *    corrigiu dia 1º) já é reajuste — os meses de antes ficam com o valor
     *    digitado errado. A saída é pagar com o valor real (o campo é editável,
     *    dentro do teto de 3×) ou, sem pagamento ainda, excluir e recadastrar.
     *  - Valor igual ao atual (editar só o nome manda o mesmo valor de volta): nada
     *    muda, nem o histórico.
     *
     * O "mês em que o valor atual foi salvo" é derivado, sem coluna própria: o mês
     * seguinte ao `until` do último período do histórico ou, sem histórico, o mês de
     * `created_at` (nulo = desconhecido, tratado como antigo → reajuste).
     *
     * Quem chama trava a linha antes (`lockForUpdate`): o valor ATUAL é lido aqui
     * para virar histórico, e duas edições simultâneas não podem ler o mesmo.
     *
     * @return bool true quando o valor anterior foi preservado para as competências
     *              de antes do mês corrente (reajuste); false em correção ou sem mudança
     */
    public function definirValorPrevisto(float|int|string $novo, ?CarbonImmutable $hoje = null): bool
    {
        $hoje ??= CarbonImmutable::today();
        $novoCentavos = self::centavos($novo);
        $atualCentavos = self::centavos($this->amount);
        $reajuste = false;

        if ($this->exists && $novoCentavos !== $atualCentavos) {
            $desde = $this->mesDoValorAtual();

            if ($desde === null || $desde < $hoje->format('Y-m')) {
                $historico = $this->amount_history ?? [];
                $historico[] = [
                    'until' => $hoje->startOfMonth()->subMonthNoOverflow()->format('Y-m'),
                    'amount' => self::formatarCentavos($atualCentavos),
                ];
                $this->amount_history = $historico;
                $reajuste = true;
            }
        }

        $this->amount = self::formatarCentavos($novoCentavos);

        return $reajuste;
    }

    /** Mês ("Y-m") em que o valor atual foi salvo — ver `definirValorPrevisto()`. */
    private function mesDoValorAtual(): ?string
    {
        $historico = $this->amount_history ?? [];

        if ($historico !== []) {
            return CarbonImmutable::createFromFormat('!Y-m', end($historico)['until'])
                ->addMonthNoOverflow()
                ->format('Y-m');
        }

        return $this->created_at?->format('Y-m');
    }

    /**
     * Dinheiro em centavos inteiros, para comparar sem erro de ponto flutuante
     * ("1.500,00" normalizado chega como "1500.00"; o cast devolve "1500.00").
     */
    private static function centavos(float|int|string|null $valor): int
    {
        return (int) round(((float) $valor) * 100);
    }

    private static function formatarCentavos(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }
}
