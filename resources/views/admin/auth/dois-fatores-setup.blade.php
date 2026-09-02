@extends('layouts.admin-auth')
@section('title', 'Configurar o autenticador')

@section('content')
    <h2 style="font-family:Sora,sans-serif;font-size:17px;margin:0 0 8px;color:var(--text)">Configure o autenticador</h2>
    <p style="font-size:13px;color:var(--muted);margin:0 0 18px;line-height:1.55">
        O painel não abre sem segundo fator. Escaneie o código no Google Authenticator,
        Authy ou 1Password e digite os 6 dígitos para confirmar.
    </p>

    @if ($qr)
        <div style="background:#fff;padding:14px;border-radius:12px;display:grid;place-items:center;margin-bottom:16px">
            {!! $qr !!}
        </div>
    @endif

    <details style="margin-bottom:18px">
        <summary style="font-size:13px;color:var(--muted);cursor:pointer">Não consigo escanear</summary>
        <p style="font-size:13px;color:var(--text);margin:10px 0 0;line-height:1.6">
            Cadastre manualmente com esta chave:
            <code style="display:block;margin-top:6px;font-family:ui-monospace,monospace;font-size:13px;
                         letter-spacing:.08em;word-break:break-all">{{ $segredo }}</code>
        </p>
    </details>

    @if ($errors->any())
        <div class="flash-error" role="alert" style="margin-bottom:14px">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('painel.2fa.confirmar') }}">
        @csrf
        <div class="field">
            <label for="codigo">Código do autenticador</label>
            <input class="input" type="text" id="codigo" name="codigo" required autofocus
                   inputmode="numeric" autocomplete="one-time-code" maxlength="7"
                   data-no-money style="letter-spacing:.3em;text-align:center;font-size:19px">
        </div>
        <button type="submit" class="btn-primary" style="width:100%">Confirmar</button>
    </form>
@endsection
