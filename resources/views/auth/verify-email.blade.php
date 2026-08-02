@extends('layouts.auth')

@section('title', 'Verificar e-mail')

{{-- Painel visual (mesma linguagem do StabilMoney Login.html v2) --}}
@section('eyebrow')
    Último passo do cadastro
@endsection

@section('headline')
    Confirme seu e-mail e <em>comece</em> a usar.
@endsection

@section('sub')
    Enviamos um link de confirmação para o e-mail que você cadastrou. Um clique nele e sua conta está pronta.
@endsection

@section('features')
    <div class="av-feat"><span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/></svg></span>É o e-mail que devolve o acesso se você perder a senha</div>
    <div class="av-feat"><span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 2 4 5v6c0 5 3.5 8.5 8 11 4.5-2.5 8-6 8-11V5l-8-3Z"/><path d="m9 12 2 2 4-4"/></svg></span>Confirma que a conta é mesmo sua</div>
    <div class="av-feat"><span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 6 9 17l-5-5"/></svg></span>Leva um clique — e não pedimos nada além disso</div>
@endsection

{{-- Card do formulário --}}
@section('card')
    <div class="ac-head">
        <h1>Verifique seu e-mail</h1>
        <p>Obrigado por se cadastrar! Antes de começar, confirme seu endereço de e-mail clicando no link
           que acabamos de enviar. Se você não recebeu, enviamos outro com prazer.</p>
    </div>

    {{-- Aviso de novo link enviado --}}
    @if (session('status') == 'verification-link-sent')
        <div class="auth-status" role="status">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 5-5.5"/></svg>
            Um novo link de verificação foi enviado para o e-mail cadastrado.
        </div>
    @endif

    {{-- Reenviar e-mail de verificação --}}
    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <button type="submit" class="btn-primary">Reenviar e-mail de verificação
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </button>
    </form>

    {{-- Sair (ação secundária: não compete com o botão principal) --}}
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="btn-quiet">Sair</button>
    </form>
@endsection
