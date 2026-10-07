@extends('layouts.auth')

@section('title', 'Ligar sua conta Google')

{{--
    Ligar o Google a uma conta que JÁ EXISTE com o mesmo e-mail (out/2026 — GoogleLoginController).
    A ligação pede a senha desta conta: "e-mail confirmado" nem sempre prova que a conta é de quem
    tem o e-mail, e alguém pode ter cadastrado o e-mail de outra pessoa esperando a dona chegar
    pelo Google (pré-sequestro de conta). Quem não sabe a senha usa "Esqueci a senha".
--}}
@section('eyebrow')
    Conta encontrada
@endsection

@section('headline')
    Você já tem uma conta com este e-mail.
@endsection

@section('sub')
    Digite a senha dela uma vez para ligar o Google. Depois, é só entrar com o Google.
@endsection

@section('features')
    <div class="av-feat"><span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 2 4 5v6c0 5 3.5 8.5 8 11 4.5-2.5 8-6 8-11V5l-8-3Z"/><path d="m9 12 2 2 4-4"/></svg></span>A senha prova que a conta é sua</div>
    <div class="av-feat"><span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></span>Se ligou a verificação em duas etapas, ela continua valendo</div>
@endsection

@section('card')
    <div class="ac-head">
        <h1>Ligar sua conta Google</h1>
        <p>Já existe uma conta no Stabil Money com <strong>{{ $email }}</strong>. Digite a senha dela para continuar.</p>
    </div>

    <form method="POST" action="{{ route('google.ligar.confirmar') }}">
        @csrf

        <div class="field">
            <label for="password">Senha da sua conta</label>
            <div class="input">
                <svg class="lead" viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                <input type="password" id="password" name="password"
                       placeholder="••••••••" autocomplete="current-password" required autofocus />
                <button type="button" class="toggle" data-toggle="password" aria-label="Mostrar senha">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>
            @error('password')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="btn-primary spaced">Ligar e entrar
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </button>
    </form>

    <p class="ac-alt">Não lembra a senha? <a href="{{ route('password.request') }}">Esqueci a senha</a></p>
@endsection
