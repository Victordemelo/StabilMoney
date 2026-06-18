<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investments', function (Blueprint $table) {
            $table->id();
            // Dono = titular da família (mesma semântica de transactions/accounts/goals).
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Quem criou o investimento (titular ou dependente). Autor removido => null.
            $table->foreignId('made_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('name');
            // renda_fixa | renda_variavel | fundos | cripto
            $table->string('classe');
            // CDI | Selic | IPCA+ | Prefixado (ou null = não indexado / RV / cripto)
            $table->string('indexador')->nullable();
            // Percentual do indexador / taxa fixa (% a.a.); só metadado para projeção.
            $table->decimal('taxa', 8, 2)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investments');
    }
};
