<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Brl;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * O valor que o aviso da fila offline MOSTRA é o valor que o servidor GRAVA (P-3 da
 * auditoria de PWA de 06/09/2026).
 *
 * O aviso "precisa de você" lê o `amount` guardado na fila com uma cópia, em JS, das
 * regras do `NormalizesMoneyInput` + `decimal:0,2`. Cópia de regra é regra que diverge em
 * silêncio. Este teste manda cada entrada da tabela compartilhada
 * (`tests/js/fixtures/valores-da-fila-offline.json`) ao servidor DE VERDADE — rota,
 * middlewares (TrimStrings incluso), Form Request e banco — exatamente como o reenvio da
 * fila manda: JSON, com o valor como a máscara deixou. `tests/js/offline-queue-revisao.test.js`
 * confere que o aviso mostra o rótulo da mesma tabela.
 *
 * Se este teste ficar vermelho depois de mexer na normalização, o `lerValor()` do
 * `resources/js/sm/offline-queue.js` precisa acompanhar — e a tabela, ser corrigida.
 */
class ValorDaFilaOfflineIgualAoServidorTest extends TestCase
{
    use RefreshDatabase;

    private const TABELA = __DIR__.'/../js/fixtures/valores-da-fila-offline.json';

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function casosDaTabela(): iterable
    {
        $tabela = json_decode((string) file_get_contents(self::TABELA), true, flags: JSON_THROW_ON_ERROR);

        foreach ($tabela['casos'] as $caso) {
            yield json_encode($caso['entrada']).' — '.$caso['porque'] => [$caso['entrada'], $caso['rotulo']];
        }
    }

    #[DataProvider('casosDaTabela')]
    public function test_o_servidor_le_o_valor_como_o_aviso_da_fila_mostra(string $entrada, string $rotulo): void
    {
        $user = User::factory()->create();
        $conta = Account::factory()->for($user)->create(['type' => 'checking', 'initial_balance' => 0]);

        // Receita: o guard de saldo não entra na conversa, só a leitura do valor.
        $resposta = $this->actingAs($user)->postJson(route('transactions.store'), [
            'type' => 'income',
            'amount' => $entrada,
            'account_id' => $conta->id,
            'date' => CarbonImmutable::today()->toDateString(),
            'description' => 'Lançamento que dormiu na fila',
        ]);

        if (str_starts_with($rotulo, 'R$ ')) {
            $resposta->assertCreated();

            $this->assertSame(
                $rotulo,
                Brl::format(Transaction::sole()->amount),
                'o aviso da fila mostraria '.$rotulo.', mas o servidor gravou outro valor',
            );

            return;
        }

        // "valor não reconhecido" / "sem valor": o servidor também não lê valor nenhum ali.
        $resposta->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('transactions', 0);
    }
}
