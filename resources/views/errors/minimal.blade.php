<!DOCTYPE html>
{{--
    Moldura de TODAS as páginas de erro — as nossas (errors/4xx, 404, 419…) e as que o
    framework ainda traz prontas (401, 402), que estendem `errors::minimal` e passam a
    ganhar esta cara em vez da página mínima em inglês.

    ⚠️ Esta página precisa funcionar com o app QUEBRADO, porque é exatamente quando ela
    aparece. Por isso, e não por estilo:
      - sem @vite: o 500 pode ser o próprio build faltando (ou o `public/hot` esquecido
        em produção) — o CSS vai embutido no <style>, com os tokens do `:root` do
        design-system.css copiados como valores literais (tema claro fixo, como as telas
        de auth);
      - sem banco, sem sessão, sem auth, sem View Composer, sem partial do shell: o 500
        de "banco fora do ar" não pode depender do banco para ser desenhado;
      - ZERO <script>: a CSP das respostas de erro (SecurityHeaders::CSP_SEM_SCRIPT) não
        tem nonce e bloqueia qualquer um;
      - nada que varie por CAMINHO ou por QUEM está logado: o 404 do prefixo do painel
        desligado tem de sair byte a byte igual ao de uma URL que não existe
        (PainelAdminDesligadoNaoSeRevelaTest) — ecoar o endereço pedido entregaria o
        painel. Pelo mesmo motivo o link de volta vai sempre para o início;
      - nunca a mensagem da exceção: com o debug desligado, o texto é o daqui.
--}}
@php
    // Quanto esperar, quando a resposta diz: o `Retry-After` do 429 (limite de tentativas)
    // e o do 503 (manutenção com `--retry`). No `php artisan down --render=errors::503` a
    // view é desenhada ANTES, sem exceção nenhuma, e recebe o valor em `$retryAfter`.
    $retry = isset($exception) && method_exists($exception, 'getHeaders')
        ? (array_change_key_case($exception->getHeaders())['retry-after'] ?? null)
        : null;
    $retry ??= $retryAfter ?? null;

    $espera = null;
    if (is_numeric($retry) && (int) $retry > 0) {
        $segundos = (int) $retry;
        $minutos = (int) ceil($segundos / 60);
        $horas = (int) ceil($segundos / 3600);

        $espera = match (true) {
            $segundos < 60 => $segundos === 1 ? '1 segundo' : $segundos.' segundos',
            $minutos < 60 => $minutos === 1 ? '1 minuto' : $minutos.' minutos',
            default => $horas === 1 ? '1 hora' : $horas.' horas',
        };
    }
@endphp
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#0C3D2B">
    <title>@yield('title') · StabilMoney</title>
    <link rel="icon" type="image/png" href="{{ asset('assets/favicon.png') }}">
    <style>
        :root {
            --brand-700: #11624A;
            --brand-600: #15795A;
            --brand-500: #1C9A70;
            --brand-300: #6FCDA8;
            --brand-100: #DCF1E8;
            --brand-50: #EEF8F3;
            --bg: #EFF4F1;
            --surface: #FFFFFF;
            --ink: #112019;
            --ink-2: #46584F;
            --line: #E5ECE8;
            --shadow-lg: 0 18px 48px rgba(11,58,40,.14), 0 6px 18px rgba(11,58,40,.08);
            --font-head: "Sora", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            --font-body: "Plus Jakarta Sans", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            min-height: 100vh;
            min-height: 100dvh;
            display: grid;
            place-items: center;
            padding: 24px 16px;
            background: radial-gradient(120% 70% at 50% 0%, var(--brand-50) 0%, var(--bg) 62%);
            color: var(--ink);
            font-family: var(--font-body);
            -webkit-font-smoothing: antialiased;
        }
        ::selection { background: var(--brand-100); color: var(--ink); }

        .erro {
            width: 100%;
            max-width: 440px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 22px;
            box-shadow: var(--shadow-lg);
            padding: 32px 28px;
        }
        .marca { display: flex; align-items: center; gap: 11px; margin-bottom: 28px; }
        .marca .selo {
            width: 40px; height: 40px; flex: none;
            border-radius: 12px; background: var(--brand-50);
            display: grid; place-items: center;
        }
        .marca .selo img { width: 30px; height: 30px; display: block; }
        .marca .nome { font-family: var(--font-head); font-weight: 700; letter-spacing: .1em; }
        .marca .nome b { color: var(--brand-600); font-weight: 700; }

        .codigo {
            display: inline-block;
            margin-bottom: 14px;
            padding: 4px 10px;
            border: 1px solid var(--brand-100);
            border-radius: 999px;
            background: var(--brand-50);
            color: var(--brand-700);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        h1 {
            font-family: var(--font-head);
            font-size: 24px;
            font-weight: 600;
            line-height: 1.25;
            letter-spacing: -.02em;
        }
        .explicacao, .espera { margin-top: 10px; font-size: 15px; line-height: 1.6; color: var(--ink-2); }
        .espera strong { color: var(--ink); }

        .acoes { margin-top: 26px; }
        .botao {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            padding: 14px;
            border-radius: 13px;
            background: linear-gradient(135deg, var(--brand-500), var(--brand-700));
            box-shadow: 0 10px 24px rgba(17,98,74,.3);
            color: #fff;
            font-size: 15px;
            font-weight: 700;
            text-decoration: none;
        }
        .botao:hover { filter: brightness(1.06); }
        .botao:focus-visible { outline: 3px solid var(--brand-300); outline-offset: 3px; }

        @media (max-width: 480px) {
            .erro { padding: 26px 20px; }
            h1 { font-size: 21px; }
        }
    </style>
</head>
<body>
    <main class="erro">
        <div class="marca">
            <span class="selo"><img src="{{ asset('assets/stabilmoney-mark.png') }}" alt="" width="30" height="30"></span>
            <span class="nome">Stabil<b>Money</b></span>
        </div>

        <p class="codigo">Erro @yield('code')</p>
        <h1>@yield('message')</h1>
        <p class="explicacao">@yield('explicacao', 'Volte ao início e tente de novo.')</p>
        @if ($espera)
            <p class="espera">Tente de novo em <strong>{{ $espera }}</strong>.</p>
        @endif

        <div class="acoes">
            <a class="botao" href="{{ url('/') }}">@yield('acao', 'Voltar ao início')</a>
        </div>
    </main>
</body>
</html>
