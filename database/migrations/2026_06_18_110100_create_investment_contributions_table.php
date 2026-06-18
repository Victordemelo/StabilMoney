<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investment_id')->constrained()->cascadeOnDelete();
            // restrictOnDelete: não dá para apagar uma conta que tem aportes/resgates
            // (o saldo "reservado" deixaria de fazer sentido). Guard no AccountController.
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            // Quem fez o aporte/resgate (titular ou dependente). Removido => null.
            $table->foreignId('made_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            // aporte | resgate
            $table->string('type');
            $table->decimal('amount', 15, 2); // sempre positivo; o sentido vem do type
            $table->date('date');
            $table->timestamps();

            $table->index(['investment_id', 'type']);
            $table->index(['account_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_contributions');
    }
};
