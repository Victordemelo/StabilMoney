<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Hash Driver
    |--------------------------------------------------------------------------
    |
    | Driver de hash padrão do app. Usamos "argon2id" (memory-hard, recomendado
    | pelo OWASP). O login verifica o algoritmo pelo prefixo do hash, então
    | hashes antigos em bcrypt continuam validando normalmente.
    |
    | Suportados: "bcrypt", "argon", "argon2id"
    |
    */

    'driver' => 'argon2id',

    /*
    |--------------------------------------------------------------------------
    | Bcrypt Options
    |--------------------------------------------------------------------------
    */

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => true,
        'limit' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Argon Options
    |--------------------------------------------------------------------------
    |
    | Parâmetros do Argon2 (valem para "argon" e "argon2id"). Os defaults
    | seguem a recomendação do Laravel.
    |
    */

    'argon' => [
        'memory' => 65536,
        'threads' => 1,
        'time' => 4,
        'verify' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rehash On Login
    |--------------------------------------------------------------------------
    |
    | Re-hasheia a senha no login quando os parâmetros do hash mudam.
    |
    */

    'rehash_on_login' => true,

];
