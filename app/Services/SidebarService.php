<?php

namespace App\Services;

use App\Models\Account;
use App\Models\GoalContribution;
use App\Models\InvestmentContribution;
use App\Models\Transaction;
use Carbon\CarbonImmutable;

/**
 * Dados do card "Patrimônio total" da sidebar (presente em TODAS as páginas
 * autenticadas), calculados com poucas queries agregadas baratas:
 * - saldo total (soma dos saldos iniciais + receitas − despesas de todas as contas);
 * - sparkline com o saldo acumulado dia a dia dos últimos 7 dias
 *   (mesma lógica do sparkline "saldo" do dashboard — DashboardService::saldoSpark);
 * - variação % do saldo atual vs o saldo de 30 dias atrás (null = sem base, oculta .sb-foot).
 *
 * Registrado via View Composer no AppServiceProvider para partials.sidebar.
 */
class SidebarService
{
    public function __construct(private DashboardService $dashboard)
    {
    }

    /** Monta os dados do card de patrimônio, escopado no usuário. */
    public function build(int $userId): array
    {
        $today = CarbonImmutable::today();

        // Cartões NÃO são caixa e ficam fora do patrimônio/saldo: o de CRÉDITO
        // porque é dívida, o de DÉBITO porque só espelha a corrente/poupança
        // vinculada (contá-lo somaria o mesmo dinheiro duas vezes).
        // Precisa ser a MESMA lista do DashboardService — quando a sidebar
        // excluía só o crédito, os dois totais divergiam na mesma tela.
        $cardIds = Account::where('user_id', $userId)
            ->whereIn('type', ['credit_card', 'debit_card'])
            ->pluck('id')
            ->all();

        // Soma dos saldos iniciais das contas que são caixa (sem cartões) (1 query)
        $initialTotal = round((float) Account::where('user_id', $userId)
            ->whereNotIn('id', $cardIds)
            ->sum('initial_balance'), 2);

        // Receitas − despesas das transações que NÃO são de cartão (1 query)
        $delta = (float) Transaction::where('user_id', $userId)
            ->whereNotIn('account_id', $cardIds)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE -amount END), 0) AS signed_total")
            ->value('signed_total');

        $saldoTotal = round($initialTotal + $delta, 2);
        $hasData = Transaction::where('user_id', $userId)->exists();

        // Reservado em metas (modelo "cofrinho"): Σ aportes − Σ resgates da família.
        // = total guardado nas metas; o disponível é o saldo cru menos isso.
        // O patrimônio total (saldoTotal) NÃO muda: o dinheiro só está "earmarked".
        $guardado = (float) GoalContribution::query()
            ->join('goals', 'goals.id', '=', 'goal_contributions.goal_id')
            ->where('goals.user_id', $userId)
            ->selectRaw("COALESCE(SUM(CASE WHEN goal_contributions.type = 'aporte' THEN goal_contributions.amount ELSE -goal_contributions.amount END), 0) AS reservado")
            ->value('reservado');
        $guardado = round($guardado, 2);

        // Aplicado em investimentos (mesmo modelo "cofrinho"): Σ aportes − Σ resgates
        // da família. Também é dinheiro "earmarked" — sai do disponível, mas continua
        // dentro do saldo cru (patrimônio total não muda).
        $investido = (float) InvestmentContribution::query()
            ->join('investments', 'investments.id', '=', 'investment_contributions.investment_id')
            ->where('investments.user_id', $userId)
            ->selectRaw("COALESCE(SUM(CASE WHEN investment_contributions.type = 'aporte' THEN investment_contributions.amount ELSE -investment_contributions.amount END), 0) AS aplicado")
            ->value('aplicado');
        $investido = round($investido, 2);

        // Disponível = saldo cru − guardado em metas − investido (o que sobra para gastar).
        $disponivel = round($saldoTotal - $guardado - $investido, 2);

        // Sparkline: saldo acumulado dos últimos 7 dias (lógica compartilhada
        // com o dashboard, cartões excluídos); sem transações => linha flat no nível atual.
        $spark = $hasData
            ? $this->dashboard->saldoSpark($userId, $initialTotal, $today, null, $cardIds)
            : array_fill(0, 7, $saldoTotal);

        // Variação % vs saldo de 30 dias atrás (base zero/sem dados => null).
        // Cartões ficam fora do cálculo (não são caixa).
        $variacao = null;
        if ($hasData) {
            $saldoAnterior = round(
                $initialTotal + $this->dashboard->signedSumUntil($userId, $today->subDays(30), $cardIds),
                2,
            );
            $variacao = $this->dashboard->pctChange($saldoTotal, $saldoAnterior);
        }

        // Cheque especial da família: limite total das contas correntes e quanto
        // dele já está sendo usado (uma conta por vez, só as que têm limite).
        $chequeLimite = round((float) Account::where('user_id', $userId)
            ->where('type', 'checking')
            ->sum('overdraft_limit'), 2);

        $chequeUsado = 0.0;
        if ($chequeLimite > 0) {
            foreach (Account::where('user_id', $userId)->where('type', 'checking')->get() as $conta) {
                $chequeUsado += $conta->overdraftUsed;
            }
            $chequeUsado = round($chequeUsado, 2);
        }

        return [
            'saldoTotal' => $saldoTotal,
            // Cheque especial (só conta corrente): total, usado e o que resta.
            'chequeLimite' => $chequeLimite,
            'chequeUsado' => $chequeUsado,
            'chequeDisponivel' => round(max(0.0, $chequeLimite - $chequeUsado), 2),
            // "Em conta" = o que está nas contas, fora dos investimentos
            // (= disponível + guardado em metas). Patrimônio total = isto + investido.
            'emConta' => round($disponivel + $guardado, 2),
            // Cofrinho: disponível (saldo cru − reservado), guardado em metas e investido.
            'disponivel' => $disponivel,
            'guardado' => $guardado,
            'investido' => $investido,
            'variacao' => $variacao,
            'spark' => $spark,
            'sparkLine' => $this->sparkPath($spark, false),
            'sparkArea' => $this->sparkPath($spark, true),
        ];
    }

    /**
     * Converte os 7 pontos em um path SVG no viewBox 180×40 do protótipo
     * (x de 2 a 178; y entre 6 e 30; área fechada até y=40 quando $area).
     * Série flat (sem variação) vira uma linha no meio do card.
     */
    private function sparkPath(array $points, bool $area): string
    {
        $n = count($points);
        if ($n < 2) {
            return '';
        }

        $min = min($points);
        $max = max($points);
        $range = $max - $min;

        $coords = [];
        foreach ($points as $i => $value) {
            $x = round(2 + ($i * 176 / ($n - 1)), 1);
            $y = $range > 0
                ? round(30 - (($value - $min) / $range) * 24, 1)
                : 18.0; // todos iguais => linha no meio
            $coords[] = [$x, $y];
        }

        $d = 'M' . implode(' L', array_map(fn ($c) => "{$c[0]} {$c[1]}", $coords));

        if ($area) {
            $d .= ' L178 40 L2 40 Z';
        }

        return $d;
    }
}
