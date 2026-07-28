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

    'version' => '2.0',

    'updated_at' => '27 de julho de 2026',

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
