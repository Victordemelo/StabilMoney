<?php

namespace App\Listeners;

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\GoogleLoginController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\TwoFactorController;
use App\Models\Atividade;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/**
 * Entradas e saídas do APP no registro de atividade (Configurações › Atividade).
 *
 * Os eventos do framework, e não chamadas espalhadas pelos controllers: o `Login` dispara
 * também quando o cookie de "lembrar de mim" reabre a sessão sozinho — no meio de qualquer
 * requisição, sem passar por controller de login nenhum —, e esse é justamente o acesso
 * que a pessoa mais precisa enxergar ("entrou pelo celular que eu achava que tinha saído").
 *
 * O que decide a frase é a AÇÃO da rota em que o evento aconteceu:
 *  - o POST do login → entrou com e-mail e senha;
 *  - o POST da segunda etapa → entrou com a verificação em duas etapas;
 *  - o POST do cadastro e o de confirmar o 2FA → nada: o cadastro já tem a linha dele, e
 *    confirmar o 2FA chama `login()` só para reemitir o cookie desta MESMA sessão;
 *  - qualquer outra → voltou pelo "lembrar de mim".
 *
 * Só o guard `web` (o painel administrativo tem guard próprio e histórico próprio). No
 * `Logout`, só a rota `logout`: o mesmo evento sai quando o `BloqueiaUsuarioBanido` derruba
 * um banido, e "saiu do app" seria mentira.
 *
 * Falha de SENHA no login não entra, de propósito: gravar só quando o e-mail existe
 * deixaria um tempo de resposta diferente para conta cadastrada (a enumeração que o
 * `timebox` do login existe para impedir), e daria a qualquer um um jeito de encher o
 * histórico de uma família. O código errado do 2FA entra (ver TwoFactorChallengeController):
 * ali a senha já foi conferida, e é exatamente o sinal que importa.
 */
class RegistraAcessoNaAtividade
{
    public function handleLogin(Login $evento): void
    {
        if ($evento->guard !== 'web' || ! $evento->user instanceof User) {
            return;
        }

        // Pela AÇÃO da rota, e não pelo nome: os POST do login, do cadastro e da segunda
        // etapa não têm nome (só os GET que mostram as telas).
        $acaoDaRota = request()->route()?->getActionName();

        [$acao, $oQue] = match ($acaoDaRota) {
            RegisteredUserController::class.'@store', TwoFactorController::class.'@confirmar',
            GoogleLoginController::class.'@criarConta' => [null, null], // o cadastro registra a própria linha
            GoogleLoginController::class.'@retorno' => ['acesso.entrou_google', 'entrou no app com a conta Google'],
            AuthenticatedSessionController::class.'@store' => ['acesso.entrou', 'entrou no app com e-mail e senha'],
            // Código de recuperação gasto é um sinal à parte: são poucos, e quem os usa
            // costuma ter perdido o celular (ou alguém achou o papel onde estavam).
            TwoFactorChallengeController::class.'@store' => ['acesso.entrou_2fa', request()->boolean('recuperacao')
                ? 'entrou no app com um código de recuperação (restam '.(int) $evento->user->fresh()?->codigosDeRecuperacaoRestantes().')'
                : 'entrou no app com a verificação em duas etapas'],
            default => ['acesso.lembrado', 'voltou ao app pelo “Lembrar de mim”, sem digitar a senha'],
        };

        if ($acao === null) {
            return;
        }

        Atividade::registrar($acao, $oQue, $evento->user->ownerId(), $evento->user, autor: $evento->user);
    }

    public function handleLogout(Logout $evento): void
    {
        if ($evento->guard !== 'web' || ! $evento->user instanceof User
            || request()->route()?->getName() !== 'logout') {
            return;
        }

        Atividade::registrar('acesso.saiu', 'saiu do app', $evento->user->ownerId(), $evento->user, autor: $evento->user);
    }
}
