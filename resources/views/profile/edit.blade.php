@extends('layouts.app')

@section('title', 'Meu perfil')

@section('content')
@php
    $iniciais = function (string $nome) {
        $p = preg_split('/\s+/', trim($nome), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return mb_strtoupper(count($p) >= 2
            ? mb_substr($p[0], 0, 1) . mb_substr(end($p), 0, 1)
            : mb_substr($p[0] ?? 'U', 0, 2));
    };
@endphp

<section class="view">
    <div class="section-head">
        <h2>Meu perfil</h2>
        <span class="sub">Seus dados pessoais</span>
    </div>

    <div class="grid">
        <div class="card span12 form-card">
            @if ($errors->any())
                <div class="flash-error" role="alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
                    <ul>@foreach ($errors->all() as $erro)<li>{{ $erro }}</li>@endforeach</ul>
                </div>
            @endif

            <form method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data">
                @csrf
                @method('patch')

                {{-- Foto --}}
                <div class="avatar-edit">
                    <span class="avatar-preview" id="avatarPreview">
                        @if ($user->avatarUrl())
                            <img src="{{ $user->avatarUrl() }}" alt="Foto de perfil">
                        @else
                            {{ $iniciais($user->name) }}
                        @endif
                    </span>
                    <div class="avatar-edit-actions">
                        <label class="btn-ghost" for="avatar">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 7h3l1.5-2h7L18 7h3a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1Z"/><circle cx="12" cy="13" r="3.5"/></svg>
                            Trocar foto
                        </label>
                        <input type="file" id="avatar" name="avatar" accept="image/*" hidden>
                        <span class="hint">JPG ou PNG, até 2 MB</span>
                    </div>
                </div>

                {{-- Nome --}}
                <div class="field">
                    <label for="name">Nome</label>
                    <input class="input" type="text" id="name" name="name" value="{{ old('name', $user->name) }}" required autocomplete="name">
                </div>

                {{-- E-mail --}}
                <div class="field">
                    <label for="email">E-mail</label>
                    <input class="input" type="email" id="email" name="email" value="{{ old('email', $user->email) }}" required autocomplete="email" inputmode="email">
                </div>

                {{-- Telefone --}}
                <div class="field">
                    <label for="phone">Telefone <span class="hint">(opcional)</span></label>
                    <input class="input" type="tel" id="phone" name="phone" value="{{ old('phone', $user->phone) }}" placeholder="(11) 90000-0000" autocomplete="tel">
                </div>

                <div class="form-actions">
                    <button class="btn-primary" type="submit">Salvar alterações</button>
                </div>
            </form>
        </div>
    </div>
</section>

<script nonce="{{ Vite::cspNonce() }}">
    // Pré-visualização da foto escolhida antes de salvar
    (function () {
        var input = document.getElementById('avatar');
        var preview = document.getElementById('avatarPreview');
        if (!input || !preview) return;
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file) return;
            var url = URL.createObjectURL(file);
            preview.innerHTML = '<img src="' + url + '" alt="Pré-visualização">';
        });
    })();
</script>
@endsection
