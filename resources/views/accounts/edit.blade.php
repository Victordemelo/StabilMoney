@extends('layouts.app')

@section('title', 'Editar conta')

@section('content')
    <div class="section-head">
        <h2>Editar conta</h2>
        <span class="sub">Atualize os dados de "{{ $account->name }}"</span>
        <div class="head-actions">
            <form method="POST" action="{{ route('accounts.destroy', $account) }}"
                  onsubmit="return confirm('Excluir esta conta? Contas com transações não podem ser excluídas.')">
                @csrf
                @method('DELETE')
                <button class="btn-danger" type="submit">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 7h16M9 7V5a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 5v2M6.5 7l.8 12a2 2 0 0 0 2 1.9h5.4a2 2 0 0 0 2-1.9l.8-12M10 11v6M14 11v6"/></svg>
                    Excluir
                </button>
            </form>
        </div>
    </div>

    @include('accounts._form')
@endsection
