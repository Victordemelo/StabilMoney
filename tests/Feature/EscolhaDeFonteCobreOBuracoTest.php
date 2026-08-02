<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Investment;
use App\Models\InvestmentContribution;
use App\Models\Transaction;
use App\Models\User;
use App\Support\FundingSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dois defeitos da escolha de fonte, do mesmo tema: **o modal prometia o que o
 * servidor não entregava.**
 *
 * A) `SpendingGuard::faltante()` clampava o disponível em zero
 *    (`max(0, $disponivel)`), então uma conta NO VERMELHO era tratada como conta
 *    zerada: com −R$ 100 e uma despesa de R$ 300, o app resgatava R$ 300, a
 *    despesa comia os R$ 300 e a conta continuava em −R$ 100 — embaixo do texto
 *    "seu saldo não fica negativo". Hoje o faltante inclui o buraco (R$ 400) e a
 *    conta termina zerada, que é o que "tirar do investimento" quer dizer.
 *
 * B) A opção "Resgatar de um investimento" vinha com `cobre: true` comparando o
 *    faltante com a SOMA de todos os investimentos, mas o pedido carrega um
 *    `funding_investment_id` só. Com dois CDBs de R$ 300 e um faltante de R$ 500
 *    o modal dizia que cobria, o usuário escolhia e o servidor recusava: beco sem
 *    saída. Hoje quem manda é o MAIOR investimento, e quem não cobre sozinho vem
 *    desabilitado com o motivo e o caminho (resgatar mais de um em Investimentos).
 *
 * O que NÃO pode regredir e está coberto aqui: o `funding_amount` do cheque
 * especial continua sendo o INCREMENTO (nunca o buraco somado duas vezes), gasto
 * novo sem fonte continua recusado (422) e obrigação vencida continua passando e
 * negativando a conta.
 */
class EscolhaDeFonteCobreOBuracoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-01');

        $this->user = User::factory()->create(['is_admin' => true, 'account_owner_id' => null]);

        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'name' => 'Corrente',
            'initial_balance' => 2000,
            'overdraft_limit' => 500,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------- helpers

    private function investimentoCom(float $aplicado, string $nome = 'CDB'): Investment
    {
        $inv = Investment::create([
            'user_id' => $this->user->id,
            'name' => $nome,
            'classe' => 'renda_fixa',
        ]);

        $inv->contributions()->create([
            'account_id' => $this->conta->id,
            'made_by_user_id' => $this->user->id,
            'type' => 'aporte',
            'amount' => $aplicado,
            'date' => '2026-08-01',
        ]);

        return $inv;
    }

    private function cartaoComFatura(float $valor): Account
    {
        $card = Account::factory()->for($this->user)->create([
            'type' => 'credit_card',
            'name' => 'Nubank',
            'initial_balance' => null,
            'credit_limit' => 9000,
            'closing_day' => 10,
            'due_day' => 20,
        ]);

        Transaction::create([
            'user_id' => $this->user->id,
            'account_id' => $card->id,
            'type' => 'expense',
            'amount' => $valor,
            'date' => '2026-08-01',
        ]);

        return $card;
    }

    private function despesa(array $extra = []): array
    {
        return array_merge([
            'type' => 'expense',
            'amount' => '300,00',
            'account_id' => $this->conta->id,
            'date' => '2026-08-01',
        ], $extra);
    }

    private function disponivel(): float
    {
        return Account::find($this->conta->id)->available;
    }

    /**
     * Deixa a conta em −R$ 100 pelo caminho REAL do app: fatura de R$ 1.100 com
     * R$ 1.000 disponíveis (R$ 1.000 aplicados) e o usuário escolhendo o cheque
     * especial. É assim que uma conta com investimento fica no vermelho — havendo
     * fonte, o app nunca negativa sozinho.
     */
    private function contaNoVermelhoComInvestimento(float $aplicado = 1000): Investment
    {
        $inv = $this->investimentoCom($aplicado);
        $card = $this->cartaoComFatura(1100);

        $this->actingAs($this->user)->post(route('faturas.fatura.pagar', $card), [
            'pay_account_id' => $this->conta->id,
            'funding_source' => FundingSource::CHEQUE_ESPECIAL,
        ])->assertSessionHasNoErrors();

        $this->assertSame(-100.0, $this->disponivel(), 'cenário: a conta precisa começar em −100');

        return $inv;
    }

    // ============================================================= BUG A

    /**
     * A) Conta em −R$ 100, despesa de R$ 300, resgate escolhido.
     *
     * Antes: resgatava 300 → disponível continuava −100 (a promessa do modal era
     * falsa). Agora: resgata 400 → disponível 0, e o investido cai exatamente 400.
     */
    public function test_resgate_cobre_o_buraco_e_a_conta_sai_do_vermelho(): void
    {
        $inv = $this->contaNoVermelhoComInvestimento(1000);

        // 1) Sem escolher a fonte: 409 — e o faltante oferecido já é o do buraco.
        $r = $this->actingAs($this->user)
            ->postJson(route('transactions.store'), $this->despesa())
            ->assertStatus(409);

        fwrite(STDERR, "\n[A] disponivel=-100 | despesa=300 | faltante oferecido: " . $r->json('fonte.faltante') . "\n");
        $this->assertEqualsWithDelta(400.0, $r->json('fonte.faltante'), 0.001,
            'o resgate precisa trazer a despesa (300) MAIS o buraco (100)');

        $resgate = collect($r->json('fonte.fontes'))->firstWhere('id', FundingSource::RESGATE_INVESTIMENTO);
        $this->assertTrue($resgate['cobre'], 'o CDB de 1.000 cobre os 400');
        $this->assertStringContainsString('não fica negativa', $resgate['detalhe']);
        $this->assertStringContainsString('já está devendo', $resgate['detalhe'],
            'o texto precisa explicar por que o resgate é maior que a despesa');

        // 2) Escolhendo o resgate: a conta sai do vermelho.
        $this->actingAs($this->user)->post(route('transactions.store'), $this->despesa([
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
            'funding_investment_id' => $inv->id,
        ]))->assertSessionHasNoErrors();

        $conta = Account::find($this->conta->id);
        fwrite(STDERR, "[A] apos o resgate -> saldo={$conta->balance} reservado={$conta->reserved} "
            . "disponivel={$conta->available} | investido={$inv->fresh()->aplicado}\n");

        // saldo 900 − 300 = 600 | reservado 1000 − 400 = 600 | disponível 0
        $this->assertSame(600.0, $conta->balance);
        $this->assertSame(600.0, $conta->reserved);
        $this->assertSame(0.0, $conta->available, 'ERA AQUI O BUG: antes ficava em −100');
        $this->assertSame(600.0, $inv->fresh()->aplicado, 'o investido caiu os 400 resgatados');

        // 3) Auditoria: a despesa registra a fonte e o quanto veio dela.
        $despesa = Transaction::where('account_id', $this->conta->id)
            ->where('amount', 300)->firstOrFail();
        $this->assertSame(FundingSource::RESGATE_INVESTIMENTO, $despesa->funding_source);
        $this->assertEqualsWithDelta(400.0, (float) $despesa->funding_amount, 0.001);

        // 4) E o resgate nasce ligado à despesa (é o que o estorno usa para desfazer).
        $mov = InvestmentContribution::where('type', 'resgate')->firstOrFail();
        $this->assertSame($despesa->id, $mov->transaction_id);
        $this->assertEqualsWithDelta(400.0, (float) $mov->amount, 0.001);
    }

    /** A) O mesmo com conta positiva: nada muda (o clamp só existia para o vermelho). */
    public function test_com_conta_positiva_o_resgate_continua_sendo_so_o_que_falta(): void
    {
        $inv = $this->investimentoCom(1000); // disponível = 2000 − 1000 = 1000

        $r = $this->actingAs($this->user)
            ->postJson(route('transactions.store'), $this->despesa(['amount' => '1.500,00']))
            ->assertStatus(409);

        $this->assertEqualsWithDelta(500.0, $r->json('fonte.faltante'), 0.001);

        $this->actingAs($this->user)->post(route('transactions.store'), $this->despesa([
            'amount' => '1.500,00',
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
            'funding_investment_id' => $inv->id,
        ]))->assertSessionHasNoErrors();

        fwrite(STDERR, "[A+] conta positiva -> disponivel=" . $this->disponivel()
            . " | investido=" . $inv->fresh()->aplicado . "\n");
        $this->assertSame(0.0, $this->disponivel());
        $this->assertSame(500.0, $inv->fresh()->aplicado, 'resgatou só o faltante, não o total');
    }

    // ============================================================= BUG B

    /**
     * B) Dois investimentos de R$ 300 e um faltante de R$ 500.
     *
     * A opção não pode mais dizer que cobre (o pedido leva UM investimento), e o
     * usuário não pode ficar preso: aqui ele tem o cheque especial no mesmo modal.
     */
    public function test_resgate_que_so_cobre_somado_nao_promete_o_que_nao_entrega(): void
    {
        $a = $this->investimentoCom(300, 'CDB A');
        $b = $this->investimentoCom(300, 'CDB B'); // disponível = 2000 − 600 = 1400

        $r = $this->actingAs($this->user)
            ->postJson(route('transactions.store'), $this->despesa(['amount' => '1.900,00']))
            ->assertStatus(409);

        $fontes = collect($r->json('fonte.fontes'));
        $resgate = $fontes->firstWhere('id', FundingSource::RESGATE_INVESTIMENTO);
        $cheque = $fontes->firstWhere('id', FundingSource::CHEQUE_ESPECIAL);

        fwrite(STDERR, "[B] faltante=" . $r->json('fonte.faltante')
            . " | teto do resgate={$resgate['teto']} (soma seria 600) | cobre="
            . var_export($resgate['cobre'], true) . "\n");

        $this->assertEqualsWithDelta(500.0, $r->json('fonte.faltante'), 0.001);
        $this->assertFalse($resgate['cobre'], 'ERA AQUI O BUG: a soma (600) dizia que cobria');
        $this->assertEqualsWithDelta(300.0, $resgate['teto'], 0.001, 'o teto é o MAIOR, não a soma');
        $this->assertEqualsWithDelta(600.0, $resgate['total'], 0.001);
        $this->assertStringContainsString('um investimento por vez', $resgate['motivo']);
        $this->assertStringContainsString('Investimentos', $resgate['motivo'], 'o motivo aponta a saída');

        // Os itens também vêm marcados um a um (é assim que o select desabilita).
        $this->assertSame([false, false], array_column($resgate['itens'], 'cobre'));

        // O usuário NÃO fica preso: o cheque especial cobre e está habilitado.
        $this->assertTrue($cheque['cobre']);

        // Defesa em profundidade: mesmo forçando o resgate, o servidor recusa
        // com uma mensagem que aponta a saída (e não grava nada).
        $this->actingAs($this->user)->post(route('transactions.store'), $this->despesa([
            'amount' => '1.900,00',
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
            'funding_investment_id' => $a->id,
        ]))->assertSessionHasErrors('funding_investment_id');

        $erro = session('errors')->first('funding_investment_id');
        fwrite(STDERR, "[B] forcando o resgate de um so -> {$erro}\n");
        $this->assertStringContainsString('um investimento por vez', $erro);
        $this->assertSame(1400.0, $this->disponivel(), 'nada foi gasto');
        $this->assertSame(300.0, $b->fresh()->aplicado, 'nada foi resgatado');

        // E pelo cheque especial passa.
        $this->actingAs($this->user)->post(route('transactions.store'), $this->despesa([
            'amount' => '1.900,00',
            'funding_source' => FundingSource::CHEQUE_ESPECIAL,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(-500.0, $this->disponivel());
        $this->assertSame(300.0, $a->fresh()->aplicado);
    }

    /**
     * B) Sem cheque especial, a saída é resgatar os dois em Investimentos e lançar
     * depois — o app precisa DIZER isso e o caminho precisa funcionar de verdade.
     */
    public function test_sem_cheque_especial_a_saida_e_resgatar_na_tela_de_investimentos(): void
    {
        $this->conta->update(['overdraft_limit' => 0]);
        $this->conta->refresh();

        $a = $this->investimentoCom(300, 'CDB A');
        $b = $this->investimentoCom(300, 'CDB B'); // disponível = 1400

        // Nenhuma fonte cobre sozinha: 422 (gasto novo é recusado), com a saída no texto.
        $this->actingAs($this->user)
            ->post(route('transactions.store'), $this->despesa(['amount' => '1.900,00']))
            ->assertSessionHasErrors('amount');

        $erro = session('errors')->first('amount');
        fwrite(STDERR, "[B-] sem cheque especial -> {$erro}\n");
        $this->assertStringContainsString('R$ 300,00', $erro, 'fala do MAIOR investimento');
        $this->assertStringContainsString('R$ 600,00', $erro, 'e do total somado');
        $this->assertStringContainsString('Investimentos', $erro);
        $this->assertDatabaseCount('transactions', 0);

        // O caminho indicado funciona: resgata os dois e a despesa passa direto.
        foreach ([$a, $b] as $inv) {
            $this->actingAs($this->user)->post(route('investimentos.resgates.store', $inv), [
                'amount' => '300,00',
                'account_id' => $this->conta->id,
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(2000.0, $this->disponivel(), 'os 600 voltaram para a conta');

        $this->actingAs($this->user)
            ->post(route('transactions.store'), $this->despesa(['amount' => '1.900,00']))
            ->assertSessionHasNoErrors();

        fwrite(STDERR, "[B-] apos resgatar os dois e lancar -> disponivel=" . $this->disponivel() . "\n");
        $this->assertSame(100.0, $this->disponivel());
    }

    /** B) Um investimento que cobre sozinho continua funcionando igual. */
    public function test_um_investimento_que_cobre_sozinho_continua_valendo(): void
    {
        $grande = $this->investimentoCom(900, 'CDB grande');
        $this->investimentoCom(100, 'CDB pequeno'); // disponível = 2000 − 1000 = 1000

        $r = $this->actingAs($this->user)
            ->postJson(route('transactions.store'), $this->despesa(['amount' => '1.500,00']))
            ->assertStatus(409);

        $resgate = collect($r->json('fonte.fontes'))->firstWhere('id', FundingSource::RESGATE_INVESTIMENTO);

        $this->assertTrue($resgate['cobre'], 'o de 900 cobre os 500 sozinho');
        $this->assertEqualsWithDelta(900.0, $resgate['teto'], 0.001);
        $this->assertNull($resgate['motivo']);
        // O select mostra os dois, mas só o que cobre fica selecionável.
        $this->assertSame([true, false], array_column($resgate['itens'], 'cobre'));

        $this->actingAs($this->user)->post(route('transactions.store'), $this->despesa([
            'amount' => '1.500,00',
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
            'funding_investment_id' => $grande->id,
        ]))->assertSessionHasNoErrors();

        fwrite(STDERR, "[B+] um so cobre -> disponivel=" . $this->disponivel()
            . " | investido=" . $grande->fresh()->aplicado . "\n");
        $this->assertSame(0.0, $this->disponivel());
        $this->assertSame(400.0, $grande->fresh()->aplicado);
    }

    /**
     * B) O mesmo na tela SEM JS: o bloco server-rendered precisa mostrar o motivo
     * e desabilitar o investimento que não cobre sozinho — senão o usuário confirma
     * e leva o erro na cara, que é o beco sem saída de volta.
     */
    public function test_fallback_sem_js_desabilita_o_investimento_que_nao_cobre(): void
    {
        $this->investimentoCom(300, 'CDB A');
        $this->investimentoCom(300, 'CDB B'); // disponível 1400, faltante 500, cheque 500

        $this->actingAs($this->user)
            ->from(route('transactions.index'))
            ->post(route('transactions.store'), $this->despesa(['amount' => '1.900,00']))
            ->assertRedirect()
            ->assertSessionHas('fonteNecessaria');

        $html = $this->actingAs($this->user)->get(route('transactions.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-funding-fallback', $html);
        $this->assertStringContainsString('um investimento por vez', $html, 'o motivo aparece na tela');
        $this->assertStringContainsString('não cobre sozinho', $html, 'o item vem marcado');
        $this->assertMatchesRegularExpression('/<option[^>]*disabled/', $html);
    }

    /**
     * Robustez do mesmo bloco: payload sem investimento nenhum e sem fonte que
     * cubra (corrida entre o check e o render) não pode estourar o Blade.
     */
    public function test_fallback_sem_js_aguenta_payload_sem_investimento(): void
    {
        $this->actingAs($this->user)
            ->withSession(['fonteNecessaria' => [
                'conta' => ['id' => $this->conta->id, 'nome' => 'Corrente'],
                'valor' => 900.0,
                'disponivel' => 100.0,
                'faltante' => 800.0,
                'fontes' => [[
                    'id' => FundingSource::CHEQUE_ESPECIAL,
                    'rotulo' => 'Usar o cheque especial',
                    'teto' => 500.0,
                    'cobre' => false,
                    'detalhe' => '',
                    'motivo' => null,
                ]],
                'acao' => route('transactions.store'),
                'metodo' => 'POST',
                'campos' => [],
            ]])
            ->get(route('transactions.index'))
            ->assertOk()
            ->assertSee('Nenhuma fonte cobre este pagamento sozinha.');
    }

    // ================================================== CHEQUE ESPECIAL

    /**
     * O `funding_amount` do cheque especial é o INCREMENTO — o vermelho que já
     * existe não entra de novo (ele já saiu do `overdraftAvailable`).
     * Contá-lo duas vezes recusaria despesa que cabe no limite.
     */
    public function test_cheque_especial_grava_o_incremento_e_nao_o_buraco_somado(): void
    {
        $this->contaNoVermelhoComInvestimento(1000); // disponível −100, cheque usado 100 de 500

        // A quitação da fatura já provou o caso simples: 1.100 com 1.000 disponíveis.
        $quitacao = Transaction::where('account_id', $this->conta->id)
            ->where('funding_source', FundingSource::CHEQUE_ESPECIAL)->firstOrFail();
        $this->assertEqualsWithDelta(100.0, (float) $quitacao->funding_amount, 0.001,
            'usou 100 de cheque especial: o disponível cobria os outros 1.000');

        // Agora com a conta JÁ negativa: cabe (350 ≤ 400 que restam do cheque).
        // Se o cálculo somasse o buraco (450 > 400), isto seria recusado.
        $this->actingAs($this->user)->post(route('transactions.store'), $this->despesa([
            'amount' => '350,00',
            'funding_source' => FundingSource::CHEQUE_ESPECIAL,
        ]))->assertSessionHasNoErrors();

        $despesa = Transaction::where('account_id', $this->conta->id)->where('amount', 350)->firstOrFail();
        $conta = Account::find($this->conta->id);

        fwrite(STDERR, "[CE] disponivel={$conta->available} | cheque usado={$conta->overdraftUsed} de "
            . "{$conta->overdraftLimitValue} | funding_amount={$despesa->funding_amount}\n");

        $this->assertEqualsWithDelta(350.0, (float) $despesa->funding_amount, 0.001,
            'o incremento é 350 — o buraco de 100 não conta duas vezes');
        $this->assertSame(-450.0, $conta->available);
        $this->assertSame(450.0, $conta->overdraftUsed);

        // E o limite continua sendo respeitado: 100 é o que resta, 150 estoura.
        $this->actingAs($this->user)->post(route('transactions.store'), $this->despesa([
            'amount' => '150,00',
            'funding_source' => FundingSource::CHEQUE_ESPECIAL,
        ]))->assertSessionHasErrors('amount');

        $this->assertSame(-450.0, $this->disponivel(), 'nem um centavo além do limite');
    }

    // ============================================ regras que não regridem

    /** Gasto novo sem fonte nenhuma continua RECUSADO. */
    public function test_gasto_novo_sem_fonte_continua_recusado(): void
    {
        $this->conta->update(['overdraft_limit' => 0]);

        $this->actingAs($this->user)
            ->post(route('transactions.store'), $this->despesa(['amount' => '5.000,00']))
            ->assertSessionHasErrors('amount');

        $this->assertSame(2000.0, $this->disponivel());
        $this->assertDatabaseCount('transactions', 0);
    }

    /** Obrigação vencida continua PASSANDO e negativando a conta. */
    public function test_obrigacao_continua_passando_e_negativando(): void
    {
        $this->conta->update(['overdraft_limit' => 0]);
        $card = $this->cartaoComFatura(3000);

        $this->actingAs($this->user)
            ->post(route('faturas.fatura.pagar', $card), ['pay_account_id' => $this->conta->id])
            ->assertSessionHasNoErrors();

        fwrite(STDERR, "[obrigacao] fatura de 3000 com 2000 em conta -> disponivel=" . $this->disponivel() . "\n");
        $this->assertSame(-1000.0, $this->disponivel(), 'não se recusa um boleto');
        $this->assertNotNull(Transaction::where('account_id', $card->id)->first()->paid_at);
    }

    /** Os dois números, lado a lado, sem passar por HTTP. */
    public function test_faltante_e_cheque_necessario_medem_coisas_diferentes(): void
    {
        $this->contaNoVermelhoComInvestimento(1000);

        $guard = app(\App\Services\SpendingGuard::class);
        $conta = Account::find($this->conta->id);

        $faltante = $guard->faltante($conta, 300);
        $doCheque = $guard->chequeNecessario($conta, 300);

        fwrite(STDERR, "[guard] disponivel={$conta->available} | faltante(resgate)={$faltante} "
            . "| chequeNecessario={$doCheque}\n");

        $this->assertSame(400.0, $faltante, 'o resgate tapa o buraco também');
        $this->assertSame(300.0, $doCheque, 'o cheque especial só conta o incremento');

        // Numa edição, o `$ignore` devolve o valor antigo antes de medir.
        $this->assertSame(100.0, $guard->faltante($conta, 300, 300.0));
        $this->assertSame(100.0, $guard->chequeNecessario($conta, 300, 300.0));
    }
}
