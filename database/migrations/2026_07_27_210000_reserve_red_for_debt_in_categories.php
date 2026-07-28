<?php

use App\Support\DefaultCategories;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tira o vermelho das categorias.
 *
 * O #E5604D é o token --neg do design system: a cor de "está devendo" (saldo
 * negativo, valor a pagar, fatura vencida). Usá-lo numa categoria (Alimentação
 * estava assim) deixa a leitura financeira ambígua — vermelho na tela tem que
 * significar dívida, não "comida".
 *
 * Reaplica as cores padrão atuais. Como sempre, só mexe em quem está com uma
 * cor que o próprio sistema atribuiu (DefaultCategories::LEGACY_COLORS);
 * cor escolhida à mão pelo usuário é preservada.
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
                    if ($linha->color && ! in_array($linha->color, DefaultCategories::LEGACY_COLORS, true)) {
                        continue; // cor do usuário: não toca
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
        // Sem volta: recolorir de novo é inofensivo e o vermelho não deve voltar.
    }
};
