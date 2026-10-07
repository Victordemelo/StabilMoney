<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\ChaveDeIp;
use App\Support\Mailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Quanto esperar entre dois pedidos de link (out/2026 — pedido do Victor depois do primeiro
     * teste em produção). Antes a tela dizia "enviado" e deixava pedir na hora de novo, para
     * outro e-mail. Agora, depois de um pedido, a tela mostra para onde foi e só libera o
     * reenvio depois disto. A espera vale no SERVIDOR, por sessão E por rede (a `ChaveDeIp`):
     * sem ela um POST direto pulava a tela; só pela sessão, bastaria descartar o cookie.
     */
    public const ESPERA_EM_SEGUNDOS = 60;

    /** Onde a sessão guarda o último pedido: ['email' => ..., 'em' => timestamp]. */
    private const CHAVE_DA_SESSAO = 'recuperacao_senha';

    /** Depois disto a tela volta ao formulário (a pessoa já foi embora e voltou). */
    private const VALIDADE_DO_PEDIDO_EM_SEGUNDOS = 1800;

    /**
     * A tela: o formulário, ou — depois de um pedido — a confirmação com o e-mail informado e o
     * "Reenviar" com a contagem. `?outro=1` volta ao formulário, mas só depois da espera (antes
     * dela o servidor recusaria o pedido de qualquer jeito).
     */
    public function create(Request $request): View
    {
        $pedido = $this->ultimoPedido($request);
        $espera = $this->segundosParaPedirDeNovo($request);

        if ($pedido !== null && $request->boolean('outro') && $espera === 0) {
            $request->session()->forget(self::CHAVE_DA_SESSAO);
            $pedido = null;
        }

        return view('auth.forgot-password', [
            'enviadoPara' => $pedido['email'] ?? null,
            'esperaParaReenviar' => $pedido !== null ? $espera : 0,
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Sem transporte que entregue, o link iria para storage/logs e mais ninguém o
        // veria. Dizer "enviamos para o seu e-mail" seria mentir para quem está trancado
        // fora da conta — a pessoa esperaria um e-mail que nunca chega em vez de pedir
        // ajuda. Enquanto o SMTP não entra no .env, o app assume a limitação.
        if (! Mailer::entrega()) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => Mailer::avisoDeIndisponibilidade()]);
        }

        // A espera vem ANTES do broker e vale para qualquer e-mail, exista a conta ou não: a
        // resposta "aguarde" é a mesma para todos e não passa pelo banco — nem o conteúdo nem o
        // tempo dela dizem quem tem conta.
        $espera = $this->segundosParaPedirDeNovo($request);
        if ($espera > 0) {
            return redirect()->route('password.request')->withErrors(['email' => self::mensagemDeEspera($espera)]);
        }

        $email = (string) $request->input('email');
        RateLimiter::hit($this->chaveDaRede($request), self::ESPERA_EM_SEGUNDOS);
        $request->session()->put(self::CHAVE_DA_SESSAO, ['email' => $email, 'em' => now()->getTimestamp()]);

        // We will send the password reset link to this user. Once we have attempted
        // to send the link, we will examine the response then see the message we
        // need to show to the user. Finally, we'll send out a proper response.
        $status = Password::sendResetLink(['email' => $email]);

        // Resposta SEMPRE igual quando o e-mail não existe (INVALID_USER): dizer
        // "não existe nenhum usuário com esse e-mail" entregava a um script a lista
        // de quem tem conta aqui — insumo para phishing dirigido e credential
        // stuffing. E igual também no pedido REPETIDO (THROTTLED, dentro de 60 s): o
        // "aguarde para tentar de novo" só aparecia para e-mail cadastrado — pedir duas
        // vezes bastava para descobrir quem tem conta (rodada de 22-23/09/2026). Quem
        // pediu há pouco já recebeu o link.
        if (in_array($status, [Password::RESET_LINK_SENT, Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            return redirect()->route('password.request')->with('status', __(Password::RESET_LINK_SENT));
        }

        return back()->withInput($request->only('email'))
            ->withErrors(['email' => __($status)]);
    }

    public static function mensagemDeEspera(int $segundos): string
    {
        return 'Aguarde '.$segundos.' '.($segundos === 1 ? 'segundo' : 'segundos')
            .' para pedir outro link. Se o primeiro não chegou, confira também a caixa de spam.';
    }

    /** Quanto falta para poder pedir de novo: o maior entre a espera da sessão e a da rede. */
    private function segundosParaPedirDeNovo(Request $request): int
    {
        $pelaSessao = 0;
        if ($pedido = $this->ultimoPedido($request)) {
            $pelaSessao = max(0, $pedido['em'] + self::ESPERA_EM_SEGUNDOS - now()->getTimestamp());
        }

        $chave = $this->chaveDaRede($request);
        $pelaRede = RateLimiter::tooManyAttempts($chave, 1) ? RateLimiter::availableIn($chave) : 0;

        return (int) max($pelaSessao, $pelaRede);
    }

    /** @return array{email: string, em: int}|null o último pedido desta sessão, se ainda vale */
    private function ultimoPedido(Request $request): ?array
    {
        $pedido = $request->session()->get(self::CHAVE_DA_SESSAO);
        if (! is_array($pedido) || ! is_string($pedido['email'] ?? null) || ! is_int($pedido['em'] ?? null)) {
            return null;
        }
        if (now()->getTimestamp() - $pedido['em'] > self::VALIDADE_DO_PEDIDO_EM_SEGUNDOS) {
            return null;
        }

        return $pedido;
    }

    private function chaveDaRede(Request $request): string
    {
        return 'recuperar-senha:'.ChaveDeIp::da($request);
    }
}
