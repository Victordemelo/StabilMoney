@extends('layouts.app')

@section('title', 'Visão geral — StabilMoney')

@section('content')
@php
    // Helpers locais de formatação (espelham o BRL e o pct do dashboard.js,
    // para o server-render bater com o que o JS re-renderiza depois)
    $money = fn ($v, $dec = 2) => number_format($v, $dec, ',', '.');
    $pct = fn ($v) => rtrim(rtrim(number_format(abs($v), 1, ',', '.'), '0'), ',') . '%';
    $initials = function (string $name) {
        $words = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));
        return mb_strtoupper(count($words) >= 2
            ? mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1)
            : mb_substr($name, 0, 2));
    };

    $arrowUp = 'M7 17 17 7M17 7h-7M17 7v7';
    $arrowDown = 'M7 7 17 17M17 17h-7M17 17v-7';

    // Stat cards na mesma ordem/visual do design (ícones exatos do protótipo)
    $statCards = [
        ['key' => 'saldo', 'label' => 'Saldo total', 'g' => 'g1', 'delay' => '.02s',
         'icon' => '<path d="M3 7h18v12H3zM3 7l2-3h14l2 3M16 13h2"/>'],
        ['key' => 'receitas', 'label' => 'Receitas', 'g' => 'g2', 'delay' => '.08s',
         'icon' => '<path d="M12 19V5M12 5l-6 6M12 5l6 6"/>'],
        ['key' => 'despesas', 'label' => 'Despesas', 'g' => 'g3', 'delay' => '.14s',
         'icon' => '<path d="M12 5v14M12 19l6-6M12 19l-6-6"/>'],
        ['key' => 'economia', 'label' => 'Economizado', 'g' => 'g4', 'delay' => '.2s',
         'icon' => '<path d="M12 2v4M5 9a7 7 0 1 1 14 0c0 4-3 5-3 8H8c0-3-3-4-3-8Z"/><path d="M9 21h6"/>'],
    ];

    // Cores que ciclam nas iniciais das contas (mesmas do design)
    $abColors = ['var(--brand-600)', 'var(--c-lazer)', 'var(--c-alimentacao)'];
@endphp

