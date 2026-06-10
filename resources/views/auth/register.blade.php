@extends('layouts.guest')

@section('title', 'Criar conta · StabilMoney')

@section('content')
    {{-- Cabeçalho da tela --}}
    <h1 class="text-center text-[20px] font-bold [font-family:var(--font-head)] tracking-[-0.01em]">Criar conta</h1>
    <p class="mt-1 mb-6 text-center text-[13px] text-[var(--ink-3)]">Comece a organizar suas finanças em minutos.</p>

    <form method="POST" action="{{ route('register') }}" class="grid gap-4">
        @csrf

        {{-- Nome --}}
        <div class="field">
            <label for="name">Nome</label>
            <input id="name" class="input" type="text" name="name" value="{{ old('name') }}"
                   required autofocus autocomplete="name" placeholder="Seu nome" />
            @error('name')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        {{-- E-mail --}}
        <div class="field">
            <label for="email">E-mail</label>
            <input id="email" class="input" type="email" name="email" value="{{ old('email') }}"
                   required autocomplete="email" inputmode="email" placeholder="voce@exemplo.com" />
            @error('email')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        {{-- Senha --}}
        <div class="field">
            <label for="password">Senha</label>
            <input id="password" class="input" type="password" name="password"
                   required autocomplete="new-password" placeholder="Mínimo de 8 caracteres" />
            @error('password')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        {{-- Confirmar senha --}}
        <div class="field">
            <label for="password_confirmation">Confirmar senha</label>
            <input id="password_confirmation" class="input" type="password" name="password_confirmation"
                   required autocomplete="new-password" placeholder="Repita a senha" />
            @error('password_confirmation')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="btn-primary w-full">Criar conta</button>
    </form>

    {{-- Rodapé: link para entrar --}}
    <p class="mt-6 text-center text-[13px] text-[var(--ink-3)]">
        Já tem conta?
        <a href="{{ route('login') }}"
           class="font-semibold text-[var(--brand-600)] hover:underline [[data-theme=dark]_&]:text-[var(--brand-300)]">
            Entrar
        </a>
    </p>
@endsection
