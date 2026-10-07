<!DOCTYPE html>
{{--
    Telas de ENTRADA do painel administrativo (login, configurar o autenticador, códigos de
    recuperação, desafio do código) — no MESMO desenho split do login do app (layouts/auth +
    auth.css, out/2026): painel visual à esquerda, formulário à direita, sempre claro. O que
    diz "isto não é o app do cliente" é o selo vermelho "Acesso restrito" e o texto do painel.

    Diferenças de propósito em relação ao layouts/auth: sem SEO (o painel nunca é indexável —
    o SecurityHeaders ainda manda `X-Robots-Tag: noindex`), sem PWA e sem aviso de cookies (o
    painel não faz parte do app instalado nem da Política do cliente).

    `@section('largo')` alarga o formulário (tela do QR: o QR fica ao lado das instruções,
    sem rolagem).
--}}
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    {{-- `noindex` de verdade: o painel não pode aparecer em buscador nenhum. --}}
    <meta name="robots" content="noindex, nofollow, noarchive" />
    <meta name="theme-color" content="#032628" />
    <title>@yield('title', 'Painel') · Stabil Money</title>
    <link rel="icon" type="image/png" href="{{ asset('assets/favicon.png') }}" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600..800&family=Geist:wght@400..600&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="auth-body">
<main class="auth painel-auth @hasSection('largo') painel-auth-largo @endif">

    <section class="auth-visual">
        <video class="auth-video" id="authVideo" autoplay muted loop playsinline preload="auto">
            <source src="{{ asset('assets/video_login.mp4') }}" type="video/mp4" />
        </video>
        <div class="auth-veil"></div>
        @include('partials.selo-do-video')

        <div class="av-top">
            <span class="av-badge"><img src="{{ asset('assets/stabilmoney-mark.png') }}" alt="StabilMoney" /></span>
            <span class="av-word">Stabil<b>Money</b></span>
        </div>

        <div class="av-body">
            <span class="av-eyebrow painel-selo"><span class="pulse"></span>Painel administrativo · acesso restrito</span>
            <h2>Painel <em>administrativo</em>.</h2>
            <p>Para quem cuida do Stabil Money: pessoas, banimentos e o histórico de cada ação.</p>
            <div class="av-features">
                <div class="av-feat"><span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></span>Senha e código do autenticador, sempre</div>
                <div class="av-feat"><span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><path d="m3 3 18 18"/></svg></span>Nunca mostra saldo nem valor de ninguém</div>
                <div class="av-feat"><span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 8v4l3 2"/><circle cx="12" cy="12" r="9"/></svg></span>Toda tentativa de acesso fica registrada</div>
            </div>
        </div>

        <div class="av-foot"></div>
    </section>

    <section class="auth-panel">
        <div class="auth-card">
            <div class="ac-brand">
                <span class="b"><img src="{{ asset('assets/stabilmoney-mark.png') }}" alt="" /></span>
                <span>Stabil<b>Money</b></span>
            </div>

            @yield('content')

            <p class="painel-rodape">Todas as tentativas de acesso são registradas.</p>
        </div>
    </section>

</main>
</body>
</html>
