<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use App\Support\PrimeirosPassos;

class DashboardController extends Controller
{
    /**
     * Tela inicial: visão geral das finanças do usuário logado.
     * Toda a agregação fica no DashboardService.
     */
    public function index(DashboardService $dashboard)
    {
        $user = auth()->user();
        $passos = $user->primeiros_passos_ocultos_at === null ? PrimeirosPassos::de($user) : null;

        return view('dashboard', $dashboard->build($user->ownerId()) + [
            // O card "Primeiros passos" (08/10/2026): só enquanto falta algum passo e a pessoa não o escondeu.
            'primeirosPassos' => $passos && PrimeirosPassos::aparece($user, $passos) ? $passos : null,
        ]);
    }
}
