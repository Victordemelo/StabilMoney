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
    @include('partials.seo', ['seoTitulo' => trim($__env->yieldContent('title', 'StabilMoney'))])

    <link rel="icon" type="image/png" href="{{ asset('assets/favicon.png') }}" />
    @include('partials.pwa-head')

    {{-- Anti-flash: aplica o tema (o salvo; sem escolha, o claro) antes de pintar +
         acerta as cores das bordas do sistema (barra do navegador/status). Cópia
         deliberada do inline do layouts/app — o iOS lê a meta da barra no carregamento,
         então isto não pode esperar o bundle; ao mexer aqui, mexa lá também (o
         tests/js/theme.test.js executa os dois e compara com o `resolverTema`). --}}
    <script nonce="{{ Vite::cspNonce() }}">
        (function () {
            var t;
            try { t = localStorage.getItem('sm-theme'); } catch (e) { /* storage indisponível */ }
            // Sem escolha salva, o CLARO — inclusive com o sistema no modo escuro (out/2026).
            if (t !== 'dark') t = 'light';
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

        /* Barra do topo, presa ao rolar: a pessoa nunca fica sem saída num documento longo. */
        .legal-topo { position: sticky; top: 0; z-index: 20; background: color-mix(in srgb, var(--bg) 86%, transparent);
                      backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); border-bottom: 1px solid var(--line); }
        .legal-topo-in { max-width: 1140px; margin: 0 auto; padding: 12px 20px; display: flex; align-items: center; gap: 16px;
                         /* PWA no iPhone deitado: o notch vira margem lateral (viewport-fit=cover). */
                         padding-left: calc(20px + env(safe-area-inset-left, 0px)); padding-right: calc(20px + env(safe-area-inset-right, 0px)); }
        .legal-docs { display: flex; gap: 4px; padding: 4px; margin-left: auto; border-radius: 12px;
                      background: var(--surface-3); border: 1px solid var(--line); }
        .legal-docs a { padding: 7px 14px; border-radius: 9px; font-size: 13px; font-weight: 600; color: var(--ink-3); text-decoration: none; white-space: nowrap; }
        .legal-docs a.ativo { background: var(--surface); color: var(--brand-700); box-shadow: var(--shadow-sm); }
        [data-theme="dark"] .legal-docs a.ativo { color: var(--brand-300); }
        .legal-voltar { font-size: 13px; font-weight: 600; color: var(--ink-2); text-decoration: none; padding: 8px 14px;
                        border-radius: 10px; border: 1px solid var(--line); background: var(--surface); }
        .legal-voltar::before { content: "← "; }

        .legal-wrap { max-width: 1140px; margin: 0 auto; padding: 28px 20px 80px;
                      padding-left: calc(20px + env(safe-area-inset-left, 0px)); padding-right: calc(20px + env(safe-area-inset-right, 0px)); }
        /* O documento num card legível; com sumário, o sumário vira uma coluna FIXA à
           esquerda (só no desktop) — os itens continuam na mesma ordem do HTML. */
        .legal { background: var(--surface); border: 1px solid var(--line); border-radius: 20px;
                 padding: 34px 40px 40px; box-shadow: var(--shadow-card, var(--shadow-sm)); }
        @media (min-width: 1000px) {
            .legal:has(.legal-toc) { display: grid; grid-template-columns: 250px minmax(0, 1fr); column-gap: 44px; }
            .legal:has(.legal-toc) > * { grid-column: 2; }
            .legal:has(.legal-toc) > .legal-toc { grid-column: 1; grid-row: 1 / span 300; align-self: start;
                position: sticky; top: 86px; max-height: calc(100vh - 110px); overflow-y: auto; margin: 0; }
        }
        .legal-head { display: inline-flex; align-items: center; gap: 10px; text-decoration: none; }
        .legal-head img { width: 30px; height: 30px; display: block; }
        .legal-head .bn { font: 700 18px/1 var(--font-head); color: var(--ink); }
        .legal-head .bn b { color: var(--brand-600); }
        .legal h1 { font: 700 30px/1.15 var(--font-head); color: var(--ink); letter-spacing: -.02em; }
        .legal .upd { color: var(--ink-3); font-size: 13px; margin: 6px 0 24px; }
        .legal h2 { font: 600 18px/1.3 var(--font-head); color: var(--ink); margin: 32px 0 10px; padding-top: 18px;
                    border-top: 1px solid var(--line-2); scroll-margin-top: 86px; }
        .legal h3 { font: 600 15px/1.35 var(--font-head); color: var(--ink); margin: 18px 0 6px; }
        .legal p, .legal li { color: var(--ink); font-size: 15px; line-height: 1.65; }
        .legal a { color: var(--brand-600); font-weight: 600; }
        .legal ul { margin: 8px 0 8px 20px; }
        .legal li { margin-bottom: 5px; }
        .legal-note { margin-top: 26px; padding: 14px 16px; border-radius: 12px;
                      background: rgba(15,107,71,.07); border: 1px solid var(--line); font-size: 14px; }
        .legal-back { display: inline-block; margin-top: 30px; }

        /* Tela de aceite da versão nova (legal/aceite): estreita, um só caminho adiante. */
        .aceite { max-width: 640px; margin: 0 auto; }
        .aceite-form { margin-top: 24px; display: grid; gap: 14px; justify-items: start; }
        .aceite-check { align-items: flex-start; font-size: 14.5px; line-height: 1.5; color: var(--ink); }
        .aceite-check .box { margin-top: 2px; }
        .aceite-check input:focus-visible + .box { outline: 2px solid var(--brand-500); outline-offset: 2px; }
        .aceite-sair { margin-top: 30px; padding-top: 18px; border-top: 1px solid var(--line-2);
                       display: flex; flex-wrap: wrap; align-items: center; gap: 12px 18px; }
        .aceite-sair p { flex: 1 1 260px; font-size: 13.5px; color: var(--ink-2); margin: 0; }

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
        .legal-toc li { font-size: 13.5px; margin-bottom: 4px; }
        .legal-toc a { font-weight: 500; text-decoration: none; }
        .legal-toc a:hover { text-decoration: underline; }
        @media (max-width: 720px) {
            .legal { padding: 22px 18px 28px; border-radius: 16px; }
            .legal-topo-in { flex-wrap: wrap; gap: 10px; }
            .legal-docs { order: 3; width: 100%; margin-left: 0; }
            .legal-docs a { flex: 1; text-align: center; padding: 7px 8px; }
            .legal-voltar { margin-left: auto; }
            /* Alvos de toque de 32px (tests/e2e/responsividade.mjs): os itens do sumário
               eram linhas de 17px coladas umas nas outras. */
            .legal-toc li { margin-bottom: 0; }
            .legal-toc a { display: block; padding: 7px 0; }
            .legal-head, .legal-back { min-height: 32px; display: inline-flex; align-items: center; }
        }
        /* Celular estreito: "Política de Privacidade" quebra em duas linhas dentro da
           pílula em vez de passar da tela; o e-mail de contato quebra no meio. */
        @media (max-width: 420px) {
            .legal-docs a { white-space: normal; min-width: 0; }
            .legal-topo-in { padding: 10px calc(14px + env(safe-area-inset-right, 0px)) 10px calc(14px + env(safe-area-inset-left, 0px)); }
            .legal-wrap { padding: 18px calc(10px + env(safe-area-inset-right, 0px)) 60px calc(10px + env(safe-area-inset-left, 0px)); }
            .legal { padding: 18px 14px 24px; overflow-wrap: anywhere; }
            .legal h1 { font-size: 24px; }
        }
    </style>
</head>
<body class="legal-body">
    {{-- Barra do topo: marca, os dois documentos lado a lado e o caminho de volta. O texto
         jurídico mora SÓ nas views de legal/ (é delas a impressão que o
         VersaoDosDocumentosLegaisTest confere); mudar este layout não muda o documento. --}}
    <header class="legal-topo">
        <div class="legal-topo-in">
            <a class="legal-head" href="{{ url('/') }}">
                <img src="{{ asset('assets/stabilmoney-mark.png') }}" alt="StabilMoney" />
                <span class="bn">Stabil<b>Money</b></span>
            </a>
            <nav class="legal-docs" aria-label="Documentos">
                <a href="{{ route('termos') }}" @class(['ativo' => request()->routeIs('termos')]) @if (request()->routeIs('termos')) aria-current="page" @endif>Termos de Uso</a>
                <a href="{{ route('privacidade') }}" @class(['ativo' => request()->routeIs('privacidade')]) @if (request()->routeIs('privacidade')) aria-current="page" @endif>Política de Privacidade</a>
            </nav>
            <a class="legal-voltar" href="{{ url('/') }}" data-voltar>Voltar</a>
        </div>
    </header>

    <div class="legal-wrap">
        <main class="legal">
            @yield('content')
        </main>
    </div>

{{-- "← Voltar" (`a[data-voltar]`): volta para onde a pessoa estava — em geral o cadastro, com o
     que ela já tinha digitado — e cai no `href` quando não há de onde voltar (aba nova). Era um
     `onclick` inline, que a CSP com nonce bloqueia: o link sempre levava à página inicial. --}}
<script nonce="{{ Vite::cspNonce() }}">
    document.addEventListener('click', function (e) {
        var link = e.target.closest ? e.target.closest('a[data-voltar]') : null;
        if (!link || window.history.length <= 1) return;
        e.preventDefault();
        window.history.back();
    });
</script>

@include('partials.cookie-consent')
</body>
</html>
