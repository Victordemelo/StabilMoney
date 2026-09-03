<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SpendingGuard;
use App\Support\Brl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verificação cética do achado "edição de despesa no cheque especial".
 *
 * Reproduz o cenário pelo caminho HTTP real (PUT /transactions/{id}) em dois
 * mundos: com a lógica do HEAD (guard injetado abaixo, cópia fiel do commit
 * 9997f07) e com a lógica atual do working tree.
 */
class CeticoEdicaoChequeEspecialTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Account, 2: Category, 3: Transaction} */
    private function cenario(): array
    {
        $u = User::factory()->create(['is_admin' => true, 'account_owner_id' => null]);
        $conta = Account::factory()->for($u)->create([
            'type' => 'checking',
            'name' => 'Corrente',
            'initial_balance' => 0,
            'overdraft_limit' => 1500,
        ]);
        $cat = Category::factory()->for($u)->create(['type' => 'expense']);

        $t = Transaction::create([
            'user_id' => $u->id,
            'made_by_user_id' => $u->id,
            'account_id' => $conta->id,
            'category_id' => $cat->id,
            'type' => 'expense',
            'amount' => 1400,
            'description' => 'digitou errado',
            'date' => now()->toDateString(),
        ]);

        return [$u, $conta->fresh(), $cat, $t];
    }

    private function payload(Account $conta, Category $cat, string $amount, ?string $fonte = null): array
    {
        return array_filter([
            'type' => 'expense',
            'amount' => $amount,
            'account_id' => $conta->id,
            'category_id' => $cat->id,
            'date' => now()->toDateString(),
            'description' => 'corrigido',
            'funding_source' => $fonte,
        ]);
    }

    /** Os números crus dos dois cálculos, medidos no banco. */
    public function test_aritmetica_do_head_versus_a_atual(): void
    {
        [, $conta] = $this->cenario();

        $ignore = 1400.0;
        $disponivelComIgnore = round($conta->available + $ignore, 2);
        $faltante = round(140.0 - max(0.0, $disponivelComIgnore), 2);

        fwrite(STDERR, "\n[aritmetica] available=".$conta->available
            .' | disponivel+ignore='.$disponivelComIgnore
            .' | faltante='.$faltante
            .' | teto HEAD (overdraftAvailable)='.$conta->overdraftAvailable
            .' | teto atual (overdraftAvailableWith(1400))='.$conta->overdraftAvailableWith($ignore)."\n");

        // O disponível já devolveu os R$ 1.400; o teto do HEAD ainda os considera gastos.
        $this->assertSame(0.0, $disponivelComIgnore);
        $this->assertSame(100.0, $conta->overdraftAvailable, 'teto do HEAD');
        $this->assertSame(1500.0, $conta->overdraftAvailableWith($ignore), 'teto com o ignore');
        $this->assertTrue($faltante > $conta->overdraftAvailable, 'no HEAD, 140 > 100 => ESTOURA_LIMITE');
        $this->assertTrue($faltante <= $conta->overdraftAvailableWith($ignore), 'com o ignore, cabe');
    }

    /** HEAD: PUT baixando 1400 -> 140 é RECUSADO, mesmo escolhendo a fonte. */
    public function test_head_recusa_a_correcao_para_menos(): void
    {
        [$u, $conta, $cat, $t] = $this->cenario();

        // Guard com a lógica exata do commit 9997f07.
        $this->app->bind(SpendingGuard::class, fn () => new HeadSpendingGuard);

        $r = $this->actingAs($u)->from(route('transactions.edit', $t))
            ->put(route('transactions.update', $t), $this->payload($conta, $cat, '140,00'));

        $erro = session('errors')?->first('amount');
        fwrite(STDERR, "[HEAD] status={$r->status()} erro=".$erro."\n");
        fwrite(STDERR, '[HEAD] valor gravado='.$t->fresh()->amount."\n");

        $r->assertSessionHasErrors('amount');
        $this->assertSame(1400.0, (float) $t->fresh()->amount, 'o valor errado continua gravado');

        // Nem escolhendo o cheque especial explicitamente ele passa.
        $r2 = $this->actingAs($u)->from(route('transactions.edit', $t))
            ->put(route('transactions.update', $t), $this->payload($conta, $cat, '140,00', 'cheque_especial'));
        $r2->assertSessionHasErrors('amount');
        $this->assertSame(1400.0, (float) $t->fresh()->amount);

        // Trocar só a descrição, mantendo o valor, é EDIÇÃO NEUTRA (02/09/2026):
        // não move dinheiro, então nem passa pelo guard — grava direto. Antes
        // desta regra o guard do HEAD recusava até isso.
        $r3 = $this->actingAs($u)->from(route('transactions.edit', $t))
            ->put(route('transactions.update', $t), $this->payload($conta, $cat, '1400,00'));
        $r3->assertSessionHasNoErrors();
        $this->assertSame('corrigido', $t->fresh()->description);
        $this->assertSame(1400.0, (float) $t->fresh()->amount);
    }

    /** Working tree atual: a correção passa (via escolha da fonte). */
    public function test_codigo_atual_permite_corrigir(): void
    {
        [$u, $conta, $cat, $t] = $this->cenario();

        // Sem escolher a fonte: o app pergunta (409 / sessão), não recusa.
        $r = $this->actingAs($u)->from(route('transactions.edit', $t))
            ->putJson(route('transactions.update', $t), $this->payload($conta, $cat, '140,00'));
        fwrite(STDERR, "[ATUAL] sem fonte -> status={$r->status()}\n");
        $r->assertStatus(409);

        // Escolhendo o cheque especial: grava.
        $r2 = $this->actingAs($u)->from(route('transactions.edit', $t))
            ->put(route('transactions.update', $t), $this->payload($conta, $cat, '140,00', 'cheque_especial'));
        $r2->assertRedirect(route('transactions.index'));

        fwrite(STDERR, '[ATUAL] valor gravado='.$t->fresh()->amount
            .' | saldo='.$conta->fresh()->available."\n");
        $this->assertSame(140.0, (float) $t->fresh()->amount);
        $this->assertSame(-140.0, $conta->fresh()->available);
    }
}

