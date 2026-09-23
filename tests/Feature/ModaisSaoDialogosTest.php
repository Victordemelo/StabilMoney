<?php

namespace Tests\Feature;

use App\Http\Controllers\CategoryController;
use App\Models\Account;
use App\Models\Category;
use App\Models\Goal;
use App\Models\Investment;
use App\Models\User;
use App\Support\FundingSource;
use App\Support\NomeDaCor;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Todo modal destas telas é um DIÁLOGO para o leitor de tela — achado A-2 da auditoria de
 * acessibilidade de 07/09/2026 —, os grupos de radio têm nome, as cores têm nome falado (o
 * achado crítico do axe: as dez bolinhas de "Nova meta" eram "botão de opção" e nada mais)
 * e a ordem das categorias muda sem arrastar (A-4).
 *
 * O COMPORTAMENTO (foco que entra, Tab preso, Esc, página inerte, foco que volta) é do
 * `sm/dialogo.js` e está em `tests/js/dialogo.test.js`. Aqui é a MARCAÇÃO que o Blade
 * entrega, porque é ela que o leitor de tela lê: o papel `dialog` no PAINEL (não no véu,
 * que ocuparia a tela inteira), `aria-modal`, e o nome vindo de um título que existe UMA
 * vez na página — com um modal por conta/meta/ativo/dependente, dois `aria-labelledby`
 * apontando para o mesmo id dariam a todos o nome do primeiro.
 *
 * Fora daqui, de propósito: os modais de `/faturas` (a view está com outra frente de
 * trabalho e ainda não passou por isto).
 */
class ModaisSaoDialogosTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => 'Titular Teste']);
    }

    // ================================================================ as telas

    public function test_o_lancar_e_o_de_fonte_do_shell_sao_dialogos(): void
    {
        Account::factory()->for($this->user)->create(['type' => 'checking', 'name' => 'Corrente']);
        Account::factory()->for($this->user)->create(['type' => 'savings', 'name' => 'Poupança']);

        $xp = $this->pagina(route('dashboard'));

        $this->assertModaisSaoDialogos($xp, ['launchModal', 'fundingModal']);
        // O "Tipo" do lançamento (Receita/Despesa/Transferência) é um grupo com nome.
        $this->assertRadiosAgrupadosComNome($xp, 'launchModal');
        // As opções do modal de fonte são montadas pelo JS DENTRO deste grupo.
        $grupo = $this->um($xp, '//*[@id="fundingModal"]//*[@data-funding-opcoes]');
        $this->assertSame('radiogroup', $grupo->getAttribute('role'));
        $this->assertSame('fundingModal-titulo', $grupo->getAttribute('aria-labelledby'));
    }

    public function test_o_modal_de_categoria_e_dialogo_e_os_tres_grupos_tem_nome(): void
    {
        $xp = $this->pagina(route('categories.index'));

        $this->assertModaisSaoDialogos($xp, ['catModal']);
        $this->assertRadiosAgrupadosComNome($xp, 'catModal');

        $nomes = $this->nomesDosGrupos($xp, 'catModal');
        $this->assertSame(['Tipo', 'Ícone', 'Cor'], $nomes);
    }

    public function test_os_modais_de_metas_sao_dialogos_com_ids_unicos(): void
    {
        $viagem = Goal::factory()->for($this->user)->create(['name' => 'Viagem', 'color' => '#1FA06E']);
        $carro = Goal::factory()->for($this->user)->create(['name' => 'Carro', 'color' => '#3E84D8']);

        $xp = $this->pagina(route('metas.index'));

        $this->assertModaisSaoDialogos($xp, [
            'metaCreateModal', 'metaAporteModal', 'metaResgateModal',
            'metaEditModal-'.$viagem->id, 'metaEditModal-'.$carro->id,
            'metaDeleteModal-'.$viagem->id, 'metaDeleteModal-'.$carro->id,
        ]);
        foreach (['metaCreateModal', 'metaEditModal-'.$viagem->id, 'metaEditModal-'.$carro->id] as $id) {
            $this->assertRadiosAgrupadosComNome($xp, $id);
            $this->assertSame(['Ícone', 'Cor'], $this->nomesDosGrupos($xp, $id));
        }

        // Um modal de edição POR META: o nome do diálogo diz qual.
        $this->assertStringContainsString('Carro', $this->nomeDoDialogo($xp, 'metaEditModal-'.$carro->id));
    }

    public function test_os_modais_de_investimentos_sao_dialogos(): void
    {
        $cdb = Investment::factory()->for($this->user)->create(['name' => 'CDB Liquidez']);

        $xp = $this->pagina(route('investimentos.index'));

        $this->assertModaisSaoDialogos($xp, [
            'invCreateModal', 'invAporteModal', 'invResgateModal',
            'invEditModal-'.$cdb->id, 'invDeleteModal-'.$cdb->id,
        ]);
        $this->assertStringContainsString('CDB Liquidez', $this->nomeDoDialogo($xp, 'invEditModal-'.$cdb->id));
    }

    public function test_os_modais_de_metodos_de_pagamento_tem_um_nome_cada(): void
    {
        $corrente = Account::factory()->for($this->user)->create(['type' => 'checking', 'name' => 'Corrente Itaú']);
        $poupanca = Account::factory()->for($this->user)->create(['type' => 'savings', 'name' => 'Poupança Caixa']);

        $xp = $this->pagina(route('accounts.index'));

        $this->assertModaisSaoDialogos($xp, [
            'acctModal-novo', 'acctModal-'.$corrente->id, 'acctModal-'.$poupanca->id,
        ]);
        $this->assertSame('Editar Corrente Itaú', $this->nomeDoDialogo($xp, 'acctModal-'.$corrente->id));
        $this->assertSame('Editar Poupança Caixa', $this->nomeDoDialogo($xp, 'acctModal-'.$poupanca->id));
    }

    public function test_os_modais_de_dependentes_sao_dialogos_e_a_foto_alcanca_pelo_teclado(): void
    {
        $dependente = User::factory()->create([
            'name' => 'Filha Teste', 'account_owner_id' => $this->user->id, 'is_admin' => false,
        ]);

        $xp = $this->pagina(route('dependentes'));

        $this->assertModaisSaoDialogos($xp, ['depModal', 'depEditModal-'.$dependente->id]);

        // O campo de arquivo era `hidden`: fora da ordem do Tab, quem usa teclado não
        // escolhia a foto. Agora é só escondido da vista (`sr-only`).
        $campos = $xp->query('//input[@data-avatar-input]');
        $this->assertSame(2, $campos->length);
        foreach ($campos as $campo) {
            $this->assertFalse($campo->hasAttribute('hidden'), '#'.$campo->getAttribute('id').' não pode ser hidden');
            $this->assertStringContainsString('sr-only', $campo->getAttribute('class'));
        }
    }

    public function test_o_modal_de_excluir_conta_tem_o_papel_no_painel_e_nao_no_veu(): void
    {
        $xp = $this->pagina(route('settings', 'conta'));

        $this->assertModaisSaoDialogos($xp, ['confirm-user-deletion']);

        $veu = $this->um($xp, '//*[@id="confirm-user-deletion"]');
        $this->assertFalse($veu->hasAttribute('role'), 'o véu ocupa a tela inteira: o diálogo é o painel');
        $this->assertSame('Excluir sua conta?', $this->nomeDoDialogo($xp, 'confirm-user-deletion'));
    }

    public function test_o_fallback_sem_js_da_escolha_de_fonte_tambem_e_dialogo(): void
    {
        $conta = Account::factory()->for($this->user)->create(['type' => 'checking']);
        $cdb = Investment::factory()->for($this->user)->create(['name' => 'CDB']);

        $html = $this->actingAs($this->user)
            ->withSession(['fonteNecessaria' => [
                'conta' => ['id' => $conta->id, 'nome' => 'Corrente'],
                'valor' => 900.0,
                'disponivel' => 100.0,
                'faltante' => 800.0,
                'fontes' => [[
                    'id' => FundingSource::RESGATE_INVESTIMENTO,
                    'rotulo' => 'Resgatar de um investimento',
                    'teto' => 1000.0,
                    'cobre' => true,
                    'detalhe' => 'Resgata só o que falta.',
                    'itens' => [['id' => $cdb->id, 'nome' => 'CDB', 'aplicado' => 1000.0, 'cobre' => true]],
                ]],
                'acao' => route('transactions.store'),
                'metodo' => 'POST',
                'campos' => [],
            ]])
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();
        $xp = $this->xpath($html);

        $this->assertModaisSaoDialogos($xp, ['fundingModalSemJs']);
        $this->assertRadiosAgrupadosComNome($xp, 'fundingModalSemJs');
        // O <label> em volta nomeia o RADIO; o select do investimento precisa do seu.
        $select = $this->um($xp, '//*[@id="fundingModalSemJs"]//select[@name="funding_investment_id"]');
        $this->assertSame('Investimento a resgatar', $select->getAttribute('aria-label'));
    }

    // ================================================================ cores

    public function test_cada_cor_de_meta_tem_nome_falado_distinto_e_nunca_o_hex(): void
    {
        // Cor fora da paleta (meta antiga): ganha nome pela aparência.
        $antiga = Goal::factory()->for($this->user)->create(['color' => '#123456']);

        $xp = $this->pagina(route('metas.index'));

        foreach (['metaCreateModal', 'metaEditModal-'.$antiga->id] as $id) {
            $nomes = $this->nomesDasCores($xp, $id);
            $this->assertNotEmpty($nomes);
            $this->assertSame($nomes, array_unique($nomes), "#{$id}: duas cores com o mesmo nome");
            foreach ($nomes as $nome) {
                $this->assertDoesNotMatchRegularExpression('/#[0-9A-F]{3,6}/i', $nome);
            }
        }

        $this->assertContains('Azul-escuro', $this->nomesDasCores($xp, 'metaEditModal-'.$antiga->id));
    }

    public function test_cada_cor_de_categoria_tem_nome_no_modal_e_na_pagina_cheia(): void
    {
        $esperados = array_merge(['Sem cor (padrão)'], array_map([NomeDaCor::class, 'de'], CategoryController::CORES));

        $this->assertSame($esperados, $this->nomesDasCores($this->pagina(route('categories.index')), 'catModal'));
        $this->assertSame($esperados, $this->nomesDasCores($this->pagina(route('categories.create')), null));
        $this->assertSame($esperados, array_unique($esperados), 'a paleta de categorias tem nomes repetidos');
    }

    public function test_a_pagina_cheia_de_categoria_tambem_agrupa_os_radios(): void
    {
        $xp = $this->pagina(route('categories.create'));

        // Só o formulário da página: o Lançar do shell também está nela, com o grupo dele.
        $formulario = '//*['.$this->temClasse('form-card').']';
        $grupos = $xp->query($formulario.'//*[@role="radiogroup"]');
        $this->assertSame(3, $grupos->length);
        $radios = $xp->query($formulario.'//input[@type="radio"]');
        $this->assertGreaterThan(0, $radios->length);
        foreach ($radios as $radio) {
            $this->assertNotNull($this->grupoDe($radio), 'radio '.$radio->getAttribute('id').' fora de um grupo com nome');
        }
    }

    // ================================================================ reordenar sem arrastar

    public function test_cada_chip_tem_mover_para_cima_e_para_baixo_com_o_nome_da_categoria(): void
    {
        $mercado = Category::factory()->expense()->for($this->user)->create(['name' => 'Mercado', 'position' => 1]);
        $lazer = Category::factory()->expense()->for($this->user)->create(['name' => 'Lazer', 'position' => 2]);
        $fixa = Category::factory()->expense()->for($this->user)->create(['name' => 'Moradia', 'position' => 3, 'is_locked' => true]);

        $xp = $this->pagina(route('categories.index'));

        $sobe = fn (Category $c) => $this->um($xp, '//*[@data-id="'.$c->id.'"]//button[@data-cat-mover="-1"]');
        $desce = fn (Category $c) => $this->um($xp, '//*[@data-id="'.$c->id.'"]//button[@data-cat-mover="1"]');

        $this->assertSame('Mover Mercado para cima', $sobe($mercado)->getAttribute('aria-label'));
        $this->assertSame('Mover Mercado para baixo', $desce($mercado)->getAttribute('aria-label'));
        $this->assertSame('button', $sobe($mercado)->getAttribute('type'), 'botão sem type dentro de form submeteria');

        // As pontas já nascem marcadas (o JS recalcula a cada movimento).
        $this->assertSame('true', $sobe($mercado)->getAttribute('aria-disabled'));
        $this->assertFalse($desce($mercado)->hasAttribute('aria-disabled'));
        $this->assertFalse($sobe($lazer)->hasAttribute('aria-disabled'));

        // Categoria fixa não arrasta — e, pelo mesmo motivo, não ganha os botões.
        $this->assertSame(0, $xp->query('//*[@data-id="'.$fixa->id.'"]//*[@data-cat-mover]')->length);
        $this->assertSame('true', $this->um($xp, '//*[@data-id="'.$fixa->id.'"]//*[contains(@class, "cc-mover")]')->getAttribute('aria-hidden'));

        // Onde o categories.js anuncia a posição nova.
        $this->assertSame('status', $this->um($xp, '//*[@id="catAnuncio"]')->getAttribute('role'));
    }

    // ================================================================ asserções

    /**
     * Cada `.modal-scrim` da página — e pelo menos os `$esperados` — é um diálogo: um
     * único elemento com `role="dialog"`, `aria-modal="true"` e o nome vindo de um título
     * que existe UMA vez na página, dentro do próprio diálogo, com texto.
     */
    private function assertModaisSaoDialogos(DOMXPath $xp, array $esperados): void
    {
        $vistos = [];

        foreach ($xp->query('//*['.$this->temClasse('modal-scrim').']') as $veu) {
            $id = $veu->getAttribute('id');
            $vistos[] = $id;

            $dialogos = $xp->query('descendant-or-self::*[@role="dialog" or @role="alertdialog"]', $veu);
            $this->assertSame(1, $dialogos->length, "#{$id}: precisa de exatamente um elemento com papel de diálogo");

            /** @var DOMElement $dialogo */
            $dialogo = $dialogos->item(0);
            $this->assertSame('true', $dialogo->getAttribute('aria-modal'), "#{$id}: sem aria-modal=\"true\"");

            foreach (['aria-labelledby' => true, 'aria-describedby' => false] as $atributo => $obrigatorio) {
                $referencia = trim($dialogo->getAttribute($atributo));
                if ($referencia === '' && ! $obrigatorio) {
                    continue;
                }
                $this->assertNotSame('', $referencia, "#{$id}: sem {$atributo}");

                foreach (preg_split('/\s+/', $referencia) as $alvo) {
                    $nos = $xp->query('//*[@id="'.$alvo.'"]');
                    $this->assertSame(1, $nos->length, "#{$id}: {$atributo} aponta para #{$alvo}, que tem de existir UMA vez na página");
                    $this->assertTrue($this->contem($dialogo, $nos->item(0)), "#{$id}: #{$alvo} está fora do diálogo");
                    if ($atributo === 'aria-labelledby') {
                        $this->assertNotSame('', trim($nos->item(0)->textContent), "#{$id}: o título #{$alvo} está vazio");
                    }
                }
            }
        }

        foreach ($esperados as $id) {
            $this->assertContains($id, $vistos, "#{$id} não foi renderizado na página");
        }
    }

    /** Todo radio dentro do modal `$id` pertence a um grupo com nome. */
    private function assertRadiosAgrupadosComNome(DOMXPath $xp, string $id): void
    {
        $radios = $xp->query('//*[@id="'.$id.'"]//input[@type="radio"]');
        $this->assertGreaterThan(0, $radios->length, "#{$id}: nenhum radio para conferir");

        foreach ($radios as $radio) {
            $this->assertNotNull(
                $this->grupoDe($radio),
                "#{$id}: o radio ".$radio->getAttribute('name').'='.$radio->getAttribute('value').' está fora de um grupo com nome',
            );
        }
    }

    /** O `radiogroup` (ou `fieldset`) ancestral, se tiver nome; null se não houver. */
    private function grupoDe(DOMElement $radio): ?DOMElement
    {
        for ($no = $radio->parentNode; $no instanceof DOMElement; $no = $no->parentNode) {
            $ehGrupo = $no->getAttribute('role') === 'radiogroup' || $no->nodeName === 'fieldset';
            if ($ehGrupo && $this->nomeDe($no) !== '') {
                return $no;
            }
        }

        return null;
    }

    /** @return list<string> os nomes dos grupos de radio do modal, na ordem da tela */
    private function nomesDosGrupos(DOMXPath $xp, string $id): array
    {
        $nomes = [];
        foreach ($xp->query('//*[@id="'.$id.'"]//*[@role="radiogroup"]') as $grupo) {
            $nomes[] = $this->nomeDe($grupo);
        }

        return $nomes;
    }

    /**
     * Nome acessível de cada radio de cor: o texto do `<label for>` dele (o `.sr-only`).
     * `$id` null = a página inteira.
     *
     * @return list<string>
     */
    private function nomesDasCores(DOMXPath $xp, ?string $id): array
    {
        $escopo = $id === null ? '' : '//*[@id="'.$id.'"]';
        $nomes = [];

        foreach ($xp->query($escopo.'//*['.$this->temClasse('color-picker').']//input[@type="radio"]') as $radio) {
            $rotulo = $xp->query('//label[@for="'.$radio->getAttribute('id').'"]');
            $this->assertSame(1, $rotulo->length, 'radio de cor sem <label for> próprio');
            $nome = trim($rotulo->item(0)->textContent);
            $this->assertNotSame('', $nome, 'radio de cor '.$radio->getAttribute('value').' sem nome acessível');
            $nomes[] = $nome;
        }

        return $nomes;
    }

    /** O nome que o leitor de tela anuncia para o diálogo do véu `$id`. */
    private function nomeDoDialogo(DOMXPath $xp, string $id): string
    {
        $dialogo = $this->um($xp, '//*[@id="'.$id.'"]/descendant-or-self::*[@role="dialog"]');

        return $this->nomeDe($dialogo);
    }

    /** Nome por `aria-labelledby` (texto dos referenciados) ou `aria-label`, espaços normalizados. */
    private function nomeDe(DOMElement $el): string
    {
        $referencia = trim($el->getAttribute('aria-labelledby'));
        if ($referencia !== '') {
            $partes = [];
            foreach (preg_split('/\s+/', $referencia) as $alvo) {
                $no = $el->ownerDocument->getElementById($alvo)
                    ?? (new DOMXPath($el->ownerDocument))->query('//*[@id="'.$alvo.'"]')->item(0);
                if ($no) {
                    $partes[] = $this->textoVisivel($no);
                }
            }

            return trim(preg_replace('/\s+/u', ' ', implode(' ', $partes)));
        }

        return trim($el->getAttribute('aria-label'));
    }

    /** Texto do nó sem o que está `hidden` (o aviso de categoria fixa, por exemplo). */
    private function textoVisivel(DOMNode $no): string
    {
        if ($no instanceof DOMElement && $no->hasAttribute('hidden')) {
            return '';
        }
        if (! $no->hasChildNodes()) {
            return $no->nodeType === XML_TEXT_NODE ? $no->textContent : '';
        }

        $texto = '';
        foreach ($no->childNodes as $filho) {
            $texto .= $this->textoVisivel($filho);
        }

        return $texto;
    }

    private function contem(DOMNode $pai, DOMNode $filho): bool
    {
        for ($no = $filho; $no !== null; $no = $no->parentNode) {
            if ($no->isSameNode($pai)) {
                return true;
            }
        }

        return false;
    }

    private function um(DOMXPath $xp, string $consulta): DOMElement
    {
        $nos = $xp->query($consulta);
        $this->assertSame(1, $nos->length, "esperava exatamente um nó para {$consulta}");

        return $nos->item(0);
    }

    private function temClasse(string $classe): string
    {
        return 'contains(concat(" ", normalize-space(@class), " "), " '.$classe.' ")';
    }

    private function pagina(string $url): DOMXPath
    {
        return $this->xpath($this->actingAs($this->user)->get($url)->assertOk()->getContent());
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
