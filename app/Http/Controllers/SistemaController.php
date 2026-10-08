<?php

namespace App\Http\Controllers;

use App\Support\PrimeirosPassos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Páginas do menu do perfil que não são configuração (out/2026): o Tutorial (o tour guiado
 * por todas as telas — sm/tutorial.js) e as Informações do sistema (versão, autor, documentos).
 */
class SistemaController extends Controller
{
    public function tutorial(Request $request): View
    {
        return view('sistema.tutorial', [
            'primeirosPassos' => PrimeirosPassos::de($request->user()),
            'passosOcultos' => $request->user()->primeiros_passos_ocultos_at !== null,
        ]);
    }

    /**
     * Esconder / mostrar de novo o card "Primeiros passos" da Visão geral (08/10/2026). Direto na
     * tabela: é preferência de tela, não deve entrar no registro de atividade nem no `updated_at`.
     */
    public function ocultarPrimeirosPassos(Request $request): RedirectResponse
    {
        DB::table('users')->where('id', $request->user()->id)->update(['primeiros_passos_ocultos_at' => now()]);

        return back()->with('status', 'Primeiros passos escondidos. Dá para rever em Tutorial, no menu do perfil.');
    }

    public function mostrarPrimeirosPassos(Request $request): RedirectResponse
    {
        DB::table('users')->where('id', $request->user()->id)->update(['primeiros_passos_ocultos_at' => null]);

        return redirect()->route('dashboard')->with('status', 'Os primeiros passos voltaram para a Visão geral.');
    }

    public function informacoes(Request $request): View
    {
        return view('sistema.informacoes', [
            'usuario' => $request->user(),
        ]);
    }
}
