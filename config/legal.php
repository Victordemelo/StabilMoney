<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Versão dos documentos legais
    |--------------------------------------------------------------------------
    |
    | Fonte única da verdade para Termos de Uso e Política de Privacidade.
    | As views em resources/views/legal/ exibem estes valores, e o cadastro
    | grava `version` em users.terms_version como prova do aceite.
    |
    | AO ALTERAR O TEXTO DOS DOCUMENTOS: suba a `version` e ajuste `updated_at`.
    | Assim dá para saber qual versão cada usuário aceitou — sem isso, mudar o
    | texto reescreveria retroativamente o que todos "concordaram".
    |
    */

    'version' => '3.4',

    'updated_at' => '7 de outubro de 2026',
    // A mesma data em AAAA-MM-DD (o <lastmod> do sitemap.xml). Mude junto com a de cima.
    'updated_at_iso' => '2026-10-07',

    /*
    |--------------------------------------------------------------------------
    | O que mudou em cada versão (tela de novo aceite)
    |--------------------------------------------------------------------------
    |
    | Quem aceitou uma versão anterior cai, depois do login, na tela de aceite
    | (`ExigeAceiteDaPoliticaAtual`), que mostra estes itens da versão atual. Ao
    | subir a `version`, escreva aqui, em linguagem simples, o que mudou.
    |
    */

    'mudancas' => [
        '3.4' => [
            'Novo e-mail de contato, também para assuntos de privacidade e para o encarregado dos dados: victor.rosa.system@gmail.com.',
            'Os e-mails do aplicativo (confirmação de cadastro, redefinição de senha, alertas e lembretes) passam a ser enviados pelo serviço de e-mail da Oracle, na região de São Paulo (Brasil) — a mesma empresa e a mesma região do servidor.',
        ],
        '3.3' => [
            'O “Lembrar de mim” passa a valer por 7 dias. Depois disso, o aplicativo pede a sua senha de novo.',
            'Em Configurações › Atividade, o titular continua vendo o que cada pessoa da família fez e de qual aparelho, mas o endereço IP só aparece para a própria pessoa.',
            'Quando os documentos mudarem, o aplicativo pede o seu aceite da nova versão no próximo acesso — como agora.',
        ],
        '3.2' => [
            'Registro de atividade da família (quem fez o quê, quando e de onde), guardado por 6 meses.',
            'Opção “Confiar neste aparelho por 7 dias” na verificação em duas etapas.',
            'Fuso do relógio escolhido em Configurações.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Controlador dos dados (LGPD) e contato
    |--------------------------------------------------------------------------
    |
    | Quem responde pelo tratamento dos dados e atua como Encarregado (DPO).
    |
    */

    'controller' => 'Victor de Melo da Rosa',

    'contact_email' => 'victor.rosa.system@gmail.com',

];
