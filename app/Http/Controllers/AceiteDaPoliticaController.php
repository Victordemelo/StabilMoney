<?php

namespace App\Http\Controllers;

use App\Models\Atividade;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tela de aceite da versão atual dos Termos/Política (ver `ExigeAceiteDaPoliticaAtual`).
 */
class AceiteDaPoliticaController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if ($request->user()->terms_version === config('legal.version')) {
            return redirect()->route('dashboard');
        }

        $versao = config('legal.version');

        return view('legal.aceite', [
            'versao' => $versao,
            // Nunca `config("legal.mudancas.{$versao}")`: o ponto de "3.3" vira separador de chave.
            'mudancas' => config('legal.mudancas')[$versao] ?? [],
            'primeiroAceite' => $request->user()->terms_version === null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(
            ['aceito' => ['accepted']],
            ['aceito.accepted' => 'Marque a caixa para aceitar os Termos de Uso e a Política de Privacidade.'],
        );

        $user = $request->user();
        $versao = config('legal.version');

        // Mesma prova do cadastro (LGPD art. 8º, §1º): quando, qual versão e de onde.
        $user->forceFill([
            'terms_accepted_at' => now(),
            'terms_version' => $versao,
            'terms_accepted_ip' => $request->ip(),
        ])->save();

        Atividade::registrar(
            'acesso.termos_aceitos',
            "aceitou os Termos de Uso e a Política de Privacidade (versão {$versao})",
            $user->ownerId(),
            $user,
        );

        return redirect()->intended(route('dashboard'))
            ->with('status', "Obrigado! Você aceitou a versão {$versao} dos Termos e da Política.");
    }
}
