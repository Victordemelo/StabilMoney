@extends('layouts.auth')

@section('title', 'Recuperar senha')

{{-- Painel visual (mesma linguagem do StabilMoney Login.html v2) --}}
@section('eyebrow')
    Recuperação de acesso
@endsection

@section('headline')
    Esqueceu a senha? A gente <em>devolve</em> o acesso.
@endsection

@section('sub')
    Informe o e-mail da sua conta e enviaremos um link para você criar uma nova senha, com segurança.
@endsection

@section('features')
    <div class="av-feat"><span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/></svg></span>Link enviado só para o e-mail cadastrado</div>
    <div class="av-feat"><span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 2 4 5v6c0 5 3.5 8.5 8 11 4.5-2.5 8-6 8-11V5l-8-3Z"/><path d="m9 12 2 2 4-4"/></svg></span>Ninguém descobre se um e-mail tem conta aqui</div>
    <div class="av-feat"><span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 7v5.2l3.2 2"/></svg></span>O link expira sozinho depois de um tempo</div>
@endsection

{{-- Card do formulário --}}
@section('card')
    <div class="ac-head">
        <h1>Recuperar senha</h1>
        <p>Informe seu e-mail e enviaremos um link para você criar uma nova senha.</p>
    </div>

    {{-- Status da sessão (link de redefinição enviado) --}}
    @if (session('status'))
        <div class="auth-status" role="status">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 5-5.5"/></svg>
            {{ session('status') }}
        </div>
    @endif

    {{-- Erro no banner (e não sob o campo): a tela tem um campo só, e a mensagem que
         mais cai aqui é o aviso de que o app ainda não envia e-mail — texto longo, que
         precisa de espaço e destaque para quem está trancado fora da conta. --}}
    @error('email')
        <div class="auth-error long" role="alert">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
            <span>{{ $message }}</span>
        </div>
    @enderror

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        {{-- E-mail --}}
        <div class="field">
            <label for="email">E-mail</label>
            <div class="input">
                <svg class="lead" viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/></svg>
                <input type="email" id="email" name="email" value="{{ old('email') }}"
                       placeholder="voce@email.com" autocomplete="email" inputmode="email" required autofocus />
            </div>
        </div>

        <button type="submit" class="btn-primary spaced">Enviar link de redefinição
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </button>
    </form>

    <p class="ac-alt">Lembrou a senha? <a href="{{ route('login') }}">Voltar para entrar</a></p>
@endsection
