<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDependentRequest;
use App\Http\Requests\UpdateDependentRequest;
use App\Mail\AlertaDeSeguranca;
use App\Mail\BemVindoDependente;
use App\Models\User;
use App\Support\BrowserSessions;
use App\Support\ContextoDeSeguranca;
use App\Support\Notificador;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Gerenciamento de dependentes — apenas o titular (account_owner_id null) acessa.
 * Dependente é um User com account_owner_id apontando para o titular; compartilha
 * a visão financeira da família. Cada dependente pode ter um saldo/limite de
 * gasto: as despesas que ELE lança (made_by_user_id) descontam desse valor.
 */
class DependentController extends Controller
{
    public function index(Request $request)
    {
        $titular = $request->user();
        abort_unless($titular->isTitular(), 403);

        // `gasto` = soma das DESPESAS do MÊS CORRENTE lançadas por cada pessoa
        // (made_by_user_id), pré-agregada para evitar N+1 ao montar os cards.
        // Transferência entre contas não é gasto de ninguém — fica de fora.
        $mesInicio = now()->startOfMonth()->toDateString();
        $mesFim = now()->endOfMonth()->toDateString();

        $dependents = $titular->dependents()
            ->withSum(['madeTransactions as gasto' => fn ($q) => $q
                ->where('type', 'expense')->whereNull('transfer_group_id')->whereBetween('date', [$mesInicio, $mesFim])], 'amount')
            ->orderBy('name')
            ->get();

        // Quanto o próprio titular gastou no mês (mesma base dos cards).
        $gastoTitular = (float) $titular->madeTransactions()
            ->where('type', 'expense')->whereNull('transfer_group_id')->whereBetween('date', [$mesInicio, $mesFim])->sum('amount');

        // Total da família no mês: é o denominador da fatia de cada pessoa. Sem
        // ele o card mostra um número solto — "R$ 1.590" é muito ou pouco só em
        // relação ao resto, e é essa comparação que a tela existe para dar.
        $gastoFamilia = $gastoTitular + (float) $dependents->sum('gasto');

        return view('dependents.index', compact('dependents', 'gastoTitular', 'gastoFamilia'));
    }

