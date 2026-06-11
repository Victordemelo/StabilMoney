<?php

namespace App\Services;

use App\Models\Account;
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

        // Soma dos saldos iniciais de todas as contas (1 query)
        $initialTotal = round((float) Account::where('user_id', $userId)->sum('initial_balance'), 2);

        // Receitas − despesas de todas as transações (1 query)
        $delta = (float) Transaction::where('user_id', $userId)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE -amount END), 0) AS signed_total")
            ->value('signed_total');

        $saldoTotal = round($initialTotal + $delta, 2);
        $hasData = Transaction::where('user_id', $userId)->exists();

        // Sparkline: saldo acumulado dos últimos 7 dias (lógica compartilhada
        // com o dashboard); sem transações => linha flat no nível atual.
        $spark = $hasData
            ? $this->dashboard->saldoSpark($userId, $initialTotal, $today)
            : array_fill(0, 7, $saldoTotal);

        // Variação % vs saldo de 30 dias atrás (base zero/sem dados => null)
        $variacao = null;
        if ($hasData) {
            $saldoAnterior = round(
                $initialTotal + $this->dashboard->signedSumUntil($userId, $today->subDays(30)),
                2,
            );
            $variacao = $this->dashboard->pctChange($saldoTotal, $saldoAnterior);
        }

        return [
            'saldoTotal' => $saldoTotal,
            // "Em conta" = soma das contas (sem investimentos ainda — mesma
            // semântica do protótipo, onde "em conta" são as contas).
            'emConta' => $saldoTotal,
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