/** Cópia fiel do SpendingGuard do commit 9997f07 (antes do `...With($ignore)`). */
class HeadSpendingGuard extends SpendingGuard
{
    public function check(Account $account, float $amount, float $ignore = 0.0): string
    {
        if (! $account->isCash()) {
            return self::OK;
        }

        $amount = round($amount, 2);
        $disponivel = round($account->available + $ignore, 2);

        if ($amount <= $disponivel + self::EPSILON) {
            return self::OK;
        }

        $faltante = round($amount - max(0.0, $disponivel), 2);
        $cobreCheque = $faltante <= $account->overdraftAvailable + self::EPSILON;
        $cobreResgate = $faltante <= $this->resgatavel($account) + self::EPSILON;

        return ($cobreCheque || $cobreResgate) ? self::PRECISA_FONTE : self::ESTOURA_LIMITE;
    }

    public function mensagemSemFonte(Account $account, float $amount, float $ignore = 0.0): string
    {
        $disponivel = round($account->available + $ignore, 2);
        $gastavel = round(max(0.0, $disponivel) + $account->overdraftAvailable, 2);

        $msg = 'Saldo insuficiente: a conta '.$account->name.' tem '
            .Brl::format($disponivel).' disponíveis e esta despesa é de '
            .Brl::format($amount).'.';

        if ($account->overdraftLimitValue > 0) {
            $msg .= ' Somando o cheque especial, o máximo agora é '.Brl::format($gastavel).'.';
        }

        return $msg.' Lance um recebimento para completar o valor.';
    }
}
