<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\BloqueiaUsuarioBanido;
use App\Models\Atividade;
use App\Models\User;
use App\Support\AparelhoConfiavel;
use App\Support\LoginComGoogle;
use App\Support\Mailer;
use App\Support\Notificador;
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
 * Quem a conta é: pelo identificador da conta Google (`users.google_id`). Conta que já existe
 * com o mesmo e-mail (o Google tem de dizer que o e-mail é verificado) só é LIGADA depois que a
 * pessoa digita a senha dela — ver `retorno()`, o pré-sequestro de conta. Conta nova NÃO nasce no retorno
 * do Google: a pessoa vê uma tela com os dados recebidos e precisa aceitar os Termos e a
 * Política (a prova do aceite é a mesma do cadastro). Pedimos só nome e e-mail (`openid email
 * profile`); nunca a foto, os contatos ou qualquer outro dado da conta Google.
 */
class GoogleLoginController extends Controller
{
    /** Onde a sessão guarda o cadastro pendente: ['sub', 'email', 'name', 'em']. */
    public const CHAVE_CADASTRO = 'google_cadastro';

    /** Onde a sessão guarda a ligação pendente (conta existente): ['sub', 'email', 'user_id', 'em']. */
    public const CHAVE_LIGAR = 'google_ligar';

    /** Quanto tempo as telas de confirmação (cadastro e ligação) valem (como o login pendente do 2FA). */
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

        if ($sub === '' || $email === '') {
            return $this->falhou();
        }

        // Já ligada a este Google: o identificador da conta Google basta (o e-mail não decide nada).
        $user = User::where('google_id', $sub)->first();

        if ($user === null) {
            $user = User::where('email', $email)->first();

            // E-mail que o Google NÃO confirmou nunca leva a uma conta que já existe: ligar
            // por ele seria aceitar um endereço que ninguém provou ser de quem está entrando.
            if ($user !== null && ! $verificado) {
                return $this->falhou('Esta conta Google não tem o e-mail confirmado. Entre com e-mail e senha.');
            }
        }

