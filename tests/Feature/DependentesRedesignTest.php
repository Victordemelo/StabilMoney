<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Tela de Dependentes v2: resumo da família, fatia do gasto e o card-fantasma.
 *
 * O card antigo mostrava um número solto por pessoa. "R$ 1.590" é muito ou pouco
 * dependendo do total da família — sem denominador, a tela não respondia a
 * pergunta que ela existe para responder.
 */
class DependentesRedesignTest extends TestCase
{
    use RefreshDatabase;

    private User $titular;

    private Account $conta;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-15');

        $this->titular = User::factory()->create(['name' => 'Victor Rosa']);
        $this->conta = Account::factory()->for($this->titular)->create([
            'type' => 'checking', 'initial_balance' => 100000,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function dependente(string $nome, string $parentesco = 'conjuge'): User
    {
        return User::factory()->create([
            'name' => $nome,
            'account_owner_id' => $this->titular->id,
            'is_admin' => false,
            'relationship' => $parentesco,
        ]);
    }

    private function gastou(User $quem, float $valor, ?string $data = null): void
    {
        Transaction::factory()->for($this->titular)->for($this->conta)->expense()->create([
            'amount' => $valor,
            'made_by_user_id' => $quem->id,
            'date' => $data ?? '2026-08-10',
        ]);
    }

    private function ver(): TestResponse
    {
        return $this->actingAs($this->titular)->get(route('dependentes'))->assertOk();
    }

    public function test_o_total_da_familia_soma_titular_e_dependentes(): void
    {
        $maria = $this->dependente('Maria Silva');
        $this->gastou($this->titular, 600);
        $this->gastou($maria, 400);

        $this->assertSame(1000.0, (float) $this->ver()->viewData('gastoFamilia'));
    }

    public function test_a_fatia_de_cada_pessoa_aparece_na_tela(): void
    {
        $maria = $this->dependente('Maria Silva');
        $this->gastou($this->titular, 750);
        $this->gastou($maria, 250);

        $this->ver()
            ->assertSee('75%')
            ->assertSee('25%')
            ->assertSee('do gasto da família');
    }

    public function test_mes_sem_gasto_nenhum_nao_estoura_na_divisao(): void
    {
        $this->dependente('Maria Silva');

        // Denominador zero: a barra fica vazia e "quem mais gastou" vira travessão.
        // Sem a guarda seria divisão por zero — e um NaN na largura passa em
        // silêncio no navegador, que só ignora a regra.
        $this->ver()
            ->assertSee('0%')
            ->assertSee('—');

        $this->assertSame(0.0, (float) $this->ver()->viewData('gastoFamilia'));
    }

    public function test_gasto_de_outro_mes_nao_entra_na_conta(): void
    {
        $this->gastou($this->titular, 500, '2026-07-20');
        $this->gastou($this->titular, 300, '2026-08-02');

        $this->assertSame(300.0, (float) $this->ver()->viewData('gastoFamilia'));
    }

    public function test_o_card_fantasma_so_aparece_sem_dependente(): void
    {
        // Estado vazio que só diz "não há nada" deixa o usuário adivinhando o que
        // ganha em troca de cadastrar alguém. O fantasma mostra o formato.
        $this->ver()->assertSee('dep-ghost', escape: false)->assertSee('Alguém da família');

        $this->dependente('Maria Silva');

        $this->ver()->assertDontSee('dep-ghost', escape: false)->assertDontSee('Alguém da família');
    }

    public function test_o_parentesco_aparece_como_selo(): void
    {
        $this->dependente('Maria Silva', 'filho');

        $this->ver()
            ->assertSee('dp-badge', escape: false)
            ->assertSee('Titular')
            ->assertSee('Filho(a)');
    }

    public function test_o_resumo_conta_todas_as_pessoas_da_conta(): void
    {
        $this->assertSame(1, $this->ver()->viewData('dependents')->count() + 1);

        $this->dependente('Maria Silva');
        $this->dependente('João Silva', 'filho');

        $this->ver()->assertSee('Pessoas na conta')->assertSee('Quem mais gastou');
        $this->assertSame(3, $this->ver()->viewData('dependents')->count() + 1);
    }

    public function test_quem_mais_gastou_pode_ser_o_titular(): void
    {
        $maria = $this->dependente('Maria Silva');
        $this->gastou($this->titular, 900);
        $this->gastou($maria, 100);

        // O titular disputa como qualquer um — excluí-lo daria o pódio a quem
        // gastou menos e a linha mentiria.
        $this->ver()->assertSeeInOrder(['Quem mais gastou', 'Victor Rosa']);
    }

    public function test_a_foto_do_dependente_aparece_no_card(): void
    {
        $maria = $this->dependente('Maria Silva');
        $maria->forceFill(['avatar_path' => 'avatars/maria.jpg'])->save();

        $this->ver()->assertSee(route('avatar.show', $maria), escape: false);
    }

    public function test_sem_foto_o_card_cai_nas_iniciais(): void
    {
        $this->dependente('Maria Silva');

        // MS = primeira letra do primeiro e do último nome.
        $this->ver()->assertSee('MS');
    }

    public function test_a_foto_do_perfil_aparece_no_rodape_da_sidebar(): void
    {
        // Era o único lugar do app que continuava mostrando as letras depois do
        // upload — quem sobe uma foto espera vê-la no shell.
        $this->ver()->assertDontSee(route('avatar.show', $this->titular), escape: false);

        $this->titular->forceFill(['avatar_path' => 'avatars/victor.jpg'])->save();

        $this->actingAs($this->titular)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('avatar.show', $this->titular), escape: false);
    }

    public function test_dependente_nao_entra_na_tela(): void
    {
        $maria = $this->dependente('Maria Silva');

        // A tela é do titular: quem gerencia acessos é quem criou a conta.
        $this->actingAs($maria)->get(route('dependentes'))->assertForbidden();
    }
}
