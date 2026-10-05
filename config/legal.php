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

    'version' => '3.3',

    'updated_at' => '5 de outubro de 2026',

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

    'contact_email' => 'victor.rosa.faculdade@gmail.com',

];
