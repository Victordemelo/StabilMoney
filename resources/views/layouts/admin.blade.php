<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <meta name="robots" content="noindex, nofollow, noarchive" />
    <meta name="theme-color" content="#07140E" />
    <title>@yield('title', 'Painel') · Stabil Money</title>
    <link rel="icon" type="image/png" href="{{ asset('assets/favicon.png') }}" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Sora:wght@600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body style="background:var(--bg);min-height:100dvh">

    {{-- Faixa de identificação. Existe para o painel NUNCA ser confundido com o app:
         quem administra a conta dos outros precisa saber, o tempo todo, onde está. --}}
    <div style="background:#8A1C1C;color:#fff;font:600 12px/1 system-ui,sans-serif;padding:7px 16px;text-align:center;letter-spacing:.02em">
        PAINEL ADMINISTRATIVO — você está vendo dados de outras pessoas
    </div>

    <header style="border-bottom:1px solid var(--line);background:var(--card)">
        <div style="max-width:1080px;margin:0 auto;padding:14px 20px;display:flex;align-items:center;gap:18px;flex-wrap:wrap">
            <a href="{{ route('painel.home') }}" style="display:flex;align-items:center;gap:10px;text-decoration:none">
                <img src="{{ asset('assets/stabilmoney-mark.png') }}" alt="" width="30" height="30" style="border-radius:8px">
                <strong style="font-family:Sora,sans-serif;font-size:15px;color:var(--text)">Stabil Money</strong>
            </a>

            <nav style="display:flex;gap:4px;margin-left:auto;flex-wrap:wrap">
                @php($abas = [
                    'painel.home' => 'Visão geral',
                    'painel.pessoas' => 'Pessoas',
                    'painel.historico' => 'Histórico',
                ])
                @foreach ($abas as $rota => $rotulo)
                    @php($ativa = request()->routeIs($rota) || ($rota === 'painel.pessoas' && request()->routeIs('painel.pessoa')))
                    <a href="{{ route($rota) }}"
                       style="padding:8px 14px;border-radius:999px;text-decoration:none;font:600 13px/1 system-ui,sans-serif;
                              color:{{ $ativa ? 'var(--brand-ink,#07140E)' : 'var(--muted)' }};
                              background:{{ $ativa ? 'var(--brand-500)' : 'transparent' }}">{{ $rotulo }}</a>
                @endforeach
            </nav>

            <form method="POST" action="{{ route('painel.logout') }}" style="margin:0">
                @csrf
                <button type="submit" class="btn-ghost" style="padding:8px 14px;font-size:13px">Sair</button>
            </form>
        </div>
    </header>

    <main style="max-width:1080px;margin:0 auto;padding:24px 20px 60px">
        @if (session('status'))
            <div class="flash" role="status" style="margin-bottom:18px">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="flash-error" role="alert" style="margin-bottom:18px">
                <ul style="margin:0;padding-left:18px">
                    @foreach ($errors->all() as $erro)
                        <li>{{ $erro }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>
