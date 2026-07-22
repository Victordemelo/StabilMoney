{{-- Sidebar do app (shell v2 do handoff; links reais para rotas Laravel, ativo via routeIs) --}}
{{-- $patrimonio vem do View Composer registrado no AppServiceProvider (SidebarService). --}}
@php
    $usuario = auth()->user();
    // Iniciais para o avatar: 1ª letra do primeiro e do último nome (ou 2 primeiras letras)
    $partesNome = preg_split('/\s+/', trim($usuario->name ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $iniciais = count($partesNome) >= 2
        ? mb_substr($partesNome[0], 0, 1) . mb_substr(end($partesNome), 0, 1)
        : mb_substr($partesNome[0] ?? 'U', 0, 2);
    $iniciais = mb_strtoupper($iniciais);

    // Formato pt-BR com os centavos separados (o v2 põe ",dd" num <span> menor)
    [$patInteiro, $patCentavos] = $patrimonio
        ? explode(',', number_format($patrimonio['saldoTotal'], 2, ',', '.'))
        : ['0', '00'];
@endphp
<aside class="sidebar scroll" id="sidebar">
    <div class="brand">
        <span class="brand-badge"><img src="{{ asset('assets/stabilmoney-mark.png') }}" alt="StabilMoney" /></span>
        <span class="brand-name">Stabil<b>Money</b></span>
    </div>

    <div class="nav-group-label">Menu</div>
    <nav class="nav">
        <a data-pjax class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>
            <span class="nav-label">Visão geral</span>
        </a>
        <a data-pjax class="nav-item {{ request()->routeIs('transactions.*') ? 'active' : '' }}" href="{{ route('transactions.index') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 8v4l3 2M21 12a9 9 0 1 1-9-9c2.5 0 4.8 1 6.4 2.7M21 4v4h-4"/></svg>
            <span class="nav-label">Histórico</span>
        </a>
        <a data-pjax class="nav-item {{ request()->routeIs('faturas.*') ? 'active' : '' }}" href="{{ route('faturas.index') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 3h9l3 3v15l-2-1.3L13 21l-2-1.3L9 21l-2-1.3L5 21V5a2 2 0 0 1 1-2Z"/><path d="M9 8h6M9 12h6M9 16h3"/></svg>
            <span class="nav-label">Faturas / Despesas</span>
        </a>
        <a data-pjax class="nav-item {{ request()->routeIs('metas.*') ? 'active' : '' }}" href="{{ route('metas.index') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r=".7" fill="currentColor"/></svg>
            <span class="nav-label">Metas</span>
        </a>
        <a data-pjax class="nav-item {{ request()->routeIs('investimentos.*') ? 'active' : '' }}" href="{{ route('investimentos.index') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 19V6M4 19h16M8 16v-4M12 16V8M16 16v-7M20 16v-3"/></svg>
            <span class="nav-label">Investimentos</span>
        </a>
    </nav>

    <div class="nav-group-label">Preferências</div>
    <nav class="nav">
        <a data-pjax class="nav-item {{ request()->routeIs('accounts.*') ? 'active' : '' }}" href="{{ route('accounts.index') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 9.5h19M6 15h4"/></svg>
            <span class="nav-label">Métodos de Pagamento</span>
        </a>
        <a data-pjax class="nav-item {{ request()->routeIs('categories.*') ? 'active' : '' }}" href="{{ route('categories.index') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="8" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/><rect x="13" y="13" width="8" height="8" rx="2"/></svg>
            <span class="nav-label">Categorias</span>
        </a>
    </nav>

    @if ($patrimonio)
        <div class="side-balance">
            <div class="sb-glow"></div>
            <div class="sb-top">
                <span class="sb-label">Patrimônio total</span>
                <svg class="sb-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 7h18v12H3zM3 7l2-3h14l2 3M16 13h2"/></svg>
            </div>
            <div class="sb-value">R$ {{ $patInteiro }}<span>,{{ $patCentavos }}</span></div>
            <div class="sb-subline">Disponível: R$ {{ number_format($patrimonio['disponivel'], 2, ',', '.') }}</div>
            <div class="sb-subline">Guardado em metas: R$ {{ number_format($patrimonio['guardado'], 2, ',', '.') }}</div>
            <div class="sb-subline">Investido: R$ {{ number_format($patrimonio['investido'], 2, ',', '.') }}</div>
            <svg class="sb-spark" viewBox="0 0 180 40" preserveAspectRatio="none" aria-hidden="true">
                <defs><linearGradient id="sbg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#6FCDA8" stop-opacity=".35"/><stop offset="1" stop-color="#6FCDA8" stop-opacity="0"/></linearGradient></defs>
                <path d="{{ $patrimonio['sparkArea'] }}" fill="url(#sbg)"/>
                <path d="{{ $patrimonio['sparkLine'] }}" fill="none" stroke="#9BE0C4" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            @if (! is_null($patrimonio['variacao']))
                <div class="sb-foot">
                    <span class="sb-chg" @if ($patrimonio['variacao'] < 0) style="color:var(--neg)" @endif>{{ $patrimonio['variacao'] < 0 ? '▼' : '▲' }} {{ number_format(abs($patrimonio['variacao']), 1, ',', '.') }}%</span>
                    <span class="sb-period">nos últimos 30 dias</span>
                </div>
            @endif
        </div>
    @endif

    <div class="sidebar-spacer"></div>

    {{-- Dependentes: só o titular gerencia (card escondido para dependentes) --}}
    @if ($usuario->isTitular())
        @php($numDep = $usuario->dependents()->count())
        <a data-pjax class="dep-card {{ request()->routeIs('dependentes') ? 'active' : '' }}" href="{{ route('dependentes') }}">
            <div class="dep-avatars">
                <span class="da solo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="9" cy="8" r="3.4"/><path d="M2.5 20c0-3.4 2.9-5.6 6.5-5.6 1 0 2 .2 2.8.5M17 8.5v6M14 11.5h6"/></svg></span>
            </div>
            <div class="dep-card-txt">
                <strong>Dependentes</strong>
                <span>{{ $numDep === 0 ? 'Nenhum dependente' : $numDep . ' ' . ($numDep === 1 ? 'pessoa' : 'pessoas') }}</span>
            </div>
            <svg class="dep-card-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
        </a>
    @endif

    <button class="side-foot" id="profileBtn" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="profilePop">
        <span class="avatar-initials">{{ $iniciais }}</span>
        <div class="side-foot-text">
            <strong>{{ $usuario->name }}</strong>
            <span>{{ $usuario->email }}</span>
        </div>
        <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M8 14l4-4 4 4"/></svg>
    </button>
</aside>

{{-- Popover do perfil (abre pelo .side-foot; posicionado via JS em shell.js) --}}
<div class="profile-pop" id="profilePop" role="menu" aria-hidden="true">
    <div class="pp-head">
        <span class="avatar-initials">{{ $iniciais }}</span>
        <div class="pp-head-txt">
            <strong>{{ $usuario->name }}</strong>
            <span>{{ $usuario->email }}</span>
        </div>
    </div>
    <div class="pp-sep"></div>
    <div class="pp-list">
        <a class="pp-item" href="{{ route('profile.edit') }}" role="menuitem">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="8" r="3.6"/><path d="M5 20c0-3.6 3.1-6 7-6s7 2.4 7 6"/></svg>
            <span>Meu perfil</span>
        </a>
        <a class="pp-item" href="{{ route('settings') }}" role="menuitem">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="3.2"/><path d="M12 2.5v2.5M12 19v2.5M4.2 4.2l1.8 1.8M18 18l1.8 1.8M2.5 12H5M19 12h2.5M4.2 19.8 6 18M18 6l1.8-1.8"/></svg>
            <span>Configurações</span>
        </a>
    </div>
    <div class="pp-sep"></div>
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button class="pp-item danger" type="submit" role="menuitem">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M15 17l5-5-5-5M20 12H9M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3"/></svg>
            <span>Sair</span>
        </button>
    </form>
</div>
