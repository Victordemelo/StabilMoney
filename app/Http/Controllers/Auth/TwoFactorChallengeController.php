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
 * conta + a escolha de "lembrar de mim" + o instante em que começou + a impressão digital da
 * senha conferida). A sessão de verdade só nasce em `store()`, depois do código certo.
 *
 * Por que o pendente tem prazo: sem validade, um computador compartilhado ficaria com a
 * porta encostada — quem sentasse depois só precisaria do código, e a senha (já digitada)
 * não seria mais cobrada. Cinco minutos é tempo de pegar o celular e ler o código.
 *
 * Por que o pendente morre quando a senha muda: ele é a prova de que a SENHA foi
 * conferida. Se ela for trocada nesses cinco minutos — redefinição pelo link, troca nas
 * Configurações, o titular trocando a do dependente —, a prova passa a ser de uma senha que
 * não vale mais, e o código do autenticador completaria um login com ela. É exatamente o
 * caso de quem descobre a invasão e redefine a senha: o invasor que já estava nesta tela,
 * com a senha antiga, entraria assim mesmo. Ver `impressaoDaSenha()`.
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

    /** Impressão digital da senha conferida na primeira etapa (HMAC, nunca o hash). */
    private const CHAVE_SENHA = 'login.senha';

    /** Minutos que o login pendente sobrevive antes de exigir a senha de novo. */
    public const VALIDADE_EM_MINUTOS = 5;

    public function __construct(private readonly TwoFactorService $twoFactor) {}

    /**
     * Marca o login como "pendente de segunda etapa". Chamado pelo controller de login.
     *
     * `$user` tem de ser o MESMO model cuja senha acabou de ser conferida, e não um
     * recarregado do banco: a impressão sai do hash que foi comparado com o que a pessoa
     * digitou. Reler aqui abriria justamente a janela que ela fecha — uma troca de senha
     * entre a conferência e esta linha seria carimbada como se fosse a senha digitada.
     */
    public static function aguardar(Session $sessao, User $user, bool $lembrar): void
    {
        $sessao->put([
            self::CHAVE_ID => $user->getKey(),
            self::CHAVE_LEMBRAR => $lembrar,
            self::CHAVE_INICIO => now()->timestamp,
            self::CHAVE_SENHA => self::impressaoDaSenha($user),
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

        if (! $user instanceof User) {
            return $user;
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
        // Antes de olhar o código, e não depois: com a pendência vencida (inclusive pela
        // troca de senha), nada pode ser gasto — nem o passo do TOTP, que barraria o mesmo
        // código no login seguinte, nem um código de recuperação, que não volta.
        $user = $this->pendente($request);

        if (! $user instanceof User) {
            return $user;
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
     * A conta que está no meio do login — ou o caminho de volta para a senha, se não há
     * pendência, se ela expirou, se o 2FA foi desligado (de outro aparelho), se a senha
     * da conta mudou enquanto esta tela estava aberta ou se a conta está suspensa.
     */
    private function pendente(Request $request): User|RedirectResponse
    {
        $id = $request->session()->get(self::CHAVE_ID);
        $inicio = $request->session()->get(self::CHAVE_INICIO);
        $impressao = $request->session()->get(self::CHAVE_SENHA);

        // Sem a impressão da senha a pendência não vale, mesmo com id e horário: é o que
        // sobra de um login começado antes desta checagem existir (no máximo 5 minutos
        // depois do deploy, e custa só digitar a senha de novo). Aceitar a falta dela
        // deixaria qualquer caminho futuro que esquecesse de gravá-la pular a checagem
        // em silêncio.
        if ($id === null || $inicio === null || ! is_string($impressao)) {
            $this->limpar($request);

            return $this->expirou();
        }

        if (now()->timestamp - (int) $inicio > self::VALIDADE_EM_MINUTOS * 60) {
            $this->limpar($request);

            return $this->expirou();
        }

        $user = User::find($id);

        // Conta excluída, ou 2FA desligado de outro aparelho no meio do caminho: mandar
        // de volta para o login é o certo — entrar sem código quando a pendência foi
        // criada justamente porque havia código seria contornar a própria etapa.
        if ($user === null || ! $user->temDoisFatores()) {
            $this->limpar($request);

            return $this->expirou();
        }

        // A senha conferida na primeira etapa ainda é a senha da conta? A comparação é
        // pelo HASH gravado agora, e não por `password_changed_at`, de propósito: a data é
        // um carimbo que cada caminho precisa lembrar de gravar — e já houve caminho que não
        // gravava (a edição de dependente, achado A-6 da auditoria de 05/09/2026) —, enquanto
        // o hash muda em TODOS, inclusive nos que ainda não existem.
        if (! hash_equals($impressao, self::impressaoDaSenha($user))) {
            $this->limpar($request);

            return $this->senhaMudou();
        }

        // Banido para aqui, ANTES de gastar código (observação da auditoria de 05/09/2026).
        // Barrado só depois, pelo `BloqueiaUsuarioBanido` na requisição seguinte ao login,
        // ele queimava o passo do TOTP — ou um código de recuperação, que não volta e faria
        // falta se o banimento fosse desfeito. A senha já foi conferida (a impressão acima),
        // então ele descobre o banimento no mesmo ponto de quem entra sem 2FA.
        if ($user->estaBanido()) {
            $this->limpar($request);

            return $this->suspensa();
        }

        return $user;
    }

    /**
     * Impressão digital do hash da senha, para perceber na segunda etapa que ela mudou.
     *
     * É a mesma HMAC (com a APP_KEY) que o próprio Laravel usa no `AuthenticateSession`
     * para notar uma senha trocada debaixo de uma sessão aberta. NUNCA o hash cru: a sessão
     * vai para a tabela `sessions` e para os backups dela, e um argon2id copiado ali seria
     * mais um alvo de quebra offline. A HMAC só serve para comparar — nem com a APP_KEY
     * dá para testar senha contra ela, porque o sal do argon2id não está na sessão.
     *
     * Muda sempre que o hash muda, e não só quando a senha muda: "Encerrar outras sessões"
     * regrava o hash com a mesma senha (`logoutOtherDevices`) e também derruba o login
     * pendente — que é o que essa ação promete, derrubar todo acesso que não seja o atual.
     */
    private static function impressaoDaSenha(User $user): string
    {
        return Auth::guard('web')->hashPasswordForCookie((string) $user->getAuthPassword());
    }

    private function limpar(Request $request): void
    {
        $request->session()->forget([
            self::CHAVE_ID, self::CHAVE_LEMBRAR, self::CHAVE_INICIO, self::CHAVE_SENHA,
        ]);
    }

    private function expirou(): RedirectResponse
    {
        return redirect()->route('login')->withErrors([
            'email' => 'A verificação expirou. Entre com seu e-mail e senha novamente.',
        ]);
    }

    /**
     * Mensagem própria, e não o "expirou": quem só visse "expirou" digitaria a senha
     * antiga de novo e levaria um "credenciais inválidas" sem entender por quê. Não conta
     * nada a quem tinha a senha antiga que a próxima tentativa já não fosse contar.
     */
    private function senhaMudou(): RedirectResponse
    {
        return redirect()->route('login')->withErrors([
            'email' => 'A verificação foi cancelada: a senha desta conta foi alterada ou os outros acessos '
                .'foram encerrados. Entre de novo com o e-mail e a senha atual.',
        ]);
    }

    /**
     * O recado do `BloqueiaUsuarioBanido`, palavra por palavra: quem tem 2FA não pode
     * receber um texto diferente de quem não tem. Sem o motivo — o texto da moderação não
     * é para virar tela pública. O BanidoNaoGastaCodigoDoDoisFatoresTest compara os dois.
     */
    private function suspensa(): RedirectResponse
    {
        return redirect()->route('login')->withErrors([
            'email' => 'Esta conta está suspensa. Fale com o suporte em '
                .config('legal.contact_email').'.',
        ]);
    }
}
