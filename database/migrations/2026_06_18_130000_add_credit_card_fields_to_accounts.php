<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Campos exclusivos de cartão de crédito (type=credit_card):
     * limite, dia de fechamento e dia de vencimento da fatura. Nullable —
     * só fazem sentido para cartões; as demais contas os deixam em null.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->decimal('credit_limit', 15, 2)->nullable()->after('initial_balance');
            $table->unsignedTinyInteger('closing_day')->nullable()->after('credit_limit');
            $table->unsignedTinyInteger('due_day')->nullable()->after('closing_day');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['credit_limit', 'closing_day', 'due_day']);
        });
    }
};
