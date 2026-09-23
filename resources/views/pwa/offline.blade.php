<!DOCTYPE html>
<html lang="pt-BR">
{{--
    Página offline do PWA (mostrada pelo service worker quando uma navegação
    falha por falta de rede). É autossuficiente DE PROPÓSITO: estilos inline,
    sem @vite e sem fontes externas — assim renderiza mesmo na primeira vez
    offline. O ícone vem de /assets/icons (pré-cacheado pelo service worker).
--}}
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    {{-- Esta página é escura nos DOIS temas (estilos próprios, sem design system
         e sem acesso ao tema salvo), então a cor da barra é fixa mesmo — e casa
         com o topo do gradiente do body (#114B34 no centro superior), não com o
         verde da marca. Pelo mesmo motivo a barra de status do iPhone pede
         `black` (glifos claros): sem esta meta o iOS assume `default`, e glifos
         escuros sobre este fundo verde-escuro somem. --}}
    <meta name="theme-color" content="#114B34" />
    <meta name="apple-mobile-web-app-status-bar-style" content="black" />
    <title>Você está offline — StabilMoney</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #E8F3EC;
            background: radial-gradient(120% 120% at 50% 0%, #114B34 0%, #0C3D2B 45%, #082A1E 100%);
            text-align: center;
        }
        .card { max-width: 360px; }
        .mark {
            width: 76px; height: 76px;
            margin: 0 auto 22px;
            border-radius: 20px;
            background: #fff;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .3);
        }
        .mark img { width: 52px; height: 52px; display: block; }
        h1 { font-size: 22px; font-weight: 700; margin-bottom: 10px; letter-spacing: -.01em; }
        p { font-size: 15px; line-height: 1.5; color: #B9D6C6; margin-bottom: 26px; }
        button {
            appearance: none; border: 0; cursor: pointer;
            font: inherit; font-weight: 600;
            padding: 13px 26px; border-radius: 12px;
            color: #0C3D2B; background: #36D38A;
            transition: filter .15s ease;
        }
        button:hover { filter: brightness(1.05); }
        .hint { margin-top: 18px; font-size: 13px; color: #8FB7A2; }
    </style>
</head>
<body>
    <main class="card">
        <div class="mark">
            <img src="/assets/icons/icon-192.png" alt="StabilMoney" />
        </div>
        <h1>Você está offline</h1>
        <p>Não foi possível carregar esta página porque você está sem conexão. Suas finanças continuam seguras — é só reconectar.</p>
        <button type="button" id="tentarDeNovo">Tentar de novo</button>
        <div class="hint">A conexão volta? Esta tela recarrega sozinha.</div>
    </main>
    <script nonce="{{ Vite::cspNonce() }}">
        // O clique é ligado AQUI, e não num `onclick="..."` no botão: a CSP com nonce
        // bloqueia todo manipulador inline (nonce não vale para atributo), e o botão
        // ficava sem fazer nada — em silêncio, só o console avisava.
        document.getElementById('tentarDeNovo').addEventListener('click', function () { location.reload(); });
        // Quando a rede voltar, recarrega automaticamente.
        window.addEventListener('online', function () { location.reload(); });
    </script>
</body>
</html>
