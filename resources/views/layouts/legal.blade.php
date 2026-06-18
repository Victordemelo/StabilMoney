<!DOCTYPE html>
<html lang="pt-BR" data-theme="light">
{{-- Layout das páginas legais (Termos / Privacidade): coluna legível e centrada,
     marca no topo, públicas. Reaproveita os tokens do design system via @vite. --}}
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <meta name="theme-color" content="#0C3D2B" />
    <title>@yield('title', 'StabilMoney')</title>

    <link rel="icon" type="image/png" href="{{ asset('assets/favicon.png') }}" />
    @include('partials.pwa-head')

    {{-- Anti-flash: aplica o tema salvo antes de pintar --}}
    <script>
        (function () {
            try {
                var t = localStorage.getItem('sm-theme');
                if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-theme', t);
            } catch (e) {}
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        .legal-wrap { max-width: 760px; margin: 0 auto; padding: 34px 20px 80px; }
        .legal-head { display: inline-flex; align-items: center; gap: 10px; margin-bottom: 28px; text-decoration: none; }
        .legal-head img { width: 30px; height: 30px; display: block; }
        .legal-head .bn { font: 700 18px/1 var(--font-head); color: var(--ink); }
        .legal-head .bn b { color: var(--brand-600); }
        .legal h1 { font: 700 26px/1.2 var(--font-head); color: var(--ink); letter-spacing: -.01em; }
        .legal .upd { color: var(--ink-3); font-size: 13px; margin: 6px 0 24px; }
        .legal h2 { font: 600 17px/1.3 var(--font-head); color: var(--ink); margin: 26px 0 8px; }
        .legal p, .legal li { color: var(--ink); font-size: 15px; line-height: 1.65; }
        .legal a { color: var(--brand-600); font-weight: 600; }
        .legal ul { margin: 8px 0 8px 20px; }
        .legal li { margin-bottom: 5px; }
        .legal-note { margin-top: 26px; padding: 14px 16px; border-radius: 12px;
                      background: rgba(15,107,71,.07); border: 1px solid var(--line); font-size: 14px; }
        .legal-back { display: inline-block; margin-top: 30px; }
    </style>
</head>
<body>
    <div class="legal-wrap">
        <a class="legal-head" href="{{ url('/') }}">
            <img src="{{ asset('assets/stabilmoney-mark.png') }}" alt="StabilMoney" />
            <span class="bn">Stabil<b>Money</b></span>
        </a>

        <main class="legal">
            @yield('content')
        </main>
    </div>

@include('partials.cookie-consent')
</body>
</html>
