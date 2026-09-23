<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalContribution;
use App\Models\Investment;
use App\Models\InvestmentContribution;
use App\Support\Brl;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccountController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        $accounts = Account::with(['linkedChecking', 'linkedSavings'])
            // Cada conta já vem sabendo se tem histórico: são 3 subconsultas
            // DENTRO da mesma query, e é isso que trava o tipo no modal de edição.
            // Perguntar `hasMoneyHistory()` card a card seriam 3 queries por conta
            // dentro do laço — exatamente o N+1 que o `preloadMoney` matou.
            ->withExists(['transactions', 'goalContributions', 'investmentContributions'])
            ->where('user_id', $request->user()->ownerId())
            ->orderBy('name')
            ->get();

        // Saldo/reservado/comprometido de todas as contas em 4 queries agregadas.
        // Sem isto, cada card da tela dispara ~6 consultas (2 SUM em `balance`,
        // 4 em `reserved`, 1 em `committed`): 30 contas = ~195 queries.
        Account::preloadMoney($accounts);

        return view('accounts.index', [
            'accounts' => $accounts,
            // A tela agora traz os formulários (um modal para criar, um por conta
            // para editar), então precisa dos mesmos dados de `formData()`. As
            // opções de vínculo (débito/Pix) saem das contas já carregadas — sem
            // query nova, e na mesma ordem alfabética.
            'types' => Account::TYPES,
            'banks' => Account::BANKS,
            'checkingAccounts' => $accounts->where('type', 'checking')->values(),
            'savingsAccounts' => $accounts->where('type', 'savings')->values(),
            'travados' => $accounts
                ->filter(fn (Account $conta) => $this->temHistorico($conta))
                ->pluck('id')
                ->all(),
            // Quem espelha cada conta (débito/Pix): com algum, o tipo dela também
            // fica travado no modal, e o aviso diz qual método depende dela.
            'espelhosPorConta' => $this->espelhosPorConta($accounts),
        ]);
    }

    /**
     * [id da conta => métodos espelho que tiram dinheiro dela], montado com as
     * contas que a listagem JÁ carregou. O vínculo só aponta para conta da mesma
     * família (`StoreAccountRequest::linkRule`), então todas estão na lista — e
     * perguntar `metodosQueEspelham()` card a card seria uma query por conta.
     *
     * @param  Collection<int, Account>  $accounts
     * @return array<int, Collection<int, Account>>
     */
    private function espelhosPorConta(Collection $accounts): array
    {
        $porConta = [];

        foreach ($accounts as $metodo) {
            if (! $metodo->espelhaConta()) {
                continue;
            }

            $alvos = array_unique(array_filter([$metodo->checking_account_id, $metodo->savings_account_id]));

            foreach ($alvos as $alvo) {
                $porConta[(int) $alvo] ??= collect();
                $porConta[(int) $alvo]->push($metodo);
            }
        }

        return $porConta;
    }

    /**
     * Mesma pergunta do `Account::hasMoneyHistory()`, respondida com o que o
     * `withExists` do index já trouxe — para o laço dos cards não disparar
     * consulta nenhuma.
     */
    private function temHistorico(Account $conta): bool
    {
        return (float) $conta->initial_balance > 0
            || (bool) $conta->transactions_exists
            || (bool) $conta->goal_contributions_exists
            || (bool) $conta->investment_contributions_exists;
    }

    public function create(Request $request)
    {
        return view('accounts.create', $this->formData($request->user()->ownerId()));
    }

    public function store(StoreAccountRequest $request)
    {
        $data = $request->validated();
        $data['user_id'] = $request->user()->ownerId();

        $account = Account::create($data);

        // O modal da lista envia por AJAX (Accept: application/json) e recarrega
        // o conteúdo sozinho; a página cheia continua redirecionando. Os erros de
        // validação já saem em 422 JSON pelo próprio Form Request.
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'id' => $account->id,
                'message' => 'Conta criada com sucesso.',
                'redirect' => route('accounts.index'),
            ], 201);
        }

        return redirect()->route('accounts.index')
            ->with('status', 'Conta criada com sucesso.');
    }

    public function edit(Account $account)
    {
        $this->authorize('update', $account);

        return view('accounts.edit', $this->formData($account->user_id) + [
            'account' => $account,
        ]);
    }

    /**
     * Dados comuns dos formulários: tipos, bancos e as contas corrente/poupança
     * da família (opções de vínculo do cartão de débito).
     */
    private function formData(int $ownerId): array
    {
        return [
            'types' => Account::TYPES,
            'banks' => Account::BANKS,
            'checkingAccounts' => Account::where('user_id', $ownerId)->where('type', 'checking')->orderBy('name')->get(),
            'savingsAccounts' => Account::where('user_id', $ownerId)->where('type', 'savings')->orderBy('name')->get(),
        ];
    }

    public function update(UpdateAccountRequest $request, Account $account)
    {
        $this->authorize('update', $account);

        $dados = $request->validated();

        // Segunda linha de defesa (a 1ª é o UpdateAccountRequest): trocar a
        // CLASSE do tipo — caixa ↔ cartão — numa conta que já tem dinheiro faz
        // saldo desaparecer ou contar em dobro; trocar QUALQUER tipo de uma conta
        // que um débito/Pix espelha deixa o método lançando no lugar errado.
        if ($erro = $account->travaDeTipo($dados['type'] ?? null)) {
            if ($request->expectsJson()) {
                // Mesmo formato do 422 do Form Request: quem envia pelo modal lê
                // `errors` sem precisar saber de onde a recusa veio.
                return response()->json([
                    'message' => $erro,
                    'errors' => ['type' => [$erro]],
                ], 422);
            }

            return back()->withInput()->withErrors(['type' => $erro]);
        }

        // Nunca apagar o saldo inicial de uma conta que já carrega dinheiro:
        // `prepareForValidation` zera esse campo fora de corrente/poupança, e
        // NULL aqui seria irreversível (o valor original se perde).
        if ($account->hasMoneyHistory()
            && ($dados['initial_balance'] ?? null) === null
            && $account->initial_balance !== null) {
            unset($dados['initial_balance']);
        }

        $account->update($dados);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'id' => $account->id,
                'message' => 'Conta atualizada.',
                'redirect' => route('accounts.index'),
            ]);
        }

        return redirect()->route('accounts.index')
            ->with('status', 'Conta atualizada.');
    }

    /**
     * Mensagem PT-BR quando algum cartão de débito ou Pix da família tira dinheiro desta
     * conta — ou null quando ela pode sair. Nomeia os métodos: "excluir não deixa" sem dizer
     * QUEM depende dela manda a pessoa caçar o vínculo na lista.
     */
    private function metodosQueImpedemExclusao(Account $conta): ?string
    {
        $metodos = $conta->metodosQueEspelham();

        if ($metodos->isEmpty()) {
            return null;
        }

        $nomes = $metodos
            ->map(fn (Account $m) => $m->typeLabel().' "'.$m->name.'"')
            ->join(', ', ' e ');
        $um = $metodos->count() === 1;

        return 'Esta conta não pode ser excluída: '.$nomes.($um ? ' tira' : ' tiram').' dinheiro dela. '
            .'Sem ela, '.($um ? 'esse método ficaria' : 'esses métodos ficariam').' sem conta — e '
            .($um ? 'sumiria' : 'sumiriam').' da lista de pagamento. Edite '.($um ? 'o método' : 'cada método')
            .' para usar outra conta (ou exclua-'.($um ? 'o' : 'os').') e depois exclua esta.';
    }

    public function destroy(Account $account)
    {
        $this->authorize('delete', $account);

        // Checagem e exclusão sob o MESMO lock da conta — o primeiro elo da ordem
        // conta → pai que aportes, resgates e o FundingService usam. Um aporte feito
        // a partir desta conta no meio do caminho espera o commit e, quando entra,
        // não acha mais a conta (`lockAccount` responde com erro de validação), em
        // vez de reservar dinheiro numa linha que está sendo apagada.
        $recusa = DB::transaction(function () use ($account): ?string {
            $conta = Account::whereKey($account->id)->lockForUpdate()->first();

            if (! $conta) {
                return null; // outra aba já excluiu: nada a fazer
            }

            // Conta de onde um cartão de débito ou um Pix tira o dinheiro não sai: a FK
            // deles é nullOnDelete, e o método ficaria órfão — sumindo em silêncio do select
            // de pagamento (`paymentOptions` pula quem não espelha conta nenhuma) — ou, com a
            // outra conta vinculada, passaria a sacar dela sem ninguém ter pedido. A mesma
            // regra da troca de tipo (`travaDeEspelho`): a saída é mexer no método primeiro.
            if ($motivo = $this->metodosQueImpedemExclusao($conta)) {
                return $motivo;
            }

            // A FK de transactions é cascadeOnDelete: excluir a conta apagaria
            // todo o histórico junto. Bloqueamos aqui para o usuário não perder
            // transações sem querer.
            if ($conta->transactions()->exists()) {
                return 'Esta conta possui transações e não pode ser excluída. Exclua (ou mova) as transações dela primeiro.';
            }

            if ($motivo = $this->dinheiroGuardadoQueImpedeExclusao($conta)) {
                return $motivo;
            }

            // Tudo zerado, meta a meta e investimento a investimento: os aportes e
            // resgates desta conta se anulam DENTRO de cada um, então apagá-los não
            // muda o guardado de meta nenhuma nem o aplicado de investimento nenhum.
            // Precisam sair antes da conta: a FK deles é restrictOnDelete.
            GoalContribution::where('account_id', $conta->id)->delete();
            InvestmentContribution::where('account_id', $conta->id)->delete();
            $conta->delete();

            return null;
        });

        if ($recusa) {
            return back()->withErrors(['account' => $recusa]);
        }

        return redirect()->route('accounts.index')
            ->with('status', 'Conta removida.');
    }

    /**
     * O que ainda está guardado A PARTIR desta conta, meta a meta e investimento a
     * investimento — ou null quando tudo se anulou e a conta pode sair.
     *
     * Antes o `destroy` recusava qualquer conta que JÁ TIVESSE TIDO um aporte, e a
     * mensagem mandava "resgatar o que está guardado por ela primeiro". Só que
     * resgatar não apaga o aporte (cria outra linha, de resgate), então a saída
     * prometida não funcionava: a conta nunca mais podia ser excluída.
     *
     * A régua é o líquido POR META/INVESTIMENTO, não o reservado total da conta:
     * +R$ 100 numa meta e −R$ 100 em outra (dado antigo, de antes do resgate ficar
     * preso à conta de origem) somam zero, mas apagar essas linhas mudaria o
     * guardado das duas metas.
     */
    private function dinheiroGuardadoQueImpedeExclusao(Account $conta): ?string
    {
        $liquido = fn ($query, string $pai) => $query
            ->where('account_id', $conta->id)
            ->groupBy($pai)
            ->selectRaw($pai)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'aporte' THEN amount ELSE -amount END), 0) AS total")
            ->pluck('total', $pai)
            ->map(fn ($total) => round((float) $total, 2))
            ->filter(fn (float $total) => abs($total) > 0.001);

        $metas = $liquido(GoalContribution::query(), 'goal_id');
        $investimentos = $liquido(InvestmentContribution::query(), 'investment_id');

        if ($metas->isEmpty() && $investimentos->isEmpty()) {
            return null;
        }

        // Nomes escopados na família da conta: a mensagem nunca cita cofrinho alheio.
        $nomeDaMeta = Goal::where('user_id', $conta->user_id)->whereIn('id', $metas->keys())->pluck('name', 'id');
        $nomeDoInvestimento = Investment::where('user_id', $conta->user_id)->whereIn('id', $investimentos->keys())->pluck('name', 'id');

        $guardado = [];   // saiu desta conta e continua lá: volta com um RESGATE
        $aMais = [];      // voltou para ela mais do que saiu (dado antigo): acerta com um APORTE

        foreach ([[$metas, $nomeDaMeta, 'na meta'], [$investimentos, $nomeDoInvestimento, 'no investimento']] as [$totais, $nomes, $onde]) {
            foreach ($totais as $id => $total) {
                $cofrinho = $onde.' "'.($nomes[$id] ?? 'sem nome').'"';

                if ($total > 0) {
                    $guardado[] = Brl::format($total).' '.$cofrinho;
                } else {
                    $aMais[] = Brl::format(-$total).' a mais '.$cofrinho;
                }
            }
        }

        // A tela onde a saída fica: a de Metas, a de Investimentos, ou as duas.
        $telas = match (true) {
            $metas->isEmpty() => 'na tela Investimentos',
            $investimentos->isEmpty() => 'na tela Metas',
            default => 'nas telas Metas e Investimentos',
        };

        $frases = [];

        if ($guardado) {
            $frases[] = 'Esta conta ainda tem dinheiro guardado a partir dela: '.Arr::join($guardado, ', ', ' e ').'. '
                .'Resgate esse valor de volta para esta conta ('.$telas.') e depois exclua.';
        }

        if ($aMais) {
            $frases[] = 'Esta conta recebeu em resgates mais do que aportou: '.Arr::join($aMais, ', ', ' e ').'. '
                .'Faça um aporte desse valor a partir desta conta ('.$telas.') para zerar e depois exclua.';
        }

        return implode(' ', $frases);
    }
}
