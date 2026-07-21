<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDependentRequest;
use App\Http\Requests\UpdateDependentRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Gerenciamento de dependentes — apenas o titular (account_owner_id null) acessa.
 * Dependente é um User com account_owner_id apontando para o titular; compartilha
 * a visão financeira da família. Cada dependente pode ter um saldo/limite de
 * gasto: as despesas que ELE lança (made_by_user_id) descontam desse valor.
 */
class DependentController extends Controller
{
    public function index(Request $request)
    {
        $titular = $request->user();
        abort_unless($titular->isTitular(), 403);

        // `gasto` = soma das DESPESAS do MÊS CORRENTE lançadas por cada pessoa
        // (made_by_user_id), pré-agregada para evitar N+1 ao montar os cards.
        $mesInicio = now()->startOfMonth()->toDateString();
        $mesFim = now()->endOfMonth()->toDateString();

        $dependents = $titular->dependents()
            ->withSum(['madeTransactions as gasto' => fn ($q) => $q
                ->where('type', 'expense')->whereBetween('date', [$mesInicio, $mesFim])], 'amount')
            ->orderBy('name')
            ->get();

        // Quanto o próprio titular gastou no mês (mesma base dos cards).
        $gastoTitular = (float) $titular->madeTransactions()
            ->where('type', 'expense')->whereBetween('date', [$mesInicio, $mesFim])->sum('amount');

        return view('dependents.index', compact('dependents', 'gastoTitular'));
    }

    public function store(StoreDependentRequest $request)
    {
        $titular = $request->user();
        $data = $request->validated();

        // Não dispara Registered: o dependente compartilha as categorias da família.
        $dependent = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'is_admin' => false,
            'account_owner_id' => $titular->id,
            'relationship' => $data['relationship'] ?? null,
        ]);
        $dependent->password = Hash::make($data['password']);

        if ($request->hasFile('avatar')) {
            $dependent->avatar_path = $request->file('avatar')->store('avatars', 'public');
        }

        $dependent->save();

        return redirect()->route('dependentes')->with('status', 'Dependente adicionado.');
    }

    public function update(UpdateDependentRequest $request, User $dependent)
    {
        $data = $request->validated();

        $dependent->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'relationship' => $data['relationship'] ?? null,
        ]);

        // Senha só muda se preenchida.
        if (! empty($data['password'])) {
            $dependent->password = Hash::make($data['password']);
        }

        if ($request->hasFile('avatar')) {
            if ($dependent->avatar_path) {
                Storage::disk('public')->delete($dependent->avatar_path);
            }
            $dependent->avatar_path = $request->file('avatar')->store('avatars', 'public');
        }

        $dependent->save();

        return redirect()->route('dependentes')->with('status', 'Dependente atualizado.');
    }

    public function destroy(Request $request, User $dependent)
    {
        $titular = $request->user();
        abort_unless($titular->isTitular() && $dependent->account_owner_id === $titular->id, 403);

        if ($dependent->avatar_path) {
            Storage::disk('public')->delete($dependent->avatar_path);
        }

        $dependent->delete();

        return redirect()->route('dependentes')->with('status', 'Dependente removido.');
    }
}
