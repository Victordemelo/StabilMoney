<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Category;
use App\Models\FixedBill;
use App\Models\Goal;
use App\Models\Investment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dados de DEMONSTRAÇÃO: uma família com seis meses de histórico.
 *
 * Serve para ver as telas cheias — dashboard com gráfico, /faturas com fatura
 * aberta e conta fixa vencida, dependentes com fatia de gasto, metas e
 * investimentos com saldo. **Não é fixture de teste**: a suíte usa factories.
 *
 *     docker compose exec app php artisan db:seed --class=DadosDeDemonstracaoSeeder
 *
 * É RE-EXECUTÁVEL: apaga os dados financeiros da família e reconstrói do zero.
 * O usuário (e a foto dele) fica de pé — recriar o titular tiraria o login e
 * zeraria `avatar_path`, e é justamente a conta que a pessoa está usando.
 *
 * ⚠️ Só roda em ambiente local. Os valores são inventados, mas a ARITMÉTICA é
 * real: as despesas cabem no saldo de cada mês, então nada aqui produz um
 * estado que o SpendingGuard recusaria em uso normal. É por isso que as
 * transações podem ser escritas direto — não há decisão de fonte a tomar.
 */
class DadosDeDemonstracaoSeeder extends Seeder
{
    private User $titular;

    /** @var array<string, Category> categorias da família, indexadas pelo nome */
    private array $cat = [];

    private Account $corrente;

    private Account $poupanca;

    private Account $cartao;

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->error('Este seeder só roda em APP_ENV=local.');

