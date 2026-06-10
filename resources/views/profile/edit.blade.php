@extends('layouts.app')

@section('title', 'Configurações · StabilMoney')

@section('content')
    <section class="view">
        <div class="section-head">
            <h2>Configurações</h2>
            <span class="sub">Gerencie seu perfil e as preferências da conta</span>
        </div>

        <div class="grid">
            {{-- Perfil (nome / e-mail) --}}
            <div class="card span12">
                @include('profile.partials.update-profile-information-form')
            </div>

            {{-- Alterar senha --}}
            <div class="card span6">
                @include('profile.partials.update-password-form')
            </div>

            {{-- Excluir conta --}}
            <div class="card span6">
                @include('profile.partials.delete-user-form')
            </div>
        </div>
    </section>
@endsection
