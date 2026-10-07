@extends('layouts.auth')

@section('title', 'Criar conta com o Google')

{{--
    Cadastro pelo Google (out/2026 — GoogleLoginController). A conta NÃO nasce no retorno do
    Google: a pessoa vê os dados recebidos e aceita os Termos e a Política aqui, e a prova do
    aceite (data, versão, IP) é gravada como no cadastro pelo formulário.
--}}
@section('eyebrow')
    Conta nova
@endsection

@section('headline')
    Falta só um passo para entrar.
@endsection

@section('sub')
    Do Google recebemos apenas o seu nome e o seu e-mail. Nunca a senha, os contatos ou os arquivos da conta Google.
@endsection

@section('features')
    <div class="av-feat"><span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 2 4 5v6c0 5 3.5 8.5 8 11 4.5-2.5 8-6 8-11V5l-8-3Z"/><path d="m9 12 2 2 4-4"/></svg></span>Seu e-mail já vem confirmado pelo Google</div>
    <div class="av-feat"><span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></span>Quer senha também? Crie depois pelo "Esqueci a senha"</div>
@endsection

@section('card')
    <div class="ac-head">
        <h1>Criar sua conta</h1>
        <p>Confira os dados que vieram do Google e aceite os termos para continuar.</p>
    </div>

    <dl class="google-dados">
        <div><dt>Nome</dt><dd>{{ $nome }}</dd></div>
        <div><dt>E-mail</dt><dd>{{ $email }}</dd></div>
    </dl>

    <form method="POST" action="{{ route('google.criar-conta') }}">
        @csrf

        <label class="check terms">
            <input type="checkbox" id="terms" name="terms" value="1" @checked(old('terms')) required />
            <span class="box"><svg viewBox="0 0 24 24" fill="none"><path d="M5 12l4 4 10-10"/></svg></span>
            <span>Concordo com os <a href="{{ route('termos') }}" target="_blank" rel="noopener">Termos de Uso</a> e a <a href="{{ route('privacidade') }}" target="_blank" rel="noopener">Política de Privacidade</a> do Stabil Money.</span>
        </label>
        @error('terms')
            <p class="field-error" style="margin: -14px 0 16px;">{{ $message }}</p>
        @enderror

        <button type="submit" class="btn-primary">Criar conta e entrar
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </button>
    </form>

    <p class="ac-alt">Não é você? <a href="{{ route('login') }}">Voltar para o login</a></p>
@endsection
