{{-- Sidebar do app (links reais para rotas Laravel; ativo via routeIs) --}}
@php
    $usuario = auth()->user();
    // Iniciais para o avatar: 1ª letra do primeiro e do último nome (ou 2 primeiras letras)
    $partesNome = preg_split('/\s+/', trim($usuario->name ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $iniciais = count($partesNome) >= 2
        ? mb_substr($partesNome[0], 0, 1) . mb_substr(end($partesNome), 0, 1)
        : mb_substr($partesNome[0] ?? 'U', 0, 2);
    $iniciais = mb_strtoupper($iniciais);
@endphp
<aside class="sidebar scroll" id="sidebar">
    <div class="brand">
        <svg class="brand-mark" viewBox="0 0 48 48" aria-hidden="true">
            <path class="s-curve" d="M33 18.5 C33 13.5 28.5 11 24 11 C18.5 11 14.5 13.8 14.5 18 C14.5 22.2 18.5 23.5 24 24" />
            <path class="s-curve" d="M15 29.5 C15 34.5 19.5 37 24 37 C29.5 37 33.5 34.2 33.5 30 C33.5 25.8 29.5 24.5 24 24" />
            <path class="s-arrow" d="M15.5 33 L32 15.5" />
            <path class="s-arrow" d="M24 14 L33.5 14 L33.5 23.5" />
        </svg>
        <span class="brand-name">Stabil<b>Money</b></span>
    </div>

    <div class="nav-group-label">Menu</div>
    <nav class="nav">
        <a class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>
            <span class="nav-label">Visão geral</span>
        </a>
        <a class="nav-item {{ request()->routeIs('transactions.*') ? 'active' : '' }}" href="{{ route('transactions.index') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M7 8h13M7 8l3-3M7 8l3 3M17 16H4M17 16l-3-3M17 16l-3 3"/></svg>
            <span class="nav-label">Transações</span>
        </a>
        <a class="nav-item {{ request()->routeIs('accounts.*') ? 'active' : '' }}" href="{{ route('accounts.index') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 9.5h19M6 15h4"/></svg>
            <span class="nav-label">Cartões</span>
        </a>
        <a class="nav-item {{ request()->routeIs('investimentos') ? 'active' : '' }}" href="{{ route('investimentos') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 19V6M4 19h16M8 16v-4M12 16V8M16 16v-7M20 16v-3"/></svg>
            <span class="nav-label">Investimentos</span>
        </a>
        <a class="nav-item {{ request()->routeIs('metas') ? 'active' : '' }}" href="{{ route('metas') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r=".7" fill="currentColor"/></svg>
            <span class="nav-label">Metas</span>
        </a>
        <a class="nav-item {{ request()->routeIs('faturas') ? 'active' : '' }}" href="{{ route('faturas') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 3h9l3 3v15l-2-1.3L13 21l-2-1.3L9 21l-2-1.3L5 21V5a2 2 0 0 1 1-2Z"/><path d="M9 8h6M9 12h6M9 16h3"/></svg>
            <span class="nav-label">Faturas</span>
        </a>
        <a class="nav-item {{ request()->routeIs('relatorios') ? 'active' : '' }}" href="{{ route('relatorios') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M5 3h9l5 5v13a0 0 0 0 1 0 0H5a0 0 0 0 1 0 0V3Z"/><path d="M14 3v5h5M8 13l2.5 2.5L16 10"/></svg>
            <span class="nav-label">Relatórios</span>
        </a>
    </nav>

    <div class="sidebar-spacer"></div>

    <div class="nav-group-label">Preferências</div>
    <nav class="nav">
        <a class="nav-item {{ request()->routeIs('categories.*') ? 'active' : '' }}" href="{{ route('categories.index') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3.5 13.5V6a2.5 2.5 0 0 1 2.5-2.5h7.5l7.1 7.1a2 2 0 0 1 0 2.8Z"/><circle cx="8.5" cy="8.5" r="1.5"/></svg>
            <span class="nav-label">Categorias</span>
        </a>
        <a class="nav-item {{ request()->routeIs('profile.*') ? 'active' : '' }}" href="{{ route('profile.edit') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="3.2"/><path d="M12 2.5v2.5M12 19v2.5M4.2 4.2l1.8 1.8M18 18l1.8 1.8M2.5 12H5M19 12h2.5M4.2 19.8 6 18M18 6l1.8-1.8"/></svg>
            <span class="nav-label">Configurações</span>
        </a>
        <a class="nav-item {{ request()->routeIs('ajuda') ? 'active' : '' }}" href="{{ route('ajuda') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 0 1 4.5 1.5c0 1.7-2.5 1.8-2.5 3.5"/><circle cx="12" cy="17.5" r=".7" fill="currentColor"/></svg>
            <span class="nav-label">Ajuda</span>
        </a>
    </nav>

    <div class="upsell">
        <h4>StabilMoney Plus</h4>
        <p>Relatórios avançados, categorização automática e metas ilimitadas.</p>
        <button type="button">Fazer upgrade</button>
    </div>

    <div class="side-foot">
        <span class="avatar-initials">{{ $iniciais }}</span>
        <div class="side-foot-text">
            <strong>{{ $usuario->name }}</strong>
            <span>{{ $usuario->email }}</span>
        </div>
        <form class="side-foot-form" method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="side-logout" type="submit" title="Sair" aria-label="Sair">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 21H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3"/><path d="M16 17l5-5-5-5M21 12H9"/></svg>
            </button>
        </form>
    </div>
</aside>