        if ($user === null) {
            // Conta nova: os dados ficam na sessão até a pessoa aceitar os Termos. Com o e-mail
            // confirmado pelo Google ela entra direto; sem isso, é um cadastro comum e pede a
            // confirmação por e-mail (out/2026 — regra do Victor).
            $request->session()->put(self::CHAVE_CADASTRO, [
                'sub' => $sub,
                'email' => $email,
                'name' => Str::limit(trim((string) $google->getName()) ?: Str::before($email, '@'), 255, ''),
                'verificado' => $verificado,
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

        if ($user->google_id === null) {
            // Conta que já existe, achada pelo E-MAIL: não liga sozinha. "E-mail confirmado" nem
            // sempre prova que a conta é da dona do e-mail (o titular cria o login do dependente
            // com qualquer e-mail, já confirmado; conta criada enquanto o app não enviava e-mail
            // também nasce confirmada) — e alguém pode ter cadastrado o e-mail de outra pessoa
            // esperando a dona chegar pelo Google (pré-sequestro de conta). Para ligar, a pessoa
            // digita a SENHA desta conta: quem a criou sabe; quem só tem o e-mail usa "Esqueci a
            // senha", que troca a senha e derruba as sessões de quem a criou.
            $request->session()->put(self::CHAVE_LIGAR, [
                'sub' => $sub, 'email' => $email, 'user_id' => $user->getKey(), 'em' => now()->getTimestamp(),
            ]);

            return redirect()->route('google.ligar');
        }

        return $this->entrar($request, $user);
    }

    /** A tela "Criar sua conta com o Google": os dados recebidos e o aceite dos Termos. */
    public function cadastro(Request $request): View|RedirectResponse
    {
        abort_unless(LoginComGoogle::ativo(), 404);

        $pendente = $this->pendente($request, self::CHAVE_CADASTRO);
        if ($pendente === null) {
            return redirect()->route('login');
        }

        return view('auth.google-cadastro', ['nome' => $pendente['name'], 'email' => $pendente['email']]);
    }

    public function criarConta(Request $request): RedirectResponse
    {
        abort_unless(LoginComGoogle::ativo(), 404);

        $pendente = $this->pendente($request, self::CHAVE_CADASTRO);
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

        // Titular, fora do mass assignment, como no cadastro pelo formulário. Nasce confirmada
        // nos dois casos (o link de verificação do framework se cala diante de quem já é); sem a
        // confirmação do Google, ela só deixa de estar confirmada DEPOIS que o nosso link sai —
        // a mesma ordem do RegisteredUserController (ninguém fica trancado sem um link a caminho).
        $user->forceFill([
            'is_admin' => true,
            'google_id' => $pendente['sub'],
            'email_verified_at' => now(),
        ])->save();
        $request->session()->forget(self::CHAVE_CADASTRO);

        event(new Registered($user)); // categorias padrão
        Atividade::registrar('app.conta_criada', 'criou a conta no Stabil Money com o Google', $user->getKey(), $user, autor: $user);

        if (($pendente['verificado'] ?? true) !== true && Mailer::entrega() && Notificador::tentarEnviar(
            $user,
            'link de verificação de e-mail (cadastro pelo Google sem e-mail confirmado)',
            fn () => $user->sendEmailVerificationNotification(),
        )) {
            $user->forceFill(['email_verified_at' => null])->save();
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    /** A tela "Ligar sua conta Google": pede a senha da conta que já existe com este e-mail. */
    public function ligacao(Request $request): View|RedirectResponse
    {
        abort_unless(LoginComGoogle::ativo(), 404);

        $pendente = $this->pendente($request, self::CHAVE_LIGAR);
        if ($pendente === null) {
            return redirect()->route('login');
        }

        return view('auth.google-ligar', ['email' => $pendente['email']]);
    }

    public function ligar(Request $request): RedirectResponse
    {
        abort_unless(LoginComGoogle::ativo(), 404);

        $pendente = $this->pendente($request, self::CHAVE_LIGAR);
        $user = $pendente !== null ? User::find($pendente['user_id']) : null;
        if ($user === null || $user->email !== $pendente['email'] || $user->google_id !== null) {
            $request->session()->forget(self::CHAVE_LIGAR);

            return $this->falhou('O tempo para ligar a conta acabou. Entre com o Google de novo.');
        }

        $request->validate(['password' => ['required', 'string']], ['password.required' => 'Digite a senha da sua conta.']);

        if (! Hash::check((string) $request->input('password'), $user->password)) {
            return back()->withErrors(['password' => 'Senha incorreta. Se não lembra, use "Esqueci a senha".']);
        }
        if ($user->estaBanido()) {
            $request->session()->forget(self::CHAVE_LIGAR);

            return redirect()->route('login')->withErrors(['email' => BloqueiaUsuarioBanido::mensagem()]);
        }

        // Senha certa: é a dona da conta, e o Google provou que é dona do e-mail (só chega aqui
        // com `email_verified`) — então a conta criada por senha e ainda não confirmada passa a
        // estar confirmada ao ligar o Google (out/2026, decisão registrada no CLAUDE.md).
        $user->forceFill(['google_id' => $pendente['sub'], 'email_verified_at' => $user->email_verified_at ?? now()])->save();
        $request->session()->forget(self::CHAVE_LIGAR);

        return $this->entrar($request, $user);
    }

    /** As mesmas regras do fim do login com senha: 2FA (salvo aparelho confiável) e sessão nova. */
    private function entrar(Request $request, User $user): RedirectResponse
    {
        $request->session()->forget([self::CHAVE_CADASTRO, self::CHAVE_LIGAR]);

        if ($user->temDoisFatores() && ! AparelhoConfiavel::confia($request, $user)) {
            TwoFactorChallengeController::aguardar($request->session(), $user, false);

            return redirect()->route('two-factor.login');
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /** O cadastro ou a ligação pendente na sessão, se ainda vale (10 minutos). */
    private function pendente(Request $request, string $chave): ?array
    {
        $p = $request->session()->get($chave);
        if (! is_array($p) || ! is_string($p['sub'] ?? null) || ! is_string($p['email'] ?? null) || ! is_int($p['em'] ?? null)) {
            return null;
        }
        if (now()->getTimestamp() - $p['em'] > self::VALIDADE_DO_CADASTRO_EM_SEGUNDOS) {
            $request->session()->forget($chave);

            return null;
        }

        return $p;
    }

    private function falhou(string $mensagem = self::MENSAGEM_FALHOU): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $mensagem]);
    }
}
