@extends('layouts.app')

@section('title', 'Dependentes')

@section('content')
@php
    $iniciais = function (string $nome) {
        $p = preg_split('/\s+/', trim($nome), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return mb_strtoupper(count($p) >= 2
            ? mb_substr($p[0], 0, 1) . mb_substr(end($p), 0, 1)
            : mb_substr($p[0] ?? 'U', 0, 2));
    };
    $cores = ['var(--c-lazer)', 'var(--c-alimentacao)', 'var(--c-saude)', 'var(--brand-500)'];
    $titular = auth()->user();
    // Qual modal reabrir quando a validação volta com erro (store vs editar X).
    $formComErro = old('_form');
@endphp

<section class="view">
    <div class="section-head">
        <h2>Dependentes</h2>
        <span class="sub">Compartilhe o controle financeiro com a família</span>
        <div class="head-actions">
            <button class="btn-primary" type="button" id="depAddBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>
                Adicionar dependente
            </button>
        </div>
    </div>

    <div class="grid">
        <div class="card span12">
            <div class="card-head"><h3>Pessoas vinculadas</h3></div>

            <div class="dep-grid">
                {{-- Titular (você) --}}
                <div class="dep-person">
                    <div class="dp-top">
                        <div class="dp-av" style="background: var(--brand-600)">
                            @if ($titular->avatarUrl())
                                <img src="{{ $titular->avatarUrl() }}" alt="{{ $titular->name }}">
                            @else
                                {{ $iniciais($titular->name) }}
                            @endif
                        </div>
                        <div class="dp-id">
                            <div class="dp-name">{{ $titular->name }}</div>
                            <div class="dp-rel"><strong>Titular</strong> · {{ $titular->email }}</div>
                        </div>
                    </div>
                    <div class="dp-spent">
                        <span class="dp-spent-label">Gastou no mês</span>
                        <span class="dp-spent-val">R$ {{ number_format($gastoTitular, 2, ',', '.') }}</span>
                    </div>
                </div>

                {{-- Dependentes --}}
                @foreach ($dependents as $dep)
                    @php($gasto = (float) ($dep->gasto ?? 0))
                    <div class="dep-person">
                        <div class="dp-top">
                            <div class="dp-av" style="background: {{ $cores[$loop->index % count($cores)] }}">
                                @if ($dep->avatarUrl())
                                    <img src="{{ $dep->avatarUrl() }}" alt="{{ $dep->name }}">
                                @else
                                    {{ $iniciais($dep->name) }}
                                @endif
                            </div>
                            <div class="dp-id">
                                <div class="dp-name">{{ $dep->name }}</div>
                                <div class="dp-rel">{{ $dep->relationshipLabel() ?? 'Dependente' }} · {{ $dep->email }}</div>
                            </div>
                            <div class="dp-actions">
                                <button class="dp-edit" type="button" data-edit="{{ $dep->id }}" aria-label="Editar dependente">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 20h4L18.5 9.5a2 2 0 0 0-2.8-2.8L5 17v3zM13.5 6.5l4 4"/></svg>
                                </button>
                                <form method="POST" action="{{ route('dependentes.destroy', $dep) }}"
                                      onsubmit="return confirm('Remover {{ $dep->name }}? O acesso dele será excluído (os lançamentos da família permanecem).')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="dp-rm" type="submit" aria-label="Remover dependente">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 7h16M9 7V5a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 5v2M6.5 7l.8 12a2 2 0 0 0 2 1.9h5.4a2 2 0 0 0 2-1.9l.8-12"/></svg>
                                    </button>
                                </form>
                            </div>
                        </div>

                        {{-- Quanto já gastou (despesas lançadas por ele) --}}
                        <div class="dp-spent">
                            <span class="dp-spent-label">Gastou no mês</span>
                            <span class="dp-spent-val">R$ {{ number_format($gasto, 2, ',', '.') }}</span>
                        </div>
                    </div>
                @endforeach

                {{-- Card "adicionar" tracejado --}}
                <button class="dep-person pm-add" type="button" id="depAddCard">
                    <div class="pm-add-inner">
                        <span class="pm-plus"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg></span>
                        <strong>Adicionar dependente</strong>
                        <span class="dp-rel">Login próprio, mesma visão da família</span>
                    </div>
                </button>
            </div>
        </div>
    </div>
</section>

{{-- Modais ficam FORA da section/.card: o .card tem overflow:hidden + animação
     com transform, que prendia/recortava o position:fixed dos modais. --}}

{{-- Modal: adicionar dependente --}}
<div class="modal-scrim" id="depModal" data-close>
    <div class="modal modal-lg">
        <div class="modal-head">
            <span class="modal-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="9" cy="8" r="3.4"/><path d="M2.5 20c0-3.4 2.9-5.6 6.5-5.6 1 0 2 .2 2.8.5M17 8.5v6M14 11.5h6"/></svg></span>
            <div>
                <h3>Adicionar dependente</h3>
                <p>Crie o acesso de alguém da família. Você define a senha e pode passá-la depois.</p>
            </div>
            <button class="modal-x" type="button" data-close-btn aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>

        @if ($formComErro === 'store' && $errors->any())
            <div class="flash-error" role="alert">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
                <ul>@foreach ($errors->all() as $erro)<li>{{ $erro }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ route('dependentes.store') }}" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="_form" value="store">
            <div class="modal-body">
                <label class="avatar-pick" for="dep-avatar">
                    <span class="avatar-pick-img">
                        <span data-avatar-preview>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width:30px;height:30px"><circle cx="12" cy="9" r="3.4"/><path d="M5 20c0-3.4 3-5.6 7-5.6s7 2.2 7 5.6"/></svg>
                        </span>
                        <span class="avatar-pick-cam"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 7h3l1.5-2h7L18 7h3a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1Z"/><circle cx="12" cy="13" r="3.5"/></svg></span>
                    </span>
                    <span class="avatar-pick-hint">Clique para adicionar uma foto</span>
                    <input type="file" id="dep-avatar" name="avatar" accept="image/*" data-avatar-input hidden>
                    <span class="hint">Opcional · JPG ou PNG até 2 MB</span>
                </label>
                <div class="field">
                    <label for="dep-name">Nome</label>
                    <input class="input" type="text" id="dep-name" name="name" value="{{ old('_form') === 'store' ? old('name') : '' }}" required>
                </div>
                <div class="field">
                    <label for="dep-email">E-mail</label>
                    <input class="input" type="email" id="dep-email" name="email" value="{{ old('_form') === 'store' ? old('email') : '' }}" required>
                </div>
                <div class="field">
                    <label for="dep-relationship">Parentesco <span class="hint">(opcional)</span></label>
                    <select class="input" id="dep-relationship" name="relationship">
                        <option value="">Selecione…</option>
                        @foreach (\App\Models\User::RELATIONSHIPS as $val => $label)
                            <option value="{{ $val }}" @selected(old('_form') === 'store' && old('relationship') === $val)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="dep-password">Senha</label>
                    <input class="input" type="password" id="dep-password" name="password" placeholder="Mínimo 8 caracteres" autocomplete="new-password" required>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-close-btn>Cancelar</button>
                <button class="btn primary" type="submit">Adicionar</button>
            </div>
        </form>
    </div>
