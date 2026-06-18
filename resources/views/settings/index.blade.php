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

    {{-- Conteúdo da aba: cada aba decide seus próprios cards (coluna centrada) --}}
    <div class="settings-body">
        @if ($tab === 'seguranca')
            @include('settings.partials.security')
        @elseif ($tab === 'conta')
            <div class="card">
                @include('profile.partials.delete-user-form')
            </div>
        @endif
    </div>
</section>
@endsection
