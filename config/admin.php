<?php

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
    */

    'path' => env('ADMIN_PANEL_PATH', 'painel_admin'),

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
