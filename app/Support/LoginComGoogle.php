<?php

namespace App\Support;

/**
 * O "Entrar com o Google" está ligado? Só com as DUAS chaves da credencial OAuth no .env
 * (GOOGLE_CLIENT_ID e GOOGLE_CLIENT_SECRET, criadas no Google Cloud Console). Sem elas o botão
 * não aparece e as rotas respondem 404 — ninguém clica num botão que levaria a um erro do Google.
 */
final class LoginComGoogle
{
    public static function ativo(): bool
    {
        return trim((string) config('services.google.client_id')) !== ''
            && trim((string) config('services.google.client_secret')) !== '';
    }
}
