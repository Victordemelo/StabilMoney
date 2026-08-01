@extends('layouts.app')

@section('title', 'Visão geral')

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

    // Stat cards na mesma ordem/visual do design (ícones exatos do protótipo).
    // O `hint` vira title (tooltip) — os números precisam se explicar sozinhos.
    $statCards = [
        ['key' => 'saldo', 'label' => 'Saldo disponível', 'g' => 'g1', 'delay' => '.02s',
         'hint' => 'O que você tem nas contas para gastar agora — já descontando o que está guardado em metas e investido. Não depende do período escolhido.',
         'icon' => '<path d="M3 7h18v12H3zM3 7l2-3h14l2 3M16 13h2"/>'],
        ['key' => 'receitas', 'label' => 'Receitas', 'g' => 'g2', 'delay' => '.08s',
         'hint' => 'Tudo que ENTROU no período selecionado.',
         'icon' => '<path d="M12 19V5M12 5l-6 6M12 5l6 6"/>'],
        ['key' => 'despesas', 'label' => 'Despesas', 'g' => 'g3', 'delay' => '.14s',
         'hint' => 'Tudo que SAIU no período selecionado.',
         'icon' => '<path d="M12 5v14M12 19l6-6M12 19l-6-6"/>'],
        ['key' => 'economia', 'label' => 'Sobrou no período', 'g' => 'g4', 'delay' => '.2s',
         'hint' => 'Receitas menos despesas do período. Positivo: sobrou dinheiro. Negativo (vermelho): você gastou mais do que entrou.',
         'icon' => '<path d="M12 2v4M5 9a7 7 0 1 1 14 0c0 4-3 5-3 8H8c0-3-3-4-3-8Z"/><path d="M9 21h6"/>'],
    ];

    // Cores que ciclam nas iniciais das contas (mesmas do design)
    $abColors = ['var(--brand-600)', 'var(--c-lazer)', 'var(--c-alimentacao)'];

    // Primeiro nome para a saudação (mesma lógica da topbar)
    $primeiroNome = \Illuminate\Support\Str::before(trim(auth()->user()->name ?? ''), ' ');
@endphp

{{-- Saudação "Bem-vindo de volta" no topo do app. A topbar desktop já saúda o
     usuário, mas some no mobile (≤920px) — então aqui mostramos a saudação só no
     mobile, para o app (PWA, primeiro o celular) sempre dar as boas-vindas. --}}
<style>
    .dash-greeting { display: none; margin-bottom: 18px; }
    @media (max-width: 920px) { .dash-greeting { display: block; } }
</style>

