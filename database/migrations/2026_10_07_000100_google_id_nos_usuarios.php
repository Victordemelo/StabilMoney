<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Entrar com o Google" (out/2026): o identificador da conta Google (o `sub` do OpenID
 * Connect) de quem já entrou por ele. Único — uma conta Google entra em UMA conta do app —
 * e em texto puro, porque é procurado a cada login (cifrado, não daria para buscar). Não é
 * segredo: sozinho não entra em nada; é o Google quem prova, a cada login, que a pessoa é dona dele.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id', 64)->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropColumn('google_id');
        });
    }
};
