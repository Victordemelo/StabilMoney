<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;

class DashboardController extends Controller
{
    /**
     * Tela inicial: visão geral das finanças do usuário logado.
     * Toda a agregação fica no DashboardService.
     */
    public function index(DashboardService $dashboard)
    {
        return view('dashboard', $dashboard->build((int) auth()->id()));
    }
}
