<?php

use App\Support\DefaultCategories;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Recolore as categorias padrão já existentes.
 *
 * A paleta antiga tinha 6 cores (4 delas verdes) e ciclava pela lista, então
 * categorias diferentes acabavam com a MESMA cor (ex.: Alimentação e Compras).
 * Agora cada categoria padrão tem cor própria e distinta.
 *
 * Só atualiza quem ainda está com uma das cores antigas — se o usuário escolheu
 * uma cor à mão, ela é preservada.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('categories')
            ->select('id', 'name', 'type', 'color')
            ->orderBy('id')
            ->chunkById(200, function ($linhas) {
                foreach ($linhas as $linha) {
                    // Respeita cor escolhida pelo usuário (fora da paleta antiga).
                    if ($linha->color && ! in_array($linha->color, DefaultCategories::LEGACY_COLORS, true)) {
                        continue;
                    }

                    $nova = DefaultCategories::defaultColorFor($linha->name, $linha->type);
                    if ($nova && $nova !== $linha->color) {
                        DB::table('categories')->where('id', $linha->id)->update(['color' => $nova]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Sem volta: as cores antigas se repetiam e não dá para reconstruir
        // qual delas cabia a cada categoria. Recolorir de novo é inofensivo.
    }
};
