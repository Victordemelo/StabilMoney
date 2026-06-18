<?php

namespace App\Http\Controllers;

use App\Support\BrowserSessions;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Configurações da conta com navegação por subabas (server-routed).
 * Segurança = senha + sessões ativas; Conta = excluir conta. (Permissões de
 * dependentes entram aqui num subprojeto futuro.)
 */
class SettingsController extends Controller
{
    private const TABS = [
        'seguranca' => 'Segurança',
        'conta' => 'Conta',
    ];

    public function index(Request $request, string $tab = 'seguranca'): View
    {
        abort_unless(array_key_exists($tab, self::TABS), 404);

        $data = [
            'user' => $request->user(),
            'tab' => $tab,
            'tabs' => self::TABS,
        ];

        // A aba Segurança lista as sessões/dispositivos conectados.
        if ($tab === 'seguranca') {
            $data['sessions'] = BrowserSessions::forUser($request);
        }

        return view('settings.index', $data);
    }
}
