@extends('layouts.app')

@section('title', 'Editar transação — StabilMoney')

@section('content')
    <div class="section-head">
        <h2>Editar transação</h2>
        <span class="sub">Atualize ou exclua esta movimentação</span>
        <div class="head-actions">
            <form method="POST" action="{{ route('transactions.destroy', $transaction) }}"
                  onsubmit="return confirm('Excluir esta transação? Essa ação não pode ser desfeita.')">
                @csrf
                @method('DELETE')
                <button class="btn-danger" type="submit">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 7h16M9 7V5a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 5v2M6.5 7l.8 12a2 2 0 0 0 2 1.9h5.4a2 2 0 0 0 2-1.9l.8-12M10 11v6M14 11v6"/></svg>
                    Excluir
                </button>
            </form>
        </div>
    </div>

    @include('transactions._form')
@endsection
