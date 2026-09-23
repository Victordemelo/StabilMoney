<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Support\AdminAudit;
use App\Support\BrowserSessions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * As ações do painel que MEXEM na conta de alguém: banir, desbanir e excluir.
 *
 * Separado das telas de leitura de propósito — é aqui que mora todo o poder do painel,
 * e concentrá-lo num arquivo só deixa óbvio o que precisa de auditoria, confirmação e
 * cuidado. Toda ação daqui passa por `AdminAudit` (log + e-mail).
 */
class ModeracaoController extends Controller
{
    /**
     * Banir: bloqueia o acesso, preserva os dados.
     *
     * Banir o TITULAR alcança os dependentes (eles veem os mesmos dados da família —
     * deixá-los entrar tornaria o banimento decorativo). Banir um dependente afeta só ele.
     */
    public function banir(Request $request, User $user)
    {
        $dados = $request->validate(
            ['motivo' => ['required', 'string', 'min:5', 'max:500']],
            ['motivo.required' => 'Diga o motivo do banimento — ele fica no histórico.'],
            ['motivo' => 'motivo'],
        );

        if ($user->estaBanidoDiretamente()) {
            return back()->with('status', 'Esta conta já estava banida.');
        }

        $admin = $request->user('admin');

        DB::transaction(function () use ($user, $dados, $admin) {
            $user->forceFill([
                'banned_at' => now(),
                'banned_reason' => $dados['motivo'],
                'banned_by_admin_id' => $admin->id,
            ])->save();

            // Derruba quem já está logado AGORA. O middleware `BloqueiaUsuarioBanido`
            // fecha o resto (inclusive o remember-me, que recriaria a sessão sozinho).
            foreach ($this->alcancados($user) as $id) {
                BrowserSessions::purgeForUser($id);
            }
        });

        AdminAudit::registrar(AdminAuditLog::BANIU, $admin, $request, $user, $dados['motivo']);

        return back()->with('status', $user->name.' foi banido e não consegue mais entrar.');
    }

    public function desbanir(Request $request, User $user)
    {
        if (! $user->estaBanidoDiretamente()) {
            return back()->with('status', 'Esta conta não estava banida.');
        }

        $admin = $request->user('admin');

        $user->forceFill([
            'banned_at' => null,
            'banned_reason' => null,
            'banned_by_admin_id' => null,
        ])->save();

        AdminAudit::registrar(AdminAuditLog::DESBANIU, $admin, $request, $user);

        return back()->with('status', $user->name.' voltou a ter acesso.');
    }

    /**
     * Excluir de vez — irreversível.
     *
     * Reaproveita o hook `deleting` do `User`, que é quem apaga a foto do disco e as
     * linhas de `sessions` (o cascade da FK não alcança nem uma coisa nem outra) e
     * remove os dependentes um a um para as fotos DELES não ficarem órfãs.
     *
     * A confirmação por digitação do e-mail existe porque esta é a única ação do painel
     * sem volta: um clique errado numa lista apaga a vida financeira de uma família.
     *
     * Os dependentes vão junto, e hoje ninguém os avisa por aqui (ao contrário da exclusão
     * feita pelo próprio titular, em ProfileController::destroy). Avisar ou não numa
     * exclusão por moderação é decisão pendente (moderação/LGPD), não esquecimento.
     */
    public function excluir(Request $request, User $user)
    {
        $request->validate(
            ['confirmacao' => ['required', 'string']],
            ['confirmacao.required' => 'Digite o e-mail da pessoa para confirmar.'],
        );

        if (! hash_equals($user->email, trim((string) $request->input('confirmacao')))) {
            throw ValidationException::withMessages([
                'confirmacao' => 'O e-mail digitado não confere com o da conta.',
            ]);
        }

        $admin = $request->user('admin');

        // A descrição é capturada ANTES do delete: depois, o id não identifica ninguém
        // e o histórico ficaria com uma linha muda.
        // Pelo `descreverAlvo`, e não montada à mão: com nome longo o corte levava justamente
        // o E-MAIL embora — e este é o único registro que sobra de uma exclusão sem volta.
        // Lá o nome encolhe e o e-mail fica inteiro.
        $descricao = AdminAudit::descreverAlvo($user);

        // O delete e a linha do histórico numa transação só: os dois ou nenhum. O histórico
        // é a única prova de uma exclusão sem volta — antes, uma falha ao gravá-lo deixava
        // a família apagada SEM registro; e uma falha no meio do delete (o 2º dependente,
        // por exemplo) deixava a família pela metade. O que não volta atrás fica para
        // depois do commit: a foto do disco (hook do User) e o e-mail do AdminAudit.
        //
        // Sem `attempts`: repetir o closure reusaria models que a tentativa desfeita já
        // marcou como apagados, e o `delete()` deles viraria no-op.
        try {
            DB::transaction(function () use ($user, $admin, $request, $descricao) {
                $user->delete();

                // O model ainda carrega o id depois do delete: é ele que vai em
                // `target_user_id` (sem FK, para a linha sobreviver ao alvo).
                AdminAudit::registrar(
                    AdminAuditLog::EXCLUIU,
                    $admin,
                    $request,
                    $user,
                    alvoDescricao: $descricao,
                );
            });
        } catch (\Throwable $e) {
            // Antes a falha subia como um 500 sem explicação. A exceção continua indo para
            // o log; o admin recebe o que aconteceu, no mesmo tom do ProfileController::destroy.
            report($e);

            // "Nada foi apagado" só se for verdade. O rollback devolve tudo o que é de banco,
            // e a foto e o e-mail rodam depois do commit — mas justamente por isso uma falha
            // vinda deles chegaria aqui com a exclusão JÁ feita, e o admin tentaria de novo
            // apagar quem não existe mais. Confere-se no banco, não se supõe.
            if (User::whereKey($user->getKey())->exists()) {
                throw ValidationException::withMessages([
                    'excluir' => 'Não conseguimos excluir esta conta agora, e nada foi apagado. Tente de novo em instantes.',
                ]);
            }
        }

        return redirect()->route('painel.pessoas')
            ->with('status', 'Conta de '.$descricao.' excluída definitivamente.');
    }

    /**
     * Ids que o banimento alcança: a própria pessoa e, se for titular, os dependentes.
     *
     * @return list<int>
     */
    private function alcancados(User $user): array
    {
        $ids = [$user->id];

        if ($user->isTitular()) {
            $ids = [...$ids, ...$user->dependents()->pluck('id')->all()];
        }

        return $ids;
    }
}
