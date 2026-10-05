@extends('layouts.admin-auth')
@section('title', 'Configurar o autenticador')
{{-- Formulário largo: o QR fica AO LADO das instruções, e a tela cabe sem rolagem. --}}
@section('largo', '1')

@section('content')
    <div class="ac-head">
        <h1>Configure o autenticador</h1>
        <p>O painel não abre sem segundo fator. Escaneie o código no Google Authenticator, Authy ou 1Password e digite os 6 dígitos para confirmar.</p>
    </div>

    <div class="painel-setup">
        @if ($qr)
            <div class="painel-qr">{!! $qr !!}</div>
        @endif

        <div class="painel-setup-form">
            <details class="painel-chave">
                <summary>Não consigo escanear</summary>
                <p>Cadastre manualmente com esta chave:</p>
                <code>{{ $segredo }}</code>
            </details>

            @if ($errors->any())
                <div class="auth-error" role="alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            <form method="POST" action="{{ route('painel.2fa.confirmar') }}">
                @csrf
                <div class="field">
                    <label for="codigo">Código do autenticador</label>
                    <div class="input">
                        <input class="painel-codigo" type="text" id="codigo" name="codigo" required autofocus
                               inputmode="numeric" autocomplete="one-time-code" maxlength="7" data-no-money placeholder="000000">
                    </div>
                </div>
                <button type="submit" class="btn-primary spaced"><span class="btn-label">Confirmar</span></button>
            </form>
        </div>
    </div>
@endsection
