<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ordem manual das categorias: o usuário arrasta os chips dentro da coluna e
 * decide quem aparece primeiro (ex.: "Salário" no topo das receitas).
 *
 * `integer` e NÃO `unsignedInteger` de propósito: um cálculo que escorregasse
 * para -1 seria erro no MySQL e passaria batido no sqlite — a divergência
 * clássica que este projeto já pagou caro (ver a nota do `varchar` vs `text`
 * nas colunas `encrypted`). Coluna com o mesmo comportamento nos dois drivers.
 *
 * O backfill é feito em PHP, e não em SQL com função de janela (ROW_NUMBER só
 * chega ao sqlite 3.25 e ao MySQL 8): percorre cada coluna da tela — isto é,
 * cada par (user_id, type) — na ORDEM QUE A TELA JÁ MOSTRAVA (fixas primeiro,
 * depois alfabética) e numera 0,1,2… Assim quem já usa o app não vê a tela se
 * embaralhar no dia da migration, e nenhuma linha nasce empatada com outra
 * (empate = ordem indefinida entre um refresh e outro).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Reentrante: o `hasColumn` deixa rodar o backfill de novo sem estourar
        // por coluna duplicada (é assim que o teste exercita só a numeração, e
        // é o que salva um deploy que morreu entre o ALTER e o UPDATE — o MySQL
        // não tem DDL transacional).
        if (! Schema::hasColumn('categories', 'position')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->integer('position')->default(0)->after('is_locked');
            });
        }

        DB::table('categories')
            ->select('id', 'user_id', 'type')
            ->orderBy('user_id')
            ->orderBy('type')
            ->orderByDesc('is_locked')
            ->orderBy('name')
            ->orderBy('id') // desempate final: dois nomes iguais não podem alternar
            ->get()
            ->groupBy(fn ($categoria) => $categoria->user_id.'|'.$categoria->type)
            ->each(function ($coluna) {
                $posicao = 0;

                foreach ($coluna as $categoria) {
                    DB::table('categories')
                        ->where('id', $categoria->id)
                        ->update(['position' => $posicao++]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
