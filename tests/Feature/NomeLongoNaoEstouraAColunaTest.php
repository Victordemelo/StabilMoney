<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\FixedBill;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nome no limite do formulário (255 caracteres) somado a um texto fixo do app não pode
 * estourar o `varchar(255)` de destino — M-1 e M-2 da auditoria de MySQL de 05/09/2026.
 *
 * No MySQL em modo estrito o estouro é erro 1406: HTTP 500 no pagamento da fatura, e o
 * log do painel que não é gravado (a ação em si já tinha sido). A suíte roda em sqlite,
 * que NÃO aplica tamanho de coluna e gravava 277 caracteres sem reclamar — por isso estes
 * testes não esperam erro do banco: eles MEDEM o que foi gravado, com a régua do MySQL
 * (caracteres em utf8mb4, não bytes).
 *
 * Os nomes levam acento e emoji de propósito: são os caracteres de mais de um byte, onde
 * medir ou cortar errado aparece.
 */
class NomeLongoNaoEstouraAColunaTest extends TestCase
{
    use RefreshDatabase;

    /** `$table->string(...)` sem tamanho = `varchar(255)`. */
    private const VARCHAR = 255;

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    /** O maior nome que os formulários aceitam (`max:255`, contado em caracteres). */
    private function nomeNoLimite(string $pedaco): string
    {
        $nome = mb_substr(str_repeat($pedaco, 30), 0, self::VARCHAR);
        $this->assertSame(self::VARCHAR, mb_strlen($nome), 'o nome do cenário precisa estar no limite do formulário');

        return $nome;
    }

    /** A régua do MySQL numa coluna `varchar(255)` utf8mb4. */
    private function assertCabeNoVarchar(?string $valor, string $coluna): void
    {
        $this->assertNotNull($valor, "{$coluna} não foi gravada");
        $this->assertTrue(
            mb_check_encoding($valor, 'UTF-8'),
            "{$coluna} ficou com UTF-8 inválido — o MySQL recusaria com erro 1366",
        );
        $this->assertLessThanOrEqual(
            self::VARCHAR,
            mb_strlen($valor, 'UTF-8'),
            "{$coluna} ficou com ".mb_strlen($valor, 'UTF-8').' caracteres — no MySQL isso é erro 1406 (HTTP 500)',
        );
    }

    private function comoAdmin(): static
    {
        config(['admin.enabled' => true]);

        return $this->actingAs(Admin::factory()->comDoisFatores()->create(), 'admin')
            ->withSession(['admin_2fa_ok' => true]);
    }

    // ── M-1: descrições montadas em `transactions.description` ───────────────

