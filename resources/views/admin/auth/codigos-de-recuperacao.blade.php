@extends('layouts.admin-auth')
@section('title', 'Códigos de recuperação')

@section('content')
    <div class="ac-head">
        <h1>Guarde estes códigos</h1>
        <p>Eles aparecem <strong>uma única vez</strong>. Cada um serve para entrar uma vez, no lugar do código do autenticador — é a sua saída se o celular quebrar, for roubado ou trocar de número. Guarde longe do celular (papel, cofre de senhas) e não compartilhe em prints.</p>
    </div>

    <ul class="painel-codigos">
        @foreach ($codigos as $codigo)
            <li>{{ $codigo }}</li>
        @endforeach
    </ul>

    <a href="{{ route('painel.home') }}" class="btn-primary spaced painel-botao-link">
        <span class="btn-label">Já guardei — ir para o painel</span>
    </a>
@endsection
