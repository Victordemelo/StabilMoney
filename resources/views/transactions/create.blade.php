@extends('layouts.app')

@section('title', 'Nova transação')

@section('content')
    <div class="section-head">
        <h2>Nova transação</h2>
        <span class="sub">Registre uma receita ou despesa</span>
    </div>

    @if ($accounts->isEmpty())
        {{-- Sem conta não dá para registrar transação --}}
        <div class="grid">
            <div class="card span12 form-card">
                <div class="empty-state">
                    <div class="pico">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 9.5h19M6 15h4"/></svg>
                    </div>
                    <h3>Crie uma conta primeiro</h3>
                    <p>Para registrar transações você precisa de pelo menos uma conta ou carteira.</p>
                    <a class="btn-primary" href="{{ route('accounts.create') }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>
                        Criar conta
                    </a>
                </div>
            </div>
        </div>
    @else
        @include('transactions._form', ['transaction' => null])
    @endif
@endsection
