<?php

namespace Tests\Feature;

use App\Exceptions\RequiresFundingChoice;
use App\Models\Account;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * A pergunta "de onde sai esse dinheiro?" (HTTP 409) é fluxo normal, não erro — e não
 * pode ir para o log como se fosse.
 *
 * O defeito (item 28a da rodada de pré-publicação): `RequiresFundingChoice` não estava
 * marcada, então o handler a registrava como ERROR, com stack trace inteiro, TODA vez que
 * alguém gastava mais que o disponível tendo cheque especial ou investimento para cobrir.
 * Em produção isso é ruído que esconde erro de verdade.
 *
 * A correção não pode mexer na resposta: o modal "Lançar" depende do 409 com o payload,
 * e as telas sem JS dependem do redirect com `fonteNecessaria` na sessão.
 */
class EscolhaDeFonteNaoVaiParaOLogTest extends TestCase
{
    use RefreshDatabase;

    private const PERGUNTA = 'Escolha de onde sai o dinheiro desta despesa.';

    /** @var list<MessageLogged> */
    private array $registros = [];

    private User $user;

    private Account $conta;

    protected function setUp(): void
    {
        parent::setUp();

        // Nada vai para o arquivo de log de verdade; cada registro fica capturado aqui.
        config(['logging.default' => 'null']);
        Log::listen(function (MessageLogged $registro) {
            $this->registros[] = $registro;
        });

        $this->user = User::factory()->create(['is_admin' => true, 'account_owner_id' => null]);

        // R$ 50 disponíveis e R$ 500 de cheque especial: uma despesa de R$ 300 tem fonte
        // que cobre, então o servidor PERGUNTA (409) em vez de gravar ou recusar.
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'name' => 'Conta Corrente',
            'initial_balance' => 50,
            'overdraft_limit' => 500,
        ]);
    }

    private function despesaDe300(): array
    {
        return [
            'type' => 'expense',
            'amount' => '300,00',
            'account_id' => $this->conta->id,
            'date' => CarbonImmutable::today()->toDateString(),
        ];
    }

    /** @return list<MessageLogged> registros de nível erro ou pior, ou que falem da pergunta */
    private function registrosDaPergunta(): array
    {
        return array_values(array_filter(
            $this->registros,
            fn (MessageLogged $r) => in_array($r->level, ['error', 'critical', 'alert', 'emergency'], true)
                || str_contains($r->message, self::PERGUNTA),
        ));
    }

    public function test_o_409_do_modal_lancar_nao_deixa_erro_no_log(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('transactions.store'), $this->despesaDe300())
            ->assertStatus(409)
            ->assertExactJsonStructure(['message', 'precisa_fonte', 'fonte'])
            ->assertJsonPath('message', self::PERGUNTA)
            ->assertJsonPath('precisa_fonte', true)
            ->assertJsonPath('fonte.faltante', fn ($faltante) => (float) $faltante === 250.0);

        $this->assertSame([], $this->registrosDaPergunta(), 'A pergunta da fonte foi para o log como erro.');
        $this->assertDatabaseCount('transactions', 0);
    }

    /** Sem JS a pergunta vira formulário na tela — o redirect e a sessão continuam iguais. */
    public function test_sem_js_a_escolha_volta_na_sessao_e_tambem_nao_vai_para_o_log(): void
    {
        $this->actingAs($this->user)
            ->from(route('transactions.create'))
            ->post(route('transactions.store'), $this->despesaDe300())
            ->assertRedirect(route('transactions.create'))
            ->assertSessionHas('fonteNecessaria', fn (array $pergunta) => $pergunta['acao'] === route('transactions.store')
                && $pergunta['metodo'] === 'POST'
                && $pergunta['campos']['amount'] === '300,00'
                && (float) $pergunta['faltante'] === 250.0);

        $this->assertSame([], $this->registrosDaPergunta(), 'A pergunta da fonte foi para o log como erro.');
        $this->assertDatabaseCount('transactions', 0);
    }

    /** A marca é na exceção, não um filtro geral: erro de verdade continua sendo reportado. */
    public function test_so_a_pergunta_deixa_de_ser_reportada(): void
    {
        $handler = app(ExceptionHandler::class);

        $this->assertFalse($handler->shouldReport(new RequiresFundingChoice([])));
        $this->assertTrue($handler->shouldReport(new RuntimeException('erro de verdade')));
    }
}
