<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    {{-- `noindex` de verdade: o painel não pode aparecer em buscador nenhum. --}}
    <meta name="robots" content="noindex, nofollow, noarchive" />
    <meta name="theme-color" content="#07140E" />
    <title>@yield('title', 'Painel') · Stabil Money</title>
    <link rel="icon" type="image/png" href="{{ asset('assets/favicon.png') }}" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Sora:wght@600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{-- Tema ESCURO fixo nas telas do painel: é o sinal visual mais barato de "você não
     está no app do cliente, está no lugar onde se mexe na conta dos outros". --}}
<body style="min-height:100dvh;display:grid;place-items:center;padding:24px;background:var(--bg)">
    <main style="width:100%;max-width:420px">
        <div style="text-align:center;margin-bottom:22px">
            <img src="{{ asset('assets/stabilmoney-mark.png') }}" alt="" width="42" height="42" style="border-radius:10px">
            <h1 style="font-family:Sora,sans-serif;font-size:19px;margin:12px 0 4px;color:var(--text)">Painel administrativo</h1>
            <p style="font-size:13px;color:var(--muted);margin:0">Acesso restrito · Stabil Money</p>
        </div>

        <div class="card" style="padding:24px">
            @yield('content')
        </div>

        <p style="text-align:center;font-size:12px;color:var(--muted);margin-top:18px">
            Todas as tentativas de acesso são registradas.
        </p>
    </main>
</body>
</html>
