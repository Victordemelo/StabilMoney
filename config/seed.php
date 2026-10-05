<?php

/*
 * Usuário de DESENVOLVIMENTO criado pelo DatabaseSeeder (só em APP_ENV=local).
 *
 * As chaves SEED_USER_* são OPCIONAIS e temporárias: sem e-mail E senha, o seeder não cria
 * usuário nenhum (nunca um com senha padrão). Quem já tem conta não precisa delas — a senha
 * fica só como hash no banco. Para recriar o usuário num banco zerado, defina as duas, rode o
 * seed e apague-as do .env de novo.
 */
return [
    'usuario' => [
        'nome' => env('SEED_USER_NAME', 'Victor'),
        'email' => env('SEED_USER_EMAIL'),
        'senha' => env('SEED_USER_PASSWORD'),
    ],
];