<section class="view" id="view-dashboard">
    <div class="greeting dash-greeting">
        <h1>Bem-vindo de volta, {{ $primeiroNome }} <span class="wave">👋</span></h1>
        <p>Aqui está o resumo das suas finanças.</p>
    </div>

    <div class="section-head">
        <h2>Visão geral</h2>
        <span class="sub" id="periodSub">{{ $initialSub }}</span>
        {{-- O período que abre marcado vem do DashboardService::DEFAULT_PERIOD
             (hoje "semana"); o JS lê o botão .active e desenha esse período. --}}
        <div class="seg" id="period">
            <span class="seg-pill" id="segPill"></span>
            <button type="button" data-p="semana" @class(['active' => $defaultPeriod === 'semana'])><span>Semana</span></button>
            <button type="button" data-p="mes" @class(['active' => $defaultPeriod === 'mes'])><span>Mês</span></button>
            <button type="button" data-p="ano" @class(['active' => $defaultPeriod === 'ano'])><span>Ano</span></button>
        </div>
    </div>

    <div class="grid">
        {{-- Stat cards (valores do mês renderizados no servidor; o JS anima/troca o período) --}}
        @foreach ($statCards as $card)
            @php
                $value = $initialStats[$card['key']];
                $trend = $initialTrends[$card['key']];
                // DUAS coisas diferentes, que antes eram uma só:
                //  - `$subiu` decide a FLECHA: ela mostra o que aconteceu com o número.
                //  - `$bom` decide a COR: para despesas, cair é bom (verde); no resto,
                //    subir é bom.
                // Com um único flag, "despesas subiram 1540%" desenhava flecha para BAIXO
                // (porque era ruim), e o usuário lia exatamente o contrário do fato.
                $subiu = $trend === null ? null : $trend >= 0;
                $bom = $trend === null ? null : ($card['key'] === 'despesas' ? $trend < 0 : $trend >= 0);
                $sparkVals = $payload['sparks'][$card['key']] ?? [];
            @endphp
            <div class="card stat span3" style="animation-delay:{{ $card['delay'] }}">
                <div class="stat-top">
                    <div class="ico {{ $card['g'] }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor">{!! $card['icon'] !!}</svg></div>
                    <span class="label" title="{{ $card['hint'] }}">{{ $card['label'] }}</span>
                    @if ($trend === null)
                        <span class="trend neutral" data-trend="{{ $card['key'] }}">—</span>
                    @else
                        <span class="trend {{ $bom ? 'up' : 'down' }}" data-trend="{{ $card['key'] }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="{{ $subiu ? $arrowUp : $arrowDown }}"/></svg>{{ $pct($trend) }}</span>
                    @endif
                </div>
                {{-- Valor negativo (ex.: saldo/economia no vermelho) ganha .neg --}}
                <div class="value {{ $value < 0 ? 'neg' : '' }}"><span class="cur">R$</span><span class="num" data-count="{{ $value }}" data-dec="2">{{ $money($value) }}</span></div>
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
                {{-- .scroll = barra de rolagem discreta do design system --}}
                <div class="tx-list scroll">
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
                                <div class="tx-meta">{{ $transaction->category->name ?? 'Sem categoria' }} · {{ $transaction->date_human }}@if (! empty($showAuthor)) · {{ $transaction->madeBy?->name ?? 'Removido' }}@endif</div>
                            </div>
                            <div class="tx-amt {{ $isIncome ? 'pos' : '' }}">{{ $isIncome ? '+' : '−' }} R$ {{ $money($transaction->amount) }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Meus cartões: um painel por cartão de crédito (gasto + limite livre).
             Sem cartão cadastrado, cai para a lista de contas. --}}
        <div class="card span4" style="animation-delay:.3s">
            <div class="card-head">
                <h3>{{ count($cartoes) ? 'Meus cartões' : 'Minhas contas' }}</h3>
                <a class="mini-btn" href="{{ route('accounts.index') }}">Gerenciar<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 6l6 6-6 6"/></svg></a>
            </div>

            @if (count($cartoes))
                {{-- Totais da carteira de cartões --}}
                <div class="cards-sum">
                    <div>
                        <span class="lbl">Gasto total das faturas</span>
                        <b>R$ {{ $money($cartoesTotais['gasto']) }}</b>
                    </div>
                    <div class="right">
                        <span class="lbl">Limite total livre</span>
                        <b>R$ {{ $money($cartoesTotais['disponivel']) }}</b>
                        <span class="sub">de R$ {{ $money($cartoesTotais['limite']) }}</span>
                    </div>
                </div>

                {{-- Lista compacta: um item por cartão, com a miniatura do banco.
                     Mostra 2 e rola do 3º em diante (.scroll = barra discreta). --}}
                <div class="cc-list scroll">
                    @foreach ($cartoes as $c)
                        <div class="cc-item">
                            <span class="cc-thumb">
                                @if ($c['imagem'])
                                    <img src="{{ $c['imagem'] }}" alt="{{ $c['banco'] }}" loading="lazy">
                                @else
                                    {{ $initials($c['nome']) }}
                                @endif
                            </span>
                            <div class="cc-item-body">
                                <div class="cc-item-top">
                                    <strong>{{ $c['nome'] }}</strong>
                                    <b class="cc-item-free">R$ {{ $money($c['disponivel']) }}</b>
                                </div>
                                <div class="cc-item-line">
                                    <span>Gasto R$ {{ $money($c['gasto']) }}</span>
                                    <span class="cc-item-freelbl">livre</span>
                                </div>
                                <div class="dp-bar"><div class="dp-bar-fill {{ $c['usadoPct'] >= 90 ? 'over' : '' }}" style="width:{{ $c['usadoPct'] }}%"></div></div>
                                <div class="cc-item-days">
                                    @if ($c['vencimento'])
                                        <span title="Dia em que a fatura precisa estar paga">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M8 2v3M16 2v3M4 5h16a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z"/></svg>
                                            Vence {{ $c['vencimento'] }}
                                        </span>
                                    @endif
                                    @if ($c['melhorDia'])
                                        <span title="Comprando a partir deste dia, a despesa cai só na fatura seguinte — é o maior prazo para pagar.">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 3l2.4 5.3 5.6.6-4.2 3.9 1.2 5.6L12 15.6 6.9 18.4l1.2-5.6L4 8.9l5.6-.6z"/></svg>
                                            Melhor compra dia {{ $c['melhorDia'] }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @elseif ($accounts->isEmpty())
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
                {{-- Sem cartão de crédito: lista as contas com o saldo de cada uma --}}
                <div class="cc-picker">
                    @foreach ($accounts->take(5) as $account)
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
        </div>

        {{-- Metas de economia --}}
        <div class="card span4" style="animation-delay:.34s">
            <div class="card-head">
                <h3>Metas de economia</h3>
                <a class="mini-btn" href="{{ route('metas.index') }}">Todas<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 6l6 6-6 6"/></svg></a>
            </div>
            @if ($metasResumo['count'] > 0)
                <div class="dash-kpi">
                    <div class="lbl">Guardado</div>
                    <div class="dash-kpi-val">R$ {{ $money($metasResumo['total']) }} <span>· {{ $metasResumo['count'] }} {{ $metasResumo['count'] == 1 ? 'meta' : 'metas' }}</span></div>
                </div>
                <div style="margin-top:14px">
                    @foreach ($metasResumo['top'] as $m)
                        <div class="acct">
                            <div class="ab" style="background:var(--brand-500)">{{ $initials($m['name']) }}</div>
                            <div><div class="an">{{ $m['name'] }}</div><div class="at">{{ $m['progress'] }}% da meta</div></div>
                            <div class="av">R$ {{ $money($m['saved']) }}</div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="empty-state">
                    <div class="pico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r=".7" fill="currentColor"/></svg></div>
                    <h3>Nenhuma meta ainda</h3>
                    <p>Crie um cofrinho e comece a guardar.</p>
                    <a class="btn-ghost" href="{{ route('metas.index') }}">Criar meta</a>
                </div>
            @endif
        </div>

        {{-- Contas a pagar (faturas de cartão em aberto) --}}
        <div class="card span4" style="animation-delay:.38s">
            <div class="card-head">
                <h3>Contas a pagar</h3>
                <a class="mini-btn" href="{{ route('faturas.index') }}">Ver<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 6l6 6-6 6"/></svg></a>
            </div>
            @if ($faturasResumo['count'] > 0)
                <div class="dash-kpi">
                    <div class="lbl">A pagar nas faturas</div>
                    <div class="dash-kpi-val">R$ {{ $money($faturasResumo['total']) }}</div>
                </div>
                <div style="margin-top:14px">
                    @foreach ($faturasResumo['top'] as $f)
                        <div class="acct">
                            <div class="ab" style="background:var(--c-lazer)">{{ $initials($f['name']) }}</div>
                            <div><div class="an">{{ $f['name'] }}</div><div class="at">{{ $f['due'] ? 'Vence ' . $f['due'] : 'Fatura atual' }}</div></div>
                            <div class="av">R$ {{ $money($f['invoice']) }}</div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="empty-state">
                    <div class="pico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 3h9l3 3v15l-2-1.3L13 21l-2-1.3L9 21l-2-1.3L5 21V5a2 2 0 0 1 1-2Z"/><path d="M9 8h6M9 12h6M9 16h3"/></svg></div>
                    <h3>Nada a pagar 🎉</h3>
                    <p>Nenhuma fatura de cartão em aberto.</p>
                </div>
            @endif
        </div>

        {{-- Investimentos --}}
        <div class="card span4" style="animation-delay:.42s">
            <div class="card-head">
                <h3>Investimentos</h3>
                <a class="mini-btn" href="{{ route('investimentos.index') }}">Carteira<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 6l6 6-6 6"/></svg></a>
            </div>
            @if ($investimentosResumo['count'] > 0)
                <div class="dash-kpi">
                    <div class="lbl">Investido</div>
                    <div class="dash-kpi-val">R$ {{ $money($investimentosResumo['total']) }} <span>· {{ $investimentosResumo['count'] }} {{ $investimentosResumo['count'] == 1 ? 'ativo' : 'ativos' }}</span></div>
                </div>
                <div style="margin-top:14px">
                    @foreach ($investimentosResumo['top'] as $i)
                        <div class="acct">
                            <div class="ab" style="background:var(--c-saude)">{{ $initials($i['name']) }}</div>
                            <div><div class="an">{{ $i['name'] }}</div><div class="at">{{ $i['classe'] }}</div></div>
                            <div class="av">R$ {{ $money($i['aplicado']) }}</div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="empty-state">
                    <div class="pico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 19V6M4 19h16M8 16v-4M12 16V8M16 16v-7M20 16v-3"/></svg></div>
                    <h3>Nenhum investimento ainda</h3>
                    <p>Comece a acompanhar sua carteira.</p>
                    <a class="btn-ghost" href="{{ route('investimentos.index') }}">Adicionar investimento</a>
                </div>
            @endif
        </div>
    </div>
</section>

{{-- Contrato de dados do dashboard (consumido por resources/js/sm/dashboard.js).
     Seguro com !!: é json_encode (com escape de "/") de dados do próprio service. --}}
<script type="application/json" id="sm-dashboard-data">{!! $payloadJson !!}</script>
@endsection
