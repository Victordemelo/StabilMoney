<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CHECK constraints nos "dias do mês".
 *
 * `unsignedTinyInteger` aceita 0..255: um `due_day = 0` ou `= 200` cabe na
 * coluna e quebra o cálculo de ciclo/vencimento em silêncio. A faixa só existia
 * nos Form Requests — ou seja, valia para o formulário e para mais nada:
 * seeder, tinker, import, comando artisan e qualquer código futuro passam por
 * fora e gravam lixo.
 *
 * FAIXA ESCOLHIDA: 1..31, não o 1..28 dos cartões. O banco garante a
 * integridade ESTRUTURAL (existe um dia com esse número no calendário); a regra
 * de NEGÓCIO mais estreita (1..28 no cartão, para o ciclo nunca pular um mês
 * curto) continua no Form Request, onde dá para mudar sem migration.
 *
 * SOMENTE MYSQL. O sqlite não suporta `ALTER TABLE ... ADD CONSTRAINT`: seria
 * preciso recriar a tabela inteira, com todas as FKs, o que deixaria a migration
 * (e principalmente o down()) frágil justo no driver em que a suíte roda. Como o
 * alvo é blindar produção/dev — que são MySQL — e a validação de aplicação segue
 * valendo nos dois, o custo/benefício não fecha para o sqlite.
 */
return new class extends Migration
{
    /** [tabela, coluna, nome da constraint] */
    private const CHECKS = [
        ['accounts', 'closing_day', 'accounts_closing_day_check'],
        ['accounts', 'due_day', 'accounts_due_day_check'],
        ['fixed_bills', 'due_day', 'fixed_bills_due_day_check'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::CHECKS as [$tabela, $coluna, $nome]) {
            // Nullable nas três: null significa "não se aplica" e precisa passar.
            DB::statement(
                "ALTER TABLE `{$tabela}` ADD CONSTRAINT `{$nome}` ".
                "CHECK (`{$coluna}` IS NULL OR (`{$coluna}` >= 1 AND `{$coluna}` <= 31))"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::CHECKS as [$tabela, , $nome]) {
            DB::statement("ALTER TABLE `{$tabela}` DROP CHECK `{$nome}`");
        }
    }
};
