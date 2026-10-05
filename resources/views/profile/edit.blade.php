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
            {{-- ---------- Cabeçalho: quem é a pessoa + o que falta ----------
                 Dentro do <form> porque "Trocar foto" / "Remover a foto" são campos dele. --}}
            <div class="card perfil-hero">
                <div class="ph-id">
                    <span class="avatar-preview" id="avatarPreview" data-iniciais="{{ $iniciais($user->name) }}">
                        @if ($user->avatarUrl())
                            <img src="{{ $user->avatarUrl() }}" alt="Foto de perfil">
                        @else
                            {{ $iniciais($user->name) }}
                        @endif
                    </span>
                    <div class="ph-text">
                        <h3>{{ $user->name }}</h3>
                        <div class="ph-badges">
                            @if ($user->isTitular())
                                <span class="dp-badge titular">Titular</span>
                            @else
                                <span class="dp-badge">{{ $user->relationshipLabel() ?? 'Dependente' }}</span>
                            @endif
                            @if ($user->pending_email)
                                <span class="ph-tag alerta">Troca de e-mail pendente</span>
                            @elseif ($user->hasVerifiedEmail())
                                <span class="ph-tag ok">E-mail verificado</span>
                            @endif
                            <span class="ph-tag {{ $resumo['doisFatores'] ? 'ok' : '' }}">2FA {{ $resumo['doisFatores'] ? 'ligado' : 'desligado' }}</span>
                        </div>
                        <span class="ph-sub">Na família desde {{ $user->created_at->translatedFormat('F \\d\\e Y') }}</span>
                        <div class="ph-foto">
                            <label class="btn-ghost" for="avatar">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 7h3l1.5-2h7L18 7h3a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1Z"/><circle cx="12" cy="13" r="3.5"/></svg>
                                {{ $user->avatarUrl() ? 'Trocar foto' : 'Adicionar foto' }}
                            </label>
                            <input type="file" id="avatar" name="avatar" accept="image/*" hidden>
                            <span class="hint">JPG ou PNG, até 2 MB</span>
                            {{-- Tirar a foto sem pôr outra. Só aparece quando há foto; vale ao
                                 salvar, como o resto do formulário. --}}
                            @if ($user->avatarUrl())
                                <label class="check">
                                    <input type="checkbox" id="removerFoto" name="remover_foto" value="1" @checked(old('remover_foto'))>
                                    <span class="box"><svg viewBox="0 0 24 24" fill="none"><path d="M5 12l4 4 10-10"/></svg></span>
                                    <span>Remover a foto</span>
                                </label>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Resumo (out/2026, 2ª versão): UM painel com três colunas iguais — rótulo curto,
                     número grande e uma linha de contexto, tudo alinhado no topo. Antes eram três
                     caixas de alturas de conteúdo diferentes, com o número solto no meio. --}}
                @php
                    $outrasPessoas = $resumo['pessoasNaFamilia'] - 1;
                @endphp
                <div class="ph-stats">
                    <div class="ph-stat">
                        <span class="lbl">Pessoas na família</span>
                        <strong>{{ $resumo['pessoasNaFamilia'] }}</strong>
                        <span class="ph-ctx">{{ $outrasPessoas === 0 ? 'Só você por enquanto' : 'Você e mais '.$outrasPessoas }}</span>
                    </div>
                    <div class="ph-stat">
                        <span class="lbl">Lançamentos</span>
                        <strong>{{ $resumo['lancamentosNoMes'] }}</strong>
                        <span class="ph-ctx">seus em {{ now()->translatedFormat('F') }}</span>
                    </div>
                    <div class="ph-stat">
                        <span class="lbl">Perfil completo</span>
                        <strong>{{ $resumo['completo'] }}%</strong>
                        <div class="dp-bar" role="presentation"><span class="dp-bar-fill" style="--fatia: {{ $resumo['completo'] }}%"></span></div>
                        <span class="ph-ctx ph-falta">
                            @if ($resumo['faltando'])
                                Falta: {{ implode(', ', $resumo['faltando']) }}
                            @else
                                Tudo preenchido
                            @endif
                        </span>
                    </div>
                </div>
            </div>

            {{-- ---------- Seus dados: identidade + contato num card só, um Salvar (out/2026) ----------
                 Grade com ÁREAS: no desktop cada linha casa um campo de cada lado (Nome ↔ E-mail,
                 Nascimento/Sexo ↔ Telefone), então os campos ficam alinhados mesmo com textos de
                 tamanhos diferentes; no celular as áreas empilham por assunto. --}}
            <div class="card sec-card span12 perfil-dados-card">
                <div class="card-head">
                    <h3>Seus dados</h3>
                    <span class="chip">{{ $user->isTitular() ? 'Titular' : 'Dependente' }}</span>
                </div>

                <div class="perfil-dados">
                    <div class="pd-sec pd-quem">
                        <h4>Quem é você</h4>
                        <p class="sec-card-desc">O nome aparece para a família nos seus lançamentos. Nascimento e sexo são opcionais e ficam só no seu perfil.</p>
                    </div>
                    <div class="pd-sec pd-contato">
                        <h4>Como falamos com você</h4>
                        <p class="sec-card-desc">O e-mail é o que recupera sua conta — por isso trocá-lo pede a senha atual.</p>
                    </div>

                    <div class="field pd-nome">
                        <label for="name">Nome</label>
                        <input class="input @error('name') input-error @enderror" type="text" id="name" name="name"
                               value="{{ old('name', $user->name) }}" required autocomplete="name">
                        @error('name')<div class="field-error">{{ $message }}</div>@enderror
                    </div>

                    <div class="field pd-email">
                        <label for="email">E-mail</label>
                        <input class="input @error('email') input-error @enderror" type="email" id="email" name="email"
                               value="{{ old('email', $user->email) }}" required autocomplete="email" inputmode="email">
                        @error('email')<div class="field-error">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-row pd-nasc">
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

                    <div class="field pd-tel">
                        <label for="phone">Telefone <span class="hint">(opcional)</span></label>
                        <input class="input @error('phone') input-error @enderror" type="tel" id="phone" name="phone"
                               value="{{ old('phone', $user->phone) }}" placeholder="(11) 90000-0000" autocomplete="tel">
                        @error('phone')<div class="field-error">{{ $message }}</div>@enderror
                    </div>

                    {{-- Senha atual: exigida SÓ quando o e-mail muda (ProfileUpdateRequest).
                         O campo aparece assim que o e-mail é editado — pedir de saída, para
                         quem só quer trocar o telefone, seria pedágio à toa. --}}
                    <div class="field pd-senha" id="campoSenhaAtual" @if (! $errors->has('current_password')) hidden @endif>
                        <label for="current_password">Senha atual</label>
                        <input class="input @error('current_password') input-error @enderror" type="password"
                               id="current_password" name="current_password" autocomplete="current-password"
                               placeholder="Confirme com a sua senha">
                        @error('current_password')<div class="field-error">{{ $message }}</div>@enderror
                        <span class="hint">Só para confirmar a troca de e-mail.</span>
                    </div>
                </div>

                <div class="perfil-acoes">
                    <button class="btn-primary" type="submit">Salvar alterações</button>
                </div>
            </div>
        </div>
    </form>

    {{-- ---------- Segurança e acesso: o estado de cada coisa + o caminho para mexer ---------- --}}
    <div class="settings-body perfil-seguranca">
        <div class="card">
            <div class="card-head">
                <h3>Segurança e acesso</h3>
                <span class="chip">Configurações</span>
            </div>
            <div class="perfil-atalhos">
                <a class="perfil-atalho" href="{{ route('settings', 'seguranca') }}">
                    <span class="pa-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/></svg></span>
                    <span class="pa-txt">
                        <strong>Senha</strong>
                        <span>{{ $resumo['senhaTrocadaEm'] ? 'Trocada '.$resumo['senhaTrocadaEm']->diffForHumans() : 'Sem registro de troca' }}</span>
                    </span>
                </a>
                <a class="perfil-atalho" href="{{ route('settings', '2fa') }}">
                    <span class="pa-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 3 4.5 6v5.5c0 4.6 3.1 8.3 7.5 9.5 4.4-1.2 7.5-4.9 7.5-9.5V6Z"/><path d="m9 12 2 2 4-4"/></svg></span>
                    <span class="pa-txt">
                        <strong>Verificação em duas etapas</strong>
                        @if ($resumo['doisFatores'])
                            <span>Ligada neste login</span>
                        @else
                            <span class="alerta">Desligada — ligue para proteger o login</span>
                        @endif
                    </span>
                </a>
                <a class="perfil-atalho" href="{{ route('settings', 'seguranca') }}">
                    <span class="pa-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="4.5" width="13" height="10" rx="1.8"/><path d="M6.5 18h6M9.5 14.5V18"/><rect x="17" y="8" width="4.5" height="10" rx="1.2"/></svg></span>
                    <span class="pa-txt">
                        <strong>Aparelhos conectados</strong>
                        <span>
                            @if ($resumo['aparelhos'] > 0)
                                {{ $resumo['aparelhos'] }} {{ $resumo['aparelhos'] === 1 ? 'aparelho' : 'aparelhos' }} com sessão aberta
                            @else
                                Ver onde a conta está aberta
                            @endif
                        </span>
                    </span>
                </a>
            </div>
        </div>
    </div>
</section>

<script nonce="{{ Vite::cspNonce() }}">
    (function () {
        // Pré-visualização da foto escolhida antes de salvar
        var input = document.getElementById('avatar');
        var preview = document.getElementById('avatarPreview');
        var remover = document.getElementById('removerFoto');
        var fotoAtual = preview ? preview.innerHTML : '';
        if (input && preview) {
            input.addEventListener('change', function () {
                var file = input.files && input.files[0];
                if (!file) return;
                var url = URL.createObjectURL(file);
                preview.innerHTML = '<img src="' + url + '" alt="Pré-visualização">';
                // Foto nova escolhida: é ela que vale (o servidor também dá preferência a ela).
                if (remover) remover.checked = false;
            });
        }
        // "Remover a foto": mostra as iniciais já, para a pessoa ver o que vai ficar; desmarcar
        // devolve a foto atual. Iniciais por textContent — o nome é dado do usuário.
        if (remover && preview) {
            remover.addEventListener('change', function () {
                if (remover.checked) {
                    if (input) input.value = '';
                    preview.textContent = preview.getAttribute('data-iniciais') || '';
                } else {
                    preview.innerHTML = fotoAtual;
                }
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
