<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * R2-6 da auditoria de 02/09/2026 (rodada 2): baixar o limite do cheque especial na
 * mesma edição em que o saldo inicial muda era julgado pelo uso de ANTES da edição.
 *
 * `UpdateAccountRequest::regraDoChequeEspecialEmUso` comparava o limite novo com o
 * `overdraftUsed` de agora, enquanto a regra irmã (`regraDoPisoDoSaldoInicial`) já
 * projetava o disponível com os valores novos. Quem corrigia o saldo de abertura para
 * cima — tinha lançado errado — e, na mesma edição, baixava o limite porque o banco
 * reduziu o cheque especial era recusado com "está usando R$ 300,00 agora", embora o
 * resultado coubesse com folga no limite novo.
 *
 * A projeção faz a recusa valer só quando o resultado FURA o piso. (Baixar os dois
 * campos juntos nunca era recusado indevidamente por esta regra: baixar o saldo inicial
 * só aumenta o uso. O falso "não" vinha de SUBIR o saldo inicial.)
 */
class LimiteDoChequeEspecialOlhaOSaldoInicialNovoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // 1.000 de saldo inicial, limite 500, despesa de 1.300: disponível −300.
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'name' => 'Corrente',
            'bank' => 'itau',
            'initial_balance' => 1000,
            'overdraft_limit' => 500,
        ]);
        Transaction::factory()->for($this->user)->for($this->conta)->expense()->create(['amount' => 1300]);

        $this->assertSame(-300.0, $this->conta->fresh()->available);
        $this->assertSame(300.0, $this->conta->fresh()->overdraftUsed);
    }

    private function editar(string $inicial, string $limite, bool $json = false): TestResponse
    {
        $dados = [
            'name' => 'Corrente',
            'type' => 'checking',
            'bank' => 'itau',
            'initial_balance' => $inicial,
            'overdraft_limit' => $limite,
        ];

        $cliente = $this->actingAs($this->user)->from(route('accounts.index'));

        return $json
            ? $cliente->patchJson(route('accounts.update', $this->conta), $dados)
            : $cliente->patch(route('accounts.update', $this->conta), $dados);
    }

    /** O caso do relatório: saldo inicial corrigido para 1.200 e limite para 200 — cabe (−100 ≥ −200). */
    public function test_subir_o_saldo_inicial_e_baixar_o_limite_juntos_e_aceito_quando_o_resultado_cabe(): void
    {
        $this->editar('1.200,00', '200,00')->assertSessionHasNoErrors();

        $conta = $this->conta->fresh();
        $this->assertSame(1200.0, round((float) $conta->initial_balance, 2));
        $this->assertSame(200.0, round((float) $conta->overdraft_limit, 2));
        $this->assertSame(-100.0, $conta->available);
        // O invariante que as duas regras protegem continua de pé.
        $this->assertGreaterThanOrEqual(-200.0, $conta->available);
    }

    /** No limite exato também: 1.200 − 1.300 = −100, limite 100. */
    public function test_limite_igual_ao_uso_projetado_e_aceito(): void
    {
        $this->editar('1.200,00', '100,00')->assertSessionHasNoErrors();

        $this->assertSame(-100.0, $this->conta->fresh()->available);
    }

    /** Abaixo do uso PROJETADO continua recusado — e a mensagem fala do uso que valeria depois. */
    public function test_limite_abaixo_do_uso_projetado_continua_recusado(): void
    {
        $this->editar('1.200,00', '50,00')->assertSessionHasErrors('overdraft_limit');

        $erro = session('errors')->first('overdraft_limit');
        $this->assertStringContainsString('Com o saldo inicial novo, esta conta ficaria usando R$ 100,00', $erro);
        $this->assertStringContainsString('não pode cair para R$ 50,00', $erro);

        $conta = $this->conta->fresh();
        $this->assertSame(1000.0, round((float) $conta->initial_balance, 2), 'Nada podia ter sido gravado.');
        $this->assertSame(500.0, round((float) $conta->overdraft_limit, 2));
    }

    /** Sem mexer no saldo inicial, a regra e a mensagem de sempre: o uso de agora. */
    public function test_sem_mudar_o_saldo_inicial_vale_o_uso_de_agora(): void
    {
        $this->editar('1.000,00', '200,00')->assertSessionHasErrors('overdraft_limit');

        $this->assertStringContainsString(
            'Esta conta está usando R$ 300,00 do cheque especial agora',
            session('errors')->first('overdraft_limit'),
        );

        $this->editar('1.000,00', '300,00')->assertSessionHasNoErrors();
        $this->assertSame(300.0, round((float) $this->conta->fresh()->overdraft_limit, 2));
    }

    /** Baixar os dois juntos quando cabe continuava (e continua) aceito. */
    public function test_baixar_o_saldo_inicial_e_o_limite_juntos_quando_cabe_e_aceito(): void
    {
        $this->editar('900,00', '400,00')->assertSessionHasNoErrors();

        $this->assertSame(-400.0, $this->conta->fresh()->available);
    }

    /**
     * Baixar os dois além do piso é recusado pelas DUAS regras, cada uma apontando a
     * saída pelo próprio campo. Antes só a do saldo inicial reclamava — a do limite
     * olhava o uso de agora (300 ≤ 350) e deixava passar.
     */
    public function test_baixar_os_dois_alem_do_piso_e_recusado_nos_dois_campos(): void
    {
        $this->editar('900,00', '350,00')->assertSessionHasErrors(['initial_balance', 'overdraft_limit']);

        $this->assertStringContainsString('ficaria usando R$ 400,00', session('errors')->first('overdraft_limit'));
        $this->assertSame(-300.0, $this->conta->fresh()->available);
    }

    /** Pelo modal (JSON): o mesmo aceite. */
    public function test_pelo_modal_a_correcao_conjunta_tambem_passa(): void
    {
        $this->editar('1.200,00', '200,00', json: true)->assertOk();

        $this->assertSame(-100.0, $this->conta->fresh()->available);
    }
}
