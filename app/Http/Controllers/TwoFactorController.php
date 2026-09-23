<?php

namespace App\Http\Controllers;

use App\Mail\AlertaDeSeguranca;
use App\Services\TwoFactorService;
use App\Support\ContextoDeSeguranca;
use App\Support\Notificador;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Verificação em duas etapas — o lado de Configurações › 2FA (ligar, confirmar,
 * trocar os códigos de recuperação e desligar). O lado do LOGIN mora em
 * Auth\TwoFactorChallengeController.
 *
 * **O recurso é opcional, e opcional de verdade:** não há caminho que ligue o 2FA sem o
 * dono da conta pedir explicitamente e digitar a senha atual. Titular e dependente podem
 * ligar cada um o seu — a segunda etapa protege o LOGIN, que é individual, ao contrário
 * do dinheiro, que é da família.
 *
 * **Por que a senha atual em quase tudo:** ligar, desligar e trocar os códigos são as três
 * ações que decidem quem entra na conta. Sem a senha, quem sequestrasse uma sessão
 * (celular esquecido aberto, PC compartilhado) desligaria o 2FA em um clique — e a
 * proteção viraria enfeite. Mesmo motivo pelo qual "encerrar outras sessões" e "excluir
 * conta" já pedem a senha. Todas as rotas daqui também têm limite de tentativas.
 */
class TwoFactorController extends Controller
{
    /**
     * Bags de erro — uma por formulário, e não uma por card.
     *
     * No estado "ativada" há DOIS formulários com um campo `password` na mesma tela
     * (gerar novos códigos e desativar). Numa bag só, errar a senha em um deles abriria o
     * outro e mostraria a mensagem no lugar errado. As mensagens gerais continuam todas
     * na bag principal, que é onde a view as procura.
     */
    private const BAG = 'twoFactor';

    private const BAG_CODIGOS = 'twoFactorCodigos';

    private const BAG_DESLIGAR = 'twoFactorDesligar';

    public function __construct(private readonly TwoFactorService $twoFactor) {}

    /**
     * Passo 1 — gera o segredo e mostra o QR. Ainda NÃO liga a exigência no login.
     */
    public function ativar(Request $request): RedirectResponse
    {
        $this->exigirSenha($request, self::BAG);

        $user = $request->user();

        // Já ligado: não regerar o segredo por cima. `iniciar()` limparia
        // `two_factor_confirmed_at`, e quem desistisse no meio do caminho ficaria SEM 2FA
        // sem nunca ter pedido para desligar — uma queda silenciosa de proteção. Trocar de
        // aparelho passa por desligar e ligar de novo, que é explícito.
        if ($user->temDoisFatores()) {
            return $this->voltar()->withErrors(
                ['two_factor' => 'A verificação em duas etapas já está ativa. Desative-a antes de configurar outro aparelho.'],
                self::BAG,
            );
        }

        $this->twoFactor->iniciar($user);

        return $this->voltar();
    }

    /**
     * Passo 2 — o usuário digita o primeiro código do autenticador.
     *
     * É este acerto que PROVA que o aparelho está configurado; só depois dele o login
     * passa a cobrar a segunda etapa. Confirmar também entrega os códigos de recuperação,
     * mostrados uma única vez.
     */
    public function confirmar(Request $request): RedirectResponse
    {
        $request->validateWithBag(self::BAG, [
            'codigo' => ['required', 'string'],
        ], [
            'codigo.required' => 'Digite o código de 6 dígitos do seu aplicativo.',
        ]);

        $user = $request->user();

        if (! $user->doisFatoresPendente()) {
            return $this->voltar()->withErrors(
                ['codigo' => 'Não há nenhuma configuração em andamento. Comece de novo.'],
                self::BAG,
            );
        }

        $codigos = $this->twoFactor->confirmar($user, $request->string('codigo')->toString());

        if ($codigos === null) {
            return $this->voltar()->withErrors(
                ['codigo' => 'Código incorreto ou expirado. Confira o relógio do celular e tente com o código atual.'],
                self::BAG,
            );
        }

        Notificador::avisar($user, AlertaDeSeguranca::doisFatoresAtivado(
            $user,
            ContextoDeSeguranca::doRequest($request),
        ));

        // Os códigos aparecem UMA vez, agora. Ficam na sessão (flash) e não no banco em
        // texto para a tela — quem quiser vê-los de novo gera outros.
        return $this->voltar()
            ->with('status', 'two-factor-enabled')
            ->with('codigosDeRecuperacao', $codigos);
    }

    /** Troca a lista de códigos de recuperação (invalida a anterior na hora). */
    public function regerarCodigos(Request $request): RedirectResponse
    {
        $this->exigirSenha($request, self::BAG_CODIGOS);

        $user = $request->user();

        if (! $user->temDoisFatores()) {
            return $this->voltar()->withErrors(
                ['two_factor' => 'A verificação em duas etapas não está ativa.'],
                self::BAG,
            );
        }

        return $this->voltar()
            ->with('status', 'two-factor-recovery-codes')
            ->with('codigosDeRecuperacao', $this->twoFactor->regerarCodigosDeRecuperacao($user));
    }

    /**
     * Desliga o 2FA — ou cancela uma configuração que ficou pela metade.
     *
     * A senha só é exigida quando o 2FA está ATIVO. Cancelar um setup pendente não baixa
     * proteção nenhuma (o login ainda nem cobra código), e pedir a senha de novo, segundos
     * depois de já tê-la digitado para gerar o QR, só ensinaria o usuário a digitá-la sem
     * pensar.
     */
    public function desativar(Request $request): RedirectResponse
    {
        $user = $request->user();
        $estavaAtivo = $user->temDoisFatores();

        if ($estavaAtivo) {
            $this->exigirSenha($request, self::BAG_DESLIGAR);
        }

        $this->twoFactor->desligar($user);

        // Só quando a proteção CAIU de fato. Cancelar um setup pendente não desligou
        // nada — avisar ali seria alarme falso, e alarme falso é o que faz a pessoa
        // parar de ler os próximos.
        if ($estavaAtivo) {
            Notificador::avisar($user, AlertaDeSeguranca::doisFatoresDesativado(
                $user,
                ContextoDeSeguranca::doRequest($request),
            ));
        }

        return $this->voltar()->with('status', $estavaAtivo ? 'two-factor-disabled' : 'two-factor-cancelled');
    }

    /** Confere a senha atual; lança ValidationException na bag do formulário que a pediu. */
    private function exigirSenha(Request $request, string $bag): void
    {
        $request->validateWithBag($bag, [
            'password' => ['required', 'current_password'],
        ], [
            'password.required' => 'Informe sua senha para continuar.',
            'password.current_password' => 'A senha informada está incorreta.',
        ]);
    }

    /**
     * Sempre de volta para a aba do 2FA — e não `back()`: o card tem quatro formulários
     * e um `back()` sem referer (PWA, app instalado) cairia no dashboard, escondendo o
     * erro que acabou de acontecer.
     *
     * A do 2FA, e não a Segurança: desde que o 2FA ganhou aba própria
     * (SettingsController::TABS) é só nela que o card existe. Voltar para a Segurança
     * escondia o QR de quem acabou de pedir para ligar e os erros do código — e, pior,
     * **gastava os códigos de recuperação**: eles vêm em flash, e a página que consumia o
     * flash não os mostrava. Quem ligava o 2FA nunca via a única porta de volta do dia em
     * que perdesse o celular.
     */
    private function voltar(): RedirectResponse
    {
        return redirect()->route('settings', '2fa');
    }
}
