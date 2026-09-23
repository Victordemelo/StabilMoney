<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * O índice `(user_id, recurring, paid_at)` de `transactions` existe e a migration dele
 * vai e volta (V-2 da auditoria de volume de 06/09/2026).
 *
 * É ele que atende a consulta de recorrências do sino, que roda em TODA página
 * autenticada: sem ele, o MySQL lia a família inteira (12 mil linhas estimadas com 24
 * mil transações, 14 ms) para devolver três. O ganho medido está no docblock da
 * migration; aqui se trava que o índice não suma num rollback mal feito nem numa
 * migration futura que recrie a tabela.
 *
 * Não há EXPLAIN aqui: com as poucas linhas de um teste, o otimizador do MySQL escolhe o
 * índice pelas estatísticas do momento, e o teste ficaria instável.
 */
class IndiceDasRecorrenciasDoSinoTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_22_200000_add_user_recurring_paid_index_to_transactions.php';

    private const COLUNAS = ['user_id', 'recurring', 'paid_at'];

    public function test_o_indice_das_recorrencias_do_sino_existe(): void
    {
        $this->assertTrue(
            Schema::hasIndex('transactions', self::COLUNAS),
            'Falta o índice (user_id, recurring, paid_at): o sino volta a varrer a família inteira em toda página.'
        );
    }

    public function test_a_migration_desfaz_refaz_e_aguenta_rodar_duas_vezes(): void
    {
        $migration = require base_path(self::MIGRATION);

        $migration->down();
        $migration->down(); // reentrante: rollback pela metade não trava na segunda vez
        $this->assertFalse(Schema::hasIndex('transactions', self::COLUNAS));

        $migration->up();
        $migration->up();
        $this->assertTrue(Schema::hasIndex('transactions', self::COLUNAS));
    }
}
