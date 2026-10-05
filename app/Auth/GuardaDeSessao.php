<?php

namespace App\Auth;

use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;

/**
 * A guarda `web` com o "Lembrar de mim" de validade REAL (out/2026 — decisão do Victor: 7 dias,
 * depois a senha de novo; `LembrarDeMimValeSeteDiasTest`).
 *
 * O Laravel só põe a validade no cookie, que é do NAVEGADOR: o valor `id|token|hash` não tem data,
 * e um cookie copiado (ou com a validade esticada) continuaria entrando para sempre. Aqui a validade
 * vai DENTRO do valor (`id|token|hash|expira`), que o EncryptCookies cifra e autentica com a
 * APP_KEY — não dá para editá-la —, e o servidor recusa o cookie vencido mesmo que o navegador o
 * mande. Cookie no formato antigo (sem a data) também é recusado: a pessoa entra com a senha uma vez.
 */
class GuardaDeSessao extends SessionGuard
{
    protected function queueRecallerCookie(AuthenticatableContract $user)
    {
        $this->getCookieJar()->queue($this->createRecaller(
            $user->getAuthIdentifier().'|'.
            $user->getRememberToken().'|'.
            $this->hashPasswordForCookie($user->getAuthPassword()).'|'.
            now()->addMinutes($this->getRememberDuration())->getTimestamp()
        ));
    }

    protected function userFromRecaller($recaller)
    {
        if ($recaller && ! $this->recallAttempted && ! $this->lembrarAindaVale()) {
            $this->recallAttempted = true;
            // O navegador ainda mandou: apaga, para não repetir a recusa a cada requisição.
            $this->getCookieJar()->queue($this->getCookieJar()->forget($this->getRecallerName()));

            return null;
        }

        return parent::userFromRecaller($recaller);
    }

    private function lembrarAindaVale(): bool
    {
        $partes = explode('|', (string) $this->request?->cookies->get($this->getRecallerName()));
        $expira = $partes[3] ?? '';

        return ctype_digit($expira) && (int) $expira >= now()->getTimestamp();
    }
}
