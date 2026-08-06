<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Investment;
use App\Support\Brl;
use App\Support\FundingSource;
use Illuminate\Support\Collection;

/**
 * Fonte única de verdade do que pode ser gasto numa conta.
 *
 * O modelo tem quatro bolsos (ver docs/superpowers/specs/2026-07-27-…):
 *
 *   saldo bruto ─┬─ reservado  (metas + investimentos: está na conta, mas não é para gastar)
 *                └─ DISPONÍVEL (= o "saldo" que o usuário vê)
 *   + cheque especial (crédito do banco, só entra quando o disponível zera)
 *
 * Gastável = disponível que resta + cheque especial que resta.
 * Piso do disponível = −limite do cheque especial.
 *
 * Este serviço só CALCULA e AVALIA. Quem grava (com lock e transação) é o
 * FundingService — o cálculo aqui é o time-of-check; lá é o time-of-use.
 */
class SpendingGuard
{
    /** Tolerância de centavo, igual à usada em HandlesContributions. */
    public const EPSILON = 0.001;

    public const OK = 'ok';

    public const PRECISA_FONTE = 'precisa_fonte';

    public const ESTOURA_LIMITE = 'estoura_limite';

    /**
     * Retrato dos bolsos de uma conta, pronto para exibição e para decisão.
     *
     * @return array{saldo: float, reservado: float, disponivel: float, chequeLimite: float, chequeUsado: float, chequeDisponivel: float, gastavel: float}
     */
    public function snapshot(Account $account): array
    {
        return [
            'saldo' => $account->balance,
            'reservado' => $account->reserved,
            'disponivel' => $account->available,
            'chequeLimite' => $account->overdraftLimitValue,
            'chequeUsado' => $account->overdraftUsed,
            'chequeDisponivel' => $account->overdraftAvailable,
            'gastavel' => $account->spendable,
        ];
    }

    /**
     * A conta suporta esta despesa?
     *
     * - OK              → o disponível cobre sozinho, nada a perguntar.
     * - PRECISA_FONTE   → o disponível não cobre, mas cheque especial e/ou
     *                     resgate de investimento cobrem: perguntar ao usuário.
     * - ESTOURA_LIMITE  → nenhuma fonte cobre: recusar.
     *
     * $ignore é o valor ANTIGO da própria transação numa edição — sem ele,
     * editar uma despesa de R$ 100,00 para R$ 100,00 seria recusada por
     * "faltar" o valor que ela mesma já ocupa.
     */
    public function check(Account $account, float $amount, float $ignore = 0.0): string
    {
        // Cartão de crédito não é caixa: quem manda ali é o limite de crédito.
        if (! $account->isCash()) {
            return self::OK;
        }

        $amount = round($amount, 2);
        $disponivel = $account->availableWith($ignore);

        if ($amount <= $disponivel + self::EPSILON) {
            return self::OK;
        }

        // As duas fontes medem coisas DIFERENTES — ver `faltante()` (o que o
        // resgate precisa trazer) e `chequeNecessario()` (o cheque adicional).
        // `...With($ignore)`: o teto do cheque especial tem de enxergar o mesmo cenário
        // que o disponível acima. Usando o accessor sem ignore, editar uma despesa de
        // 2.000 para 2.100 numa conta no vermelho era recusado indevidamente, com a
        // mensagem se contradizendo ("o máximo agora é R$ 500,00").
        $cobreCheque = $this->chequeNecessario($account, $amount, $ignore)
            <= $account->overdraftAvailableWith($ignore) + self::EPSILON;

        // O resgate sai de UM investimento (o request carrega um
        // `funding_investment_id` só), então quem manda é o MAIOR resgatável e
        // nunca a soma. Medir pela soma abria um beco sem saída: o modal dizia
        // que a fonte cobria, o usuário escolhia, e o `FundingService` recusava
        // com "o investimento X tem só R$ 300,00" — sem nenhuma outra opção.
        $cobreResgate = $this->faltante($account, $amount, $ignore)
            <= $this->maiorResgatavel($account) + self::EPSILON;

        return ($cobreCheque || $cobreResgate) ? self::PRECISA_FONTE : self::ESTOURA_LIMITE;
    }

    /**
     * Quanto o RESGATE precisa trazer para a despesa caber E a conta terminar
     * em zero ou acima (0 quando já cabe no disponível).
     *
     * Inclui o buraco que a conta JÁ tem: com disponível em −100 e despesa de
     * 300, são 400 — não 300. O clamp antigo (`max(0, $disponivel)`) tratava
     * conta no vermelho como conta zerada: resgatava 300, a despesa consumia os
     * 300 e a conta continuava em −100, enquanto o modal prometia "seu saldo não
     * fica negativo". Ou a promessa era falsa, ou o número estava errado — o
     * número estava errado, porque "tirar do investimento" existe justamente
     * para não ficar no vermelho.
     *
     * Com este cálculo o disponível final é sempre exatamente 0
     * (`disponivel + faltante − amount`), e o resgate segue limitado pelo que
     * aquela conta aportou (`resgatavelDe`), então não cria dinheiro do nada.
     */
    public function faltante(Account $account, float $amount, float $ignore = 0.0): float
    {
        return round(max(0.0, round($amount, 2) - $account->availableWith($ignore)), 2);
    }

