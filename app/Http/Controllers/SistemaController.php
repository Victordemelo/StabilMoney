<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Páginas do menu do perfil que não são configuração (out/2026): o Tutorial (o tour guiado
 * por todas as telas — sm/tutorial.js) e as Informações do sistema (versão, autor, documentos).
 */
class SistemaController extends Controller
{
    public function tutorial(): View
    {
        return view('sistema.tutorial');
    }

    public function informacoes(Request $request): View
    {
        return view('sistema.informacoes', [
            'usuario' => $request->user(),
        ]);
    }
}
