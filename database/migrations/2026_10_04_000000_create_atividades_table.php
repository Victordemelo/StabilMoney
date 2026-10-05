<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de ATIVIDADE da família — "quem mexeu em quê, onde e quando" (out/2026).
 *
 * NÃO confundir com `admin_audit_logs`, que é o histórico do PAINEL administrativo (o que
 * a administração fez). Esta tabela é da FAMÍLIA: o titular vê tudo o que aconteceu na
 * conta, e cada dependente vê o que ele mesmo fez. O painel nunca a lê.
 *
 * Decisões de esquema:
 *
 * - `owner_id` = titular da família, SEM FK: a exclusão da conta apaga as linhas pelo hook
 *   `deleting` do User (como as sessões), e uma FK com cascade faria o mesmo em silêncio
 *   pelo banco — sem chance de anonimizar as linhas de um DEPENDENTE que sai (essas ficam,
 *   são a história do dinheiro da família, mas perdem IP e aparelho).
 * - `user_id` = autor, SEM FK: o registro sobrevive à exclusão de quem o fez, e o nome do
 *   autor vai em `autor_nome`, congelado no momento da ação (nome trocado depois não
 *   reescreve o passado). Nulo = "Sistema" ou a administração.
 * - `descricao` e `mudancas` em `text`: a frase leva nomes digitados pelo usuário (até 255
 *   cada um) e um `varchar(255)` estouraria no MySQL (erro 1406) passando verde no sqlite.
 * - `ip` em `text` porque tem cast `encrypted` (o cifrado tem 200+ caracteres — a mesma
 *   armadilha do `terms_accepted_ip` e do `two_factor_secret`).
 * - Só `created_at`: registro de atividade não se edita.
 *
 * Índices pensados para a tela (Configurações › Atividade) e para a limpeza diária:
 * `(owner_id, created_at)` serve a lista do titular; `(owner_id, user_id, created_at)` a do
 * dependente e o filtro por pessoa; `(owner_id, grupo, created_at)` o filtro por tipo; e
 * `created_at` sozinho o `atividades:limpar` (que apaga por idade, de todas as famílias).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atividades', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('autor_nome');
            $table->string('acao', 60);
            $table->string('grupo', 20);
            $table->string('alvo_tipo', 60)->nullable();
            $table->unsignedBigInteger('alvo_id')->nullable();
            $table->text('descricao');
            $table->text('mudancas')->nullable();
            $table->text('ip')->nullable();
            $table->text('aparelho')->nullable();
            // `dateTime`, e não `timestamp`: num MySQL com `explicit_defaults_for_timestamp`
            // desligado, o primeiro `timestamp` NOT NULL ganha `ON UPDATE` sozinho.
            $table->dateTime('created_at');

            $table->index(['owner_id', 'created_at']);
            $table->index(['owner_id', 'user_id', 'created_at']);
            $table->index(['owner_id', 'grupo', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atividades');
    }
};
