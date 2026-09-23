<?php

namespace Tests\Feature;

use App\Http\Requests\UpdateGoalRequest;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\DesligaEscopoDaFamiliaNaRota;
use Tests\TestCase;

/**
 * Mesmo padrão do A-3, agora nas METAS: editar a meta de OUTRA família não pode
 * devolver nada sobre ela — nem de forma indireta.
 *
 * O `UpdateGoalRequest` perdoa o prazo vencido quando ele é IGUAL ao que já está
 * gravado (T-6 de 02/09/2026: senão meta vencida ficava ineditável). Para decidir
 * isso ele lia a meta da rota — sem conferir a posse, e o `authorize()` herdado
 * devolvia `true`. Como a `GoalPolicy` só roda no controller, DEPOIS da validação,
 * a resposta dependia do prazo da meta alheia:
 *
 *   - chute CERTO do prazo  → a regra "de hoje em diante" some → 403 da policy;
 *   - chute ERRADO          → 422 "A data-alvo deve ser de hoje em diante."
 *
 * Um oráculo: mês a mês (umas 300 tentativas, rota sem limite de taxa), qualquer
 * pessoa logada descobria o prazo de toda meta vencida de outra família — e que
 * ela está vencida. A mensagem nunca citava a data; quem contava era a DIFERENÇA
 * entre as duas respostas.
 *
 * Desde 23/09/2026 a meta alheia nem chega ao Form Request: o binding só encontra meta
 * da família de quem pede e responde o 404 de um id que não existe
 * (`IdAlheioNaRotaIgualAIdInexistenteTest`). O `authorize()` e a guarda do perdão do
 * prazo ficaram como linhas de TRÁS, e é delas que este arquivo cuida: o `authorize()`
 * pelos testes HTTP, com o escopo do binding desligado no setUp (ligado, a meta alheia
 * pararia no 404 do binding e o `authorize()` não seria visto); a guarda, montando as
 * regras direto, no último teste.
 */
class EditarMetaAlheiaNaoVazaPrazoTest extends TestCase
{
    use DesligaEscopoDaFamiliaNaRota, RefreshDatabase;

    private const NOME_ALHEIO = 'Cirurgia Secreta do Vizinho';

    private User $vizinho;

    private Goal $alheia;

    private User $intruso;

    protected function setUp(): void
    {
        parent::setUp();

        // A meta alheia precisa CHEGAR ao Form Request (ver o docblock).
        $this->desligarEscopoDaFamiliaNaRota('meta', Goal::class);

        $this->vizinho = User::factory()->create(['is_admin' => true, 'account_owner_id' => null]);

        // Prazo VENCIDO: é só nele que o perdão da regra muda a resposta (um prazo
        // futuro passa no "de hoje em diante" de qualquer jeito).
        $this->alheia = Goal::factory()->for($this->vizinho)->create([
            'name' => self::NOME_ALHEIO,
            'target_amount' => 25000,
            'emoji' => '🏥',
            'color' => '#0F6B47',
            'target_date' => $this->prazoGravado(),
        ]);

        $this->intruso = User::factory()->create(['is_admin' => true, 'account_owner_id' => null]);
    }

    /**
     * O prazo da meta alheia, três meses atrás. Parte do dia 1 ANTES de subtrair:
     * `now()->subMonths(3)` num dia 31 transborda para o mês seguinte, e o teste
     * passaria a depender do dia em que roda.
     */
    private function prazoGravado(): string
    {
        return now()->startOfMonth()->subMonths(3)->toDateString();
    }

    /** Formulário VÁLIDO do modal de edição: o único campo em jogo é o prazo. */
    private function formulario(string $prazo): array
    {
        return [
            'name' => 'Qualquer nome',
            'target_amount' => '500,00',
            // O <input type="month"> manda "AAAA-MM".
            'target_date' => $prazo,
            'emoji' => '🎯',
            'color' => '#0F6B47',
        ];
    }

    private function prazoCerto(): string
    {
        return $this->alheia->target_date->format('Y-m');
    }

    /** Outro mês do passado: chute errado, mas que a regra "de hoje em diante" também recusa. */
    private function prazoErrado(): string
    {
        return now()->startOfMonth()->subMonths(7)->format('Y-m');
    }

    private function editarJson(User $quem, string $prazo): TestResponse
    {
        return $this->actingAs($quem)
            ->patchJson(route('metas.update', $this->alheia), $this->formulario($prazo));
    }

    private function assertMetaAlheiaIntacta(): void
    {
        $meta = $this->alheia->fresh();

        $this->assertSame(self::NOME_ALHEIO, $meta->name);
        $this->assertSame($this->prazoGravado(), $meta->target_date->toDateString());
    }