    /**
     * Quanto de cheque especial ADICIONAL esta despesa passa a consumir.
     *
     * Aqui o clamp em zero é obrigatório, e é por isso que este número existe
     * separado do `faltante()`: o vermelho que a conta já tem foi descontado do
     * `overdraftAvailable`, então somá-lo de novo contaria o mesmo buraco duas
     * vezes e recusaria despesa que cabe no limite (disponível −100, limite 500,
     * despesa 350: cabe, e a conta a compararia com 450 > 400).
     *
     * É este valor — o incremento — que vai para `funding_amount` na auditoria.
     */
    public function chequeNecessario(Account $account, float $amount, float $ignore = 0.0): float
    {
        $disponivel = $account->availableWith($ignore);

        return round(max(0.0, round($amount, 2) - max(0.0, $disponivel)), 2);
    }

    /**
     * Total que dá para resgatar de investimentos PARA ESTA CONTA — só o que
     * saiu dela em aportes. Resgatar para uma conta que nunca aportou deixaria
     * o reservado dela negativo e criaria disponível do nada.
     */
    public function resgatavel(Account $account): float
    {
        return $this->agregadosResgataveis($this->investimentosResgataveis($account))['total'];
    }

    /**
     * O maior valor resgatável de UM ÚNICO investimento para esta conta.
     *
     * É este — e não a soma — o teto real de um resgate, porque o pedido carrega
     * um `funding_investment_id` só. Dois CDBs de R$ 300 não pagam um faltante
     * de R$ 500 numa tacada: para isso o usuário resgata na tela de
     * Investimentos e lança a despesa depois.
     */
    public function maiorResgatavel(Account $account): float
    {
        return $this->agregadosResgataveis($this->investimentosResgataveis($account))['maior'];
    }

    /**
     * Soma e máximo dos resgatáveis, com os valores já em float.
     *
     * O alias `resgatavel` vem cru do SELECT (string no sqlite), então converter
     * antes de somar/comparar evita comparação de string em cenário de centavos.
     *
     * @param  Collection<int, Investment>  $investimentos
     * @return array{total: float, maior: float}
     */
    private function agregadosResgataveis($investimentos): array
    {
        $valores = $investimentos->map(fn ($i) => round((float) $i->resgatavel, 2));

        return [
            'total' => round((float) $valores->sum(), 2),
            'maior' => round((float) ($valores->max() ?? 0), 2),
        ];
    }

    /**
     * Investimentos da família com valor resgatável para esta conta, do maior
     * para o menor. Cada item traz `id`, `name`, `classe` e `resgatavel`.
     *
     * @return Collection<int, Investment>
     */
    public function investimentosResgataveis(Account $account)
    {
        return Investment::query()
            ->where('investments.user_id', $account->user_id)
            ->join('investment_contributions as ic', 'ic.investment_id', '=', 'investments.id')
            ->where('ic.account_id', $account->id)
            ->groupBy('investments.id', 'investments.name', 'investments.classe')
            ->selectRaw('investments.id, investments.name, investments.classe, '
                ."COALESCE(SUM(CASE WHEN ic.type = 'aporte' THEN ic.amount ELSE -ic.amount END), 0) AS resgatavel")
            ->havingRaw('resgatavel > 0')
            ->orderByDesc('resgatavel')
            ->get();
    }

    /**
     * Quanto desta conta dá para resgatar de UM investimento específico.
     * Limitado ao que saiu desta conta (não ao aplicado total do investimento),
     * pela mesma razão de `resgatavel()`.
     */
    public function resgatavelDe(Account $account, Investment $investment): float
    {
        $total = $investment->contributions()
            ->where('account_id', $account->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'aporte' THEN amount ELSE -amount END), 0) AS total")
            ->value('total');

