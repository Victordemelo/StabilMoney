<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            // Dono = titular da família (mesma semântica de transactions/accounts).
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Quem criou a meta (titular ou dependente). Autor removido => null.
            $table->foreignId('made_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('emoji');
            $table->string('color');
            $table->decimal('target_amount', 15, 2);
            $table->date('target_date')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goals');
    }
};