    /**
     * O coração do achado: acertar ou errar o prazo da meta alheia tem de dar
     * EXATAMENTE a mesma resposta. É a diferença entre as duas que vazava.
     */
    public function test_chute_certo_e_chute_errado_do_prazo_alheio_recebem_a_mesma_resposta(): void
    {
        // A comparação é do corpo que o ATACANTE recebe, e em produção ele vem sem
        // rastro de depuração. Com o debug ligado, o JSON do 403 traz a pilha — e a
        // pilha cita a linha deste teste de onde saiu cada requisição, que é
        // diferente nas duas chamadas mesmo com a correção no lugar.
        config(['app.debug' => false]);

        $certo = $this->editarJson($this->intruso, $this->prazoCerto());
        $errado = $this->editarJson($this->intruso, $this->prazoErrado());

        $this->assertSame(
            [$errado->status(), $errado->json()],
            [$certo->status(), $certo->json()],
            'A resposta muda conforme o chute acerta o prazo da meta alheia: oráculo do prazo.',
        );

        $this->assertStringNotContainsString(self::NOME_ALHEIO, $certo->getContent().$errado->getContent());
        $this->assertMetaAlheiaIntacta();
    }

    /** Posse PRIMEIRO: meta de outra família recebe 403 sem regra nenhuma chegar a olhar para ela. */
    public function test_patch_json_em_meta_alheia_da_403_antes_de_qualquer_regra(): void
    {
        foreach ([$this->prazoCerto(), $this->prazoErrado()] as $prazo) {
            $this->editarJson($this->intruso, $prazo)
                ->assertForbidden()
                ->assertJsonMissingValidationErrors();
        }

        // Nem um formulário inteiro inválido chega a virar 422: a validação não roda.
        $this->actingAs($this->intruso)
            ->patchJson(route('metas.update', $this->alheia), [])
            ->assertForbidden()
            ->assertJsonMissingValidationErrors();

        $this->assertMetaAlheiaIntacta();
    }

    /** Sem JS o vazamento ia pela sessão (redirect com `errors`), não pelo corpo. */
    public function test_patch_web_em_meta_alheia_da_403_sem_erro_de_validacao_na_sessao(): void
    {
        $r = $this->actingAs($this->intruso)
            ->from(route('metas.index'))
            ->patch(route('metas.update', $this->alheia), $this->formulario($this->prazoErrado()));

        $r->assertForbidden()->assertSessionHasNoErrors();
        $r->assertDontSee(self::NOME_ALHEIO);

        $this->assertMetaAlheiaIntacta();
    }

    /**
     * A correção não pode tirar o perdão do prazo vencido de quem é DA FAMÍLIA — e
     * "da família" é `ownerId()`, não o id de quem está logado: o dependente edita
     * as metas do titular e continua podendo renomear uma meta vencida. Trocar
     * para OUTRA data passada segue recusado para os dois.
     */
    public function test_titular_e_dependente_continuam_editando_a_propria_meta_com_prazo_vencido(): void
    {
        $dependente = User::factory()->create(['is_admin' => false, 'account_owner_id' => $this->vizinho->id]);

        foreach ([$this->vizinho, $dependente] as $quem) {
            $this->editarJson($quem, $this->prazoCerto())
                ->assertRedirect(route('metas.index'));

            $this->editarJson($quem, $this->prazoErrado())
                ->assertStatus(422)
                ->assertJsonValidationErrors('target_date');
        }

        $meta = $this->alheia->fresh();
        $this->assertSame('Qualquer nome', $meta->name);
        $this->assertSame($this->prazoGravado(), $meta->target_date->toDateString());
    }

    /**
     * A SEGUNDA LINHA, testada sozinha: o perdão do prazo só vale para a meta da
     * família mesmo que o `authorize()` um dia deixe passar.
     *
     * Pelo HTTP não dá para ver esta guarda — o `authorize()` barra a meta alheia
     * antes de o validador existir. Por isso as regras são montadas direto, com o
     * mesmo request que o controller receberia (rota com a meta, usuário logado e
     * o prazo já no formato que o `prepareForValidation` entrega).
     */
    public function test_segunda_linha_o_perdao_do_prazo_so_vale_para_a_meta_da_familia(): void
    {
        $dependente = User::factory()->create(['is_admin' => false, 'account_owner_id' => $this->vizinho->id]);

        $this->assertTrue(
            $this->exigeHojeEmDiante($this->intruso),
            'Para a meta de outra família o prazo alheio não pode afrouxar a regra: seria o oráculo de volta.',
        );

        foreach ([$this->vizinho, $dependente] as $quem) {
            $this->assertFalse(
                $this->exigeHojeEmDiante($quem),
                'Quem é da família (titular ou dependente) mantém o perdão do prazo vencido.',
            );
        }
    }

    /** As regras do `target_date` para `$quem` enviando o prazo IGUAL ao gravado incluem "de hoje em diante"? */
    private function exigeHojeEmDiante(User $quem): bool
    {
        $request = UpdateGoalRequest::create(
            route('metas.update', $this->alheia, false),
            'PATCH',
            ['target_date' => $this->prazoGravado()],
        );

        $rota = (new Route('PATCH', 'metas/{meta}', []))->bind($request);
        $rota->setParameter('meta', $this->alheia);

        $request->setRouteResolver(fn () => $rota);
        $request->setUserResolver(fn () => $quem);

        return collect($request->rules()['target_date'])
            ->contains(fn ($regra) => is_string($regra) && str_starts_with($regra, 'after_or_equal:'));
    }
}
