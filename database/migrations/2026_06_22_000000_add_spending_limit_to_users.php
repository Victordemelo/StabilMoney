<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saldo/limite de gasto do dependente: quanto ele pode gastar. As despesas
 * lançadas por ele (made_by_user_id) descontam desse valor. Null = sem limite
 * (titular nunca tem um).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('spending_limit', 15, 2)->nullable()->after('account_owner_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('spending_limit');
        });
    }
};
