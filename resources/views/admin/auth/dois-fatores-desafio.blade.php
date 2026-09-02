@extends('layouts.admin-auth')
@section('title', 'Verificação')

@section('content')
    <h2 style="font-family:Sora,sans-serif;font-size:17px;margin:0 0 8px;color:var(--text)">Código do autenticador</h2>
    <p style="font-size:13px;color:var(--muted);margin:0 0 18px;line-height:1.55">
        Digite os 6 dígitos do app. Se você perdeu o celular, use um dos códigos de
        recuperação que guardou.
    </p>

    @if ($errors->any())
        <div class="flash-error" role="alert" style="margin-bottom:14px">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('painel.2fa.verificar') }}">
        @csrf
        <div class="field">
            <label for="codigo">Código ou código de recuperação</label>
            <input class="input" type="text" id="codigo" name="codigo" required autofocus
                   inputmode="numeric" autocomplete="one-time-code"
                   data-no-money style="letter-spacing:.2em;text-align:center;font-size:19px">
        </div>
        <button type="submit" class="btn-primary" style="width:100%">Entrar no painel</button>
    </form>

    <form method="POST" action="{{ route('painel.logout') }}" style="margin-top:14px">
        @csrf
        <button type="submit" class="btn-ghost" style="width:100%;font-size:13px">Cancelar e sair</button>
    </form>
@endsection
