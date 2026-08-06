<!DOCTYPE html>
<html lang="pt-BR" data-theme="light">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    {{-- Id do usuário logado: a fila offline marca cada lançamento pendente com
         ele e só sincroniza os do usuário atual (aparelho compartilhado). --}}
    <meta name="sm-user" content="{{ auth()->id() }}" />
    {{-- Cor que o navegador do celular usa na barra de cima (e o Android na barra
         de status do app instalado). Precisa casar com o fundo REAL da página,
         que é o token `--bg` do design-system.css: #EFF4F1 no claro, #07140E no
         escuro. Antes era fixa em #0C3D2B (verde da sidebar): no tema claro dava
         uma faixa escura colada num conteúdo quase branco, e no escuro uma faixa
         mais clara que a página.

         Os dois hex ficam aqui em data-light/data-dark para o inline abaixo e o
         `sm/theme.js` lerem do MESMO lugar (o CSS não é acessível pelo JS sem
         getComputedStyle). O marcador `data-sm-theme` é o que autoriza o JS a
         mexer nesta meta — as telas de auth têm cor fixa e não o levam. --}}
    <meta name="theme-color" content="#EFF4F1" data-sm-theme data-light="#EFF4F1" data-dark="#07140E" />

    {{-- Título da aba: cada view define @section('title'); o pjax copia daqui ao navegar. --}}
    @php($tituloPagina = trim($__env->yieldContent('title')))
    <title>{{ $tituloPagina ? $tituloPagina . ' · StabilMoney' : 'StabilMoney' }}</title>

    <link rel="icon" type="image/png" href="{{ asset('assets/favicon.png') }}" />
    @include('partials.pwa-head')

    {{-- Anti-flash: aplica o tema salvo ANTES do CSS pintar a página — e, junto,
         as cores das "bordas do sistema" (barra do navegador/status do celular),
         que o CSS não alcança porque vivem em metas do <head>.
         Tem de ser AQUI, inline e cedo: o iOS lê a meta da barra de status durante
         o carregamento, e o bundle do Vite só roda depois do parse do documento.
         A mesma conta é refeita pelo `sm/theme.js` quando o usuário troca o tema
         (as duas pontas leem os hex dos data-light/data-dark da meta acima). --}}
    <script nonce="{{ Vite::cspNonce() }}">
        (function () {
            var t;
            try { t = localStorage.getItem('sm-theme'); } catch (e) { /* storage indisponível */ }
            if (t !== 'dark' && t !== 'light') t = document.documentElement.getAttribute('data-theme') || 'light';
            document.documentElement.setAttribute('data-theme', t);

            var cor = document.querySelector('meta[name="theme-color"][data-sm-theme]');
            if (cor) cor.setAttribute('content', cor.getAttribute(t === 'dark' ? 'data-dark' : 'data-light'));

            // iOS: `black` = glifos claros (combina com o fundo escuro do tema escuro).
            var barra = document.querySelector('meta[name="apple-mobile-web-app-status-bar-style"]');
            if (barra) barra.setAttribute('content', t === 'dark' ? 'black' : 'default');
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
