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
                        <div class="dp-av" style="background: var(--brand-600)">{{ $iniciais($titular->name) }}</div>
                        <div>
                            <div class="dp-name">{{ $titular->name }}</div>
                            <div class="dp-rel"><strong>Titular</strong> · {{ $titular->email }}</div>
                        </div>
                    </div>
                </div>

                {{-- Dependentes --}}
                @foreach ($dependents as $dep)
                    <div class="dep-person">
                        <div class="dp-top">
                            <div class="dp-av" style="background: {{ $cores[$loop->index % count($cores)] }}">{{ $iniciais($dep->name) }}</div>
                            <div>
                                <div class="dp-name">{{ $dep->name }}</div>
                                <div class="dp-rel">Dependente · {{ $dep->email }}</div>
                            </div>
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

        @if ($errors->any())
            <div class="flash-error" role="alert">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
                <ul>@foreach ($errors->all() as $erro)<li>{{ $erro }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ route('dependentes.store') }}">
            @csrf
            <div class="modal-body">
                <div class="field">
                    <label for="dep-name">Nome</label>
                    <input class="input" type="text" id="dep-name" name="name" value="{{ old('name') }}" required autofocus>
                </div>
                <div class="field">
                    <label for="dep-email">E-mail</label>
                    <input class="input" type="email" id="dep-email" name="email" value="{{ old('email') }}" required>
                </div>
                <div class="field">
                    <label for="dep-password">Senha</label>
                    <input class="input" type="password" id="dep-password" name="password" placeholder="Mínimo 8 caracteres" required>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-close-btn>Cancelar</button>
                <button class="btn primary" type="submit">Adicionar</button>
            </div>
        </form>
    </div>
</div>

<script>
    (function () {
        var modal = document.getElementById('depModal');
        if (!modal) return;
        var abrir = function () { modal.classList.add('open'); };
        var fechar = function () { modal.classList.remove('open'); };

        ['depAddBtn', 'depAddCard'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.addEventListener('click', abrir);
        });
        modal.addEventListener('click', function (e) { if (e.target === modal) fechar(); });
        modal.querySelectorAll('[data-close-btn]').forEach(function (b) { b.addEventListener('click', fechar); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fechar(); });

        @if ($errors->any())
            abrir(); // reabre o modal quando o cadastro volta com erro
        @endif
    })();
</script>
@endsection
