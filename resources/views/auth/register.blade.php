@extends('layouts.auth')

@section('title', 'Criar conta · StabilMoney')

{{-- Painel visual (textos do StabilMoney Cadastro.html v2) --}}
@section('eyebrow')
    Comece grátis em 1 minuto
@endsection

@section('headline')
    Dê o primeiro passo rumo à sua <em>liberdade financeira</em>.
@endsection

@section('sub')
    Junte-se a milhares de pessoas que transformaram a relação com o dinheiro. Sem taxas escondidas, sem complicação.
@endsection

@section('features')
    <div class="av-feat"><span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 6 9 17l-5-5"/></svg></span>Conta 100% gratuita, sem cartão de crédito</div>
    <div class="av-feat"><span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 2 4 5v6c0 5 3.5 8.5 8 11 4.5-2.5 8-6 8-11V5l-8-3Z"/><path d="m9 12 2 2 4-4"/></svg></span>Seus dados protegidos com criptografia</div>
    <div class="av-feat"><span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8Z"/></svg></span>Configuração rápida e categorização automática</div>
@endsection

{{-- Card do formulário --}}
@section('card')
    <div class="ac-head">
        <h1>Crie sua conta</h1>
        <p>Já é cliente? <a href="{{ route('login') }}">Faça login</a></p>
    </div>

    <form method="POST" action="{{ route('register') }}">
        @csrf

        {{-- Nome --}}
        <div class="field">
            <label for="name">Nome completo</label>
            <div class="input">
                <svg class="lead" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="8" r="3.6"/><path d="M5 20c0-3.6 3.1-6 7-6s7 2.4 7 6"/></svg>
                <input type="text" id="name" name="name" value="{{ old('name') }}"
                       placeholder="Seu nome" autocomplete="name" required autofocus />
            </div>
            @error('name')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        {{-- E-mail --}}
        <div class="field">
            <label for="email">E-mail</label>
            <div class="input">
                <svg class="lead" viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/></svg>
                <input type="email" id="email" name="email" value="{{ old('email') }}"
                       placeholder="voce@email.com" autocomplete="email" inputmode="email" required />
            </div>
            @error('email')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        {{-- Senha (com medidor de força via sm/auth.js) --}}
        <div class="field">
            <label for="password">Senha</label>
            <div class="input">
                <svg class="lead" viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                <input type="password" id="password" name="password"
                       placeholder="Crie uma senha forte" autocomplete="new-password" required />
                <button type="button" class="toggle" data-toggle="password" aria-label="Mostrar senha">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>
            <div class="strength" id="strength" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
            <div class="strength-txt" id="strengthTxt"></div>
            @error('password')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        {{-- Termos de uso (obrigatório; validado também no servidor) --}}
        <label class="check terms">
            <input type="checkbox" id="terms" name="terms" value="1" @checked(old('terms')) required />
            <span class="box"><svg viewBox="0 0 24 24" fill="none"><path d="M5 12l4 4 10-10"/></svg></span>
            <span>Concordo com os <a href="#">Termos de Uso</a> e a <a href="#">Política de Privacidade</a> da StabilMoney.</span>
        </label>
        @error('terms')
            <p class="field-error" style="margin: -14px 0 16px;">{{ $message }}</p>
        @enderror

        <button type="submit" class="btn-primary">Criar conta gratuita
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </button>
    </form>

    <p class="ac-alt">Já tem uma conta? <a href="{{ route('login') }}">Entrar</a></p>
@endsection
