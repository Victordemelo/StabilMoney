<?php

// Caminho do painel, normalizado ANTES de virar config (ver a seção "Caminho base").
$caminhoDoPainel = trim((string) env('ADMIN_PANEL_PATH', ''), " \t/");

return [

    /*
    |--------------------------------------------------------------------------
    | Painel ligado?
    |--------------------------------------------------------------------------
    |
    | Desligado, TODAS as rotas do painel respondem 404 — não "403", não uma tela
    | de login: 404, como se a rota não existisse. Um atacante que varre o site não
    | descobre nem que existe um painel.
    |
    | O padrão é DESLIGADO. Quem quiser usar liga no `.env` (ADMIN_PANEL_ENABLED=true)
    | e desliga depois. Na maior parte do tempo a superfície de ataque simplesmente
    | não está no ar.
    |
    */

    'enabled' => (bool) env('ADMIN_PANEL_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Caminho base
    |--------------------------------------------------------------------------
    |
    | Trocar aqui muda a URL do painel inteiro. Não é segurança de verdade (URL
    | secreta não protege nada sozinha), mas tira o painel dos caminhos que os
    | scanners automáticos testam primeiro.
    |
    | ⚠️ NUNCA pode ficar vazio. As rotas do painel usam este valor como prefixo
    | (routes/admin.php), e prefixo vazio as registra na RAIZ do site: `GET /`
    | passava a cair na tela de login do painel e, com o painel desligado, a página
    | inicial do app inteiro virava 404. Bastava deixar `ADMIN_PANEL_PATH=` sem
    | valor no `.env` — o `env()` só aplica o padrão quando a chave NÃO existe.
    |
    | Por isso: vazio, só barras ou só espaços caem no padrão, e barras nas pontas
    | saem (`/segredo/` vira `segredo`). A comparação é com `''`, não um `?:`: com
    | `?:`, o caminho "0" também seria trocado pelo padrão.
    |
    */

    'path' => $caminhoDoPainel === '' ? 'painel_admin' : $caminhoDoPainel,

    /*
    |--------------------------------------------------------------------------
    | E-mail dos alertas
    |--------------------------------------------------------------------------
    |
    | Para onde vão os avisos de login no painel, banimento e exclusão. Vazio =
    | manda para o e-mail do próprio admin que agiu.
    |
    */

    'alert_email' => env('ADMIN_ALERT_EMAIL'),

];