    public function store(StoreDependentRequest $request)
    {
        $titular = $request->user();
        $data = $request->validated();

        // Não dispara Registered: o dependente compartilha as categorias da família.
        $dependent = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'relationship' => $data['relationship'] ?? null,
        ]);
        // Fora do mass assignment de propósito (ver $fillable no model): são os campos
        // de privilégio, e quem decide o valor deles é o servidor, nunca o formulário.
        $dependent->is_admin = false;
        $dependent->account_owner_id = $titular->id;
        $dependent->password = Hash::make($data['password']);

        // Nasce com o e-mail JÁ verificado, sempre — inclusive quando o SMTP estiver
        // configurado. Dependente não passa pelo `/register`, não dispara `Registered`
        // e portanto NINGUÉM lhe envia link de confirmação: quem responde pelo endereço
        // é o titular, que acabou de digitá-lo. Deixar o campo nulo trancaria todos os
        // dependentes fora do app no dia em que alguma rota exigir `verified`
        // (o User implementa MustVerifyEmail — ver o comentário no model).
        $dependent->email_verified_at = now();

        if ($request->hasFile('avatar')) {
            $dependent->storeAvatar($request->file('avatar')); // sem metadados (EXIF/GPS)
        }

        $dependent->save();

        // O dependente é o único usuário que não escolheu se cadastrar — o titular fez
        // isso por ele. Sem este aviso, a pessoa ganha acesso a todo o dinheiro da
        // família e só descobre quando alguém lhe conta. A senha NÃO vai no e-mail
        // (ver BemVindoDependente): o caminho oferecido é o "Esqueci a senha", que é o
        // único que lhe dá uma senha que o titular não conhece.
        Notificador::avisar($dependent, new BemVindoDependente($dependent, $titular));

        return redirect()->route('dependentes')->with('status', 'Dependente adicionado.');
    }

    public function update(UpdateDependentRequest $request, User $dependent)
    {
        // Segunda linha de defesa, com a MESMA regra do `destroy`: só o titular da família do
        // dependente. O `authorize()` do UpdateDependentRequest já barra isso, mas era a única
        // barreira — numa mutação que o removeu, o dependente de outra família foi editado de
        // fato (nome, e-mail de acesso e senha). Uma regra de posse que vive num lugar só some
        // no primeiro refactor desse lugar.
        $titular = $request->user();
        abort_unless($titular->isTitular() && $dependent->account_owner_id === $titular->id, 403);

        $data = $request->validated();

        // O dependente como ele era ANTES desta edição. Serve só para endereçar o aviso de
        // senha trocada (abaixo) — nunca é salvo.
        $antes = clone $dependent;

        $dependent->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'relationship' => $data['relationship'] ?? null,
        ]);

        $trocouEmail = $dependent->isDirty('email');

        // Senha só muda se preenchida. Em branco, a edição é de cadastro (nome, foto,
        // parentesco) e não mexe em sessão nem avisa ninguém.
        $trocouSenha = ! empty($data['password']);

        if ($trocouSenha) {
            $dependent->password = Hash::make($data['password']);

            // A-6 da auditoria de 05/09/2026: era o único caminho de troca de senha do app
            // que não fazia nada do que os outros fazem (PasswordController e
            // NewPasswordController). A tela de Segurança mostra a idade da senha a partir
            // deste carimbo; e o token novo invalida o cookie de "lembrar de mim", que
            // re-autentica SEM sessão nenhuma — apagar as sessões, abaixo, não o alcança.
            $dependent->password_changed_at = now();
            $dependent->setRememberToken(Str::random(60));
        }

        if ($request->hasFile('avatar')) {
            // Apaga a foto antiga e grava a nova sem metadados (EXIF/GPS).
            $dependent->storeAvatar($request->file('avatar'));
        }

        $dependent->save();

        if (! $trocouSenha) {
            return redirect()->route('dependentes')->with('status', 'Dependente atualizado.');
        }

        // Derruba TODAS as sessões do dependente, sem exceção. Quem troca é o titular, na
        // sessão DELE: não há sessão "atual" do dependente a preservar, e uma senha trocada
        // que deixa os aparelhos conectados não tira ninguém de dentro — nem o celular
        // perdido, nem quem já tinha entrado com a senha antiga, que costumam ser o motivo
        // da troca.
        BrowserSessions::purgeForUser($dependent->getKey());

        // Avisa o DEPENDENTE, no e-mail que ele tinha ANTES desta edição. Se o titular trocou
        // senha e e-mail de uma vez, o endereço novo foi o titular que digitou: mandar o aviso
        // para lá deixaria o dependente sem saber de nada justamente no caso mais grave — e
        // tornaria o aviso inútil contra quem tomou a conta do titular, que só precisaria
        // trocar os dois campos no mesmo envio.
        Notificador::avisar($antes, AlertaDeSeguranca::senhaAlteradaPeloTitular(
            $dependent,
            $titular,
            ContextoDeSeguranca::doRequest($request),
            emailNovo: $trocouEmail ? $dependent->email : null,
        ));

        // O titular precisa saber do efeito colateral: sem isto, a reclamação de "fui
        // desconectado do nada" chega sem explicação.
        return redirect()->route('dependentes')->with(
            'status',
            'Dependente atualizado. Com a senha nova, '.$dependent->name.' vai precisar entrar de novo em todos os aparelhos.',
        );
    }

    public function destroy(Request $request, User $dependent)
    {
        $titular = $request->user();
        abort_unless($titular->isTitular() && $dependent->account_owner_id === $titular->id, 403);

        // A foto e as sessões saem no hook `deleting` do User.
        $dependent->delete();

        return redirect()->route('dependentes')->with('status', 'Dependente removido.');
    }
}
