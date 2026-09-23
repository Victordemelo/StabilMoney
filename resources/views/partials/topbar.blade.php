{{-- Barras superiores: mobile (hamburger + brand + tema) e desktop (saudação, busca, ações) --}}
@php
    $primeiroNome = \Illuminate\Support\Str::before(trim(auth()->user()->name ?? ''), ' ');
    // Ex.: "Terça-feira, 9 de junho" (Carbon::setLocale é configurado no AppServiceProvider)
    $dataHoje = \Illuminate\Support\Str::ucfirst(now()->translatedFormat('l, j \d\e F'));
@endphp

@php
    $vencimentos = $vencimentos ?? collect();
    $temVencida = $vencimentos->contains(fn ($v) => (int) $v['diasRestantes'] < 0);

    // A contagem DITA do sino. O selo com o número é só visual: o `aria-label` do botão
    // substitui o conteúdo dele como nome acessível, então quem usa leitor de tela nunca
    // ouvia quantas contas havia. Ex.: "Notificações: 3 contas a pagar, 1 vencida".
    $qtdVencidas = $vencimentos->filter(fn ($v) => (int) $v['diasRestantes'] < 0)->count();
    $resumoDoSino = $vencimentos->isEmpty() ? '' : ': '.$vencimentos->count()
        .($vencimentos->count() === 1 ? ' conta a pagar' : ' contas a pagar')
        .($qtdVencidas ? ', '.$qtdVencidas.($qtdVencidas === 1 ? ' vencida' : ' vencidas') : '');
@endphp

{{-- Os dois sinos, a lista e a data vivem no SHELL, que a navegação por pjax não troca.
     Por isso levam `data-pjax-atualizar` + id: o `sm/nav.js` traz de cada página nova os
     filhos deles e os atributos listados no valor (P-4 da auditoria de 06/09/2026 — antes,
     criar uma conta fixa que vence hoje e navegar não mudava o número). Não liste ali
     atributo que o JS controla (o `aria-expanded` do botão, a classe `open` do popover). --}}

{{-- Mobile top bar --}}
<header class="mobile-top">
    <button class="icon-btn" id="mMenu" type="button" aria-label="Menu">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
    <img class="mbrand-mark" src="{{ asset('assets/stabilmoney-mark.png') }}" alt="StabilMoney" />
    <span class="mbrand">Stabil<b>Money</b></span>
    {{-- Sino também no mobile: o app é PWA-first e, sem isto, quem usa pelo
         celular nunca via aviso de vencimento (a .topbar some em ≤920px). --}}
    <a class="icon-btn {{ $vencimentos->isNotEmpty() ? 'has-notif' : '' }}" href="{{ route('faturas.index') }}"
       id="mNotif" data-pjax-atualizar="class aria-label"
       aria-label="Vencimentos{{ $resumoDoSino }}" style="margin-left:auto">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
        @if ($vencimentos->isNotEmpty())
            <span class="notif-badge {{ $temVencida ? 'late' : '' }}">{{ $vencimentos->count() }}</span>
        @endif
    </a>
    <button class="icon-btn" id="mTheme" type="button" aria-label="Alternar tema">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5 19 19M19 5l-1.5 1.5M6.5 17.5 5 19"/></svg>
    </button>
</header>

{{-- Desktop topbar --}}
<header class="topbar">
    <button class="collapse-btn" id="collapseBtn" type="button" aria-label="Recolher menu">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 6h16M4 12h11M4 18h16"/></svg>
    </button>
    <div class="greeting">
        <h1>Bem-vindo de volta, {{ $primeiroNome }} <span class="wave">👋</span></h1>
        {{-- A data também é do servidor: aba aberta de um dia para o outro mostrava ontem. --}}
        <p id="topbarData" data-pjax-atualizar>{{ $dataHoje }} · resumo das suas finanças</p>
    </div>
    {{-- "Lançar": recolhido vira só "+"; no hover/foco floresce em "+ Lançar" --}}
    <a class="launch-btn" href="{{ route('transactions.create') }}" data-launch-open aria-label="Lançar nova transação">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
        <span>Lançar</span>
    </a>
    <button class="icon-btn" id="themeBtn" type="button" aria-label="Alternar tema">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" id="themeIcon"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5 19 19M19 5l-1.5 1.5M6.5 17.5 5 19"/></svg>
    </button>
    {{-- Notificações: vencidas primeiro, depois as dos próximos 7 dias --}}
    <div class="topbar-notif">
        <button class="icon-btn {{ $vencimentos->isNotEmpty() ? 'has-notif' : '' }}" id="notifBtn" type="button"
                data-pjax-atualizar="class aria-label"
                aria-label="Notificações{{ $resumoDoSino }}" aria-expanded="false" aria-haspopup="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
            @if ($vencimentos->isNotEmpty())<span class="notif-badge {{ $temVencida ? 'late' : '' }}">{{ $vencimentos->count() }}</span>@endif
        </button>
        <div class="notif-pop" id="notifPop" data-pjax-atualizar role="region" aria-label="Vencimentos próximos" aria-hidden="true">
            <div class="notif-head"><strong>Vencimentos</strong><span class="notif-sub">{{ $temVencida ? 'há contas vencidas' : 'próximos 7 dias' }}</span></div>
            @forelse ($vencimentos as $v)
                @php($dias = (int) $v['diasRestantes'])
                <div class="notif-item">
                    <span class="ni-ico ni-{{ $v['tipo'] }}">
                        @if ($v['tipo'] === 'fatura')
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 9.5h19"/></svg>
                        @elseif ($v['tipo'] === 'conta_fixa')
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 10 12 4l9 6M5 10v9h14v-9M9 19v-5h6v5"/></svg>
                        @else
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 8v4l3 2M21 12a9 9 0 1 1-9-9c2.5 0 4.8 1 6.4 2.7M21 4v4h-4"/></svg>
                        @endif
                    </span>
                    <div class="ni-txt">
                        <strong>{{ $v['nome'] }}</strong>
                        <span>
                            @if ($dias < 0)<em class="ni-late">vencida</em>@elseif ($dias === 0)<em class="ni-late">vence hoje</em>@else vence em {{ $dias }} {{ $dias === 1 ? 'dia' : 'dias' }}@endif
                            · {{ $v['due']->translatedFormat('d \d\e M') }}
                        </span>
                    </div>
                    <b class="ni-val">@brl($v['valor'])</b>
                </div>
            @empty
                <div class="notif-empty">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M8 2v3M16 2v3M4 5h16a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z"/><path d="m9.4 13.6 1.9 1.9 3.6-4"/></svg>
                    <p>Nada perto de vencer</p>
                    <span>Faturas de cartão, contas fixas e recorrências a vencer nos próximos 7 dias aparecem aqui.</span>
                </div>
            @endforelse
        </div>
    </div>
</header>