            return;
        }

        $this->titular = User::whereNull('account_owner_id')->orderBy('id')->firstOrFail();

        DB::transaction(function () {
            $this->limpar();
            $this->carregarCategorias();
            $this->criarDependentes();
            $this->criarMetodosDePagamento();
            $this->criarHistorico();
            $this->criarContasFixas();
            $this->pagarContasFixasPassadas();
            $this->criarMetasEInvestimentos();
        });

        $this->command?->info("Dados de demonstração criados para {$this->titular->name}.");
    }

    /**
     * Zera o financeiro da família mantendo usuário e categorias.
     *
     * A ordem importa: as contribuições apontam para contas, e as transações
     * também. Apagar conta primeiro deixaria órfão o que não tem FK com cascade.
     */
    private function limpar(): void
    {
        $familia = User::where('account_owner_id', $this->titular->id)->pluck('id')
            ->push($this->titular->id);

        DB::table('goal_contributions')
            ->whereIn('goal_id', Goal::where('user_id', $this->titular->id)->pluck('id'))->delete();
        DB::table('investment_contributions')
            ->whereIn('investment_id', Investment::where('user_id', $this->titular->id)->pluck('id'))->delete();

        Transaction::where('user_id', $this->titular->id)->delete();
        FixedBill::where('user_id', $this->titular->id)->delete();
        Goal::where('user_id', $this->titular->id)->delete();
        Investment::where('user_id', $this->titular->id)->delete();
        Account::where('user_id', $this->titular->id)->delete();

        // Dependentes de demonstração saem junto (o hook de `deleting` do model
        // limpa a foto e as sessões deles). Um dependente real do Victor não
        // seria recriado igual, então a lista é fechada pelos e-mails daqui.
        User::whereIn('id', $familia)->whereNot('id', $this->titular->id)
            ->whereIn('email', ['maria.demo@stabilmoney.test', 'lucas.demo@stabilmoney.test'])
            ->get()->each->delete();
    }

    private function carregarCategorias(): void
    {
        $this->cat = Category::where('user_id', $this->titular->id)
            ->get()->keyBy('name')->all();
    }

    private function categoria(string $nome): ?int
    {
        return $this->cat[$nome]->id ?? null;
    }

    private function criarDependentes(): void
    {
        foreach ([
            ['Maria Rosa', 'maria.demo@stabilmoney.test', 'conjuge'],
            ['Lucas Rosa', 'lucas.demo@stabilmoney.test', 'filho'],
        ] as [$nome, $email, $parentesco]) {
            User::create([
                'name' => $nome,
                'email' => $email,
                'password' => Str::random(32),
                'account_owner_id' => $this->titular->id,
                'is_admin' => false,
                'relationship' => $parentesco,
                // Dependente nasce verificado: ninguém lhe manda link de confirmação.
                'email_verified_at' => now(),
            ]);
        }
    }

    private function criarMetodosDePagamento(): void
    {
        $this->corrente = Account::create([
            'user_id' => $this->titular->id,
            'name' => 'Conta Corrente',
            'type' => 'checking',
            'bank' => 'nubank',
            'initial_balance' => 1800,
            'overdraft_limit' => 1500,
        ]);

        $this->poupanca = Account::create([
            'user_id' => $this->titular->id,
            'name' => 'Poupança',
            'type' => 'savings',
            'bank' => 'caixa',
            'initial_balance' => 42000,
            'overdraft_limit' => 0,
        ]);

        $this->cartao = Account::create([
            'user_id' => $this->titular->id,
            'name' => 'Nubank Roxinho',
            'type' => 'credit_card',
            'bank' => 'nubank',
            'credit_limit' => 9000,
            'closing_day' => 25,
            'due_day' => 5,
        ]);

        // Método espelho: não tem saldo próprio, saca da corrente.
        Account::create([
            'user_id' => $this->titular->id,
            'name' => 'Pix Nubank',
            'type' => 'pix',
            'bank' => 'nubank',
            'checking_account_id' => $this->corrente->id,
        ]);

        Account::create([
            'user_id' => $this->titular->id,
            'name' => 'Débito Nubank',
            'type' => 'debit_card',
            'bank' => 'nubank',
            'checking_account_id' => $this->corrente->id,
            'savings_account_id' => $this->poupanca->id,
        ]);
    }

    /** Grava uma transação já com dono, autor e conta resolvidos. */
    private function lancar(array $dados): Transaction
    {
        return Transaction::create(array_merge([
            'user_id' => $this->titular->id,
            'made_by_user_id' => $this->titular->id,
            'account_id' => $this->corrente->id,
            'paid_at' => $dados['date'] ?? now(),
        ], $dados));
    }

    /**
     * Seis meses de movimentação, do mais antigo até hoje.
     *
     * As despesas do mês somam menos que as receitas — o objetivo é uma tela
     * plausível, e uma família no vermelho todo mês não é o caso comum que se
     * quer ver ao abrir o app.
     */
    private function criarHistorico(): void
    {
        $familia = User::where('account_owner_id', $this->titular->id)->orderBy('id')->get();
        $maria = $familia->firstWhere('relationship', 'conjuge');
        $lucas = $familia->firstWhere('relationship', 'filho');

        $hoje = Carbon::today();

        for ($voltar = 5; $voltar >= 0; $voltar--) {
            $mes = $hoje->copy()->subMonthsNoOverflow($voltar)->startOfMonth();
            $ultimoDia = min($mes->daysInMonth, $voltar === 0 ? $hoje->day : $mes->daysInMonth);

            $dia = fn (int $d) => $mes->copy()->setDay(min($d, $ultimoDia))->toDateString();
            $cabe = fn (int $d) => $d <= $ultimoDia;

            // ---------- Receitas ----------
            if ($cabe(5)) {
                $this->lancar([
                    'type' => 'income', 'amount' => 7480.00, 'date' => $dia(5),
                    'category_id' => $this->categoria('Salário'),
                    'description' => 'Salário',
                ]);
                $this->lancar([
                    'type' => 'income', 'amount' => 4260.00, 'date' => $dia(5),
                    'category_id' => $this->categoria('Salário'),
                    'description' => 'Salário Maria',
                    'made_by_user_id' => $maria?->id ?? $this->titular->id,
                ]);
            }

            // Freelance em meses alternados — receita que não é todo mês deixa
            // o gráfico com relevo, em vez de seis barras idênticas.
            if ($voltar % 2 === 1 && $cabe(18)) {
                $this->lancar([
                    'type' => 'income', 'amount' => 1200.00 + $voltar * 130, 'date' => $dia(18),
                    'category_id' => $this->categoria('Freelance'),
                    'description' => 'Projeto freelance',
                ]);
            }

            // ---------- Despesas na conta ----------
            $naConta = [
                [3, 'Contas', 'Internet e telefone', 139.90],
                [4, 'Educação', 'Escola do Lucas', 980.00],
                [6, 'Contas', 'Água', 94.30],
                [8, 'Alimentação', 'Supermercado', 1180.00 + $voltar * 18],
                [9, 'Saúde', 'Academia', 189.00],
                [12, 'Transporte', 'Combustível', 412.60],
                [13, 'Contas', 'Gás', 128.00],
                [16, 'Saúde', 'Plano de saúde', 892.00],
                [17, 'Alimentação', 'Restaurantes', 386.40],
                [21, 'Alimentação', 'Feira e padaria', 248.50],
                [22, 'Saúde', 'Farmácia', 143.80],
                [24, 'Compras', 'Casa e limpeza', 264.90],
                [26, 'Transporte', 'Aplicativo de transporte', 196.40],
                [27, 'Lazer', 'Passeio em família', 318.00],
            ];

            foreach ($naConta as [$d, $categoria, $descricao, $valor]) {
                if (! $cabe($d)) {
                    continue;
                }
                $this->lancar([
                    'type' => 'expense', 'amount' => $valor, 'date' => $dia($d),
                    'category_id' => $this->categoria($categoria),
                    'description' => $descricao,
                ]);
            }

            // ---------- Despesas no cartão ----------
            // Meses anteriores já foram pagos; o mês corrente fica em aberto,
            // que é o que faz /faturas e o sino terem o que mostrar.
            $pago = $voltar > 0;

            $noCartao = [
                [7, 'Compras', 'Farmácia', 118.70, $this->titular->id],
                [11, 'Lazer', 'Streaming e cinema', 154.90, $maria?->id],
                [14, 'Alimentação', 'Delivery', 187.30, $lucas?->id],
                [19, 'Educação', 'Curso de inglês', 320.00, $lucas?->id],
                [23, 'Compras', 'Roupas', 279.90, $maria?->id],
            ];

            foreach ($noCartao as [$d, $categoria, $descricao, $valor, $autor]) {
                if (! $cabe($d)) {
                    continue;
                }
                $this->lancar([
                    'type' => 'expense', 'amount' => $valor, 'date' => $dia($d),
                    'account_id' => $this->cartao->id,
                    'category_id' => $this->categoria($categoria),
                    'description' => $descricao,
                    'made_by_user_id' => $autor ?? $this->titular->id,
                    'paid_at' => $pago ? $dia(min(28, $ultimoDia)) : null,
                ]);
            }
        }

        $this->criarParcelamento($hoje);
        $this->criarSemanaCorrente($hoje, $maria, $lucas);
    }

    /**
     * Movimento nos ÚLTIMOS SETE DIAS.
     *
     * O dashboard abre em "Semana", e o calendário não colabora: rodar o seeder
     * no dia 2 deixaria a tela de entrada com quatro zeros e um gráfico em
     * branco — a pior primeira impressão possível de um app que existe para
     * mostrar movimento. Estas linhas são ancoradas em HOJE, não no mês.
     */
    private function criarSemanaCorrente(Carbon $hoje, ?User $maria, ?User $lucas): void
    {
        $itens = [
            [0, 'expense', 'Alimentação', 'Padaria', 38.60, $this->corrente, $this->titular],
            [1, 'expense', 'Transporte', 'Combustível', 220.00, $this->corrente, $this->titular],
            [1, 'expense', 'Alimentação', 'Almoço', 62.90, $this->cartao, $maria],
            [2, 'expense', 'Compras', 'Papelaria', 74.30, $this->cartao, $lucas],
            // Em HOJE, e não "há N dias": a semana do gráfico é segunda a domingo,
            // então qualquer deslocamento maior cai fora dela quando o seeder roda
            // no começo da semana — e o card de Receitas abre zerado.
            [0, 'income', 'Freelance', 'Ajuste de projeto', 850.00, $this->corrente, $this->titular],
            [4, 'expense', 'Lazer', 'Cinema', 96.00, $this->cartao, $maria],
            [1, 'income', 'Outros', 'Reembolso', 214.00, $this->corrente, $maria],
            [5, 'expense', 'Alimentação', 'Supermercado', 412.70, $this->corrente, $this->titular],
            [6, 'expense', 'Saúde', 'Consulta', 280.00, $this->corrente, $this->titular],
        ];

        foreach ($itens as [$atras, $tipo, $categoria, $descricao, $valor, $conta, $autor]) {
            $data = $hoje->copy()->subDays($atras)->toDateString();

            $this->lancar([
                'type' => $tipo, 'amount' => $valor, 'date' => $data,
                'account_id' => $conta->id,
                'category_id' => $this->categoria($categoria),
                'description' => $descricao,
                'made_by_user_id' => ($autor ?? $this->titular)->id,
                // Compra no cartão nasce EM ABERTO: é o que alimenta a fatura
                // do ciclo atual e o consumo de limite.
                'paid_at' => $conta->isCard() ? null : $data,
            ]);
        }
    }

    /**
     * Uma compra parcelada em 8x — o `client_uuid` fica SÓ na primeira parcela
     * (o índice único é `(user_id, client_uuid)`), e as parcelas futuras nascem
     * não pagas, então continuam consumindo limite do cartão.
     */
    private function criarParcelamento(Carbon $hoje): void
    {
        // Oito parcelas começando há cinco meses: sobram duas ou três no futuro.
        // Com dez parcelas começando há dois meses, o Histórico (ordenado por
        // data decrescente) abria com uma parede de oito "Notebook" idênticos
        // antes de qualquer movimento real.
        $grupo = (string) Str::uuid();
        $inicio = $hoje->copy()->subMonthsNoOverflow(5)->setDay(9);

        for ($n = 1; $n <= 8; $n++) {
            $data = $inicio->copy()->addMonthsNoOverflow($n - 1);
            $jaVenceu = $data->lessThan($hoje->copy()->startOfMonth());

            $this->lancar([
                'type' => 'expense', 'amount' => 389.90, 'date' => $data->toDateString(),
                'account_id' => $this->cartao->id,
                'category_id' => $this->categoria('Compras'),
                'description' => 'Notebook',
                'group_id' => $grupo,
                'installment_no' => $n,
                'installments' => 8,
                'client_uuid' => $n === 1 ? (string) Str::uuid() : null,
                'paid_at' => $jaVenceu ? $data->toDateString() : null,
            ]);
        }
    }

    /**
     * Contas fixas. As competências NÃO são materializadas — elas são
     * projetadas pelo FixedBillService a partir de `starts_on`. Por isso basta
     * cadastrar; a tela de /faturas já mostra o mês corrente e o que venceu.
     */
    private function criarContasFixas(): void
    {
        $inicio = Carbon::today()->subMonthsNoOverflow(5)->startOfMonth()->toDateString();

        foreach ([
            ['Aluguel', 1850.00, 10, 'Moradia'],
            ['Condomínio', 470.00, 5, 'Moradia'],
            ['Energia elétrica', 236.00, 20, 'Contas'],
            ['Parcela do carro', 890.00, 15, 'Transporte'],
        ] as [$nome, $valor, $dia, $categoria]) {
            FixedBill::create([
                'user_id' => $this->titular->id,
                'made_by_user_id' => $this->titular->id,
                'name' => $nome,
                'amount' => $valor,
                'due_day' => $dia,
                'account_id' => $this->corrente->id,
                'category_id' => $this->categoria($categoria),
                'starts_on' => $inicio,
                'active' => true,
            ]);
        }
    }

    /**
     * Paga as competências passadas das contas fixas.
     *
     * As competências são PROJETADAS (FixedBillService), não materializadas: só
     * existe linha em `transactions` quando alguém paga. Sem este passo o
     * aluguel apareceria eternamente em aberto e o saldo da corrente subiria
     * como se a família não tivesse onde morar.
     *
     * A energia do mês passado fica de fora de propósito — é ela que dá à tela
     * uma conta VENCIDA, com selo vermelho e aviso no sino.
     */
    private function pagarContasFixasPassadas(): void
    {
        $hoje = Carbon::today();
        $mesAtual = $hoje->copy()->startOfMonth();

        foreach (FixedBill::where('user_id', $this->titular->id)->get() as $conta) {
            for ($voltar = 5; $voltar >= 1; $voltar--) {
                $competencia = $mesAtual->copy()->subMonthsNoOverflow($voltar);

                if ($conta->name === 'Energia elétrica' && $voltar === 1) {
                    continue;
                }

                // Dia do vencimento, respeitando mês curto (o clamp de 1..31 é
                // feito em PHP, como no FixedBillService).
                $vencimento = $competencia->copy()
                    ->setDay(min($conta->due_day, $competencia->daysInMonth));

                $this->lancar([
                    'type' => 'expense',
                    // Luz e água variam: o previsto é o cadastro, o real vai na
                    // transação. Uma variação de alguns por cento deixa isso à vista.
                    'amount' => round((float) $conta->amount * (1 + ($voltar % 3 - 1) * 0.04), 2),
                    'date' => $vencimento->toDateString(),
                    'account_id' => $conta->account_id,
                    'category_id' => $conta->category_id,
                    'description' => $conta->name,
                    'fixed_bill_id' => $conta->id,
                    'competence' => $competencia->toDateString(),
                ]);
            }
        }
    }

    /**
     * Metas e investimentos saem da POUPANÇA, não da corrente.
     *
     * Aporte reserva dinheiro: `available = balance − reserved`. Reservar tudo
     * na corrente deixaria o "Saldo em conta" perto de zero e todo lançamento
     * novo cairia no 409 de escolha de fonte — o oposto de uma tela de demo.
     */
    private function criarMetasEInvestimentos(): void
    {
        $hoje = Carbon::today();

        $metas = [
            ['Viagem para o Nordeste', '🏖️', '#18B6BE', 9000, 3400, 4],
            ['Reserva de emergência', '🛟', '#1C9A70', 24000, 8600, 12],
        ];

        foreach ($metas as [$nome, $emoji, $cor, $alvo, $guardado, $mesesAlvo]) {
            $meta = Goal::create([
                'user_id' => $this->titular->id,
                'made_by_user_id' => $this->titular->id,
                'name' => $nome,
                'emoji' => $emoji,
                'color' => $cor,
                'target_amount' => $alvo,
                'target_date' => $hoje->copy()->addMonthsNoOverflow($mesesAlvo)->toDateString(),
            ]);

            // Quatro aportes mensais em vez de um só: é o que dá história ao
            // extrato da meta. Datas passadas — aporte futuro é recusado.
            $porAporte = round($guardado / 4, 2);
            for ($i = 3; $i >= 0; $i--) {
                DB::table('goal_contributions')->insert([
                    'goal_id' => $meta->id,
                    'account_id' => $this->poupanca->id,
                    'made_by_user_id' => $this->titular->id,
                    'type' => 'aporte',
                    // A última parcela absorve o arredondamento das outras três.
                    'amount' => $i === 0 ? round($guardado - $porAporte * 3, 2) : $porAporte,
                    // Grampeado em HOJE: o dia 6 do mês corrente ainda não
                    // chegou quando o seeder roda no começo do mês, e aporte com
                    // data futura é justamente o que os cinco Form Requests
                    // recusam — a demo não pode gravar o que o app proíbe.
                    'date' => min(
                        $hoje->copy()->subMonthsNoOverflow($i)->setDay(6),
                        $hoje,
                    )->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $investimentos = [
            ['CDB Banco Inter', 'renda_fixa', 'CDI', 112.0, 15000],
            ['Tesouro Selic 2029', 'renda_fixa', 'Selic', 100.0, 7500],
            ['ETF IVVB11', 'renda_variavel', null, null, 4200],
        ];

        foreach ($investimentos as [$nome, $classe, $indexador, $taxa, $aplicado]) {
            $inv = Investment::create([
                'user_id' => $this->titular->id,
                'made_by_user_id' => $this->titular->id,
                'name' => $nome,
                'classe' => $classe,
                'indexador' => $indexador,
                'taxa' => $taxa,
            ]);

            DB::table('investment_contributions')->insert([
                'investment_id' => $inv->id,
                'account_id' => $this->poupanca->id,
                'made_by_user_id' => $this->titular->id,
                'type' => 'aporte',
                'amount' => $aplicado,
                'date' => $hoje->copy()->subMonthsNoOverflow(4)->setDay(12)->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
