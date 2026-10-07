<!DOCTYPE html>
{{--
    Layout split das telas de login/cadastro (handoff v2 do Claude Design):
    painel visual com vídeo de fundo à esquerda + card do formulário à direita.
    Os textos do painel visual variam por tela e chegam via @section
    (eyebrow, headline, sub, features); o formulário via @section('card').
    Vale para TODAS as telas de auth: login, cadastro e também as secundárias
    (esqueci/redefinir/confirmar senha, verificar e-mail), que migraram para cá
    em 02/08/2026 — o antigo layouts.guest foi removido por ficar sem uso.
--}}
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    {{-- Aqui a cor NÃO acompanha o tema, de propósito: as telas de auth são
         sempre claras (tokens fixos no escopo `.auth` do auth.css) e, no celular,
         o topo da tela é o painel do vídeo — verde-escuro em qualquer tema. Por
         isso esta meta fica sem o marcador `data-sm-theme` e o `sm/theme.js` não
         encosta nela. #0C3D2B é o verde da marca (mesmo fundo dos ícones). --}}
    <meta name="theme-color" content="#032628" />

    {{-- Mesmo padrão do layouts/app: a view informa `@section('title')` e o
         layout compõe. Com o título fixo, as cinco telas de auth apareciam
         idênticas na aba do navegador — e quem deixa a redefinição de senha
         aberta numa aba não achava mais qual era. --}}
    {{-- Num bloco só: a diretiva php na forma de UMA linha, seguida de um bloco no mesmo
         arquivo, faz o Blade compilar as duas juntas (erro de sintaxe só em execução). --}}
    @php
        $tituloPagina = trim($__env->yieldContent('title'));
        $tituloCompleto = $tituloPagina ? $tituloPagina . ' · StabilMoney' : 'StabilMoney';
        // Página pública com título de SEO próprio (config/seo.php): o login e o cadastro.
        $tituloCompleto = \App\Support\Seo::pagina(request()->route()?->getName())['titulo'] ?? $tituloCompleto;
    @endphp
    <title>{{ $tituloCompleto }}</title>
    @include('partials.seo', ['seoTitulo' => $tituloCompleto])

    <link rel="icon" type="image/png" href="{{ asset('assets/favicon.png') }}" />
    @include('partials.pwa-head')

    {{-- Fontes do design system (Sora + Plus Jakarta Sans) --}}
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600..800&family=Geist:wght@400..600&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="auth-body">
<main class="auth">

    {{-- Painel visual: vídeo + marca + mensagem (a classe .ready entra via sm/auth.js) --}}
    <section class="auth-visual">
        {{-- `poster` + `preload="metadata"` (out/2026): a página aparece com a imagem na hora, e o
             vídeo (1,5 MB) carrega depois — antes ele segurava o maior elemento da tela (LCP). --}}
        <video class="auth-video" id="authVideo" autoplay muted loop playsinline preload="metadata"
               poster="{{ asset('assets/og-stabilmoney.jpg') }}">
            <source src="{{ asset('assets/video_login.mp4') }}" type="video/mp4" />
        </video>
        <div class="auth-veil"></div>
        @include('partials.selo-do-video')

        {{-- A marca leva à página inicial pública (out/2026). --}}
        <a class="av-top" href="{{ url('/') }}" aria-label="Stabil Money — página inicial">
            <span class="av-badge"><img src="{{ asset('assets/stabilmoney-mark.png') }}" alt="" /></span>
            <span class="av-word">Stabil<b>Money</b></span>
        </a>

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

@include('partials.cookie-consent')
</body>
</html>
