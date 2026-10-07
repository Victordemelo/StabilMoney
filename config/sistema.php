<?php

/*
 * Informações do sistema (out/2026) — a página "Informações do sistema" do menu do perfil.
 *
 * `versao` segue o versionamento semântico (MAIOR.MENOR.CORREÇÃO): suba a CORREÇÃO num conserto,
 * a MENOR numa função nova e a MAIOR quando algo deixar de funcionar como antes. A versão dos
 * Termos e da Política NÃO mora aqui: é a `legal.version` (config/legal.php).
 */
return [
    'versao' => '1.0.0',
    'lancada_em' => 'outubro de 2026',
    'fase' => 'Fase de testes',

    'autor' => [
        'nome' => 'Victor de Melo da Rosa',
        'site' => 'https://victordemelo.com.br',
        'linkedin' => 'https://www.linkedin.com/in/victor-de-melo-da-rosa/',
        'github' => 'https://github.com/Victordemelo',
        // A foto da seção "Quem fez" da página inicial (public/assets).
        'foto' => 'assets/victor-de-melo.jpg',
    ],
];
