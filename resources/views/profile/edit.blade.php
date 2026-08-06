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

    $nascimento = old('birth_date', optional($user->birth_date)->format('Y-m-d'));
    $sexo = old('gender', $user->gender);
@endphp

<section class="view settings-view">
    <div class="section-head">
        <h2>Meu perfil</h2>
        <span class="sub">Seus dados pessoais</span>
    </div>

    @if ($errors->any())
        <div class="flash-error" role="alert">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
            <ul>@foreach ($errors->all() as $erro)<li>{{ $erro }}</li>@endforeach</ul>
        </div>
    @endif

    {{-- UM formulário envolvendo os dois cards: é o mesmo cadastro, separado em
         blocos só para não virar uma coluna estreita e comprida. O corpo reusa o
         `.settings-body` (grid de 12 colunas, cards de altura igual). --}}
    <form method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data">
        @csrf
        @method('patch')

        <div class="settings-body">
            {{-- ---------- Identidade ---------- --}}
            <div class="card sec-card span6">
                <div class="card-head">
                    <h3>Quem é você</h3>
                    <span class="chip">{{ $user->isTitular() ? 'Titular' : 'Dependente' }}</span>
                </div>

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

                <div class="field">
                    <label for="name">Nome</label>
                    <input class="input @error('name') input-error @enderror" type="text" id="name" name="name"
                           value="{{ old('name', $user->name) }}" required autocomplete="name">
                    @error('name')<div class="field-error">{{ $message }}</div>@enderror
                </div>

                <div class="form-row">
                    <div class="field">
                        <label for="birth_date">Data de nascimento <span class="hint">(opcional)</span></label>
                        <input class="input @error('birth_date') input-error @enderror" type="date" id="birth_date"
                               name="birth_date" value="{{ $nascimento }}"
                               max="{{ now()->toDateString() }}" min="1900-01-01" autocomplete="bday">
                        @error('birth_date')<div class="field-error">{{ $message }}</div>@enderror
                    </div>

                    <div class="field">
                        <label for="gender">Sexo <span class="hint">(opcional)</span></label>
                        <select class="input @error('gender') input-error @enderror" id="gender" name="gender">
                            <option value="">Selecione</option>
                            @foreach (\App\Models\User::GENEROS as $valor => $rotulo)
                                <option value="{{ $valor }}" @selected($sexo === $valor)>{{ $rotulo }}</option>
                            @endforeach
                        </select>
                        @error('gender')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>

            {{-- ---------- Contato ---------- --}}
            <div class="card sec-card span6">
                <div class="card-head">
                    <h3>Como falamos com você</h3>
                    <span class="chip">Contato</span>
                </div>

                <p class="sec-card-desc">
                    O e-mail é o que recupera sua conta — por isso trocá-lo pede a senha atual.
                </p>

                <div class="field">
                    <label for="email">E-mail</label>
                    <input class="input @error('email') input-error @enderror" type="email" id="email" name="email"
                           value="{{ old('email', $user->email) }}" required autocomplete="email" inputmode="email">
                    @error('email')<div class="field-error">{{ $message }}</div>@enderror
                </div>

                <div class="field">
                    <label for="phone">Telefone <span class="hint">(opcional)</span></label>
                    <input class="input @error('phone') input-error @enderror" type="tel" id="phone" name="phone"
                           value="{{ old('phone', $user->phone) }}" placeholder="(11) 90000-0000" autocomplete="tel">
                    @error('phone')<div class="field-error">{{ $message }}</div>@enderror
                </div>

                {{-- Senha atual: exigida SÓ quando o e-mail muda (ProfileUpdateRequest).
                     O campo aparece assim que o e-mail é editado — pedir de saída, para
                     quem só quer trocar o telefone, seria pedágio à toa. --}}
                <div class="field" id="campoSenhaAtual" @if (! $errors->has('current_password')) hidden @endif>
                    <label for="current_password">Senha atual</label>
                    <input class="input @error('current_password') input-error @enderror" type="password"
                           id="current_password" name="current_password" autocomplete="current-password"
                           placeholder="Confirme com a sua senha">
                    @error('current_password')<div class="field-error">{{ $message }}</div>@enderror
                    <span class="hint">Só para confirmar a troca de e-mail.</span>
                </div>

                <div class="perfil-acoes">
                    <button class="btn-primary" type="submit">Salvar alterações</button>
                </div>
            </div>
        </div>
    </form>
</section>

<script nonce="{{ Vite::cspNonce() }}">
    (function () {
        // Pré-visualização da foto escolhida antes de salvar
        var input = document.getElementById('avatar');
        var preview = document.getElementById('avatarPreview');
        if (input && preview) {
            input.addEventListener('change', function () {
                var file = input.files && input.files[0];
                if (!file) return;
                var url = URL.createObjectURL(file);
                preview.innerHTML = '<img src="' + url + '" alt="Pré-visualização">';
            });
        }

        // O campo de senha só aparece quando o e-mail muda de verdade — a mesma regra
        // do servidor (o ProfileUpdateRequest exige `current_password` só nesse caso).
        // Voltando ao valor original, o campo some de novo.
        var email = document.getElementById('email');
        var campoSenha = document.getElementById('campoSenhaAtual');
        if (email && campoSenha) {
            var original = email.defaultValue;
            email.addEventListener('input', function () {
                campoSenha.hidden = email.value.trim().toLowerCase() === original.trim().toLowerCase();
            });
        }
    })();
</script>
@endsection
