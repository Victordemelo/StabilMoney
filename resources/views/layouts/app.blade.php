<!DOCTYPE html>
<html lang="pt-BR" data-theme="light">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <meta name="theme-color" content="#0C3D2B" />

    <title>@yield('title', 'StabilMoney')</title>

    <link rel="icon" type="image/png" href="{{ asset('assets/favicon.png') }}" />
    <link rel="apple-touch-icon" href="{{ asset('assets/stabilmoney-mark.png') }}" />

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
<body>
<div class="app" id="app">

    @include('partials.sidebar')

    <div class="main">
        @include('partials.topbar')

        <main class="content scroll" id="content">
            @include('partials.flash')

            @hasSection('content')
                @yield('content')
            @else
                {{ $slot ?? '' }}
            @endif
        </main>
    </div>

    @include('partials.bottom-nav')

    <div class="scrim" id="scrim"></div>
</div>
</body>
</html>
