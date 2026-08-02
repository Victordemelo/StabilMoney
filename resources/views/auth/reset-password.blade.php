@extends('layouts.auth')

@section('title', 'Redefinir senha')

{{-- Painel visual (mesma linguagem do StabilMoney Login.html v2) --}}
@section('eyebrow')
    Falta só um passo
@endsection

@section('headline')
    Crie uma senha <em>nova</em> e volte para o controle.
@endsection

@section('sub')
    Escolha uma senha que só você saiba. Assim que salvar, é com ela que você entra no StabilMoney.
@endsection

@section('features')
    <div class="av-feat"><span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></span>Senha guardada com criptografia forte</div>
    <div class="av-feat"><span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 2 4 5v6c0 5 3.5 8.5 8 11 4.5-2.5 8-6 8-11V5l-8-3Z"/><path d="m9 12 2 2 4-4"/></svg></span>Nem nós conseguimos ler a sua senha</div>
    <div class="av-feat"><span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 6 9 17l-5-5"/></svg></span>Pronto: é só entrar com a nova senha</div>
@endsection

{{-- Card do formulário --}}
@section('card')
    <div class="ac-head">
        <h1>Redefinir senha</h1>
        <p>Crie uma nova senha para a sua conta.</p>
    </div>

    <form method="POST" action="{{ route('password.store') }}">
        @csrf

        {{-- Token de redefinição de senha --}}
        <input type="hidden" name="token" value="{{ $request->route('token') }}" />

        {{-- E-mail --}}
        <div class="field">
            <label for="email">E-mail</label>
            <div class="input">
                <svg class="lead" viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/></svg>
                <input type="email" id="email" name="email" value="{{ old('email', $request->email) }}"
                       placeholder="voce@email.com" autocomplete="email" inputmode="email" required autofocus />
            </div>
            @error('email')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        {{-- Nova senha (medidor de força reaproveitado do cadastro, via sm/auth.js) --}}
        <div class="field">
            <label for="password">Nova senha</label>
            <div class="input">
                <svg class="lead" viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                <input type="password" id="password" name="password"
                       placeholder="Mínimo de 8 caracteres" autocomplete="new-password" required />
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

        {{-- Confirmar nova senha (a confirmação continua obrigatória aqui:
             quem redefine não vê o que digita e não pode errar em silêncio) --}}
        <div class="field">
            <label for="password_confirmation">Confirmar nova senha</label>
            <div class="input">
                <svg class="lead" viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                <input type="password" id="password_confirmation" name="password_confirmation"
                       placeholder="Repita a nova senha" autocomplete="new-password" required />
                <button type="button" class="toggle" data-toggle="password_confirmation" aria-label="Mostrar senha">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>
            @error('password_confirmation')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="btn-primary spaced">Redefinir senha
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </button>
    </form>

    <p class="ac-alt">Lembrou a senha antiga? <a href="{{ route('login') }}">Voltar para entrar</a></p>
@endsection
