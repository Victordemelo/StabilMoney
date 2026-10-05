<?php

namespace Tests\Feature;

use App\Models\Atividade;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Configurações › Atividade (out/2026): quem vê o quê, filtros, isolamento entre famílias,
 * desempenho, retenção de 180 dias e a exclusão da conta.
 */
class TelaDeAtividadeTest extends TestCase
{
    use RefreshDatabase;

    private User $titular;

    private User $maria;

    private User $vizinho;

    protected function setUp(): void
    {
        parent::setUp();

        $this->titular = User::factory()->create(['name' => 'Victor', 'is_admin' => true]);
        $this->maria = User::factory()->create(['name' => 'Maria', 'account_owner_id' => $this->titular->id, 'is_admin' => false]);
        $this->vizinho = User::factory()->create(['name' => 'Vizinho', 'is_admin' => true]);
    }

    private function linha(User $autor, string $acao, string $frase, ?CarbonImmutable $quando = null): Atividade
    {
        $linha = new Atividade;
        $linha->forceFill([
            'owner_id' => $autor->ownerId(),
            'user_id' => $autor->id,
            'autor_nome' => $autor->name,
            'acao' => $acao,
            'grupo' => Atividade::ACOES[$acao],
            'descricao' => $autor->name.' '.$frase,
            'ip' => '203.0.113.9',
            'aparelho' => 'Mozilla/5.0 (Macintosh; Mac OS X) Safari/605.1',
            'created_at' => ($quando ?? CarbonImmutable::now())->toDateTimeString(),
        ])->save();

        return $linha;
    }

    private function tela(User $quem, array $filtros = [])
    {
        return $this->actingAs($quem)->get(route('settings', ['tab' => 'atividade'] + $filtros));
    }

    public function test_titular_ve_a_familia_inteira_e_nunca_outra_familia(): void
    {
        $this->linha($this->titular, 'conta.criada', 'cadastrou o cartão Roxinho');
        $this->linha($this->maria, 'transacao.criada', 'lançou a despesa “Mercado”');
        $this->linha($this->vizinho, 'transacao.criada', 'lançou a despesa “Segredo do vizinho”');

        $this->tela($this->titular)
            ->assertOk()
            ->assertSee('Atividade da família')
            ->assertSee('Victor cadastrou o cartão Roxinho')
            ->assertSee('Maria lançou a despesa “Mercado”')
            ->assertDontSee('Segredo do vizinho')
            ->assertSee('Safari no macOS')
            ->assertSee('IP 203.0.113.9');
    }

