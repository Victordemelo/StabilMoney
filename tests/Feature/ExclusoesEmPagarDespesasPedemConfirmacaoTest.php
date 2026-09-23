<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CreditSettlement;
use App\Models\FixedBill;
use App\Models\Transaction;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * As exclusões da tela Pagar despesas voltaram a perguntar antes — 23/09/2026.
 *
 * Desde a CSP com nonce (05/08/2026) o navegador descarta todo `onsubmit="..."` em
 * silêncio (atributo de evento não aceita nonce): os quatro "x" da tela — excluir conta
 * fixa, estornar o pagamento da fatura, remover compra/estorno do cartão e remover
 * despesa em conta — apagavam direto, sem pergunta nenhuma (confirmado num Chromium
 * real). A pergunta agora é o `data-confirmar` de cada formulário (sm/confirmar.js),
 * escapado pelo Blade como qualquer atributo.
 *
 * Este teste renderiza a tela com dados que fazem TODOS esses formulários aparecerem —
 * mais o "Desfazer quitação" da quitação pelo crédito — e confere a pergunta de cada um
 * e que nenhum atributo `on…` sobrou na página. O `NenhumHandlerInlineNasViewsTest`
 * olha o código-fonte; este, o HTML que chega ao navegador.
 *
 * A pergunta diz o que o "x" apaga de fato: no parcelado são todas as parcelas em aberto,
 * e na recorrência a série inteira para de gerar cobrança (`FaturaController::destroy`).
 */
class ExclusoesEmPagarDespesasPedemConfirmacaoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    private Account $cartao;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-20');

        $this->user = User::factory()->create();
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'name' => 'Corrente', 'initial_balance' => 10000,
        ]);
        $this->cartao = Account::factory()->for($this->user)->creditCard()->create(['name' => 'Nubank']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function noCartao(string $tipo, string $data, float $valor, array $extra = []): Transaction
    {
        return Transaction::factory()->for($this->user)->for($this->cartao)->create(array_merge([
            'type' => $tipo, 'amount' => $valor, 'date' => $data, 'description' => 'Linha '.$data,
        ], $extra));
    }

    public function test_cada_exclusao_da_tela_pergunta_antes_e_nenhum_handler_inline_sobrou(): void
    {
        // Nome com aspas e apóstrofo: no `confirm('...')` de antes, o apóstrofo fechava
        // a string JS. No atributo, é só texto escapado.
        $condominio = FixedBill::factory()->for($this->user)->create([
            'name' => 'Condomínio "Bloco B" D\'Ávila', 'due_day' => 25, 'starts_on' => '2026-09-01',
        ]);

        // Compra à vista paga em caixa → "Estornar pagamento".
        $avista = $this->noCartao('expense', '2026-09-12', 100);
        $this->actingAs($this->user)->post(route('faturas.fatura.pagar', $this->cartao), [
            'pay_account_id' => $this->conta->id, 'ciclo' => 'aberto',
        ])->assertSessionHasNoErrors();
        $pagamento = Transaction::where('settles_account_id', $this->cartao->id)->sole();

        // Compra e estorno que se anulam, quitados pelo crédito → "Desfazer quitação".
        $estorno = $this->noCartao('income', '2026-09-14', 50);
        $this->noCartao('expense', '2026-09-15', 50);
        $this->actingAs($this->user)->post(route('faturas.fatura.quitar-pelo-credito', $this->cartao))
            ->assertSessionHasNoErrors();
        $quitacao = CreditSettlement::sole();

        // Parcela e recorrência no ciclo aberto.
        $parcela = $this->noCartao('expense', '2026-09-16', 300, [
            'group_id' => (string) Str::uuid(), 'installment_no' => 1, 'installments' => 3,
        ]);
        $recorrente = $this->noCartao('expense', '2026-09-17', 40, [
            'group_id' => (string) Str::uuid(), 'recurring' => true,
        ]);

        // Despesa em conta, no mês.
        $padaria = Transaction::factory()->for($this->user)->for($this->conta)->expense()->create([
            'amount' => 30, 'date' => '2026-09-18', 'description' => 'Padaria',
        ]);

        $html = $this->actingAs($this->user)->get(route('faturas.index'))->assertOk()->getContent();
        $xp = $this->xpath($html);

        $esperadas = [
            route('contas-fixas.destroy', $condominio) => 'Excluir a conta fixa “Condomínio "Bloco B" D\'Ávila”? As competências em aberto deixam de aparecer aqui; os pagamentos já feitos continuam no histórico.',
            route('faturas.fatura.estornar', $pagamento) => 'Estornar o pagamento de R$ 100,00? As compras voltam para a fatura em aberto e o valor volta para a conta.',
            route('faturas.fatura.desfazer-quitacao', $quitacao) => 'Desfazer a quitação pelo crédito do estorno? As compras e o estorno voltam para a fatura em aberto. Nenhum dinheiro entra nem sai de conta nenhuma.',
            route('faturas.compra.destroy', $avista) => 'Remover esta compra da fatura?',
            route('faturas.compra.destroy', $estorno) => 'Remover este estorno da fatura?',
            route('faturas.compra.destroy', $parcela) => 'Remover esta compra parcelada? As parcelas em aberto saem da fatura; as já pagas continuam no histórico.',
            route('faturas.compra.destroy', $recorrente) => 'Excluir esta recorrência? A cobrança em aberto sai da fatura e nenhuma nova será lançada; as já pagas continuam no histórico.',
            route('faturas.compra.destroy', $padaria) => 'Remover esta despesa?',
        ];

        foreach ($esperadas as $acao => $pergunta) {
            $form = $this->formulario($xp, $acao);
            $this->assertSame($pergunta, $form->getAttribute('data-confirmar'), "Pergunta errada no formulário de {$acao}");
            $this->assertSame('DELETE', $this->metodo($xp, $form), "O formulário de {$acao} não é uma exclusão");
        }

        // Nenhum atributo de evento na página inteira (layout incluído): a CSP o
        // descartaria em silêncio, e o "x" voltaria a apagar sem perguntar.
        $handlers = [];
        foreach ($xp->query('//@*[starts-with(name(), "on")]') as $atributo) {
            $handlers[] = $atributo->parentNode->nodeName.'['.$atributo->nodeName.']';
        }
        $this->assertSame([], $handlers, 'Handler inline no HTML servido de /faturas.');
        $this->assertStringNotContainsString('onsubmit', $html);
    }

    /** O único formulário da página que envia para `$acao`. */
    private function formulario(DOMXPath $xp, string $acao): DOMElement
    {
        $forms = $xp->query('//form[@action="'.$acao.'"]');
        $this->assertSame(1, $forms->length, "Esperava um formulário para {$acao}");

        return $forms->item(0);
    }

    /** O verbo de verdade do formulário (o `_method` do Laravel, senão o do atributo). */
    private function metodo(DOMXPath $xp, DOMElement $form): string
    {
        $falso = $xp->query('.//input[@name="_method"]', $form);

        return strtoupper($falso->length ? $falso->item(0)->getAttribute('value') : $form->getAttribute('method'));
    }

    private function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument;
        $anterior = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        return new DOMXPath($doc);
    }
}
