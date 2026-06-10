@extends('layouts.guest')

@section('title', 'Entrar · StabilMoney')

@section('content')
    {{-- Cabeçalho da tela --}}
    <h1 class="text-center text-[20px] font-bold [font-family:var(--font-head)] tracking-[-0.01em]">Entrar</h1>
    <p class="mt-1 mb-6 text-center text-[13px] text-[var(--ink-3)]">Acesse sua conta para continuar.</p>

    {{-- Status da sessão (ex.: senha redefinida com sucesso) --}}
    @if (session('status'))
        <div class="flash" data-flash role="status">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 5-5.5"/></svg>
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="grid gap-4">
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

        {{-- Senha --}}
        <div class="field">
            <label for="password">Senha</label>
            <input id="password" class="input" type="password" name="password"
                   required autocomplete="current-password" placeholder="••••••••" />
            @error('password')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        {{-- Lembrar de mim + esqueci a senha --}}
        <div class="flex items-center justify-between gap-3">
            <label for="remember" class="flex cursor-pointer select-none items-center gap-2 text-[13px] font-medium text-[var(--ink-2)]">
                <input id="remember" type="checkbox" name="remember" @checked(old('remember'))
                       class="h-4 w-4 accent-[var(--brand-600)]" />
                Lembrar de mim
            </label>

            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}"
                   class="text-[13px] font-semibold text-[var(--brand-600)] hover:underline [[data-theme=dark]_&]:text-[var(--brand-300)]">
                    Esqueceu a senha?
                </a>
            @endif
        </div>

        <button type="submit" class="btn-primary w-full">Entrar</button>
    </form>

    {{-- Rodapé: link para criar conta --}}
    <p class="mt-6 text-center text-[13px] text-[var(--ink-3)]">
        Não tem conta?
        <a href="{{ route('register') }}"
           class="font-semibold text-[var(--brand-600)] hover:underline [[data-theme=dark]_&]:text-[var(--brand-300)]">
            Criar conta
        </a>
    </p>
@endsection
