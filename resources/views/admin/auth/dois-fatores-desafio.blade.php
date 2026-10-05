@extends('layouts.admin-auth')
@section('title', 'Verificação')

@section('content')
    <div class="ac-head">
        <h1>Código do autenticador</h1>
        <p>Digite os 6 dígitos do app. Se você perdeu o celular, use um dos códigos de recuperação que guardou.</p>
    </div>

    @if ($errors->any())
        <div class="auth-error" role="alert">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
            <span>{{ $errors->first() }}</span>
        </div>
    @endif

    <form method="POST" action="{{ route('painel.2fa.verificar') }}">
        @csrf
        <div class="field">
            <label for="codigo">Código ou código de recuperação</label>
            <div class="input">
                <input class="painel-codigo" type="text" id="codigo" name="codigo" required autofocus
                       inputmode="numeric" autocomplete="one-time-code" data-no-money placeholder="000000">
            </div>
        </div>
        <button type="submit" class="btn-primary spaced"><span class="btn-label">Entrar no painel</span></button>
    </form>

    <form method="POST" action="{{ route('painel.logout') }}" class="painel-sair">
        @csrf
        <button type="submit" class="link">Cancelar e sair</button>
    </form>
@endsection
