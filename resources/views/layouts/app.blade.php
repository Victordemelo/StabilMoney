<!DOCTYPE html>
<html lang="pt-BR" data-theme="light">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    {{-- Id do usuário logado: a fila offline marca cada lançamento pendente com
         ele e só sincroniza os do usuário atual (aparelho compartilhado). --}}
    <meta name="sm-user" content="{{ auth()->id() }}" />
    <meta name="theme-color" content="#0C3D2B" />

    {{-- Título da aba: cada view define @section('title'); o pjax copia daqui ao navegar. --}}
    @php($tituloPagina = trim($__env->yieldContent('title')))
    <title>{{ $tituloPagina ? $tituloPagina . ' · StabilMoney' : 'StabilMoney' }}</title>

    <link rel="icon" type="image/png" href="{{ asset('assets/favicon.png') }}" />
    @include('partials.pwa-head')

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
{{-- Fundo animado "falling" — camada decorativa atrás de TODO o app (z-index:-1). Ver design-system.css. --}}
<div class="sm-falling" aria-hidden="true"></div>

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

@auth
    @include('partials.launch-modal')
    @include('partials.funding-modal')
@endauth

@include('partials.cookie-consent')
</body>
</html>
