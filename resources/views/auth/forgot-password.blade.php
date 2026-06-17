@extends('layouts.guest')

@section('title', 'Recuperar senha')

@section('content')
    {{-- Cabeçalho da tela --}}
    <h1 class="text-center text-[20px] font-bold [font-family:var(--font-head)] tracking-[-0.01em]">Recuperar senha</h1>
    <p class="mt-1 mb-6 text-center text-[13px] text-[var(--ink-3)]">
        Esqueceu a senha? Sem problema. Informe seu e-mail e enviaremos um link para você criar uma nova.
    </p>

    {{-- Status da sessão (link de redefinição enviado) --}}
    @if (session('status'))
        <div class="flash" data-flash role="status">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 5-5.5"/></svg>
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="grid gap-4">
        @csrf

        {{-- E-mail --}}
        <div class="field">
            <label for="email">E-mail</label>
            <input id="email" class="input" type="email" name="email" value="{{ old('email') }}"
                   required autofocus autocomplete="email" inputmode="email" placeholder="voce@exemplo.com" />
            @error('email')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="btn-primary w-full">Enviar link de redefinição</button>
    </form>

    {{-- Rodapé: voltar para o login --}}
    <p class="mt-6 text-center text-[13px] text-[var(--ink-3)]">
        Lembrou a senha?
        <a href="{{ route('login') }}"
           class="font-semibold text-[var(--brand-600)] hover:underline [[data-theme=dark]_&]:text-[var(--brand-300)]">
            Voltar para entrar
        </a>
    </p>
@endsection