    public function test_pagar_a_fatura_de_um_cartao_com_nome_no_limite(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-15'));

        $user = User::factory()->create();
        $caixa = Account::factory()->for($user)->create([
            'type' => 'checking', 'name' => 'Corrente', 'initial_balance' => 5000, 'overdraft_limit' => 0,
        ]);
        // Fecha dia 10: a compra de 12/09 está no ciclo aberto (10/09, 10/10].
        $cartao = Account::factory()->for($user)->creditCard()->create([
            'name' => $this->nomeNoLimite('Cartão da Família Conceição 💳 '),
        ]);
        Transaction::factory()->for($user)->for($cartao)->expense()->create([
            'amount' => 300, 'date' => '2026-09-12', 'description' => 'Mercado',
        ]);

        $this->actingAs($user)
            ->post(route('faturas.fatura.pagar', $cartao), ['pay_account_id' => $caixa->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('faturas.index'));

        $quitacao = Transaction::whereNotNull('settles_account_id')->sole();

        $this->assertCabeNoVarchar($quitacao->description, 'transactions.description');
        // Quem encolhe é o NOME: o prefixo explica a saída de caixa no extrato.
        $this->assertStringStartsWith('Pagamento da fatura — Cartão da Família Conceição 💳', $quitacao->description);
        $this->assertStringEndsWith('…', $quitacao->description);
        $this->assertSame(4700.0, $caixa->fresh()->available, 'e o pagamento continua acontecendo');
    }

    public function test_pagar_a_conta_fixa_com_nome_no_limite_preserva_a_competencia(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13'));

        $user = User::factory()->create();
        $caixa = Account::factory()->for($user)->create(['type' => 'checking', 'initial_balance' => 10000]);
        $conta = FixedBill::create([
            'user_id' => $user->id,
            'name' => $this->nomeNoLimite('Condomínio do Edifício São João 🏢 '),
            'amount' => 800,
            'due_day' => 10,
            'account_id' => $caixa->id,
            'starts_on' => '2026-07-01',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('contas-fixas.pagar', [$conta, '2026-07']), ['account_id' => $caixa->id, 'amount' => '800,00'])
            ->assertSessionHasNoErrors();

        $pagamento = Transaction::where('fixed_bill_id', $conta->id)->sole();

        $this->assertCabeNoVarchar($pagamento->description, 'transactions.description');
        $this->assertStringStartsWith('Condomínio do Edifício São João 🏢', $pagamento->description);
        // O mês pago é a parte que não pode sumir.
        $this->assertStringEndsWith('… — julho/2026', $pagamento->description);
    }

    public function test_transferir_entre_contas_com_nomes_no_limite(): void
    {
        [$titular, $origem, $destino] = $this->contasDeTransferencia();

        $this->actingAs($titular)->postJson(route('transactions.transfer'), [
            'amount' => '300,00',
            'account_id' => $origem->id,
            'to_account_id' => $destino->id,
            'date' => CarbonImmutable::today()->toDateString(),
            'description' => '',
        ])->assertCreated();

        $saida = Transaction::where('type', 'expense')->sole();
        $entrada = Transaction::where('type', 'income')->sole();

        $this->assertCabeNoVarchar($saida->description, 'transactions.description (saída)');
        $this->assertCabeNoVarchar($entrada->description, 'transactions.description (entrada)');
        $this->assertStringStartsWith('Transferência para Poupança da Conceição 🐷', $saida->description);
        $this->assertStringStartsWith('Transferência de Corrente do João 🏦', $entrada->description);
    }

    /** Apagar a descrição ao editar volta ao padrão de cada ponta — pelo mesmo caminho. */
    public function test_editar_transferencia_apagando_a_descricao_com_nomes_no_limite(): void
    {
        [$titular, $origem, $destino] = $this->contasDeTransferencia();

        $this->actingAs($titular)->postJson(route('transactions.transfer'), [
            'amount' => '300,00',
            'account_id' => $origem->id,
            'to_account_id' => $destino->id,
            'date' => CarbonImmutable::today()->toDateString(),
            'description' => 'Reserva do mês',
        ])->assertCreated();

        $saida = Transaction::where('type', 'expense')->sole();

        $this->actingAs($titular)->put(route('transactions.update', $saida), [
            'type' => 'expense',
            'amount' => '300,00',
            'account_id' => $origem->id,
            'description' => '',
            'date' => CarbonImmutable::today()->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect(route('transactions.index'));

        foreach (Transaction::all() as $ponta) {
            $this->assertCabeNoVarchar($ponta->description, "transactions.description (ponta {$ponta->type})");
            $this->assertStringStartsWith('Transferência ', $ponta->description);
        }
    }

    /** @return array{0: User, 1: Account, 2: Account} */
    private function contasDeTransferencia(): array
    {
        $titular = User::factory()->create(['is_admin' => true, 'account_owner_id' => null]);
        $origem = Account::factory()->for($titular)->create([
            'name' => $this->nomeNoLimite('Corrente do João 🏦 '),
            'type' => 'checking', 'initial_balance' => 1000, 'overdraft_limit' => 0,
        ]);
        $destino = Account::factory()->for($titular)->create([
            'name' => $this->nomeNoLimite('Poupança da Conceição 🐷 '),
            'type' => 'savings', 'initial_balance' => 1000,
        ]);

        return [$titular, $origem, $destino];
    }

    // ── M-2: `admin_audit_logs.alvo_descricao` ───────────────────────────────

    public function test_banir_pessoa_com_nome_no_limite_registra_o_log_com_o_email_inteiro(): void
    {
        $email = 'maria.conceicao.aparecida.dos.santos@familia-conceicao.com.br';
        $alvo = User::factory()->create([
            'name' => $this->nomeNoLimite('Maria da Conceição Aparecida 🌻 '),
            'email' => $email,
            'is_admin' => true,
        ]);

        $this->comoAdmin()
            ->post(route('painel.banir', $alvo->id), ['motivo' => 'Uso indevido reportado'])
            ->assertRedirect();

        $this->assertNotNull($alvo->fresh()->banned_at);

        $log = AdminAuditLog::where('acao', AdminAuditLog::BANIU)->sole();

        $this->assertCabeNoVarchar($log->alvo_descricao, 'admin_audit_logs.alvo_descricao');
        $this->assertStringStartsWith('Maria da Conceição Aparecida 🌻', $log->alvo_descricao);
        // Quem encolhe é o NOME: o e-mail é o que identifica a pessoa sem ambiguidade.
        $this->assertStringEndsWith('… <'.$email.'>', $log->alvo_descricao);
    }

    /**
     * Os dois no limite: nome de 255 e e-mail de 254 (o maior que a RFC 5321 admite).
     * Não há como guardar os dois inteiros em 255 caracteres — mas o log tem de existir.
     */
    public function test_desbanir_pessoa_com_nome_e_email_no_limite_ainda_registra_o_log(): void
    {
        $email = str_repeat('a', 64).'@'.str_repeat('b', 60).'.'.str_repeat('c', 60).'.'.str_repeat('d', 60).'.com.br';
        $this->assertSame(254, strlen($email));

        $alvo = User::factory()->create([
            'name' => $this->nomeNoLimite('João Conceição 🌵 '),
            'email' => $email,
            'is_admin' => true,
            'banned_at' => now(),
            'banned_reason' => 'Motivo qualquer',
        ]);

        $this->comoAdmin()->post(route('painel.desbanir', $alvo->id))->assertRedirect();

        $this->assertNull($alvo->fresh()->banned_at);
        $this->assertCabeNoVarchar(
            AdminAuditLog::where('acao', AdminAuditLog::DESBANIU)->sole()->alvo_descricao,
            'admin_audit_logs.alvo_descricao',
        );
    }

    /**
     * A exclusão é a que mais precisava do log: não tem volta, e a linha do histórico é
     * a única prova de que a pessoa existiu. O `excluir` manda a descrição já montada.
     */
    public function test_excluir_pessoa_com_nome_no_limite_registra_o_log(): void
    {
        $alvo = User::factory()->create([
            'name' => $this->nomeNoLimite('Ana Conceição 🌷 '),
            'email' => 'ana.conceicao@exemplo.com.br',
            'is_admin' => true,
        ]);

        $this->comoAdmin()
            ->delete(route('painel.excluir', $alvo->id), ['confirmacao' => 'ana.conceicao@exemplo.com.br'])
            ->assertRedirect(route('painel.pessoas'));

        $this->assertNull(User::find($alvo->id));

        $log = AdminAuditLog::where('acao', AdminAuditLog::EXCLUIU)->sole();

        $this->assertSame($alvo->id, (int) $log->target_user_id);
        $this->assertCabeNoVarchar($log->alvo_descricao, 'admin_audit_logs.alvo_descricao');
        $this->assertStringStartsWith('Ana Conceição 🌷', $log->alvo_descricao);
        // É o registro de uma exclusão sem volta: o nome pode encolher, o e-mail não.
        $this->assertStringEndsWith('… <ana.conceicao@exemplo.com.br>', $log->alvo_descricao);
    }
}
