<!DOCTYPE html>
<html lang="pt-BR" data-theme="light">
{{-- Layout das páginas legais (Termos / Privacidade): coluna legível e centrada,
     marca no topo, públicas. Reaproveita os tokens do design system via @vite. --}}
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    {{-- Mesma regra do layouts/app: a cor da barra do celular acompanha o `--bg`
         do tema (claro #EFF4F1, escuro #07140E), porque estas páginas usam o
         design system inteiro e o tema salvo. Ver o comentário longo lá. --}}
    <meta name="theme-color" content="#EFF4F1" data-sm-theme data-light="#EFF4F1" data-dark="#07140E" />
    <title>@yield('title', 'StabilMoney')</title>

    <link rel="icon" type="image/png" href="{{ asset('assets/favicon.png') }}" />
    @include('partials.pwa-head')

    {{-- Anti-flash: aplica o tema salvo antes de pintar + acerta as cores das
         bordas do sistema (barra do navegador/status). Cópia deliberada do inline
         do layouts/app — o iOS lê a meta da barra no carregamento, então isto não
         pode esperar o bundle; ao mexer aqui, mexa lá também. --}}
    <script nonce="{{ Vite::cspNonce() }}">
        (function () {
            var t;
            try { t = localStorage.getItem('sm-theme'); } catch (e) { /* storage indisponível */ }
            if (t !== 'dark' && t !== 'light') t = document.documentElement.getAttribute('data-theme') || 'light';
            document.documentElement.setAttribute('data-theme', t);

            var cor = document.querySelector('meta[name="theme-color"][data-sm-theme]');
            if (cor) cor.setAttribute('content', cor.getAttribute(t === 'dark' ? 'data-dark' : 'data-light'));

            var barra = document.querySelector('meta[name="apple-mobile-web-app-status-bar-style"]');
            if (barra) barra.setAttribute('content', t === 'dark' ? 'black' : 'default');
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        /* O design system usa `body { overflow: hidden }` porque no app a rolagem mora
           dentro do `.content`. Aqui não existe shell, então o body precisa rolar —
           mesma solução do `.auth-body`. Sem isto, documento longo fica cortado no desktop
           (no mobile a media query de 920px já devolve o overflow). */
        .legal-body { height: auto; min-height: 100%; overflow-y: auto; overflow-x: hidden; }

        .legal-wrap { max-width: 760px; margin: 0 auto; padding: 34px 20px 80px; }
        .legal-head { display: inline-flex; align-items: center; gap: 10px; margin-bottom: 28px; text-decoration: none; }
        .legal-head img { width: 30px; height: 30px; display: block; }
        .legal-head .bn { font: 700 18px/1 var(--font-head); color: var(--ink); }
        .legal-head .bn b { color: var(--brand-600); }
        .legal h1 { font: 700 26px/1.2 var(--font-head); color: var(--ink); letter-spacing: -.01em; }
        .legal .upd { color: var(--ink-3); font-size: 13px; margin: 6px 0 24px; }
        .legal h2 { font: 600 17px/1.3 var(--font-head); color: var(--ink); margin: 26px 0 8px; }
        .legal h3 { font: 600 15px/1.35 var(--font-head); color: var(--ink); margin: 18px 0 6px; }
        .legal p, .legal li { color: var(--ink); font-size: 15px; line-height: 1.65; }
        .legal a { color: var(--brand-600); font-weight: 600; }
        .legal ul { margin: 8px 0 8px 20px; }
        .legal li { margin-bottom: 5px; }
        .legal-note { margin-top: 26px; padding: 14px 16px; border-radius: 12px;
                      background: rgba(15,107,71,.07); border: 1px solid var(--line); font-size: 14px; }
        .legal-back { display: inline-block; margin-top: 30px; }

        /* Tabelas de transparência (dado → finalidade → base legal) — rolam no celular */
        .legal-table { overflow-x: auto; margin: 12px 0 18px; border: 1px solid var(--line); border-radius: 12px; }
        .legal-table table { width: 100%; border-collapse: collapse; min-width: 520px; }
        .legal-table th, .legal-table td { text-align: left; padding: 10px 12px; font-size: 14px;
                                           line-height: 1.5; border-bottom: 1px solid var(--line); color: var(--ink); }
        .legal-table th { font: 600 13px/1.4 var(--font-head); color: var(--ink-2);
                          background: rgba(15,107,71,.05); text-transform: uppercase; letter-spacing: .03em; }
        .legal-table tr:last-child td { border-bottom: 0; }

        /* Sumário no topo dos documentos longos */
        .legal-toc { margin: 0 0 26px; padding: 14px 16px; border-radius: 12px; border: 1px solid var(--line); }
        .legal-toc strong { display: block; font: 600 13px/1.4 var(--font-head); color: var(--ink-2);
                            text-transform: uppercase; letter-spacing: .03em; margin-bottom: 8px; }
        .legal-toc ol { margin: 0 0 0 20px; }
        .legal-toc li { font-size: 14px; margin-bottom: 3px; }
    </style>
</head>
<body class="legal-body">
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
