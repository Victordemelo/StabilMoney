{{-- Barras superiores: mobile (hamburger + brand + tema) e desktop (saudação, busca, ações) --}}
@php
    $primeiroNome = \Illuminate\Support\Str::before(trim(auth()->user()->name ?? ''), ' ');
    // Ex.: "Terça-feira, 9 de junho" (Carbon::setLocale é configurado no AppServiceProvider)
    $dataHoje = \Illuminate\Support\Str::ucfirst(now()->translatedFormat('l, j \d\e F'));
@endphp

{{-- Mobile top bar --}}
<header class="mobile-top">
    <button class="icon-btn" id="mMenu" type="button" aria-label="Menu">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
    <img class="mbrand-mark" src="{{ asset('assets/stabilmoney-mark.png') }}" alt="StabilMoney" />
    <span class="mbrand">Stabil<b>Money</b></span>
    <button class="icon-btn" id="mTheme" type="button" aria-label="Alternar tema" style="margin-left:auto">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5 19 19M19 5l-1.5 1.5M6.5 17.5 5 19"/></svg>
    </button>
</header>

{{-- Desktop topbar --}}
<header class="topbar">
    <button class="collapse-btn" id="collapseBtn" type="button" aria-label="Recolher menu">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 6h16M4 12h11M4 18h16"/></svg>
    </button>
    <div class="greeting">
        <h1>Olá, {{ $primeiroNome }} <span class="wave">👋</span></h1>
        <p>{{ $dataHoje }} · resumo das suas finanças</p>
    </div>
    <label class="search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <input type="text" placeholder="Buscar transações, metas…" />
        <kbd>⌘K</kbd>
    </label>
    {{-- "Lançar": hoje leva ao form de nova transação (vira modal na próxima rodada) --}}
    <a class="launch-btn" href="{{ route('transactions.create') }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
        <span>Lançar</span>
    </a>
    <button class="icon-btn" id="themeBtn" type="button" aria-label="Alternar tema">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" id="themeIcon"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5 19 19M19 5l-1.5 1.5M6.5 17.5 5 19"/></svg>
    </button>
    <button class="icon-btn" type="button" aria-label="Notificações">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
        <span class="dot"></span>
    </button>
</header>
