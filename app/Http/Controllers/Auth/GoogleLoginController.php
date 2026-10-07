<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\BloqueiaUsuarioBanido;
use App\Models\Atividade;
use App\Models\User;
use App\Support\AparelhoConfiavel;
use App\Support\LoginComGoogle;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * "Entrar com o Google" (out/2026). OPCIONAL: sem GOOGLE_CLIENT_ID e GOOGLE_CLIENT_SECRET no
 * .env as rotas respondem 404 e o botão não aparece (`LoginComGoogle::ativo()`).
 *
 * O Google só substitui a SENHA. Todo o resto do login vale igual:
 * - conta banida não entra (a mesma mensagem de toda porta);
 * - quem ligou o 2FA passa pela tela do código (o Google não é o segundo fator do app);
 * - o aparelho marcado como confiável no 2FA continua dispensando o código.
 *
 * Quem a conta é: primeiro pelo identificador da conta Google (`users.google_id`); sem ele,
 * pelo e-mail — e só se o GOOGLE disse que o e-mail é verificado (quem entra prova que é dono
 * da caixa, que é o mesmo que o "esqueci a senha" já aceita). Conta nova NÃO nasce no retorno
 * do Google: a pessoa vê uma tela com os dados recebidos e precisa aceitar os Termos e a
 * Política (a prova do aceite é a mesma do cadastro). Pedimos só nome e e-mail (`openid email
 * profile`); nunca a foto, os contatos ou qualquer outro dado da conta Google.
 */
class GoogleLoginController extends Controller
{
    /** Onde a sessão guarda o cadastro pendente: ['sub', 'email', 'name', 'em']. */
    public const CHAVE_CADASTRO = 'google_cadastro';

    /** Quanto tempo a tela de confirmação do cadastro vale (como o login pendente do 2FA). */
    private const VALIDADE_DO_CADASTRO_EM_SEGUNDOS = 600;

    public const MENSAGEM_FALHOU = 'Não foi possível entrar com o Google. Tente de novo ou entre com e-mail e senha.';

