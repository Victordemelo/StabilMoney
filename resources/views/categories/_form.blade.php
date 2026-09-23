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

            {{-- Tipo — os grupos de radio (Tipo, Ícone, Cor) levam `role="radiogroup"`
                 com o nome do rótulo, como no modal da listagem. --}}
            <div class="field">
                <label id="cat-tipo-rotulo">Tipo</label>
                <div class="type-toggle" role="radiogroup" aria-labelledby="cat-tipo-rotulo">
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

            {{-- Ícone (cada opção se chama pelo próprio emoji) --}}
            <div class="field">
                <label id="cat-icone-rotulo">Ícone</label>
                <div class="icon-picker" role="radiogroup" aria-labelledby="cat-icone-rotulo">
                    @foreach ($icones as $i => $emoji)
                        <input type="radio" id="ic-{{ $i }}" name="icon" value="{{ $emoji }}" @checked($iconeAtual === $emoji)>
                        <label for="ic-{{ $i }}">{{ $emoji }}</label>
                    @endforeach
                </div>
                @error('icon')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            {{-- Cor — bolinha sem texto: o NOME da cor vai escondido para o leitor de
                 tela e no title para o mouse (o hex de antes era soletrado). --}}
            <div class="field">
                <label id="cat-cor-rotulo">Cor</label>
                <div class="color-picker" role="radiogroup" aria-labelledby="cat-cor-rotulo">
                    <input type="radio" id="cor-default" name="color" value="" @checked($corAtual === '' || $corAtual === null)>
                    <label for="cor-default" title="Sem cor (padrão)" style="background: linear-gradient(135deg, var(--surface-3), var(--line))"><span class="sr-only">Sem cor (padrão)</span></label>
                    @foreach ($cores as $i => $cor)
                        @php $nomeCor = \App\Support\NomeDaCor::de($cor); @endphp
                        <input type="radio" id="cor-{{ $i }}" name="color" value="{{ $cor }}" @checked($corAtual === $cor)>
                        <label for="cor-{{ $i }}" title="{{ $nomeCor }}" style="background: {{ $cor }}"><span class="sr-only">{{ $nomeCor }}</span></label>
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
