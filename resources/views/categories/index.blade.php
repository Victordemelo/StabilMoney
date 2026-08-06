@extends('layouts.app')

@section('title', 'Categorias')

@section('content')
    <div class="section-head">
        <h2>Categorias</h2>
        <span class="sub">Arraste para mover entre Despesas e Receitas</span>
        <div class="head-actions">
            {{-- Abre o modal (categories.js). O href continua valendo como
                 FALLBACK sem JS — mesmo padrão do botão "Lançar" da topbar. --}}
            <a class="btn-primary" href="{{ route('categories.create') }}" data-cat-open="new">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>
                Nova categoria
            </a>
        </div>
    </div>

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

    {{-- Colunas de drag & drop (design v2): arrastar um chip entre as colunas
         troca o tipo da categoria via PATCH (resources/js/sm/categories.js). --}}
    <div class="cat-cols" id="catCols">
        @foreach ([
            ['titulo' => 'Despesas', 'tipo' => 'expense', 'cor' => '#E5604D', 'lista' => $expenseCategories, 'fallback' => '💸'],
            ['titulo' => 'Receitas', 'tipo' => 'income', 'cor' => '#1C9A70', 'lista' => $incomeCategories, 'fallback' => '💰'],
        ] as $grupo)
            <div class="cat-col">
                <div class="cat-col-head">
                    <span class="cch-dot" style="background:{{ $grupo['cor'] }}"></span>
                    <h3>{{ $grupo['titulo'] }}</h3>
                    <span class="cch-count" data-count-for="{{ $grupo['tipo'] }}">{{ $grupo['lista']->count() }}</span>
                </div>
                <div class="cat-drop" data-type="{{ $grupo['tipo'] }}" aria-label="Categorias de {{ mb_strtolower($grupo['titulo']) }}">
                    @foreach ($grupo['lista'] as $categoria)
                        {{-- Categoria fixa: não arrasta (não pode mudar de tipo) e não tem
                             botão de excluir — o cadeado explica o porquê. --}}
                        @php $fixa = $categoria->isLocked(); @endphp
                        <div class="cat-chip @if ($fixa) is-locked @endif" draggable="{{ $fixa ? 'false' : 'true' }}"
                             data-id="{{ $categoria->id }}"
                             data-name="{{ $categoria->name }}"
                             data-color="{{ $categoria->color }}"
                             data-icon="{{ $categoria->icon }}"
                             data-type="{{ $grupo['tipo'] }}"
                             data-locked="{{ $fixa ? '1' : '0' }}"
                             data-update-url="{{ route('categories.update', $categoria) }}">
                            <span class="cc-emoji" style="background:{{ $categoria->color ? $categoria->color . '22' : 'var(--surface-3)' }}">{{ $categoria->icon ?? $grupo['fallback'] }}</span>
                            <span class="cc-name">{{ $categoria->name }}</span>
                            <span class="cc-dot" style="background:{{ $categoria->color ?? 'var(--line)' }}"></span>
                            {{-- Abre o modal já preenchido (dados nos data-* do chip);
                                 o href segue como fallback sem JS. --}}
                            <a class="cc-act" href="{{ route('categories.edit', $categoria) }}" data-cat-open="edit"
                               title="Editar" aria-label="Editar {{ $categoria->name }}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 20h4L19.5 8.5a2.1 2.1 0 0 0-3-3L5 17v3Z"/><path d="m13.5 6.5 3 3"/></svg>
                            </a>
                            @unless ($fixa)
                                <form method="POST" action="{{ route('categories.destroy', $categoria) }}"
                                      onsubmit="return confirm('Excluir esta categoria? As transações dela ficarão sem categoria.')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="cc-del" type="submit" title="Excluir" aria-label="Excluir {{ $categoria->name }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
                                    </button>
                                </form>
                            @endunless
                            @if ($fixa)
                                {{-- Cadeado ocupa a MESMA caixa do botão excluir (.cc-del) --}}
                                <span class="cc-lock" role="img"
                                      title="Categoria fixa — não pode ser excluída"
                                      aria-label="{{ $categoria->name }} é uma categoria fixa e não pode ser excluída">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/><path d="M8 10.5V7.8a4 4 0 0 1 8 0v2.7"/></svg>
                                </span>
                            @endif
                            {{-- Grip sempre presente: no chip fixo fica invisível (não arrasta),
                                 mas segura o espaço para os chips ficarem alinhados entre si. --}}
                            <svg class="cc-grip" viewBox="0 0 24 24" fill="none" stroke="currentColor" @if ($fixa) aria-hidden="true" @endif><circle cx="9" cy="6" r="1.4"/><circle cx="15" cy="6" r="1.4"/><circle cx="9" cy="12" r="1.4"/><circle cx="15" cy="12" r="1.4"/><circle cx="9" cy="18" r="1.4"/><circle cx="15" cy="18" r="1.4"/></svg>
                        </div>
                    @endforeach
                    {{-- Some via CSS assim que a coluna ganha um chip (.cat-chip ~ .cat-drop-empty) --}}
                    <div class="cat-drop-empty">
                        Nenhuma categoria de {{ mb_strtolower($grupo['titulo']) }} ainda.
                        <a href="{{ route('categories.create', ['type' => $grupo['tipo']]) }}"
                           data-cat-open="new" data-cat-type="{{ $grupo['tipo'] }}">Criar agora</a>
                    </div>
                    <div class="cat-drop-hint">Solte aqui para mover para {{ $grupo['titulo'] }}</div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ============ Modal de criar/editar categoria ============
         UM modal só, compartilhado (padrão dos modais de Metas): quem abre
         preenche os campos a partir dos data-* do chip clicado. Renderizar um
         modal por categoria multiplicaria a página por N sem ganho nenhum.

         ⚠️ Nasce como IRMÃO das colunas, NUNCA dentro de uma `.card`: a `.card`
         tem overflow:hidden e animação com transform, e transform em ancestral
         vira o bloco de contenção do position:fixed — o modal encolheria para a
         largura do card e sairia recortado.

         Sem JS este bloco fica invisível (o .modal-scrim é opacity:0 +
         pointer-events:none) e os links continuam levando para as telas
         cheias `categories.create` / `categories.edit`. --}}
    <div class="modal-scrim" id="catModal">
        <div class="modal" data-type="expense">
            <div class="modal-head">
                <span class="modal-ico">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>
                </span>
                <div>
                    <h3 data-cat-title>Nova categoria</h3>
                    <p data-cat-sub>Para organizar receitas e despesas</p>
                </div>
                <button class="modal-x" type="button" data-cat-close aria-label="Fechar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>

            {{-- Banner de erro do envio AJAX (validação do servidor) --}}
            <div class="flash-error" data-cat-error role="alert" hidden>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
                <span data-cat-error-msg></span>
            </div>

            {{-- O `action` e o `_method` são definidos pelo JS conforme abrir em
                 criação ou edição. --}}
            <form method="POST" action="{{ route('categories.store') }}" data-cat-form data-type="expense">
                @csrf
                <div class="modal-body">
                    {{-- Tipo --}}
                    <div class="field">
                        {{-- O aviso vai DENTRO do label de propósito: é lá que o
                             `.field .hint` do forms.css já herda tamanho e cor
                             certos, sem precisar de regra nova. --}}
                        <label>Tipo
                            {{-- Categoria fixa só é renomeada/repintada; o tipo é
                                 travado (a mesma regra que o servidor aplica). --}}
                            <span class="hint" data-cat-locked-hint hidden>— categoria fixa: não pode mudar de tipo</span>
                        </label>
                        <div class="type-toggle">
                            <span class="tt-pill" aria-hidden="true"></span>
                            <input type="radio" id="cm-tt-income" name="type" value="income">
                            <label class="tt-income" for="cm-tt-income">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M7 17 17 7M17 7h-7M17 7v7"/></svg>
                                Receita
                            </label>
                            <input type="radio" id="cm-tt-expense" name="type" value="expense" checked>
                            <label class="tt-expense" for="cm-tt-expense">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M7 7 17 17M17 17h-7M17 17v-7"/></svg>
                                Despesa
                            </label>
                        </div>
                    </div>

                    {{-- Nome --}}
                    <div class="field">
                        <label for="cm-name">Nome da categoria</label>
                        <input class="input" type="text" id="cm-name" name="name"
                               maxlength="255" placeholder="Ex.: Alimentação, Salário, Lazer…" required>
                    </div>

                    {{-- Ícone --}}
                    <div class="field">
                        <label>Ícone</label>
                        <div class="icon-picker" data-cat-icons>
                            @foreach ($icones as $i => $emoji)
                                <input type="radio" id="cm-ic-{{ $i }}" name="icon" value="{{ $emoji }}" @checked($emoji === '✨')>
                                <label for="cm-ic-{{ $i }}">{{ $emoji }}</label>
                            @endforeach
                        </div>
                    </div>

                    {{-- Cor --}}
                    <div class="field">
                        <label>Cor</label>
                        <div class="color-picker" data-cat-colors>
                            <input type="radio" id="cm-cor-default" name="color" value="" checked>
                            <label for="cm-cor-default" title="Sem cor (padrão)" style="background: linear-gradient(135deg, var(--surface-3), var(--line))"></label>
                            @foreach ($cores as $i => $cor)
                                <input type="radio" id="cm-cor-{{ $i }}" name="color" value="{{ $cor }}">
                                <label for="cm-cor-{{ $i }}" title="{{ $cor }}" style="background: {{ $cor }}"></label>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="modal-foot">
                    <button class="btn ghost" type="button" data-cat-close>Cancelar</button>
                    <button class="btn primary" type="submit" data-cat-save>
                        <span class="btn-label">Salvar</span>
                        <span class="btn-spin" aria-hidden="true"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection
