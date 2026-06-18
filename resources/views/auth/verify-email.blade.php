@extends('layouts.guest')

@section('title', 'Verificar e-mail')

@section('content')
    {{-- Cabeçalho da tela --}}
    <h1 class="text-center text-[20px] font-bold [font-family:var(--font-head)] tracking-[-0.01em]">Verifique seu e-mail</h1>
    <p class="mt-1 mb-6 text-center text-[13px] text-[var(--ink-3)]">
        Obrigado por se cadastrar! Antes de começar, confirme seu endereço de e-mail clicando no link
        que acabamos de enviar. Se você não recebeu, enviamos outro com prazer.
    </p>

    {{-- Aviso de novo link enviado --}}
    @if (session('status') == 'verification-link-sent')
        <div class="flash" data-flash role="status">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 5-5.5"/></svg>
            Um novo link de verificação foi enviado para o e-mail cadastrado.
        </div>
    @endif

    <div class="flex flex-col gap-3">
        {{-- Reenviar e-mail de verificação --}}
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="btn-primary w-full">Reenviar e-mail de verificação</button>
        </form>

        {{-- Sair --}}
        <form method="POST" action="{{ route('logout') }}" class="text-center">
            @csrf
            <button type="submit"
                    class="text-[13px] font-semibold text-[var(--ink-3)] hover:underline">
                Sair
            </button>
        </form>
    </div>
@endsection
