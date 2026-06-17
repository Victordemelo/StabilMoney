@extends('layouts.guest')

@section('title', 'Redefinir senha')

@section('content')
    {{-- Cabeçalho da tela --}}
    <h1 class="text-center text-[20px] font-bold [font-family:var(--font-head)] tracking-[-0.01em]">Redefinir senha</h1>
    <p class="mt-1 mb-6 text-center text-[13px] text-[var(--ink-3)]">Crie uma nova senha para a sua conta.</p>

    <form method="POST" action="{{ route('password.store') }}" class="grid gap-4">
        @csrf

        {{-- Token de redefinição de senha --}}
        <input type="hidden" name="token" value="{{ $request->route('token') }}" />

        {{-- E-mail --}}
        <div class="field">
            <label for="email">E-mail</label>
            <input id="email" class="input" type="email" name="email" value="{{ old('email', $request->email) }}"
                   required autofocus autocomplete="email" inputmode="email" />
            @error('email')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        {{-- Nova senha --}}
        <div class="field">
            <label for="password">Nova senha</label>
            <input id="password" class="input" type="password" name="password"
                   required autocomplete="new-password" placeholder="Mínimo de 8 caracteres" />
            @error('password')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        {{-- Confirmar nova senha --}}
        <div class="field">
            <label for="password_confirmation">Confirmar nova senha</label>
            <input id="password_confirmation" class="input" type="password" name="password_confirmation"
                   required autocomplete="new-password" placeholder="Repita a nova senha" />
            @error('password_confirmation')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="btn-primary w-full">Redefinir senha</button>
    </form>
@endsection