    public function test_o_ip_so_aparece_para_quem_fez_a_acao(): void
    {
        $this->linha($this->titular, 'conta.criada', 'cadastrou o cartão Roxinho')->forceFill(['ip' => '198.51.100.7'])->save();
        $this->linha($this->maria, 'transacao.criada', 'lançou a despesa “Mercado”')
            ->forceFill(['ip' => '203.0.113.44', 'aparelho' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148 Safari/604.1'])->save();

        // O titular vê o que a Maria fez e de que aparelho — mas não o endereço dela.
        $this->tela($this->titular)
            ->assertSee('Maria lançou a despesa “Mercado”')
            ->assertSee('Safari no iOS')
            ->assertSee('IP 198.51.100.7')
            ->assertDontSee('203.0.113.44');

        // A própria Maria vê o dela.
        $this->tela($this->maria)->assertSee('IP 203.0.113.44')->assertDontSee('198.51.100.7');
    }

    public function test_dependente_ve_so_o_que_ele_mesmo_fez(): void
    {
        $this->linha($this->titular, 'senha.trocada', 'trocou a senha');
        $this->linha($this->maria, 'transacao.criada', 'lançou a despesa “Mercado”');

        $this->tela($this->maria)
            ->assertOk()
            ->assertSee('Maria lançou a despesa “Mercado”')
            ->assertDontSee('Victor trocou a senha')
            // O filtro de pessoa nem aparece, e forçá-lo pela URL não abre nada.
            ->assertDontSee('id="atv-pessoa"', false);

        $this->tela($this->maria, ['pessoa' => $this->titular->id])
            ->assertDontSee('Victor trocou a senha');
    }

    public function test_filtros_por_tipo_pessoa_e_periodo(): void
    {
        $this->linha($this->titular, 'acesso.entrou', 'entrou no app', CarbonImmutable::parse('2026-09-10 10:00'));
        $this->linha($this->maria, 'transacao.criada', 'lançou a despesa “Mercado”', CarbonImmutable::parse('2026-09-20 23:30'));
        $this->linha($this->maria, 'dependente.saiu', 'saiu de algo', CarbonImmutable::parse('2026-09-25 09:00'));

        $this->tela($this->titular, ['tipo' => 'dinheiro'])
            ->assertSee('Maria lançou a despesa')->assertDontSee('Victor entrou no app')->assertDontSee('Maria saiu de algo');

        $this->tela($this->titular, ['pessoa' => $this->maria->id])
            ->assertSee('Maria lançou a despesa')->assertDontSee('Victor entrou no app');

        // O "até" inclui o dia inteiro (23:30 do dia 20 entra).
        $this->tela($this->titular, ['de' => '2026-09-15', 'ate' => '2026-09-20'])
            ->assertSee('Maria lançou a despesa')->assertDontSee('Victor entrou no app')->assertDontSee('Maria saiu de algo');

        // Datas invertidas são trocadas; data inválida é ignorada (não derruba a lista).
        $this->tela($this->titular, ['de' => '2026-09-20', 'ate' => '2026-09-15'])->assertSee('Maria lançou a despesa');
        $this->tela($this->titular, ['de' => '2026-13-45', 'tipo' => 'nao-existe'])
            ->assertOk()->assertSee('Victor entrou no app')->assertSee('Maria saiu de algo');
    }

    public function test_pessoa_de_outra_familia_no_filtro_e_ignorada_nunca_aplicada(): void
    {
        $this->linha($this->titular, 'acesso.entrou', 'entrou no app');
        $this->linha($this->vizinho, 'acesso.entrou', 'entrou lá na casa dele');

        // Se o id alheio fosse aplicado, a lista ficaria VAZIA — e isso já diria que ele existe.
        $this->tela($this->titular, ['pessoa' => $this->vizinho->id])
            ->assertOk()
            ->assertSee('Victor entrou no app')
            ->assertDontSee('entrou lá na casa dele');
    }

    public function test_a_lista_nao_faz_uma_consulta_por_linha(): void
    {
        $contar = function () {
            $consultas = 0;
            DB::listen(function ($q) use (&$consultas) {
                if (str_contains($q->sql, 'atividades')) {
                    $consultas++;
                }
            });
            $this->tela($this->titular)->assertOk();

            return $consultas;
        };

        $this->linha($this->maria, 'transacao.criada', 'lançou uma');
        $poucas = $contar();

        foreach (range(1, 20) as $i) {
            $this->linha($i % 2 ? $this->maria : $this->titular, 'transacao.criada', "lançou a {$i}");
        }
        $muitas = $contar();

        $this->assertSame($poucas, $muitas, 'Consultas à tabela crescendo com o número de linhas = N+1.');
    }

    public function test_limpeza_apaga_so_o_que_passou_de_180_dias(): void
    {
        $velha = $this->linha($this->titular, 'acesso.entrou', 'entrou há muito tempo', CarbonImmutable::now()->subDays(181));
        $recente = $this->linha($this->titular, 'acesso.entrou', 'entrou ontem', CarbonImmutable::now()->subDays(179));

        Artisan::call('atividades:limpar');

        $this->assertNull(Atividade::find($velha->id));
        $this->assertNotNull(Atividade::find($recente->id));
    }

    public function test_limpeza_esta_agendada_todo_dia(): void
    {
        Artisan::call('schedule:list');

        $this->assertStringContainsString('atividades:limpar', Artisan::output());
    }

    public function test_excluir_a_conta_do_titular_apaga_a_atividade_da_familia_e_so_dela(): void
    {
        $this->linha($this->titular, 'acesso.entrou', 'entrou no app');
        $this->linha($this->maria, 'transacao.criada', 'lançou a despesa “Mercado”');
        $doVizinho = $this->linha($this->vizinho, 'acesso.entrou', 'entrou lá');

        $this->actingAs($this->titular)->delete(route('profile.destroy'), [
            'password' => 'password',
            'confirmo_dependentes' => '1',
            'confirmo_pendencias' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertNull(User::find($this->titular->id), 'A conta deveria ter sido excluída.');
        $this->assertSame(0, Atividade::where('owner_id', $this->titular->id)->count());
        $this->assertNotNull(Atividade::find($doVizinho->id));
    }
}
