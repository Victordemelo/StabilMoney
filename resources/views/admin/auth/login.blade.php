@extends('layouts.admin-auth')
@section('title', 'Entrar')

@section('content')
    @if ($errors->any())
        <div class="flash-error" role="alert" style="margin-bottom:16px">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('painel.autenticar') }}">
        @csrf

        <div class="field">
            <label for="email">E-mail</label>
            <input class="input" type="email" id="email" name="email" required autofocus
                   autocomplete="username" value="{{ old('email') }}">
        </div>

        <div class="field">
            <label for="password">Senha</label>
            <input class="input" type="password" id="password" name="password" required
                   autocomplete="current-password">
        </div>

        <button type="submit" class="btn-primary" style="width:100%;margin-top:6px">Entrar</button>
    </form>

    <p style="font-size:12px;color:var(--muted);margin:18px 0 0;line-height:1.5">
        Depois da senha ainda vem o código do autenticador — senha sozinha não abre o painel.
    </p>
@endsection
