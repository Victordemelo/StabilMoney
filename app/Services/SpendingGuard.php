<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Investment;
use App\Support\Brl;

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
        $disponivel = round($account->available + $ignore, 2);

        if ($amount <= $disponivel + self::EPSILON) {
            return self::OK;
        }

        $faltante = round($amount - max(0.0, $disponivel), 2);
        $cobreCheque = $faltante <= $account->overdraftAvailable + self::EPSILON;
        $cobreResgate = $faltante <= $this->resgatavel($account) + self::EPSILON;

        return ($cobreCheque || $cobreResgate) ? self::PRECISA_FONTE : self::ESTOURA_LIMITE;
    }

    /**
     * Quanto falta para a despesa caber no disponível (0 quando cabe).
     * É este valor — não o total da despesa — que é resgatado do investimento.
     */
    public function faltante(Account $account, float $amount, float $ignore = 0.0): float
    {
        $disponivel = round($account->available + $ignore, 2);

        return round(max(0.0, round($amount, 2) - max(0.0, $disponivel)), 2);
    }

    /**
     * Total que dá para resgatar de investimentos PARA ESTA CONTA — só o que
     * saiu dela em aportes. Resgatar para uma conta que nunca aportou deixaria
     * o reservado dela negativo e criaria disponível do nada.
     */
    public function resgatavel(Account $account): float
    {
        return round((float) $this->investimentosResgataveis($account)->sum('resgatavel'), 2);
    }

    /**
     * Investimentos da família com valor resgatável para esta conta, do maior
     * para o menor. Cada item traz `id`, `name`, `classe` e `resgatavel`.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Investment>
     */
    public function investimentosResgataveis(Account $account)
    {
        return Investment::query()
            ->where('investments.user_id', $account->user_id)
            ->join('investment_contributions as ic', 'ic.investment_id', '=', 'investments.id')
            ->where('ic.account_id', $account->id)
            ->groupBy('investments.id', 'investments.name', 'investments.classe')
            ->selectRaw('investments.id, investments.name, investments.classe, '
                . "COALESCE(SUM(CASE WHEN ic.type = 'aporte' THEN ic.amount ELSE -ic.amount END), 0) AS resgatavel")
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
     * Só entram as fontes que existem; a que não cobre vem com `cobre: false`
     * para aparecer desabilitada com o motivo, em vez de sumir.
     */
    public function opcoesDeFonte(Account $account, float $amount, float $ignore = 0.0): array
    {
        $faltante = $this->faltante($account, $amount, $ignore);
        $investimentos = $this->investimentosResgataveis($account);
        $totalResgatavel = round((float) $investimentos->sum('resgatavel'), 2);

        $fontes = [];

        if ($account->overdraftLimitValue > 0) {
            $novoSaldo = round($account->available + $ignore - $amount, 2);
            $fontes[] = [
                'id' => \App\Support\FundingSource::CHEQUE_ESPECIAL,
                'rotulo' => 'Usar o cheque especial',
                'teto' => $account->overdraftAvailable,
                'cobre' => $faltante <= $account->overdraftAvailable + self::EPSILON,
                'detalhe' => 'Sua conta fica em ' . Brl::format($novoSaldo)
                    . ' — o limite é ' . Brl::format($account->overdraftLimitValue) . '.',
            ];
        }

        if ($totalResgatavel > 0) {
            $fontes[] = [
                'id' => \App\Support\FundingSource::RESGATE_INVESTIMENTO,
                'rotulo' => 'Resgatar de um investimento',
                'teto' => $totalResgatavel,
                'cobre' => $faltante <= $totalResgatavel + self::EPSILON,
                'detalhe' => 'Vamos resgatar ' . Brl::format($faltante)
                    . ' — seu saldo não fica negativo.',
                'itens' => $investimentos->map(fn ($i) => [
                    'id' => $i->id,
                    'nome' => $i->name,
                    'aplicado' => round((float) $i->resgatavel, 2),
                    'cobre' => $faltante <= (float) $i->resgatavel + self::EPSILON,
                ])->values()->all(),
            ];
        }

        return [
            'conta' => ['id' => $account->id, 'nome' => $account->name],
            'valor' => round($amount, 2),
            'disponivel' => round($account->available + $ignore, 2),
            'faltante' => $faltante,
            'fontes' => $fontes,
        ];
    }

    /**
     * Mensagem PT-BR de quando nenhuma fonte cobre a despesa. Aponta a saída:
     * lançar um recebimento que complete o valor.
     */
    public function mensagemSemFonte(Account $account, float $amount, float $ignore = 0.0): string
    {
        $disponivel = round($account->available + $ignore, 2);
        $gastavel = round(max(0.0, $disponivel) + $account->overdraftAvailable, 2);
        $resgatavel = $this->resgatavel($account);

        $msg = 'Saldo insuficiente: a conta ' . $account->name . ' tem '
            . Brl::format($disponivel) . ' disponíveis e esta despesa é de '
            . Brl::format($amount) . '.';

        if ($account->overdraftLimitValue > 0) {
            $msg .= ' Somando o cheque especial, o máximo agora é ' . Brl::format($gastavel) . '.';
        }

        if ($resgatavel > 0) {
            $msg .= ' Resgatando tudo o que está investido nesta conta ('
                . Brl::format($resgatavel) . ') ainda não dá.';
        }

        return $msg . ' Lance um recebimento para completar o valor.';
    }

    /** Mensagem PT-BR de quando o cartão de crédito não tem limite para a compra. */
    public function mensagemLimiteCartao(Account $card, float $amount): string
    {
        return 'Esta compra passa do limite do cartão ' . $card->name . ': restam '
            . Brl::format($card->availableLimitDisplay) . ' de '
            . Brl::format((float) $card->credit_limit) . ', e a compra é de '
            . Brl::format($amount) . '.';
    }
}
