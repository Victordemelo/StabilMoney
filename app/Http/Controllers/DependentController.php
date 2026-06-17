<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

/**
 * Gerenciamento de dependentes — apenas o titular (account_owner_id null) acessa.
 * Dependente é um User com account_owner_id apontando para o titular; compartilha
 * a visão financeira da família.
 */
class DependentController extends Controller
{
    public function index(Request $request)
    {
        $titular = $request->user();
        abort_unless($titular->isTitular(), 403);

        $dependents = $titular->dependents()->orderBy('name')->get();

        return view('dependents.index', compact('dependents'));
    }

    public function store(Request $request)
    {
        $titular = $request->user();
        abort_unless($titular->isTitular(), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Rules\Password::defaults()],
        ], [
            'email.unique' => 'Este e-mail já está em uso.',
        ]);

        // Não dispara Registered: o dependente compartilha as categorias da família.
        User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'is_admin' => false,
            'account_owner_id' => $titular->id,
        ]);

        return redirect()->route('dependentes')->with('status', 'Dependente adicionado.');
    }

    public function destroy(Request $request, User $dependent)
    {
        $titular = $request->user();
        abort_unless($titular->isTitular() && $dependent->account_owner_id === $titular->id, 403);

        $dependent->delete();

        return redirect()->route('dependentes')->with('status', 'Dependente removido.');
    }
}
