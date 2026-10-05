<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Lê o período "de"/"até" de um filtro GET (Movimentações, Configurações › Atividade),
 * tolerando lixo — o filtro chega pela URL e qualquer um pode digitar `?de=ontem`.
 *
 * - Data inválida é IGNORADA em vez de estourar: filtro é conveniência, não pode derrubar
 *   a lista inteira.
 * - A leitura é ESTRITA: `createFromFormat` é tolerante e transforma "2026-13-45" em
 *   2027-02-14 em silêncio, então a data é reformatada e comparada com a entrada — só passa
 *   o que for exatamente Y-m-d válido.
 * - Datas invertidas (de > até) são TROCADAS em vez de devolver lista vazia: quem digita
 *   30/09 no "de" e 01/09 no "até" quis setembro, e uma tela em branco não ajuda a perceber
 *   o erro.
 */
final class PeriodoDoFiltro
{
    /**
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public static function ler(Request $request): array
    {
        $de = self::data($request->query('de'));
        $ate = self::data($request->query('ate'));

        if ($de && $ate && $de->greaterThan($ate)) {
            return [$ate, $de];
        }

        return [$de, $ate];
    }

    private static function data(mixed $valor): ?CarbonImmutable
    {
        $valor = is_string($valor) ? trim($valor) : '';

        if ($valor === '') {
            return null;
        }

        try {
            $data = CarbonImmutable::createFromFormat('Y-m-d', $valor);
        } catch (\Throwable) {
            return null;
        }

        return $data && $data->format('Y-m-d') === $valor ? $data->startOfDay() : null;
    }
}
