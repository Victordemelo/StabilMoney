<!DOCTYPE html>
{{--
    Layout split das telas de login/cadastro (handoff v2 do Claude Design):
    painel visual com vídeo de fundo à esquerda + card do formulário à direita.
    Os textos do painel visual variam por tela e chegam via @section
    (eyebrow, headline, sub, features); o formulário via @section('card').
    As demais telas de auth (esqueci/redefinir/confirmar senha, verificar
    e-mail) continuam no layouts.guest.
--}}
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <meta name="theme-color" content="#0B3A28" />

    @php($pageTitle = trim($__env->yieldContent('title')))
    <title>StabilMoney{{ $pageTitle ? ' · '.$pageTitle : '' }}</title>

    <link rel="icon" type="image/png" href="{{ asset('assets/favicon.png') }}" />
    <link rel="apple-touch-icon" href="{{ asset('assets/stabilmoney-mark.png') }}" />

    {{-- Fontes do design system (Sora + Plus Jakarta Sans) --}}
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="auth-body">
<main class="auth">

    {{-- Painel visual: vídeo + marca + mensagem (a classe .ready entra via sm/auth.js) --}}
    <section class="auth-visual">
        <video class="auth-video" id="authVideo" autoplay muted loop playsinline preload="auto">
            <source src="{{ asset('assets/video_login.mp4') }}" type="video/mp4" />
        </video>
        <div class="auth-veil"></div>

        <div class="av-top">
            <span class="av-badge"><img src="{{ asset('assets/stabilmoney-mark.png') }}" alt="StabilMoney" /></span>
            <span class="av-word">Stabil<b>Money</b></span>
        </div>

        <div class="av-body">
            <span class="av-eyebrow"><span class="pulse"></span>@yield('eyebrow')</span>
            <h2>@yield('headline')</h2>
            <p>@yield('sub')</p>
            <div class="av-features">
                @yield('features')
            </div>
        </div>

        <div class="av-foot"></div>
    </section>

    {{-- Painel do formulário --}}
    <section class="auth-panel">
        <div class="auth-card">
            {{-- Marca dentro do card (visível só no mobile) --}}
            <div class="ac-brand">
                <span class="b"><img src="{{ asset('assets/stabilmoney-mark.png') }}" alt="" /></span>
                <span>Stabil<b>Money</b></span>
            </div>

            @yield('card')
        </div>
    </section>

</main>
</body>
</html>
