{{-- Card de UMA conta/cartão na tela Contas e cartões. Espera: $conta (Account, com o
     dinheiro pré-carregado por `Account::preloadMoney`). --}}
{{-- Card compacto (out/2026 — pedido do Victor): o ÍCONE quadrado do banco no lugar da imagem do
     cartão, cinco por linha, nome e saldo menores, todos do mesmo tamanho. --}}
<div class="card acct-card">
    @if (($conta->isCard() || $conta->isDebit()) && $conta->bankImageUrl())
        {{-- Cartões (débito e crédito) seguem com a IMAGEM do cartão do banco (pedido do Victor:
             só as contas e o Pix/TED viraram ícone). --}}
        <div class="bankcard"><img src="{{ $conta->bankImageUrl() }}" alt="{{ $conta->bankLabel() }}" loading="lazy"></div>
    @else
    <div class="acct-topo">
        @php $canto = $conta->contaDoCanto(); @endphp
        <span class="acct-ico-caixa">
            @if ($conta->iconeDoCard())
                <img class="acct-ico" src="{{ $conta->iconeDoCard() }}" alt="{{ $conta->usaUmaContaSo() ? $conta->typeLabel() : $conta->bankLabel() }}" width="48" height="48" loading="lazy">
            @else
                {{-- Conta antiga sem banco: as iniciais do apelido num quadrado do mesmo tamanho. --}}
                <span class="acct-ico acct-ico-sem" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim($conta->name), 0, 2)) }}</span>
            @endif
            {{-- Pix e TED: o banco da conta de onde o dinheiro sai, no canto do ícone. --}}
            @if ($canto?->bankIconUrl())
                <img class="acct-ico-canto" src="{{ $canto->bankIconUrl() }}" alt="" title="Sai de {{ $canto->name }}" width="22" height="22" loading="lazy">
            @endif
        </span>
    </div>
    @endif

    <div class="acct-info">
        <div class="acct-info-top">
            <span class="acct-name" title="{{ $conta->name }}">{{ $conta->name }}</span>
            {{-- Uma linha: corrente/poupança (o grupo "Contas" junta as duas) e o banco, só o que o
                 apelido ainda não diz — "Bradesco" não vira "Bradesco · Bradesco". --}}
            <span class="acct-type">{{ $conta->linhaDoCard() }}</span>
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
        @elseif ($conta->usaUmaContaSo())
            {{-- Pix e TED espelham UMA conta, então mostram a origem em vez de
                 somar corrente + poupança. --}}
            @php
                $origem = $conta->contaDoPix();
            @endphp
            @if ($origem)
                <div class="acct-sub"><span>Sai de <b>{{ $origem->name }}</b></span></div>
            @endif
            <div class="acct-balance {{ $conta->available < 0 ? 'neg' : '' }}">@brl($conta->available) <span class="acct-balance-lbl">disponível para {{ $conta->isTed() ? 'TED' : 'Pix' }}</span></div>
        @elseif ($conta->isCard())
            <div class="acct-balance">@brl($conta->availableLimitDisplay) <span class="acct-balance-lbl">limite disponível</span></div>
        @else
            {{-- "Saldo em conta" = disponível (já fora metas e investimentos):
                 é o número que o usuário pode de fato gastar. --}}
            <div class="acct-balance {{ $conta->available < 0 ? 'neg' : '' }}">@brl($conta->available) <span class="acct-balance-lbl">saldo em conta</span></div>
            @if ($conta->overdraftUsed > 0)
                {{-- No vermelho: a conta está usando o cheque especial (14/10/2026). --}}
                <p class="acct-cheque" role="note">{{ $conta->overdraftLimitValue > 0 ? 'Usando o cheque especial' : 'Conta no vermelho' }}</p>
            @endif
            @if ($conta->reserved > 0)
                <div class="acct-sub">
                    <span>Guardado/investido <b>@brl($conta->reserved)</b></span>
                </div>
            @endif
        @endif

        {{-- Cheque especial: barra de uso. A conta SEM cheque especial mostra a mesma linha, vazia
             ("Sem cheque especial"): sem ela os cards da mesma fileira ficavam desalinhados. --}}
        @if ($conta->isCash() && $conta->overdraftLimitValue <= 0)
            <div class="fh-limit acct-sem-cheque">
                <div class="dp-bar cheque"><i style="width:0%"></i></div>
                <div class="dp-meta"><span>Sem cheque especial</span><span>@brl(0) de @brl(0)</span></div>
            </div>
        @endif
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
