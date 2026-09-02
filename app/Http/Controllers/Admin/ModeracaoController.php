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
        $descricao = $user->name.' <'.$user->email.'>';
        $alvoId = $user->id;

        $user->delete();

        AdminAudit::registrar(
            AdminAuditLog::EXCLUIU,
            $admin,
            $request,
            null,
            null,
            $descricao,
        )->forceFill(['target_user_id' => $alvoId])->save();

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
