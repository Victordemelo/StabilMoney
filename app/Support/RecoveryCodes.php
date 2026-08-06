<?php

namespace App\Support;

/**
 * Códigos de recuperação da verificação em duas etapas.
 *
 * São a ÚNICA porta de volta de quem ativa o 2FA e perde o celular. Neste app isso pesa
 * mais do que no normal: enquanto `MAIL_MAILER` for `log`, nem "esqueci a senha" entrega
 * e-mail (ver App\Support\Mailer), então não existe recuperação por e-mail nenhuma. Sem os
 * códigos, a conta fica inacessível de verdade — por isso a tela avisa antes de ligar e a
 * lista aparece logo depois de confirmar.
 *
 * Alfabeto sem caracteres ambíguos (nada de 0/O, 1/I/L, U/V): estes códigos são feitos para
 * serem ANOTADOS NO PAPEL e digitados depois, meses mais tarde, sob estresse. Confundir zero
 * com O queimaria um código à toa.
 *
 * 10 caracteres de um alfabeto de 30 ≈ 49 bits — muito além do que o limite de tentativas da
 * tela de desafio deixa alguém explorar.
 */
final class RecoveryCodes
{
    /** Quantos códigos são entregues de cada vez. */
    public const QUANTIDADE = 8;

    /** Sem 0/O, 1/I/L e U (confundido com V à mão). */
    private const ALFABETO = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const TAMANHO_GRUPO = 5;

    /**
     * Lista nova de códigos, no formato "A7K2M-9PQR4".
     *
     * @return list<string>
     */
    public static function gerar(int $quantidade = self::QUANTIDADE): array
    {
        return array_map(fn () => self::um(), range(1, $quantidade));
    }

    /**
     * Gasta um código da lista: devolve a lista SEM ele, ou null se não conferir.
     *
     * Devolver a lista nova (em vez de mexer numa referência) deixa a decisão de persistir
     * com quem chamou — é o que permite gravar o consumo na mesma transação do login.
     *
     * @param  list<string>  $codigos
     * @return list<string>|null
     */
    public static function consumir(array $codigos, string $informado): ?array
    {
        $procurado = self::normalizar($informado);

        if ($procurado === '') {
            return null;
        }

        $restantes = [];
        $achou = false;

        foreach ($codigos as $codigo) {
            // hash_equals compara em tempo constante; e o laço percorre a lista inteira
            // mesmo depois de achar, para o tempo de resposta não denunciar a posição.
            if (! $achou && hash_equals(self::normalizar($codigo), $procurado)) {
                $achou = true;

                continue;
            }

            $restantes[] = $codigo;
        }

        return $achou ? array_values($restantes) : null;
    }

    /**
     * Deixa comparável o que a pessoa digitou: maiúsculas, sem hífen e sem espaço.
     * Assim "a7k2m 9pqr4" e "A7K2M-9PQR4" são o mesmo código.
     */
    public static function normalizar(string $codigo): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($codigo))) ?? '';
    }

    private static function um(): string
    {
        $bruto = '';
        $ultimo = strlen(self::ALFABETO) - 1;

        for ($i = 0; $i < self::TAMANHO_GRUPO * 2; $i++) {
            // random_int: gerador criptográfico. rand()/mt_rand() são previsíveis e
            // tornariam os códigos adivinháveis a partir de um deles.
            $bruto .= self::ALFABETO[random_int(0, $ultimo)];
        }

        return substr($bruto, 0, self::TAMANHO_GRUPO).'-'.substr($bruto, self::TAMANHO_GRUPO);
    }
}
