<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Categorias fixas: as de uso recorrente (Alimentação, Moradia, Saúde,
 * Transporte e Contas) vêm cadastradas e NÃO podem ser excluídas nem mudar
 * de tipo. Podem ser renomeadas/repintadas normalmente.
 *
 * O backfill marca as categorias fixas que já existem no banco (criadas antes
 * desta coluna) — assim quem já usa o app não precisa rodar o seeder de novo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('is_locked')->default(false)->after('icon');
        });

        DB::table('categories')
            ->where('type', 'expense')
            ->whereIn('name', ['Alimentação', 'Moradia', 'Saúde', 'Transporte', 'Contas'])
            ->update(['is_locked' => true]);
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('is_locked');
        });
    }
};
