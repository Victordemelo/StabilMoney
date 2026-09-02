<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FixedBill;
use App\Models\Goal;
use App\Models\Investment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FixedBillService;
use App\Support\DefaultCategories;
use Database\Seeders\DadosDeDemonstracaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O seeder de demonstração produz um estado COERENTE com o modelo de dinheiro.
 *
 * Por que testar um seeder: ele escreve nas tabelas por baixo dos Services, então
 * nada valida o que ele grava. Um valor errado em `goal_contributions.type` já
 * custou uma rodada — com 'deposit' no lugar de 'aporte', o `reserved` saía
 * ZERADO e a poupança mostrava R$ 80.700 disponíveis em vez de R$ 3.300, sem
 * nenhum erro na tela. É esse tipo de mentira silenciosa que estas asserções
 * pegam.
 */
class SeederDeDemonstracaoTest extends TestCase
{
    use RefreshDatabase;

    private User $titular;

    protected function setUp(): void
    {
        parent::setUp();

        // O seeder se recusa a rodar fora de `local` — é dado de mentira, e
        // rodá-lo em produção apagaria o financeiro de quem estivesse usando.
        $this->app['env'] = 'local';

        $this->titular = User::factory()->create(['account_owner_id' => null]);
        DefaultCategories::seedFor($this->titular);

        $this->seed(DadosDeDemonstracaoSeeder::class);
    }

    private function contas(): Collection
    {
        $contas = Account::where('user_id', $this->titular->id)->get();
        Account::preloadMoney($contas);

        return $contas;
    }

    public function test_fora_do_ambiente_local_nao_escreve_nada(): void
    {
        $this->app['env'] = 'producao';
        $antes = Transaction::count();

        $this->seed(DadosDeDemonstracaoSeeder::class);

        $this->assertSame($antes, Transaction::count());
    }

    public function test_o_reservado_sai_positivo_e_derruba_o_disponivel(): void
    {
        $poupanca = $this->contas()->firstWhere('type', 'savings');

        // O bug do 'deposit': reserved zerado (ou negativo) faz o disponível
        // ficar MAIOR que o saldo bruto — dinheiro do nada na tela.
        $this->assertGreaterThan(0, $poupanca->reserved);
        $this->assertLessThan($poupanca->balance, $poupanca->available);
        $this->assertEqualsWithDelta(
            $poupanca->balance - $poupanca->reserved,
            $poupanca->available,
            0.01,
        );
    }

    public function test_nenhuma_conta_fica_abaixo_do_piso_do_cheque_especial(): void
    {
        // O piso do modelo é −overdraft_limit. Uma demo que estoure isso mostra
        // um estado que o SpendingGuard jamais deixaria acontecer em uso real.
        foreach ($this->contas()->where('type', '!=', 'credit_card') as $conta) {
            if (! $conta->isCash()) {
                continue;
            }

            $this->assertGreaterThanOrEqual(
                -(float) $conta->overdraft_limit,
                round($conta->available, 2),
                "{$conta->name} furou o piso do cheque especial",
            );
        }
    }

    public function test_o_cartao_nao_estoura_o_limite(): void
    {
        $cartao = $this->contas()->firstWhere('type', 'credit_card');

        $this->assertGreaterThan(0, $cartao->committed);
        $this->assertGreaterThanOrEqual(0, $cartao->availableLimit);
    }

    public function test_metas_e_investimentos_nascem_com_saldo(): void
    {
        $metas = Goal::where('user_id', $this->titular->id)->get();
        $investimentos = Investment::where('user_id', $this->titular->id)->get();

        $this->assertGreaterThan(0, $metas->count());
        $this->assertGreaterThan(0, $investimentos->count());

        foreach ($metas as $meta) {
            $this->assertGreaterThan(0, $meta->saved);
            // Meta cheia não tem o que mostrar de progresso.
            $this->assertLessThan((float) $meta->target_amount, $meta->saved);
        }

        foreach ($investimentos as $inv) {
            $this->assertGreaterThan(0, $inv->aplicado);
        }
    }

