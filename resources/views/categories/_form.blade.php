{{--
    Formulário compartilhado de categoria (create/edit).
    Espera: $category (null no create). No create, ?type=income|expense pré-seleciona o tipo.
--}}
@php
    $editando = $category !== null;
    // $icones/$cores vêm do CategoryController (constantes ICONES/CORES) — a
    // mesma fonte que alimenta o modal da listagem, para os dois não divergirem.
    $tipoPrefill = in_array(request('type'), ['income', 'expense'], true) ? request('type') : 'expense';
    $tipoAtual = old('type', $category->type ?? $tipoPrefill);
    $iconeAtual = old('icon', $category->icon ?? '✨');
    $corAtual = old('color', $category->color ?? '');
@endphp

<div class="grid">
    <div class="card span12 form-card">
        @if ($errors->any())
            <div class="flash-error" role="alert">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
                <ul>
                    @foreach ($errors->all() as $erro)
                        <li>{{ $erro }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ $editando ? route('categories.update', $category) : route('categories.store') }}">
            @csrf
            @if ($editando)
                @method('PUT')
            @endif

            {{-- Tipo --}}
            <div class="field">
                <label>Tipo</label>
                <div class="type-toggle">
                    <input type="radio" id="tt-income" name="type" value="income" @checked($tipoAtual === 'income')>
                    <label class="tt-income" for="tt-income">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M7 17 17 7M17 7h-7M17 7v7"/></svg>
                        Receita
                    </label>
                    <input type="radio" id="tt-expense" name="type" value="expense" @checked($tipoAtual === 'expense')>
                    <label class="tt-expense" for="tt-expense">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M7 7 17 17M17 17h-7M17 17v-7"/></svg>
                        Despesa
                    </label>
                </div>
                @error('type')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            {{-- Nome --}}
            <div class="field">
                <label for="name">Nome da categoria</label>
                <input class="input @error('name') input-error @enderror" type="text" id="name" name="name"
                       maxlength="255" placeholder="Ex.: Alimentação, Salário, Lazer…" required
                       value="{{ old('name', $category->name ?? '') }}">
                @error('name')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            {{-- Ícone --}}
            <div class="field">
                <label>Ícone</label>
                <div class="icon-picker">
                    @foreach ($icones as $i => $emoji)
                        <input type="radio" id="ic-{{ $i }}" name="icon" value="{{ $emoji }}" @checked($iconeAtual === $emoji)>
                        <label for="ic-{{ $i }}">{{ $emoji }}</label>
                    @endforeach
                </div>
                @error('icon')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            {{-- Cor --}}
            <div class="field">
                <label>Cor</label>
                <div class="color-picker">
                    <input type="radio" id="cor-default" name="color" value="" @checked($corAtual === '' || $corAtual === null)>
                    <label for="cor-default" title="Sem cor (padrão)" style="background: linear-gradient(135deg, var(--surface-3), var(--line))"></label>
                    @foreach ($cores as $i => $cor)
                        <input type="radio" id="cor-{{ $i }}" name="color" value="{{ $cor }}" @checked($corAtual === $cor)>
                        <label for="cor-{{ $i }}" title="{{ $cor }}" style="background: {{ $cor }}"></label>
                    @endforeach
                </div>
                @error('color')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            <div class="form-actions">
                <a class="btn-ghost" href="{{ route('categories.index') }}">Cancelar</a>
                <button class="btn-primary" type="submit">Salvar</button>
            </div>
        </form>
    </div>
</div>
