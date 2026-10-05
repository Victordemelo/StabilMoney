{{-- Card de UMA conta/cartão na tela Contas e cartões. Espera: $conta (Account, com o
     dinheiro pré-carregado por `Account::preloadMoney`). --}}
<div class="card span4 acct-card">
    {{-- Visual do cartão: imagem do banco, ou gradiente padrão p/ contas antigas sem banco --}}
    @if ($conta->bankImageUrl())
        <div class="bankcard"><img src="{{ $conta->bankImageUrl() }}" alt="{{ $conta->bankLabel() }}" loading="lazy"></div>
    @else
        <div class="cc"
             @if ($conta->color)
                 style="background: linear-gradient(135deg, {{ $conta->color }} 0%, color-mix(in srgb, {{ $conta->color }} 55%, #07140E) 60%, color-mix(in srgb, {{ $conta->color }} 38%, #07140E) 100%)"
             @endif>
            <div class="cc-top"><span class="net">{{ $conta->name }}</span></div>
            <div class="cc-chip"></div>
            <div class="cc-bot">
                <div><div class="lbl">Saldo em conta</div><div class="cc-balance {{ $conta->available < 0 ? 'neg' : '' }}">@brl($conta->available)</div></div>
            </div>
        </div>
    @endif

    <div class="acct-info">
        <div class="acct-info-top">
            <span class="acct-name">{{ $conta->name }}</span>
            <span class="acct-type">{{ $conta->typeLabel() }}{{ $conta->bankLabel() ? ' · ' . $conta->bankLabel() : '' }}</span>
        </div>

        @if ($conta->isDebit())
            {{-- Cartão de débito espelha o DISPONÍVEL das vinculadas (não o
                 bruto): o que está guardado em metas/investimentos não pode
                 aparecer como saldo gastável no cartão. --}}
            {{-- O débito SACA de UMA conta: a corrente, e a poupança só quando
                 não há corrente (mesma regra de Account::paymentOptions, que é o
                 que o modal de lançar submete). Por isso o saldo PRINCIPAL do
                 card é o dela — antes o card prometia o total (1.200) e o select
                 do lançamento avisava 700: dois números para o mesmo método. --}}
            @php
                $debitada = $conta->linkedChecking ?? $conta->linkedSavings;
            @endphp
            <div class="acct-sub">
                <span class="{{ $conta->linkedChecking ? 'acct-debitada' : '' }}">Corrente <b>@brl($conta->availableChecking)</b>@if ($conta->linkedChecking) <em>conta debitada</em>@endif</span>
                <span class="{{ ! $conta->linkedChecking && $conta->linkedSavings ? 'acct-debitada' : '' }}">Poupança <b>@brl($conta->availableSavings)</b>@if (! $conta->linkedChecking && $conta->linkedSavings) <em>conta debitada</em>@endif</span>
            </div>
            @if ($debitada)
                <div class="acct-balance {{ $debitada->available < 0 ? 'neg' : '' }}">@brl($debitada->available) <span class="acct-balance-lbl">sai de {{ $debitada->name }}</span></div>
                <div class="acct-sub"><span>Total disponível nas vinculadas <b>@brl($conta->available)</b></span></div>
            @else
                <div class="acct-balance">@brl(0) <span class="acct-balance-lbl">sem conta vinculada</span></div>
            @endif
        @elseif ($conta->isPix())
            {{-- Pix espelha UMA conta (a chave vive numa conta só), então
                 mostra a origem em vez de somar corrente + poupança. --}}
            @php
                $origem = $conta->contaDoPix();
            @endphp
            @if ($origem)
                <div class="acct-sub"><span>Sai de <b>{{ $origem->name }}</b></span></div>
            @endif
            <div class="acct-balance {{ $conta->available < 0 ? 'neg' : '' }}">@brl($conta->available) <span class="acct-balance-lbl">disponível para Pix</span></div>
        @elseif ($conta->isCard())
            <div class="acct-balance">@brl($conta->availableLimitDisplay) <span class="acct-balance-lbl">limite disponível</span></div>
        @else
            {{-- "Saldo em conta" = disponível (já fora metas e investimentos):
                 é o número que o usuário pode de fato gastar. --}}
            <div class="acct-balance {{ $conta->available < 0 ? 'neg' : '' }}">@brl($conta->available) <span class="acct-balance-lbl">saldo em conta</span></div>
            @if ($conta->reserved > 0)
                <div class="acct-sub">
                    <span>Guardado/investido <b>@brl($conta->reserved)</b></span>
                </div>
            @endif
        @endif

        {{-- Cheque especial: barra de uso ou o limite livre --}}
        @if ($conta->overdraftLimitValue > 0)
            @php $pctCheque = (int) min(100, round($conta->overdraftUsed / $conta->overdraftLimitValue * 100)); @endphp
            <div class="fh-limit">
                <div class="dp-bar cheque"><i style="width:{{ $pctCheque }}%"></i></div>
                <div class="dp-meta">
                    <span>Cheque especial usado</span>
                    <span>@brl($conta->overdraftUsed) de @brl($conta->overdraftLimitValue)</span>
                </div>
            </div>
        @endif
    </div>

    <div class="acct-card-actions">
        <a class="mini-btn" href="{{ route('accounts.edit', $conta) }}" data-acct-open="{{ $conta->id }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 20h4L19.5 8.5a2.1 2.1 0 0 0-3-3L5 17v3Z"/><path d="m13.5 6.5 3 3"/></svg>
            Editar
        </a>
        {{-- "Tem certeza?" pelo sm/confirmar.js: o `onsubmit` inline de
             antes era bloqueado pela CSP, e excluía sem perguntar. --}}
        <form method="POST" action="{{ route('accounts.destroy', $conta) }}"
              data-confirmar="Excluir {{ $conta->name }}? Contas com transações não podem ser excluídas.">
            @csrf
            @method('DELETE')
            <button class="mini-btn danger" type="submit">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 7h16M9 7V5a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 5v2M6.5 7l.8 12a2 2 0 0 0 2 1.9h5.4a2 2 0 0 0 2-1.9l.8-12M10 11v6M14 11v6"/></svg>
                Excluir
            </button>
        </form>
    </div>
</div>
