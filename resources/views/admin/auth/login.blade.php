@extends('layouts.admin-auth')
@section('title', 'Entrar')

@section('content')
    <div class="ac-head">
        <h1>Entrar no painel</h1>
        <p>Depois da senha ainda vem o código do autenticador — senha sozinha não abre o painel.</p>
    </div>

    @if ($errors->any())
        <div class="auth-error" role="alert">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
            <span>{{ $errors->first() }}</span>
        </div>
    @endif

    <form method="POST" action="{{ route('painel.autenticar') }}">
        @csrf

        <div class="field">
            <label for="email">E-mail</label>
            <div class="input">
                <svg class="lead" viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/></svg>
                <input type="email" id="email" name="email" required autofocus
                       autocomplete="username" placeholder="admin@exemplo.com" value="{{ old('email') }}">
            </div>
        </div>

        <div class="field">
            <label for="password">Senha</label>
            <div class="input">
                <svg class="lead" viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                <input type="password" id="password" name="password" required
                       autocomplete="current-password" placeholder="••••••••">
                <button type="button" class="toggle" data-toggle="password" aria-label="Mostrar senha">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>
        </div>

        <button type="submit" class="btn-primary spaced">
            <span class="btn-label">Entrar</span>
            <svg class="btn-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </button>
    </form>
@endsection
