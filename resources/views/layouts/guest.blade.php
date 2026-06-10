<!DOCTYPE html>
<html lang="pt-BR" data-theme="light">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <meta name="theme-color" content="#0C3D2B" />

    <title>@yield('title', 'StabilMoney')</title>

    {{-- Anti-flash: aplica o tema salvo ANTES do CSS pintar a página --}}
    <script>
        (function () {
            try {
                var t = localStorage.getItem('sm-theme');
                if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-theme', t);
            } catch (e) {}
        })();
    </script>

    {{-- Fontes do design system (Sora + Plus Jakarta Sans) --}}
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="auth-body">
<div class="auth-wrap">
    <div class="auth-card">
        <a class="auth-brand" href="{{ url('/') }}">
            <svg class="brand-mark" viewBox="0 0 48 48" aria-hidden="true">
                <path class="s-curve" d="M33 18.5 C33 13.5 28.5 11 24 11 C18.5 11 14.5 13.8 14.5 18 C14.5 22.2 18.5 23.5 24 24" />
                <path class="s-curve" d="M15 29.5 C15 34.5 19.5 37 24 37 C29.5 37 33.5 34.2 33.5 30 C33.5 25.8 29.5 24.5 24 24" />
                <path class="s-arrow" d="M15.5 33 L32 15.5" />
                <path class="s-arrow" d="M24 14 L33.5 14 L33.5 23.5" />
            </svg>
            <span class="brand-name">Stabil<b>Money</b></span>
        </a>

        @hasSection('content')
            @yield('content')
        @else
            {{ $slot ?? '' }}
        @endif
    </div>
</div>
</body>
</html>
