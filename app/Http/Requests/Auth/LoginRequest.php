<?php

namespace App\Http\Requests\Auth;

use App\Http\Middleware\BloqueiaUsuarioBanido;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /** Conta cuja senha acabou de ser conferida (preenchida por authenticate()). */
    private ?User $usuario = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Confere as credenciais e — quando a conta NÃO usa verificação em duas etapas —
     * abre a sessão.
     *
     * Por que `validate()` em vez de `attempt()`: `attempt()` já loga a pessoa, e nas
     * contas com 2FA seria preciso deslogá-la em seguida para exigir o código. O problema
     * é que `SessionGuard::logout()` **recicla o remember token**, o que derrubaria o
     * "Lembrar de mim" de TODOS os outros aparelhos daquele usuário a cada login — um
     * efeito colateral que só apareceria em quem ligasse o 2FA. `validate()` confere a
     * senha sem abrir sessão nenhuma, então quem tem 2FA simplesmente não entra ainda: o
     * controller manda para o desafio, e a sessão só nasce depois do código certo.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $guard = Auth::guard('web');

        if (! $guard->validate($this->only('email', 'password'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        $usuario = $guard->getLastAttempted();

        // Banido é barrado AQUI, com a senha já conferida e ANTES do `login()`. Antes a
        // conta entrava — sessão aberta e, com "lembrar de mim" (marcado por padrão), um
        // remember token gravado e o cookie emitido — e só o `BloqueiaUsuarioBanido`, na
        // requisição seguinte, a derrubava. Depois da senha, e não antes, para a mensagem
        // de suspensão não servir de sonda: com a senha errada, a resposta é a de sempre.
        // Conta como tentativa falha: sem isso, quem sabe a senha de uma conta banida
        // repetiria o login à vontade, cada tentativa custando um argon2id no servidor.
        if ($usuario instanceof User && $usuario->estaBanido()) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => BloqueiaUsuarioBanido::mensagem(),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        $this->usuario = $usuario;

        // Sem 2FA (o caso da imensa maioria — o recurso é opcional): entra direto,
        // exatamente como antes.
        if (! $this->precisaDeSegundaEtapa()) {
            $guard->login($this->usuario, $this->boolean('remember'));
        }
    }

    /**
     * A senha conferiu, mas a conta ainda precisa do código do autenticador?
     * Só é verdade para quem LIGOU e CONFIRMOU o 2FA nas Configurações.
     */
    public function precisaDeSegundaEtapa(): bool
    {
        return $this->usuario !== null && $this->usuario->temDoisFatores();
    }

    /** A conta cuja senha acabou de ser conferida. Só válida depois de authenticate(). */
    public function usuarioAutenticado(): User
    {
        return $this->usuario;
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