    public function redirecionar(): RedirectResponse
    {
        abort_unless(LoginComGoogle::ativo(), 404);

        return Socialite::driver('google')
            ->scopes(['openid', 'email', 'profile'])
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    public function retorno(Request $request): RedirectResponse
    {
        abort_unless(LoginComGoogle::ativo(), 404);

        // A pessoa clicou em "Cancelar" na tela do Google: volta sem alarde.
        if ($request->filled('error')) {
            return redirect()->route('login');
        }

        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            // `state` que não confere (aba velha, link copiado), código vencido ou o Google fora
            // do ar. O motivo vai para o log; a pessoa recebe uma mensagem que diz o que fazer.
            Log::warning('Login com o Google falhou no retorno.', ['motivo' => $e::class]);

            return $this->falhou();
        }

        $sub = (string) $google->getId();
        $email = Str::lower(trim((string) $google->getEmail()));
        $verificado = filter_var($google->user['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($sub === '' || $email === '' || ! $verificado) {
            return $this->falhou('Esta conta Google não tem um e-mail confirmado. Entre com e-mail e senha.');
        }

        $user = User::where('google_id', $sub)->first() ?? User::where('email', $email)->first();

        if ($user === null) {
            // Conta nova: os dados ficam na sessão até a pessoa aceitar os Termos.
            $request->session()->put(self::CHAVE_CADASTRO, [
                'sub' => $sub,
                'email' => $email,
                'name' => Str::limit(trim((string) $google->getName()) ?: Str::before($email, '@'), 255, ''),
                'em' => now()->getTimestamp(),
            ]);

            return redirect()->route('google.cadastro');
        }

        // Já ligada a OUTRA conta Google: não troca em silêncio (seria tomar a conta de quem
        // criou o login Google primeiro).
        if ($user->google_id !== null && $user->google_id !== $sub) {
            return $this->falhou('Este e-mail já está ligado a outra conta Google. Entre com e-mail e senha.');
        }

        if ($user->estaBanido()) {
            return redirect()->route('login')->withErrors(['email' => BloqueiaUsuarioBanido::mensagem()]);
        }

        if ($user->google_id === null || $user->email_verified_at === null) {
            // O Google acabou de provar que a pessoa é dona deste e-mail: liga a conta e, se o
            // e-mail ainda estava por confirmar, conta como confirmado.
            $user->forceFill([
                'google_id' => $sub,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
        }

        return $this->entrar($request, $user);
    }

    /** A tela "Criar sua conta com o Google": os dados recebidos e o aceite dos Termos. */
    public function cadastro(Request $request): View|RedirectResponse
    {
        abort_unless(LoginComGoogle::ativo(), 404);

        $pendente = $this->cadastroPendente($request);
        if ($pendente === null) {
            return redirect()->route('login');
        }

        return view('auth.google-cadastro', ['nome' => $pendente['name'], 'email' => $pendente['email']]);
    }

    public function criarConta(Request $request): RedirectResponse
    {
        abort_unless(LoginComGoogle::ativo(), 404);

        $pendente = $this->cadastroPendente($request);
        if ($pendente === null) {
            return $this->falhou('O tempo para criar a conta acabou. Entre com o Google de novo.');
        }

        $request->validate(['terms' => ['required', 'accepted']], [
            'terms.required' => 'Você precisa aceitar os Termos de Uso e a Política de Privacidade.',
            'terms.accepted' => 'Você precisa aceitar os Termos de Uso e a Política de Privacidade.',
        ]);

        try {
            $user = User::create([
                'name' => $pendente['name'],
                'email' => $pendente['email'],
                // Ninguém conhece esta senha: quem quiser entrar também por e-mail e senha cria
                // uma pelo "Esqueci a senha". Sem senha nenhuma, a coluna NOT NULL quebraria.
                'password' => Hash::make(Str::random(64)),
                'terms_accepted_at' => now(),
                'terms_version' => config('legal.version'),
                'terms_accepted_ip' => $request->ip(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Alguém criou a conta com este e-mail entre o Google e o clique (outra aba).
            $request->session()->forget(self::CHAVE_CADASTRO);

            return $this->falhou('Já existe uma conta com este e-mail. Entre com o Google de novo.');
        }

        // Titular e e-mail confirmado (o Google provou que a caixa é da pessoa), fora do mass
        // assignment, como no cadastro pelo formulário.
        $user->forceFill([
            'is_admin' => true,
            'google_id' => $pendente['sub'],
            'email_verified_at' => now(),
        ])->save();
        $request->session()->forget(self::CHAVE_CADASTRO);

        event(new Registered($user)); // categorias padrão; o link de verificação se cala (já verificado)
        Atividade::registrar('app.conta_criada', 'criou a conta no Stabil Money com o Google', $user->getKey(), $user, autor: $user);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    /** As mesmas regras do fim do login com senha: 2FA (salvo aparelho confiável) e sessão nova. */
    private function entrar(Request $request, User $user): RedirectResponse
    {
        $request->session()->forget(self::CHAVE_CADASTRO);

        if ($user->temDoisFatores() && ! AparelhoConfiavel::confia($request, $user)) {
            TwoFactorChallengeController::aguardar($request->session(), $user, false);

            return redirect()->route('two-factor.login');
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /** @return array{sub: string, email: string, name: string, em: int}|null */
    private function cadastroPendente(Request $request): ?array
    {
        $p = $request->session()->get(self::CHAVE_CADASTRO);
        if (! is_array($p) || ! is_string($p['sub'] ?? null) || ! is_string($p['email'] ?? null) || ! is_int($p['em'] ?? null)) {
            return null;
        }
        if (now()->getTimestamp() - $p['em'] > self::VALIDADE_DO_CADASTRO_EM_SEGUNDOS) {
            $request->session()->forget(self::CHAVE_CADASTRO);

            return null;
        }

        return $p;
    }

    private function falhou(string $mensagem = self::MENSAGEM_FALHOU): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $mensagem]);
    }
}
