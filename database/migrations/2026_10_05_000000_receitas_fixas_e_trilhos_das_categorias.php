<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Categorias fixas de receita + fixas sempre no topo (out/2026 — pedido do Victor).
 *
 * 1. Toda família ganha Salário, Vale alimentação e Vale transporte como receitas FIXAS
 *    (não saem, não mudam de tipo). A que já existe com o mesmo nome só ganha o cadeado.
 * 2. Cada coluna é renumerada nos trilhos do `Category`: fixas em 0,1,2…, livres em
 *    1000,1001… — mantendo a ordem relativa de cada grupo. Até aqui reordenar podia pôr
 *    uma livre acima de uma fixa; depois desta migration, nenhuma coluna começa assim.
 *
 * Os dados ficam AQUI (e não lidos de `DefaultCategories`): migration é retrato do dia
 * em que roda, e mudar a lista do cadastro depois não pode mudar o que ela faz.
 */
return new class extends Migration
{
    /** @var array<string, array{icon: string, color: string}> na ordem do topo da coluna */
    private const RECEITAS_FIXAS = [
        'Salário' => ['icon' => '💰', 'color' => '#1FA06E'],
        'Vale alimentação' => ['icon' => '🍽️', 'color' => '#F0A93B'],
        'Vale transporte' => ['icon' => '🚗', 'color' => '#3B82C4'],
    ];

    private const TRILHO_FIXA = 0;

    private const TRILHO_LIVRE = 1000;

    public function up(): void
    {
        $agora = now();

        // Categoria é da família: mora no titular (dependente não tem categoria própria).
        DB::table('users')->whereNull('account_owner_id')->orderBy('id')->pluck('id')
            ->each(function ($titular) use ($agora) {
                foreach (self::RECEITAS_FIXAS as $nome => $padrao) {
                    $existente = DB::table('categories')
                        ->where('user_id', $titular)->where('type', 'income')->where('name', $nome)
                        ->orderBy('id')->value('id');

                    if ($existente !== null) {
                        DB::table('categories')->where('id', $existente)->update(['is_locked' => true]);

                        continue;
                    }

                    DB::table('categories')->insert([
                        'user_id' => $titular,
                        'name' => $nome,
                        'type' => 'income',
                        'icon' => $padrao['icon'],
                        'color' => $padrao['color'],
                        'is_locked' => true,
                        'position' => self::TRILHO_FIXA,
                        'created_at' => $agora,
                        'updated_at' => $agora,
                    ]);
                }
            });

        $ordemDasReceitasFixas = array_flip(array_keys(self::RECEITAS_FIXAS));

        DB::table('categories')
            ->select('id', 'user_id', 'type', 'name', 'is_locked', 'position')
            ->orderBy('user_id')->orderBy('type')->orderBy('position')->orderBy('name')->orderBy('id')
            ->get()
            ->groupBy(fn ($c) => $c->user_id.'|'.$c->type)
            ->each(function ($coluna) use ($ordemDasReceitasFixas) {
                // `sortBy` é estável: dentro de cada grupo, vale a ordem que a pessoa já via.
                $fixas = $coluna->filter(fn ($c) => (bool) $c->is_locked)
                    ->sortBy(fn ($c) => $c->type === 'income' ? ($ordemDasReceitasFixas[$c->name] ?? 99) : 0)
                    ->values();
                $livres = $coluna->reject(fn ($c) => (bool) $c->is_locked)->values();

                foreach ($fixas as $i => $c) {
                    DB::table('categories')->where('id', $c->id)->update(['position' => self::TRILHO_FIXA + $i]);
                }
                foreach ($livres as $i => $c) {
                    DB::table('categories')->where('id', $c->id)->update(['position' => self::TRILHO_LIVRE + $i]);
                }
            });
    }

    /**
     * Tira o cadeado das três receitas (a regra antiga: nenhuma receita era fixa). As
     * linhas ficam: a partir daqui podem ter lançamentos, e a numeração nos trilhos
     * continua válida para a ordem antiga.
     */
    public function down(): void
    {
        DB::table('categories')
            ->where('type', 'income')
            ->whereIn('name', array_keys(self::RECEITAS_FIXAS))
            ->update(['is_locked' => false]);
    }
};