</div>

{{-- Modais: editar cada dependente (um por pessoa) --}}
@foreach ($dependents as $dep)
    <div class="modal-scrim" id="depEditModal-{{ $dep->id }}" data-close>
        <div class="modal modal-lg">
            <div class="modal-head">
                <span class="modal-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 20h4L18.5 9.5a2 2 0 0 0-2.8-2.8L5 17v3zM13.5 6.5l4 4"/></svg></span>
                <div>
                    <h3>Editar {{ $dep->name }}</h3>
                    <p>Atualize os dados, o parentesco e a foto.</p>
                </div>
                <button class="modal-x" type="button" data-close-btn aria-label="Fechar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>

            @if ($formComErro === 'edit-' . $dep->id && $errors->any())
                <div class="flash-error" role="alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
                    <ul>@foreach ($errors->all() as $erro)<li>{{ $erro }}</li>@endforeach</ul>
                </div>
            @endif

            <form method="POST" action="{{ route('dependentes.update', $dep) }}" enctype="multipart/form-data">
                @csrf
                @method('PATCH')
                <input type="hidden" name="_form" value="edit-{{ $dep->id }}">
                <div class="modal-body">
                    <label class="avatar-pick" for="dep-edit-avatar-{{ $dep->id }}">
                        <span class="avatar-pick-img">
                            <span data-avatar-preview>
                                @if ($dep->avatarUrl())
                                    <img src="{{ $dep->avatarUrl() }}" alt="{{ $dep->name }}">
                                @else
                                    {{ $iniciais($dep->name) }}
                                @endif
                            </span>
                            <span class="avatar-pick-cam"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 7h3l1.5-2h7L18 7h3a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1Z"/><circle cx="12" cy="13" r="3.5"/></svg></span>
                        </span>
                        <span class="avatar-pick-hint">Clique para trocar a foto</span>
                        <input type="file" id="dep-edit-avatar-{{ $dep->id }}" name="avatar" accept="image/*" data-avatar-input hidden>
                        <span class="hint">JPG ou PNG até 2 MB</span>
                    </label>
                    <div class="field">
                        <label for="dep-edit-name-{{ $dep->id }}">Nome</label>
                        <input class="input" type="text" id="dep-edit-name-{{ $dep->id }}" name="name" value="{{ old('_form') === 'edit-' . $dep->id ? old('name', $dep->name) : $dep->name }}" required>
                    </div>
                    <div class="field">
                        <label for="dep-edit-email-{{ $dep->id }}">E-mail</label>
                        <input class="input" type="email" id="dep-edit-email-{{ $dep->id }}" name="email" value="{{ old('_form') === 'edit-' . $dep->id ? old('email', $dep->email) : $dep->email }}" required>
                    </div>
                    <div class="field">
                        <label for="dep-edit-relationship-{{ $dep->id }}">Parentesco <span class="hint">(opcional)</span></label>
                        <select class="input" id="dep-edit-relationship-{{ $dep->id }}" name="relationship">
                            <option value="">Selecione…</option>
                            @foreach (\App\Models\User::RELATIONSHIPS as $val => $label)
                                <option value="{{ $val }}" @selected((old('_form') === 'edit-' . $dep->id ? old('relationship') : $dep->relationship) === $val)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="dep-edit-password-{{ $dep->id }}">Nova senha <span class="hint">(opcional)</span></label>
                        <input class="input" type="password" id="dep-edit-password-{{ $dep->id }}" name="password" placeholder="Deixe em branco para manter a atual" autocomplete="new-password">
                    </div>
                </div>
                <div class="modal-foot">
                    <button class="btn ghost" type="button" data-close-btn>Cancelar</button>
                    <button class="btn primary" type="submit">Salvar alterações</button>
                </div>
            </form>
        </div>
    </div>
