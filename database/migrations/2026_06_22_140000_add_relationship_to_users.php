<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grau de parentesco do dependente (cônjuge, filho, etc.) — para o titular ter
 * um controle melhor de quem é quem na família. Null = não informado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('relationship', 20)->nullable()->after('account_owner_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('relationship');
        });
    }
};
