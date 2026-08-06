<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dados pessoais do perfil: data de nascimento e sexo.
 *
 * Os dois são **opcionais** de propósito. Nada no app depende deles hoje, e um
 * app de finanças não tem por que exigir dado que não usa — a Política de
 * Privacidade promete coletar só o necessário (minimização, LGPD art. 6º, III).
 * Ficam disponíveis para o que vier (faixa etária em relatórios, saudação), e
 * quem não quiser informar simplesmente não informa.
 *
 * `gender` é string curta, não enum de banco: enum diverge entre MySQL e sqlite
 * (a suíte roda em sqlite) e trava a lista de opções no schema — a mesma razão
 * pela qual `funding_source` e `relationship` também são string. Os valores
 * válidos vivem em `User::GENEROS` e são validados no Form Request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('birth_date')->nullable()->after('phone');
            $table->string('gender', 20)->nullable()->after('birth_date');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['birth_date', 'gender']);
        });
    }
};
