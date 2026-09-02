@extends('layouts.admin-auth')
@section('title', 'Códigos de recuperação')

@section('content')
    <h2 style="font-family:Sora,sans-serif;font-size:17px;margin:0 0 8px;color:var(--text)">Guarde estes códigos</h2>
    <p style="font-size:13px;color:var(--muted);margin:0 0 16px;line-height:1.55">
        Eles aparecem <strong>uma única vez</strong>. Cada um serve para entrar uma vez,
        no lugar do código do autenticador — é a sua saída se o celular quebrar, for
        roubado ou trocar de número. Guarde longe do celular (papel, cofre de senhas).
    </p>

    <ul style="list-style:none;margin:0 0 18px;padding:14px;border:1px solid var(--line);border-radius:12px;
               display:grid;grid-template-columns:repeat(2,1fr);gap:8px;background:var(--bg)">
        @foreach ($codigos as $codigo)
            <li style="font-family:ui-monospace,monospace;font-size:14px;letter-spacing:.06em;color:var(--text);text-align:center">
                {{ $codigo }}
            </li>
        @endforeach
    </ul>

    <a href="{{ route('painel.home') }}" class="btn-primary" style="display:block;text-align:center;text-decoration:none">
        Já guardei — ir para o painel
    </a>
@endsection