        return round(max(0.0, (float) $total), 2);
    }

    /**
     * Payload que o front usa para montar o modal "de onde sai esse dinheiro?".
     * Só entram as fontes que existem; a que não cobre vem com `cobre: false` e
     * um `motivo` PT-BR, para aparecer desabilitada com a explicação em vez de
     * sumir (ou, pior, de prometer o que o servidor vai recusar).
     */
    public function opcoesDeFonte(Account $account, float $amount, float $ignore = 0.0): array
    {
        $amount = round($amount, 2);
        $disponivel = $account->availableWith($ignore);
        $faltante = $this->faltante($account, $amount, $ignore);
        $doCheque = $this->chequeNecessario($account, $amount, $ignore);

        $investimentos = $this->investimentosResgataveis($account);
        ['total' => $totalResgatavel, 'maior' => $maiorResgatavel] = $this->agregadosResgataveis($investimentos);

        $fontes = [];

        if ($account->overdraftLimitValue > 0) {
            $tetoCheque = $account->overdraftAvailableWith($ignore);
            $cobreCheque = $doCheque <= $tetoCheque + self::EPSILON;
            $novoSaldo = round($disponivel - $amount, 2);

            $fontes[] = [
                'id' => FundingSource::CHEQUE_ESPECIAL,
                // Teto do cheque = o que RESTA do limite; o vermelho atual já
                // saiu daqui, por isso a comparação é com o incremento.
                'teto' => $tetoCheque,
                'rotulo' => 'Usar o cheque especial',
                'cobre' => $cobreCheque,
                'detalhe' => 'Sua conta fica em '.Brl::format($novoSaldo)
                    .' — o limite é '.Brl::format($account->overdraftLimitValue).'.',
                'motivo' => $cobreCheque ? null : 'Passa do limite: esta despesa usaria '
                    .Brl::format($doCheque).' de cheque especial e ainda restam '
                    .Brl::format($tetoCheque).'.',
            ];
        }

        if ($totalResgatavel > 0) {
            // `cobre` pelo MAIOR investimento, nunca pela soma — ver `check()`.
            $cobreResgate = $faltante <= $maiorResgatavel + self::EPSILON;

            $detalhe = 'Vamos resgatar '.Brl::format($faltante).' — sua conta não fica negativa.';
            if ($disponivel < -self::EPSILON) {
                // Sem isto, resgatar 400 para uma despesa de 300 parece defeito.
                $detalhe .= ' Inclui os '.Brl::format(abs($disponivel))
                    .' que a conta já está devendo.';
            }

            $fontes[] = [
                'id' => FundingSource::RESGATE_INVESTIMENTO,
                'rotulo' => 'Resgatar de um investimento',
                'teto' => $maiorResgatavel,
                'total' => $totalResgatavel,
                'cobre' => $cobreResgate,
                'detalhe' => $detalhe,
                'motivo' => $cobreResgate ? null
                    : $this->motivoDoResgate($faltante, $maiorResgatavel, $totalResgatavel),
                'itens' => $investimentos->map(fn ($i) => [
                    'id' => $i->id,
                    'nome' => $i->name,
                    'aplicado' => round((float) $i->resgatavel, 2),
                    'cobre' => $faltante <= round((float) $i->resgatavel, 2) + self::EPSILON,
                ])->values()->all(),
            ];
        }

        return [
            'conta' => ['id' => $account->id, 'nome' => $account->name],
            'valor' => $amount,
            'disponivel' => $disponivel,
            'faltante' => $faltante,
            'fontes' => $fontes,
        ];
    }

    /**
     * Por que o resgate não cobre — e, quando os investimentos somados dariam,
     * qual é a saída de verdade (resgatar mais de um na tela de Investimentos).
     * Sem esta segunda frase o usuário só vê "não cobre" tendo dinheiro aplicado
     * de sobra, que é exatamente a sensação de beco sem saída.
     */
    private function motivoDoResgate(float $faltante, float $maior, float $total): string
    {
        $msg = 'Não cobre: o resgate sai de um investimento por vez, e o maior desta conta tem '
            .Brl::format($maior).' — faltam '.Brl::format($faltante).'.';

        if ($total > $maior + self::EPSILON && $total >= $faltante - self::EPSILON) {
            $msg .= ' Somando todos daria ('.Brl::format($total)
                .'): resgate de mais de um em Investimentos e lance a despesa depois.';
        }

        return $msg;
    }

    /**
     * Mensagem PT-BR de quando nenhuma fonte cobre a despesa. Aponta a saída:
     * lançar um recebimento que complete o valor.
     */
    public function mensagemSemFonte(Account $account, float $amount, float $ignore = 0.0): string
    {
        $disponivel = $account->availableWith($ignore);
        $gastavel = $account->spendableWith($ignore);
        ['total' => $total, 'maior' => $maior] = $this->agregadosResgataveis(
            $this->investimentosResgataveis($account)
        );

        $msg = 'Saldo insuficiente: a conta '.$account->name.' tem '
            .Brl::format($disponivel).' disponíveis e esta despesa é de '
            .Brl::format($amount).'.';

        if ($account->overdraftLimitValue > 0) {
            $msg .= ' Somando o cheque especial, o máximo agora é '.Brl::format($gastavel).'.';
        }

        if ($total > 0) {
            // O resgate sai de UM investimento, então o que importa é o maior —
            // dizer "resgatando tudo" prometeria uma soma que o app não faz numa
            // tacada só.
            $msg .= ' Resgatando o maior investimento desta conta ('.Brl::format($maior)
                .') ainda não dá.';

            if ($total > $maior + self::EPSILON) {
                $msg .= ' Somando todos são '.Brl::format($total)
                    .': dá para resgatar mais de um em Investimentos e lançar a despesa depois.';
            }
        }

        return $msg.' Lance um recebimento para completar o valor.';
    }

    /** Mensagem PT-BR de quando o cartão de crédito não tem limite para a compra. */
    public function mensagemLimiteCartao(Account $card, float $amount): string
    {
        return 'Esta compra passa do limite do cartão '.$card->name.': restam '
            .Brl::format($card->availableLimitDisplay).' de '
            .Brl::format((float) $card->credit_limit).', e a compra é de '
            .Brl::format($amount).'.';
    }
}
