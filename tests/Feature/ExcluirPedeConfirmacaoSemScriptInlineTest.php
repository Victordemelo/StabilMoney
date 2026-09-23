<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Tem certeza?" antes de excluir, sem manipulador de evento inline.
 *
 * Desde a CSP com nonce (05/08/2026) o navegador BLOQUEIA todo `on*="..."` — atributo de
 * evento não aceita nonce —, e os `onsubmit="return confirm(...)"` destas telas pararam de
 * rodar: excluir categoria, conta ou dependente acontecia sem pergunta nenhuma. A pergunta
 * agora vem do atributo `data-confirmar` (lido pelo `sm/confirmar.js`, testado em
 * `tests/js/confirmar.test.js`).
 *
 * O que se confere aqui é o HTML servido: nenhum `on*=` nas telas (seria código morto sob a
 * CSP) e a pergunta de cada exclusão no atributo, como TEXTO — o `confirm('Remover {{ nome }}?')`
 * de antes ainda punha o nome dentro de uma string JS, e um apóstrofo a quebrava.
 */
class ExcluirPedeConfirmacaoSemScriptInlineTest extends TestCase
{
    use RefreshDatabase;

    /** Nome com o que quebrava a string JS de antes: apóstrofo, aspas e &. */
    private const NOME_TRAICOEIRO = "D'Ávila & \"Filhos\"";

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_excluir_categoria_pergunta_pelo_atributo_na_listagem_e_na_edicao(): void
    {
        $categoria = Category::factory()->expense()->for($this->user)->create(['name' => self::NOME_TRAICOEIRO]);
        $esperada = 'Excluir a categoria '.self::NOME_TRAICOEIRO.'? As transações dela ficarão sem categoria.';

        foreach ([route('categories.index'), route('categories.edit', $categoria)] as $url) {
            $xp = $this->pagina($url);

            $this->assertSemManipuladorInline($xp, $url);
            $this->assertSame([$esperada], $this->perguntas($xp, route('categories.destroy', $categoria)), $url);
        }
    }

    public function test_excluir_metodo_de_pagamento_pergunta_pelo_atributo(): void
    {
        $conta = Account::factory()->for($this->user)->create(['type' => 'checking', 'name' => self::NOME_TRAICOEIRO]);

        $xp = $this->pagina(route('accounts.index'));

        $this->assertSemManipuladorInline($xp, 'accounts.index');
        $this->assertSame(
            ['Excluir '.self::NOME_TRAICOEIRO.'? Contas com transações não podem ser excluídas.'],
            $this->perguntas($xp, route('accounts.destroy', $conta)),
        );
    }

    public function test_remover_dependente_pergunta_pelo_atributo(): void
    {
        $dependente = User::factory()->create([
            'name' => self::NOME_TRAICOEIRO, 'account_owner_id' => $this->user->id, 'is_admin' => false,
        ]);

        $xp = $this->pagina(route('dependentes'));

        $this->assertSemManipuladorInline($xp, 'dependentes');
        $this->assertSame(
            ['Remover '.self::NOME_TRAICOEIRO.'? O acesso dele será excluído (os lançamentos da família permanecem).'],
            $this->perguntas($xp, route('dependentes.destroy', $dependente)),
        );
    }

    // ================================================================ asserções

    /**
     * Nenhum atributo `on*` no conteúdo da tela nem nos modais do shell que são desta
     * frente (Lançar e escolha de fonte). O resto do shell tem dono próprio.
     */
    private function assertSemManipuladorInline(DOMXPath $xp, string $onde): void
    {
        $escopos = '//*[@id="content" or @id="launchModal" or @id="fundingModal"]';
        $encontrados = [];

        foreach ($xp->query($escopos.'/descendant-or-self::*/@*[starts-with(name(), "on")]') as $atributo) {
            $encontrados[] = '<'.$atributo->ownerElement->nodeName.' '.$atributo->nodeName.'>';
        }

        $this->assertSame([], $encontrados, "{$onde}: manipulador inline é bloqueado pela CSP (use data-confirmar)");
    }

    /**
     * As perguntas dos formulários que EXCLUEM em `$acao`. Só os com `_method=DELETE`: a
     * mesma URL também recebe o PUT/PATCH de editar, e esse não pergunta nada.
     *
     * @return list<string>
     */
    private function perguntas(DOMXPath $xp, string $acao): array
    {
        $perguntas = [];
        foreach ($xp->query('//form[@action="'.$acao.'"][.//input[@name="_method" and @value="DELETE"]]') as $form) {
            $this->assertTrue($form->hasAttribute('data-confirmar'), "o formulário de {$acao} não pergunta nada");
            $perguntas[] = $form->getAttribute('data-confirmar');
        }

        return $perguntas;
    }

    private function pagina(string $url): DOMXPath
    {
        $html = $this->actingAs($this->user)->get($url)->assertOk()->getContent();

        $doc = new DOMDocument;
        $anterior = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        return new DOMXPath($doc);
    }
}