    public function test_nenhum_aporte_tem_data_futura(): void
    {
        // Regra das cinco portas: `reserved` soma tudo sem olhar data, então
        // aporte no futuro derrubaria o disponível de HOJE.
        foreach (['goal_contributions', 'investment_contributions'] as $tabela) {
            $futuros = DB::table($tabela)->where('date', '>', now()->toDateString())->count();

            $this->assertSame(0, $futuros, "{$tabela} tem aporte com data futura");
        }
    }

    public function test_ha_conta_fixa_vencida_para_a_tela_ter_o_que_avisar(): void
    {
        $this->assertGreaterThan(0, FixedBill::where('user_id', $this->titular->id)->count());

        $vencidas = app(FixedBillService::class)->overdue($this->titular->id);

        $this->assertGreaterThan(0, $vencidas->count(), 'sem conta vencida, o selo vermelho e o sino ficam sem caso de uso');
    }

    public function test_a_semana_corrente_tem_movimento(): void
    {
        // O dashboard ABRE em "Semana". Rodar o seeder no dia 2 do mês deixaria
        // a tela de entrada com quatro zeros se o histórico fosse só mensal.
        $daSemana = Transaction::where('user_id', $this->titular->id)
            ->where('date', '>=', now()->startOfWeek()->toDateString())
            ->where('date', '<=', now()->toDateString())
            ->get();

        $this->assertGreaterThan(0, $daSemana->where('type', 'income')->count(), 'sem receita na semana o card abre zerado');
        $this->assertGreaterThan(0, $daSemana->where('type', 'expense')->count());
    }

    public function test_a_familia_tem_dependentes_com_gasto_proprio(): void
    {
        $dependentes = $this->titular->dependents()->get();

        $this->assertCount(2, $dependentes);

        foreach ($dependentes as $dep) {
            $this->assertGreaterThan(
                0,
                Transaction::where('made_by_user_id', $dep->id)->where('type', 'expense')->count(),
                "{$dep->name} não lançou nada — a fatia do gasto da família ficaria em 0%",
            );
        }
    }

    public function test_o_parcelamento_tem_uuid_so_na_primeira_parcela(): void
    {
        $parcelas = Transaction::where('user_id', $this->titular->id)
            ->whereNotNull('installments')
            ->orderBy('installment_no')
            ->get();

        $this->assertGreaterThan(1, $parcelas->count());
        // O índice único é (user_id, client_uuid): repetir o uuid nas N parcelas
        // estouraria a constraint.
        $this->assertSame(1, $parcelas->whereNotNull('client_uuid')->count());
        $this->assertNotNull($parcelas->first()->client_uuid);
    }

    public function test_rodar_de_novo_nao_duplica_nada(): void
    {
        $antes = [
            'transactions' => Transaction::where('user_id', $this->titular->id)->count(),
            'accounts' => Account::where('user_id', $this->titular->id)->count(),
            'dependentes' => $this->titular->dependents()->count(),
            'metas' => Goal::where('user_id', $this->titular->id)->count(),
        ];

        $this->seed(DadosDeDemonstracaoSeeder::class);

        $this->assertSame($antes['transactions'], Transaction::where('user_id', $this->titular->id)->count());
        $this->assertSame($antes['accounts'], Account::where('user_id', $this->titular->id)->count());
        $this->assertSame($antes['dependentes'], $this->titular->fresh()->dependents()->count());
        $this->assertSame($antes['metas'], Goal::where('user_id', $this->titular->id)->count());
    }

    public function test_o_titular_e_a_foto_dele_sobrevivem_a_reexecucao(): void
    {
        // Recriar o titular tiraria o login e zeraria `avatar_path` — e é
        // justamente a conta que a pessoa está usando para olhar a demo.
        $this->titular->forceFill(['avatar_path' => 'avatars/victor.jpg'])->save();

        $this->seed(DadosDeDemonstracaoSeeder::class);

        $this->assertDatabaseHas('users', [
            'id' => $this->titular->id,
            'avatar_path' => 'avatars/victor.jpg',
        ]);
    }
}
