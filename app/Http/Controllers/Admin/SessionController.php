<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Support\AdminAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Login e logout do painel.
 *
 * O login aqui é só o PRIMEIRO fator: entrar com a senha certa não dá acesso a nada —
 * o middleware `ExigeDoisFatoresDoAdmin` empurra para o TOTP antes de qualquer tela.
 */
class SessionController extends Controller
{
    public function create(Request $request)
    {
        // Já autenticado: segue o fluxo (setup ou desafio do 2FA, ou o painel) em vez
        // de mostrar o formulário de novo.
        if ($request->user('admin')) {
            return redirect()->route('painel.home');
        }

        return view('admin.auth.login');
    }

    public function store(Request $request)
    {
        $dados = $request->validate(
            [
                'email' => ['required', 'string', 'email', 'max:255'],
                'password' => ['required', 'string'],
            ],
            [],
            ['email' => 'e-mail', 'password' => 'senha'],
        );

        $admin = Admin::where('email', $dados['email'])->first();

        if ($admin) {
            $senhaOk = Hash::check($dados['password'], $admin->password);
        } else {
            // E-mail inexistente também paga o preço de um argon2id (64 MiB, t=4). Sem
            // isto, a resposta instantânea denunciaria quais e-mails NÃO existem, e o
            // painel viraria um oráculo de enumeração. O custo é contido pelo
            // throttle `painel-login`.
            Hash::make($dados['password']);
            $senhaOk = false;
        }

        if (! $senhaOk) {
            AdminAudit::registrar(
                AdminAuditLog::LOGIN_FALHOU,
                null,
                $request,
                alvoDescricao: $dados['email'],
            );

            // Mensagem única: não revela se o e-mail existe.
            throw ValidationException::withMessages([
                'email' => 'Credenciais inválidas.',
            ]);
        }

        Auth::guard('admin')->login($admin);

        // Sessão nova: mata fixation e, de quebra, garante que `admin_2fa_ok` não
        // sobrevive de uma sessão anterior.
        $request->session()->regenerate();
        $request->session()->forget('admin_2fa_ok');

        return redirect()->route('painel.home');
    }

    public function destroy(Request $request)
    {
        Auth::guard('admin')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('painel.login');
    }
}
