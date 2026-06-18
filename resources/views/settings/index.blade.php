@extends('layouts.app')

@section('title', 'Configurações')

@section('content')
<section class="view">
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

    <div class="card form-card">
        @if ($tab === 'seguranca')
            @include('profile.partials.update-password-form')
        @elseif ($tab === 'conta')
            @include('profile.partials.delete-user-form')
        @endif
    </div>
</section>
@endsection
