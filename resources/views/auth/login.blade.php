@extends('layouts.auth')

@section('title', 'Entrar')

{{-- Painel visual (textos do StabilMoney Login.html v2) --}}
@section('eyebrow')
    Sua estabilidade financeira
@endsection

@section('headline')
    Seu dinheiro com <em>clareza</em>, controle e crescimento.
@endsection

@section('sub')
    Acompanhe gastos, alcance metas e invista com inteligência — tudo num só lugar, do jeito StabilMoney.
@endsection

@section('features')
    <div class="av-feat"><span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 12h4l3 8 4-16 3 8h4"/></svg></span>Visão completa das suas finanças em tempo real</div>
    <div class="av-feat"><span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 2v4M5 9a7 7 0 1 1 14 0c0 4-3 5-3 8H8c0-3-3-4-3-8Z"/><path d="M9 21h6"/></svg></span>Metas inteligentes que você realmente alcança</div>
    <div class="av-feat"><span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3 10h18"/></svg></span>Cartões e contas protegidos com segurança total</div>
@endsection

{{-- Card do formulário --}}
@section('card')
    <div class="ac-head">
        <h1>Bem-vindo de volta 👋</h1>
    </div>

    {{-- Status da sessão (ex.: senha redefinida com sucesso) --}}
    @if (session('status'))
        <div class="auth-status" role="status">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 5-5.5"/></svg>
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        {{-- E-mail --}}
        <div class="field">
            <label for="email">E-mail</label>
            <div class="input">
                <svg class="lead" viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/></svg>
                <input type="email" id="email" name="email" value="{{ old('email') }}"
                       placeholder="voce@email.com" autocomplete="email" inputmode="email" required autofocus />
            </div>
            @error('email')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        {{-- Senha --}}
        <div class="field">
            <label for="password">Senha</label>
            <div class="input">
                <svg class="lead" viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                <input type="password" id="password" name="password"
                       placeholder="••••••••" autocomplete="current-password" required />
                <button type="button" class="toggle" data-toggle="password" aria-label="Mostrar senha">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>
            @error('password')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        {{-- Lembrar de mim + esqueci a senha (marcado por padrão, como no protótipo;
             respeita a escolha do usuário quando o form volta com erro) --}}
        <div class="row-between">
            <label class="check">
                <input type="checkbox" name="remember" @checked(session()->hasOldInput() ? old('remember') : true) />
                <span class="box"><svg viewBox="0 0 24 24" fill="none"><path d="M5 12l4 4 10-10"/></svg></span>
                Lembrar de mim
            </label>

            @if (Route::has('password.request'))
                <a class="link" href="{{ route('password.request') }}">Esqueceu a senha?</a>
            @endif
        </div>

        <button type="submit" class="btn-primary">Entrar
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </button>
    </form>

    <p class="ac-alt">Não tem uma conta? <a href="{{ route('register') }}">Cadastre-se grátis</a></p>
@endsection
