<?php

namespace App\Http\Controllers;

use App\Services\TwoFactorService;
use App\Support\BrowserSessions;
use App\Support\Mailer;
use App\Support\Totp;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Configurações da conta com navegação por subabas (server-routed).
 * Segurança = senha + sessões ativas; 2FA = verificação em duas etapas;
 * Conta = excluir conta.
 *
 * O 2FA ganhou aba própria em 06/08/2026: junto com senha e sessões ele fazia a
 * Segurança passar de duas telas de rolagem, e é um fluxo de configuração com
 * passos (QR, confirmação, códigos de recuperação) que merece a tela inteira.
 * (Permissões de dependentes entram aqui num subprojeto futuro.)
 */
class SettingsController extends Controller
{
    private const TABS = [
        'seguranca' => 'Segurança',
        '2fa' => '2FA',
        'conta' => 'Conta',
    ];

    public function index(Request $request, TwoFactorService $twoFactor, string $tab = 'seguranca'): View
    {
        abort_unless(array_key_exists($tab, self::TABS), 404);

        $user = $request->user();

        $data = [
            'user' => $user,
            'tab' => $tab,
            'tabs' => self::TABS,
        ];

        // A aba Segurança lista as sessões/dispositivos conectados.
        if ($tab === 'seguranca') {
            $data['sessions'] = BrowserSessions::forUser($request);
        }

        if ($tab === '2fa') {
            // QR e chave manual só existem enquanto a configuração do 2FA está em
            // andamento — passar do controller evita a view chamar service por conta.
            $data['qrCode'] = $user->doisFatoresPendente() ? $twoFactor->qrCodeSvg($user) : null;
            $data['chaveManual'] = $user->doisFatoresPendente()
                ? Totp::formatarSegredo($user->two_factor_secret)
                : null;

            // Sem mailer configurado não há "esqueci a senha" que entregue nada, então os
            // códigos de recuperação passam a ser a ÚNICA porta de volta de quem perder o
            // celular. Isso muda o texto do aviso, e a pessoa precisa saber antes de ligar.
            $data['semRecuperacaoPorEmail'] = ! Mailer::entrega();
        }

        return view('settings.index', $data);
    }
}
