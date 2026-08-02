@extends('layouts.auth')

@section('title', 'Confirmar senha')

{{-- Painel visual (mesma linguagem do StabilMoney Login.html v2) --}}
@section('eyebrow')
    Área protegida
@endsection

@section('headline')
    Confirme que é <em>você</em> antes de continuar.
@endsection

@section('sub')
    Ações sensíveis pedem a senha de novo. É o que impede que um aparelho esquecido aberto vire uma conta perdida.
@endsection

@section('features')
    <div class="av-feat"><span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></span>Sua senha é pedida só nas ações críticas</div>
    <div class="av-feat"><span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 2 4 5v6c0 5 3.5 8.5 8 11 4.5-2.5 8-6 8-11V5l-8-3Z"/><path d="m9 12 2 2 4-4"/></svg></span>Tentativas repetidas são bloqueadas</div>
    <div class="av-feat"><span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M7 15h5"/></svg></span>Você acompanha os dispositivos conectados</div>
@endsection

{{-- Card do formulário --}}
@section('card')
    <div class="ac-head">
        <h1>Confirmar senha</h1>
        <p>Esta é uma área segura. Confirme sua senha antes de continuar.</p>
    </div>

    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf

        {{-- Senha --}}
        <div class="field">
            <label for="password">Senha</label>
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

        <button type="submit" class="btn-primary spaced">Confirmar
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </button>
    </form>

    {{-- Saída da tela: o layout v2 não tem menu nem marca clicável, e a rota de
         "esqueci a senha" é só para visitante (middleware guest) — quem está logado
         precisa de um caminho de volta que funcione. --}}
    <p class="ac-alt">Prefere não continuar agora? <a href="{{ route('dashboard') }}">Voltar para o app</a></p>
@endsection
