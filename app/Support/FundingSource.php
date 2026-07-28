<?php

namespace App\Support;

/**
 * De onde saiu o dinheiro quando o saldo disponível não cobriu a despesa
 * sozinho. Gravado em `transactions.funding_source` para auditoria.
 *
 * Não é enum de banco de propósito: enum diverge entre MySQL e sqlite e é caro
 * de alterar. A validação mora aqui e nos Form Requests.
 */
final class FundingSource
{
    /** Coberto pelo saldo que a conta já tinha (caso normal — grava null). */
    public const DISPONIVEL = 'disponivel';

    /** O usuário aceitou ficar negativo, dentro do limite do cheque especial. */
    public const CHEQUE_ESPECIAL = 'cheque_especial';

    /** O usuário resgatou de um investimento para cobrir o que faltava. */
    public const RESGATE_INVESTIMENTO = 'resgate_investimento';

    /** Todas as escolhas aceitas num POST de despesa. */
    public const TODAS = [
        self::DISPONIVEL,
        self::CHEQUE_ESPECIAL,
        self::RESGATE_INVESTIMENTO,
    ];

    /** Rótulo PT-BR (usado no modal de escolha e no histórico). */
    public static function label(?string $source): ?string
    {
        return match ($source) {
            self::CHEQUE_ESPECIAL => 'Cheque especial',
            self::RESGATE_INVESTIMENTO => 'Resgate de investimento',
            default => null,
        };
    }
}
