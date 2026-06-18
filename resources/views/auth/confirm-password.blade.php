@extends('layouts.guest')

@section('title', 'Confirmar senha')

@section('content')
    {{-- Cabeçalho da tela --}}
    <h1 class="text-center text-[20px] font-bold [font-family:var(--font-head)] tracking-[-0.01em]">Confirmar senha</h1>
    <p class="mt-1 mb-6 text-center text-[13px] text-[var(--ink-3)]">
        Esta é uma área segura. Confirme sua senha antes de continuar.
    </p>

    <form method="POST" action="{{ route('password.confirm') }}" class="flex flex-col gap-4">
        @csrf

        {{-- Senha --}}
        <div class="field">
            <label for="password">Senha</label>
            <input id="password" class="input" type="password" name="password"
                   required autofocus autocomplete="current-password" placeholder="••••••••" />
            @error('password')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="btn-primary w-full">Confirmar</button>
    </form>
@endsection
