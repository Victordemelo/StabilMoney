<!DOCTYPE html>
{{--
    Shell do painel administrativo (out/2026) — o MESMO esqueleto do app (layouts/app): menu
    lateral, barra de cima, conteúdo que rola em `#content`, gaveta no celular e tema claro/
    escuro. Os ids (app, sidebar, scrim, collapseBtn, mMenu, themeBtn, mTheme, content) são os
    do app de propósito: o `sm/shell.js` e o `sm/theme.js` já sabem ligá-los. Antes o painel era
    uma página solta com `body { overflow: hidden }` do design system — e não rolava.

    O que diz "isto não é o app do cliente": a faixa vermelha no topo do conteúdo, sempre.
    Nada de partials do app aqui (sidebar/topbar/modais leem o usuário do guard `web`).
--}}
<html lang="pt-BR" data-theme="light">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <meta name="robots" content="noindex, nofollow, noarchive" />
    <meta name="theme-color" content="#F3F5F1" data-sm-theme data-light="#F3F5F1" data-dark="#021C1E" />
    <title>@yield('title', 'Painel') · Stabil Money</title>
    <link rel="icon" type="image/png" href="{{ asset('assets/favicon.png') }}" />

    {{-- Anti-flash do tema: a mesma regra do layouts/app (escolha salva; sem ela, o sistema). --}}
    <script nonce="{{ Vite::cspNonce() }}">
        (function () {
            var t;
            try { t = localStorage.getItem('sm-theme'); } catch (e) { /* storage indisponível */ }
            if (t !== 'dark' && t !== 'light') {
                try { t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'; } catch (e) { t = 'light'; }
            }
            document.documentElement.setAttribute('data-theme', t);
            var cor = document.querySelector('meta[name="theme-color"][data-sm-theme]');
            if (cor) cor.setAttribute('content', cor.getAttribute(t === 'dark' ? 'data-dark' : 'data-light'));
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600..800&family=Geist:wght@400..600&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="painel">
@php
    $admin = auth('admin')->user();
    $partes = preg_split('/\s+/', trim($admin->name ?? 'Admin'), -1, PREG_SPLIT_NO_EMPTY) ?: ['A'];
    $iniciaisAdmin = mb_strtoupper(count($partes) >= 2
        ? mb_substr($partes[0], 0, 1).mb_substr(end($partes), 0, 1)
        : mb_substr($partes[0], 0, 2));
    $itens = [
        ['painel.home', 'Visão geral', 'Números do app', '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>'],
        ['painel.pessoas', 'Pessoas', 'Contas, banir e excluir', '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18.5 14.4c1.9.7 3 2.6 3 5.6"/>'],
        ['painel.historico', 'Histórico', 'Tudo o que o painel fez', '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/>'],
    ];
@endphp
<div class="app" id="app">
    <aside class="sidebar scroll" id="sidebar">
        <div class="brand">
            <span class="brand-badge"><img src="{{ asset('assets/stabilmoney-mark.png') }}" alt="StabilMoney" /></span>
            <span class="brand-name">Stabil<b>Money</b></span>
        </div>

        <div class="nav-group-label">Painel administrativo</div>
        <nav class="nav" aria-label="Painel administrativo">
            @foreach ($itens as [$rota, $rotulo, $descricao, $icone])
                @php
                    $ativa = request()->routeIs($rota) || ($rota === 'painel.pessoas' && request()->routeIs('painel.pessoa'));
                @endphp
                <a class="nav-item {{ $ativa ? 'active' : '' }}" href="{{ route($rota) }}" @if ($ativa) aria-current="page" @endif>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">{!! $icone !!}</svg>
                    <span class="nav-txt"><span class="nav-label">{{ $rotulo }}</span><span class="nav-desc">{{ $descricao }}</span></span>
                </a>
            @endforeach
        </nav>

        <div class="side-card painel-aviso">
            <strong>Sem valores, de propósito</strong>
            <span>O painel mostra a estrutura das contas — nunca saldo, lançamento ou meta de ninguém.</span>
        </div>

        <div class="side-foot painel-foot">
            <span class="avatar-initials">{{ $iniciaisAdmin }}</span>
            <div class="side-foot-text">
                <strong>{{ $admin->name ?? 'Administrador' }}</strong>
                <span>{{ $admin->email ?? '' }}</span>
            </div>
            <form method="POST" action="{{ route('painel.logout') }}">
                @csrf
                <button type="submit" class="icon-btn" aria-label="Sair do painel" title="Sair do painel">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M15 17l5-5-5-5M20 12H9M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3"/></svg>
                </button>
            </form>
        </div>
    </aside>

    <div class="main">
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

        <header class="topbar">
            <button class="collapse-btn" id="collapseBtn" type="button" aria-label="Recolher menu">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 6h16M4 12h11M4 18h16"/></svg>
            </button>
            <div class="greeting">
                <h1>Painel administrativo</h1>
                <p>Acesso restrito · cada ação fica no histórico</p>
            </div>
            <button class="icon-btn" id="themeBtn" type="button" aria-label="Alternar tema" style="margin-left:auto">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" id="themeIcon"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5 19 19M19 5l-1.5 1.5M6.5 17.5 5 19"/></svg>
            </button>
        </header>

        <main class="content scroll" id="content">
            {{-- Faixa de identificação. Existe para o painel NUNCA ser confundido com o app:
                 quem administra a conta dos outros precisa saber, o tempo todo, onde está. --}}
            <div class="painel-faixa" role="note">PAINEL ADMINISTRATIVO — você está vendo dados de outras pessoas</div>

            @if (session('status'))
                <div class="flash" data-flash role="status">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 5-5.5"/></svg>
                    <span class="sm-balao-txt">{{ session('status') }}</span>
                </div>
            @endif

            @if ($errors->any())
                <div class="flash-error" role="alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
                    <ul>
                        @foreach ($errors->all() as $erro)
                            <li>{{ $erro }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="painel-conteudo">
                @yield('content')
            </div>
        </main>
    </div>

    <div class="scrim" id="scrim"></div>
</div>
</body>
</html>
