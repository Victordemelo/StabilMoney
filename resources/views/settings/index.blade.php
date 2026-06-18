@extends('layouts.app')

@section('title', 'Configurações')

@section('content')
<section class="view">
    <div class="section-head">
        <h2>Configurações</h2>
        <span class="sub">Segurança e conta</span>
    </div>

    <div class="settings-layout">
        {{-- Subabas (navegação server-routed; vira barra rolável no mobile) --}}
        <nav class="settings-tabs" aria-label="Seções das configurações">
            @foreach ($tabs as $slug => $label)
                <a class="settings-tab {{ $tab === $slug ? 'active' : '' }}"
                   href="{{ route('settings', $slug) }}"
                   @if ($tab === $slug) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>

        <div class="settings-panel">
            @if ($tab === 'seguranca')
                <div class="card span12">
                    @include('profile.partials.update-password-form')
                </div>
            @elseif ($tab === 'conta')
                <div class="card span12">
                    @include('profile.partials.delete-user-form')
                </div>
            @endif
        </div>
    </div>
</section>
@endsection
