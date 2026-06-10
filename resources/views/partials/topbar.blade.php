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
    <svg class="brand-mark" viewBox="0 0 48 48" aria-hidden="true">
        <path class="s-curve" d="M33 18.5 C33 13.5 28.5 11 24 11 C18.5 11 14.5 13.8 14.5 18 C14.5 22.2 18.5 23.5 24 24" style="stroke:var(--brand-600)"/>
        <path class="s-curve" d="M15 29.5 C15 34.5 19.5 37 24 37 C29.5 37 33.5 34.2 33.5 30 C33.5 25.8 29.5 24.5 24 24" style="stroke:var(--brand-600)"/>
        <path class="s-arrow" d="M15.5 33 L32 15.5" style="stroke:var(--brand-400)"/>
        <path class="s-arrow" d="M24 14 L33.5 14 L33.5 23.5" style="stroke:var(--brand-400)"/>
    </svg>
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
    <a class="btn-primary" href="{{ route('transactions.create') }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>
        Nova transação
    </a>
    <button class="icon-btn" id="themeBtn" type="button" aria-label="Alternar tema">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" id="themeIcon"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5 19 19M19 5l-1.5 1.5M6.5 17.5 5 19"/></svg>
    </button>
    <button class="icon-btn" type="button" aria-label="Notificações">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
        <span class="dot"></span>
    </button>
</header>