@endforeach

<script>
    (function () {
        var abrir = function (modal) { if (modal) modal.classList.add('open'); };
        var fechar = function (modal) { if (modal) modal.classList.remove('open'); };

        // Abrir: adicionar
        var addModal = document.getElementById('depModal');
        ['depAddBtn', 'depAddCard'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.addEventListener('click', function () { abrir(addModal); });
        });

        // Abrir: editar (um modal por dependente)
        document.querySelectorAll('[data-edit]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                abrir(document.getElementById('depEditModal-' + btn.getAttribute('data-edit')));
            });
        });

        // Fechar: clique no fundo, botões de fechar, Esc
        document.querySelectorAll('.modal-scrim[data-close]').forEach(function (modal) {
            modal.addEventListener('click', function (e) { if (e.target === modal) fechar(modal); });
            modal.querySelectorAll('[data-close-btn]').forEach(function (b) {
                b.addEventListener('click', function () { fechar(modal); });
            });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') document.querySelectorAll('.modal-scrim.open').forEach(fechar);
        });

        // Pré-visualização da foto escolhida (em qualquer modal)
        document.querySelectorAll('[data-avatar-input]').forEach(function (input) {
            input.addEventListener('change', function () {
                var file = input.files && input.files[0];
                if (!file) return;
                var preview = input.closest('.avatar-pick').querySelector('[data-avatar-preview]');
                if (preview) preview.innerHTML = '<img src="' + URL.createObjectURL(file) + '" alt="Pré-visualização">';
            });
        });

        // Reabre o modal certo quando a validação volta com erro
        @if ($formComErro && $errors->any())
            @if ($formComErro === 'store')
                abrir(addModal);
            @else
                abrir(document.getElementById('depEditModal-{{ \Illuminate\Support\Str::after($formComErro, 'edit-') }}'));
            @endif
        @endif
    })();
</script>
@endsection
