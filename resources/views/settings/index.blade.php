@extends('layouts.app')

@section('title', 'Configurações')

@section('content')
<section class="view settings-view">
    <div class="section-head">
        <h2>Configurações</h2>
        <span class="sub">Segurança e conta</span>
    </div>

    {{-- Subabas (pílulas, server-routed) --}}
    <nav class="settings-tabs" aria-label="Seções das configurações">
        @foreach ($tabs as $slug => $label)
            <a class="settings-tab {{ $tab === $slug ? 'active' : '' }}"
               href="{{ route('settings', $slug) }}"
               @if ($tab === $slug) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    {{-- Corpo da aba: grid de 12 colunas (o mesmo do resto do app). Cada card decide
         quanto ocupa com as classes span*; sem classe, ocupa a largura toda. --}}
    <div class="settings-body">
        @if ($tab === 'seguranca')
            @include('settings.partials.security')
        @elseif ($tab === '2fa')
            @include('settings.partials.two-factor')
        @elseif ($tab === 'conta')
            @include('settings.partials.conta')
        @endif
    </div>
</section>
@endsection
