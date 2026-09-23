<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FixedBill;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O que a navegação por pjax (`sm/nav.js`) espera encontrar no shell servido.
 *
 * O pjax troca só o #content; o shell fica. Duas coisas dele dependem de marcação que só o
 * Blade pode dar — e, se ela sumir, nenhuma tela quebra: o defeito volta em silêncio. Os
 * testes de JS provam o comportamento com um shell escrito à mão; este prova que o shell de
 * VERDADE tem as peças.
 *
 *  - O sino (P-4 da auditoria de PWA de 06/09/2026): mostra dado do servidor e não mudava
 *    ao navegar. Os dois sinos e a lista levam `data-pjax-atualizar` + id, e o nav.js traz
 *    da página nova os filhos deles e os atributos listados.
 *  - O anúncio para leitor de tela (auditoria de acessibilidade de 07/09): a troca de página
 *    era silenciosa. A região viva `#sm-anuncio` precisa existir desde o carregamento e
 *    FORA do #content (que o pjax substitui) e do #app (que pode ficar inerte com modal).
 */
class ShellAcompanhaOPjaxTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-13 10:00'));
        $this->user = User::factory()->create();
    }

    private function pagina(): HTMLDocument
    {
        $html = $this->actingAs($this->user)->get('/')->assertOk()->getContent();

        return HTMLDocument::createFromString($html, LIBXML_NOERROR);
    }

    private function conta(string $nome, int $dia): void
    {
        FixedBill::create([
            'user_id' => $this->user->id,
            'name' => $nome,
            'amount' => 300,
            'due_day' => $dia,
            'account_id' => Account::factory()->for($this->user)->create(['type' => 'checking', 'initial_balance' => 5000])->id,
            'starts_on' => '2026-07-01',
            'active' => true,
        ]);
    }

    private function elemento(HTMLDocument $doc, string $id): Element
    {
        $el = $doc->getElementById($id);
        $this->assertNotNull($el, "O shell não tem #{$id}.");

        return $el;
    }

    public function test_os_dois_sinos_e_a_lista_estao_marcados_para_o_pjax(): void
    {
        $doc = $this->pagina();

        // Os botões: filhos (o selo) + a classe e o nome acessível.
        foreach (['mNotif', 'notifBtn'] as $id) {
            $this->assertSame('class aria-label', $this->elemento($doc, $id)->getAttribute('data-pjax-atualizar'));
        }

        // A lista: só os filhos. A classe `open` e o `aria-hidden` são do shell.js e não
        // podem ser pisados pela página nova.
        $lista = $this->elemento($doc, 'notifPop');
        $this->assertTrue($lista->hasAttribute('data-pjax-atualizar'));
        $this->assertSame('', $lista->getAttribute('data-pjax-atualizar'));

        // `aria-expanded` é estado do JS: fora da lista, sempre.
        $this->assertStringNotContainsString('aria-expanded', $this->elemento($doc, 'notifBtn')->getAttribute('data-pjax-atualizar'));
    }

    public function test_o_sino_diz_a_contagem_para_o_leitor_de_tela(): void
    {
        // O selo com o número é só visual: o aria-label substitui o conteúdo do botão como
        // nome acessível, e quem usa leitor de tela nunca ouvia quantas contas havia.
        $this->conta('Condomínio', 10); // venceu dia 10
        $this->conta('Luz', 15);        // vence em 2 dias

        $doc = $this->pagina();

        $this->assertSame('Notificações: 2 contas a pagar, 1 vencida', $this->elemento($doc, 'notifBtn')->getAttribute('aria-label'));
        $this->assertSame('Vencimentos: 2 contas a pagar, 1 vencida', $this->elemento($doc, 'mNotif')->getAttribute('aria-label'));
        $this->assertSame('2', trim($this->elemento($doc, 'notifBtn')->querySelector('.notif-badge')->textContent));
    }

    public function test_sem_vencimento_o_sino_fica_sem_selo_e_sem_contagem(): void
    {
        $doc = $this->pagina();

        $this->assertSame('Notificações', $this->elemento($doc, 'notifBtn')->getAttribute('aria-label'));
        $this->assertNull($this->elemento($doc, 'notifBtn')->querySelector('.notif-badge'));
        $this->assertNotNull($this->elemento($doc, 'notifPop')->querySelector('.notif-empty'));
    }

    public function test_o_nome_da_conta_chega_na_lista_como_texto(): void
    {
        // O nav.js copia a lista por nós clonados, sem reinterpretar HTML — e conta com o
        // servidor mandando o nome escapado.
        $this->conta('<img src=x onerror=alert(1)>', 10);

        $lista = $this->elemento($this->pagina(), 'notifPop');

        $this->assertNull($lista->querySelector('img'));
        $this->assertStringContainsString('<img src=x onerror=alert(1)>', $lista->textContent);
    }

    /**
     * O card de Patrimônio (achado da rodada de 23/09/2026) é do shell e ficava com o saldo de
     * quando a aba abriu — inclusive logo depois de um lançamento pelo modal "Lançar", que
     * salva por AJAX e recarrega só o #content. Marcado como a lista do sino: só os FILHOS,
     * que é onde moram os valores. E a página servida traz sempre o saldo de agora.
     */
    public function test_o_card_de_patrimonio_acompanha_o_pjax(): void
    {
        $conta = Account::factory()->for($this->user)->create(['type' => 'checking', 'initial_balance' => 1000]);

        $card = $this->elemento($this->pagina(), 'sidePatrimonio');
        $this->assertTrue($card->hasAttribute('data-pjax-atualizar'));
        $this->assertSame('', $card->getAttribute('data-pjax-atualizar'));
        $this->assertStringContainsString('R$ 1.000,00', $card->textContent);

        Transaction::factory()->for($this->user)->create([
            'account_id' => $conta->id,
            'type' => 'expense',
            'amount' => 250,
            'date' => '2026-07-13',
        ]);

        $this->assertStringContainsString('R$ 750,00', $this->elemento($this->pagina(), 'sidePatrimonio')->textContent);
    }

    public function test_a_data_da_topbar_tambem_acompanha(): void
    {
        // Aba aberta de um dia para o outro mostrava a data de ontem.
        $data = $this->elemento($this->pagina(), 'topbarData');

        $this->assertTrue($data->hasAttribute('data-pjax-atualizar'));
        $this->assertStringContainsString('13 de julho', $data->textContent);
    }

    public function test_a_regiao_de_anuncio_existe_desde_o_carregamento_fora_do_conteudo_e_do_app(): void
    {
        $doc = $this->pagina();
        $regiao = $this->elemento($doc, 'sm-anuncio');

        $this->assertSame('polite', $regiao->getAttribute('aria-live'));
        $this->assertSame('', trim($regiao->textContent), 'A região nasce vazia: o nav.js escreve nela a cada troca.');
        // Dentro do #content, o próprio pjax a apagaria; dentro do #app, um modal aberto
        // (app inerte) a calaria.
        $this->assertNull($regiao->closest('#content'));
        $this->assertNull($regiao->closest('#app'));
    }

    public function test_a_pagina_declara_a_versao_do_build_para_o_pjax(): void
    {
        $meta = $this->pagina()->querySelector('meta[name="sm-versao"]');

        $this->assertNotNull($meta);
        $this->assertNotSame('', $meta->getAttribute('content'));
    }
}