<section class="view" id="view-dashboard">
    <div class="section-head">
        <h2>Visão geral</h2>
        <span class="sub" id="periodSub">{{ $payload['periods']['mes']['sub'] }}</span>
        <div class="seg" id="period">
            <span class="seg-pill" id="segPill"></span>
            <button type="button" data-p="semana"><span>Semana</span></button>
            <button type="button" data-p="mes" class="active"><span>Mês</span></button>
            <button type="button" data-p="ano"><span>Ano</span></button>
        </div>
    </div>

    <div class="grid">
        {{-- Stat cards (valores do mês renderizados no servidor; o JS anima/troca o período) --}}
        @foreach ($statCards as $card)
            @php
                $value = $monthStats[$card['key']];
                $trend = $monthTrends[$card['key']];
                // Para despesas, cair é bom (verde); para o resto, subir é bom
                $isUp = $trend === null ? null : ($card['key'] === 'despesas' ? $trend < 0 : $trend >= 0);
                $sparkVals = $payload['sparks'][$card['key']] ?? [];
            @endphp
            <div class="card stat span3" style="animation-delay:{{ $card['delay'] }}">
                <div class="stat-top">
                    <div class="ico {{ $card['g'] }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor">{!! $card['icon'] !!}</svg></div>
                    <span class="label">{{ $card['label'] }}</span>
                    @if ($trend === null)
                        <span class="trend neutral" data-trend="{{ $card['key'] }}">—</span>
                    @else
                        <span class="trend {{ $isUp ? 'up' : 'down' }}" data-trend="{{ $card['key'] }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="{{ $isUp ? $arrowUp : $arrowDown }}"/></svg>{{ $pct($trend) }}</span>
                    @endif
                </div>
                <div class="value"><span class="cur">R$</span><span class="num" data-count="{{ $value }}" data-dec="2">{{ $money($value) }}</span></div>
                @if (count($sparkVals) > 1)
                    <svg class="spark" data-spark="{{ $card['key'] }}" preserveAspectRatio="none" viewBox="0 0 120 34"></svg>
                @endif
            </div>
        @endforeach

        {{-- Fluxo de caixa --}}
        <div class="card span8" style="animation-delay:.16s">
            <div class="card-head">
                <h3>Fluxo de caixa</h3>
                @if ($hasData)
                    <div class="chart-legend">
                        <span class="lg"><span class="dotc" style="background:var(--brand-500)"></span>Receitas</span>
                        <span class="lg"><span class="dotc" style="background:var(--c-saude)"></span>Despesas</span>
                    </div>
                @endif
            </div>
            @if ($hasData)
                <div class="cashflow-wrap" id="cfWrap">
                    <svg class="cashflow-svg" id="cfSvg" preserveAspectRatio="none"></svg>
                    <div class="cf-tip" id="cfTip"></div>
                </div>
            @else
                <div class="empty-state">
                    <div class="pico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 19V6M4 19h16M8 16v-4M12 16V8M16 16v-7M20 16v-3"/></svg></div>
                    <h3>Sem movimentações ainda</h3>
                    <p>Lance sua primeira transação para acompanhar suas receitas e despesas por aqui.</p>
                    <a class="btn-primary" href="{{ route('transactions.create') }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>
                        Lançar transação
                    </a>
                </div>
            @endif
        </div>

        {{-- Gastos por categoria (donut) --}}
        <div class="card donut-card span4" style="animation-delay:.22s">
            <div class="card-head">
                <h3>Gastos por categoria</h3>
                <a class="mini-btn" href="{{ route('transactions.index') }}">Detalhes<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 6l6 6-6 6"/></svg></a>
            </div>
            @if (count($cats))
                <div class="donut-wrap">
                    <svg class="donut-svg" id="donut" viewBox="0 0 120 120"></svg>
                    <div class="cat-legend" id="catLegend"></div>
                </div>
            @else
                <div class="empty-state">
                    <div class="pico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21 12A9 9 0 1 1 12 3"/><path d="M12 3a9 9 0 0 1 9 9h-9Z"/></svg></div>
                    <h3>Sem gastos este mês</h3>
                    <p>Suas despesas do mês aparecerão aqui, agrupadas por categoria.</p>
                </div>
            @endif
        </div>

        {{-- Transações recentes --}}
        <div class="card span8" style="animation-delay:.26s">
            <div class="card-head">
                <h3>Transações recentes</h3>
                <a class="mini-btn" href="{{ route('transactions.index') }}">Ver todas<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 6l6 6-6 6"/></svg></a>
            </div>
            @if ($recent->isEmpty())
                <div class="empty-state">
                    <div class="pico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M7 8h13M7 8l3-3M7 8l3 3M17 16H4M17 16l-3-3M17 16l-3 3"/></svg></div>
                    <h3>Nenhuma transação ainda</h3>
                    <p>Quando você lançar receitas e despesas, as mais recentes aparecem aqui.</p>
                </div>
            @else
                <div class="tx-list">
                    @foreach ($recent as $transaction)
                        @php $isIncome = $transaction->type === 'income'; @endphp
                        <div class="tx">
                            <div class="tx-ico">
                                @if ($transaction->category?->icon)
                                    {{ $transaction->category->icon }}
                                @elseif ($isIncome)
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 19V5M12 5l-6 6M12 5l6 6"/></svg>
                                @else
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M5 7h14l-1.2 8.5a2 2 0 0 1-2 1.7H8.2a2 2 0 0 1-2-1.7L5 7Z"/><path d="M9 7a3 3 0 0 1 6 0M9 11v2M15 11v2"/></svg>
                                @endif
                            </div>
                            <div>
                                <div class="tx-name">{{ $transaction->description ?: ($transaction->category->name ?? ($isIncome ? 'Receita' : 'Despesa')) }}</div>
                                <div class="tx-meta">{{ $transaction->category->name ?? 'Sem categoria' }} · {{ $transaction->date_human }}</div>
                            </div>
                            <div class="tx-amt {{ $isIncome ? 'pos' : '' }}">{{ $isIncome ? '+' : '−' }} R$ {{ $money($transaction->amount) }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Meu cartão / contas --}}
        <div class="card span4" style="animation-delay:.3s">
            <div class="card-head">
                <h3>Meu cartão</h3>
                <a class="mini-btn" href="{{ route('accounts.index') }}">Gerenciar<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 6l6 6-6 6"/></svg></a>
            </div>
            @if ($accounts->isEmpty())
                <div class="empty-state">
                    <div class="pico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 9.5h19M6 15h4"/></svg></div>
                    <h3>Nenhuma conta ainda</h3>
                    <p>Cadastre sua carteira, banco ou cartão para acompanhar os saldos.</p>
                    <a class="btn-primary" href="{{ route('accounts.create') }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>
                        Adicionar conta
                    </a>
                </div>
            @else
                @php $firstAccount = $accounts->first(); @endphp
                <div class="cc">
                    <div class="cc-top">
                        <span class="net">StabilMoney</span>
                        <svg viewBox="0 0 48 48" width="30" height="30" aria-hidden="true">
                            <path d="M33 18.5 C33 13.5 28.5 11 24 11 C18.5 11 14.5 13.8 14.5 18 C14.5 22.2 18.5 23.5 24 24" fill="none" stroke="rgba(255,255,255,.9)" stroke-width="4" stroke-linecap="round"/>
                            <path d="M15 29.5 C15 34.5 19.5 37 24 37 C29.5 37 33.5 34.2 33.5 30 C33.5 25.8 29.5 24.5 24 24" fill="none" stroke="rgba(255,255,255,.9)" stroke-width="4" stroke-linecap="round"/>
                            <path d="M15.5 33 L32 15.5M24 14 L33.5 14 L33.5 23.5" fill="none" stroke="var(--brand-300)" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <div class="cc-chip"></div>
                    <div class="cc-num">{{ $firstAccount->name }}</div>
                    <div class="cc-bot">
                        <div>
                            <div class="lbl">Saldo disponível</div>
                            <div class="cc-balance">R$ {{ $money($firstAccount->current_balance) }}</div>
                        </div>
                    </div>
                </div>
                @if ($accounts->count() > 1)
                    <div style="margin-top:16px">
                        @foreach ($accounts->slice(1)->take(3)->values() as $account)
                            <div class="acct">
                                <div class="ab" style="background:{{ $abColors[$loop->index % count($abColors)] }}">{{ $initials($account->name) }}</div>
                                <div>
                                    <div class="an">{{ $account->name }}</div>
                                    <div class="at">{{ $account->type_label }}</div>
                                </div>
                                <div class="av">R$ {{ $money($account->current_balance) }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>

        {{-- Metas de economia (em breve) --}}
        <div class="card span4" style="animation-delay:.34s">
            <div class="card-head">
                <h3>Metas de economia</h3>
                <a class="mini-btn" href="{{ route('metas') }}">Todas<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 6l6 6-6 6"/></svg></a>
            </div>
            <div class="empty-state">
                <div class="pico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r=".7" fill="currentColor"/></svg></div>
                <h3>Em breve</h3>
                <p>Metas de economia chegam em uma versão futura.</p>
            </div>
        </div>

        {{-- Contas a pagar (em breve) --}}
        <div class="card span4" style="animation-delay:.38s">
            <div class="card-head">
                <h3>Contas a pagar</h3>
                <a class="mini-btn" href="{{ route('faturas') }}">Ver<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 6l6 6-6 6"/></svg></a>
            </div>
            <div class="empty-state">
                <div class="pico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 3h9l3 3v15l-2-1.3L13 21l-2-1.3L9 21l-2-1.3L5 21V5a2 2 0 0 1 1-2Z"/><path d="M9 8h6M9 12h6M9 16h3"/></svg></div>
                <h3>Em breve</h3>
                <p>Controle de contas a pagar chega em uma versão futura.</p>
            </div>
        </div>

        {{-- Investimentos (em breve) --}}
        <div class="card span4" style="animation-delay:.42s">
            <div class="card-head">
                <h3>Investimentos</h3>
                <a class="mini-btn" href="{{ route('investimentos') }}">Carteira<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 6l6 6-6 6"/></svg></a>
            </div>
            <div class="empty-state">
                <div class="pico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 19V6M4 19h16M8 16v-4M12 16V8M16 16v-7M20 16v-3"/></svg></div>
                <h3>Em breve</h3>
                <p>Acompanhamento de investimentos chega em uma versão futura.</p>
            </div>
        </div>
    </div>
</section>

{{-- Contrato de dados do dashboard (consumido por resources/js/sm/dashboard.js).
     Seguro com !!: é json_encode (com escape de "/") de dados do próprio service. --}}
<script type="application/json" id="sm-dashboard-data">{!! $payloadJson !!}</script>
@endsection
