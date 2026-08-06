<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * A segunda etapa do login: a tela que pede o código do app autenticador.
 *
 * Só chega aqui quem digitou a senha certa de uma conta que LIGOU o 2FA — e, nesse
 * momento, a pessoa **ainda não está autenticada**: `LoginRequest::authenticate()` confere
 * a senha sem abrir sessão. O que existe é um "login pendente" guardado na sessão (id da
 * conta + a escolha de "lembrar de mim" + o instante em que começou). A sessão de verdade
 * só nasce em `store()`, depois do código certo.
 *
 * Por que o pendente tem prazo: sem validade, um computador compartilhado ficaria com a
 * porta encostada — quem sentasse depois só precisaria do código, e a senha (já digitada)
 * não seria mais cobrada. Cinco minutos é tempo de pegar o celular e ler o código.
 *
 * O limite de tentativas está na rota (`throttle:dois-fatores`, ver AppServiceProvider):
 * são 6 dígitos, e sem limite a força bruta acharia o número.
 */
class TwoFactorChallengeController extends Controller
{
    /** Id da conta que passou pela senha e espera o código. Público: o rate limiter lê. */
    public const CHAVE_ID = 'login.id';

    private const CHAVE_LEMBRAR = 'login.remember';

    private const CHAVE_INICIO = 'login.at';

    /** Minutos que o login pendente sobrevive antes de exigir a senha de novo. */
    public const VALIDADE_EM_MINUTOS = 5;

    public function __construct(private readonly TwoFactorService $twoFactor) {}

    /**
     * Marca o login como "pendente de segunda etapa". Chamado pelo controller de login.
     */
    public static function aguardar(Session $sessao, User $user, bool $lembrar): void
    {
        $sessao->put([
            self::CHAVE_ID => $user->getKey(),
            self::CHAVE_LEMBRAR => $lembrar,
            self::CHAVE_INICIO => now()->timestamp,
        ]);

        // Troca o id da sessão antes de qualquer coisa ligada à conta existir nela
        // (fixação de sessão). `regenerate()` migra os dados; `invalidate()` os apagaria,
        // levando junto o login pendente e a URL de destino.
        $sessao->regenerate();
    }

    /** A tela do código. */
    public function create(Request $request): View|RedirectResponse
    {
        $user = $this->pendente($request);

        if ($user === null) {
            return $this->expirou();
        }

        return view('auth.two-factor-challenge', [
            // O modo "código de recuperação" é um parâmetro da URL, e não um botão de
            // JavaScript: esta é a tela que aparece para quem PERDEU o celular, e ela
            // precisa funcionar mesmo com o JS quebrado ou bloqueado.
            'recuperacao' => $request->boolean('recuperacao'),
            'codigosRestantes' => $user->codigosDeRecuperacaoRestantes(),
        ]);
    }

    /** Confere o código (do autenticador ou de recuperação) e conclui o login. */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $user = $this->pendente($request);

        if ($user === null) {
            return $this->expirou();
        }

        $request->validate([
            'codigo' => ['required', 'string', 'max:64'],
        ], [
            'codigo.required' => 'Digite o código para continuar.',
        ]);

        $codigo = $request->string('codigo')->toString();

        // O usuário escolhe explicitamente qual tipo está mandando (o formulário alterna
        // entre os dois). Adivinhar pelo formato faria um código de recuperação digitado
        // errado ser testado como TOTP e vice-versa, com mensagem de erro enganosa.
        $entrou = $request->boolean('recuperacao')
            ? $this->twoFactor->consumirCodigoDeRecuperacao($user, $codigo)
            : $this->twoFactor->verificarCodigo($user, $codigo);

        if (! $entrou) {
            throw ValidationException::withMessages([
                'codigo' => $request->boolean('recuperacao')
                    ? 'Código de recuperação inválido ou já utilizado.'
                    : 'Código incorreto ou expirado. Use o código que o aplicativo mostra agora.',
            ]);
        }

        $lembrar = (bool) $request->session()->get(self::CHAVE_LEMBRAR, false);

        $this->limpar($request);

        // O contador de tentativas NÃO é zerado no acerto, de propósito: quem zera dá ao
        // atacante um jeito de renovar a cota (bastaria um login válido intercalado). A
        // cota é generosa o bastante para os enganos de quem digita certo depois.

        // Só AQUI a sessão autenticada nasce. `login()` já migra o id da sessão.
        Auth::guard('web')->login($user, $lembrar);
        $request->session()->regenerate();

        $destino = redirect()->intended(route('dashboard', absolute: false));

        return $request->expectsJson()
            ? response()->json(['redirect' => $destino->getTargetUrl()])
            : $destino;
    }

    /** "Entrar com outra conta": descarta o login pendente e volta para a senha. */
    public function destroy(Request $request): RedirectResponse
    {
        $this->limpar($request);

        return redirect()->route('login');
    }

    /**
     * A conta que está no meio do login — ou null se não há pendência, se ela expirou,
     * ou se o 2FA foi desligado (de outro aparelho) enquanto esta tela estava aberta.
     */
    private function pendente(Request $request): ?User
    {
        $id = $request->session()->get(self::CHAVE_ID);
        $inicio = $request->session()->get(self::CHAVE_INICIO);

        if ($id === null || $inicio === null) {
            return null;
        }

        if (now()->timestamp - (int) $inicio > self::VALIDADE_EM_MINUTOS * 60) {
            $this->limpar($request);

            return null;
        }

        $user = User::find($id);

        // Conta excluída, ou 2FA desligado de outro aparelho no meio do caminho: mandar
        // de volta para o login é o certo — entrar sem código quando a pendência foi
        // criada justamente porque havia código seria contornar a própria etapa.
        if ($user === null || ! $user->temDoisFatores()) {
            $this->limpar($request);

            return null;
        }

        return $user;
    }

    private function limpar(Request $request): void
    {
        $request->session()->forget([self::CHAVE_ID, self::CHAVE_LEMBRAR, self::CHAVE_INICIO]);
    }

    private function expirou(): RedirectResponse
    {
        return redirect()->route('login')->withErrors([
            'email' => 'A verificação expirou. Entre com seu e-mail e senha novamente.',
        ]);
    }
}
