<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove o limite de gasto do dependente: a tela de dependentes passou a só
 * mostrar quanto cada um já gastou (sem orçamento). down() recria a coluna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('spending_limit');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('spending_limit', 15, 2)->nullable()->after('account_owner_id');
        });
    }
};
