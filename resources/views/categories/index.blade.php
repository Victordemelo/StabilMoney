@extends('layouts.app')

@section('title', 'Categorias')

@section('content')
    <div class="section-head">
        <h2>Categorias</h2>
        <span class="sub">Arraste para reordenar — ou solte na outra coluna para trocar o tipo</span>
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

    {{-- Onde o categories.js anuncia a posição nova depois de mover ("Mercado: posição 2
         de 5 em Despesas") — pelos botões ou pelo arraste. `role="status"` é lido sem
         tirar o foco de onde está; invisível na tela, que já mostra o chip no lugar novo. --}}
    <p class="sr-only" id="catAnuncio" role="status"></p>

    {{-- Colunas de drag & drop (design v2), em resources/js/sm/categories.js:
         • arrastar DENTRO da coluna reordena os chips (PATCH categories.ordenar);
         • arrastar ENTRE as colunas troca o tipo (PATCH categories.update) e
           grava a posição no destino;
         • os botões ▲▼ de cada chip reordenam SEM arrastar (teclado, leitor de tela,
           toque), no mesmo PATCH — achado A-4 da auditoria de acessibilidade.

         RECEITA à esquerda, DESPESA à direita — a ordem que o Victor pediu; o
         que entra primeiro é o dinheiro. A ordem deste array É a ordem da tela. --}}
    <div class="cat-cols" id="catCols" data-ordenar-url="{{ route('categories.ordenar') }}">
        @foreach ([
            ['titulo' => 'Receitas', 'tipo' => 'income', 'cor' => '#1C9A70', 'lista' => $incomeCategories, 'fallback' => '💰'],
            ['titulo' => 'Despesas', 'tipo' => 'expense', 'cor' => '#E5604D', 'lista' => $expenseCategories, 'fallback' => '💸'],
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
                                {{-- "Tem certeza?" pelo sm/confirmar.js: o `onsubmit` inline
                                     de antes era bloqueado pela CSP, e excluía sem perguntar. --}}
                                <form method="POST" action="{{ route('categories.destroy', $categoria) }}"
                                      data-confirmar="Excluir a categoria {{ $categoria->name }}? As transações dela ficarão sem categoria.">
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
                            {{-- Mover para cima/baixo SEM arrastar (categories.js): o nome da
                                 categoria vai no rótulo, porque o leitor de tela lê o botão
                                 fora do chip. Ponta da coluna = `aria-disabled` (continua
                                 focável; o JS recalcula a cada movimento). O chip fixo não
                                 arrasta, então também não ganha os botões — só a caixa
                                 vazia, para os chips continuarem alinhados. --}}
                            @if ($fixa)
                                <span class="cc-mover" aria-hidden="true"></span>
                            @else
                                <span class="cc-mover">
                                    <button type="button" class="cc-mv" data-cat-mover="-1"
                                            aria-label="Mover {{ $categoria->name }} para cima"
                                            @if ($loop->first) aria-disabled="true" @endif>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="m6 15 6-6 6 6"/></svg>
                                    </button>
                                    <button type="button" class="cc-mv" data-cat-mover="1"
                                            aria-label="Mover {{ $categoria->name }} para baixo"
                                            @if ($loop->last) aria-disabled="true" @endif>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                                    </button>
                                </span>
                            @endif
                            {{-- Grip sempre presente: no chip fixo fica invisível (não arrasta),
                                 mas segura o espaço para os chips ficarem alinhados entre si.
                                 Decorativo nos dois casos — quem reordena sem mouse usa os
                                 botões ▲▼, que têm nome. --}}
                            <svg class="cc-grip" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><circle cx="9" cy="6" r="1.4"/><circle cx="15" cy="6" r="1.4"/><circle cx="9" cy="12" r="1.4"/><circle cx="15" cy="12" r="1.4"/><circle cx="9" cy="18" r="1.4"/><circle cx="15" cy="18" r="1.4"/></svg>
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
        {{-- Nasce em RECEITA, igual ao modal global de "Lançar". Quem clica no
             "Criar agora" de uma coluna vazia manda o tipo daquela coluna
             (data-cat-type) — ali o usuário já disse o que quer. --}}
        {{-- `modal-lg` (620px) e não os 440px padrão: com 14 emojis de 40px e 13
             cores, a largura menor empilhava os pickers em três fileiras e o
             formulário inteiro ficava espremido numa coluna estreita. --}}
        {{-- O painel é o DIÁLOGO; título e subtítulo mudam no JS (criar × editar) e o
             nome acompanha, porque o aria-labelledby aponta para eles. --}}
        <div class="modal modal-lg" data-type="income" role="dialog" aria-modal="true"
             aria-labelledby="catModal-titulo" aria-describedby="catModal-descricao">
            <div class="modal-head">
                <span class="modal-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>
                </span>
                <div>
                    <h3 id="catModal-titulo" data-cat-title>Nova categoria</h3>
                    <p id="catModal-descricao" data-cat-sub>Para organizar receitas e despesas</p>
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
            <form method="POST" action="{{ route('categories.store') }}" data-cat-form data-type="income">
                @csrf
                <div class="modal-body">
                    {{-- Tipo — os três grupos de radio (Tipo, Ícone, Cor) levam
                         `role="radiogroup"` com o nome do rótulo: sem isso o leitor de
                         tela lia cada opção solta, sem dizer do que é a escolha. --}}
                    <div class="field">
                        {{-- O aviso vai DENTRO do label de propósito: é lá que o
                             `.field .hint` do forms.css já herda tamanho e cor
                             certos, sem precisar de regra nova — e, visível, ele entra
                             no nome do grupo. --}}
                        <label id="cm-tipo-rotulo">Tipo
                            {{-- Categoria fixa só é renomeada/repintada; o tipo é
                                 travado (a mesma regra que o servidor aplica). --}}
                            <span class="hint" data-cat-locked-hint hidden>— categoria fixa: não pode mudar de tipo</span>
                        </label>
                        <div class="type-toggle" role="radiogroup" aria-labelledby="cm-tipo-rotulo">
                            <span class="tt-pill" aria-hidden="true"></span>
                            <input type="radio" id="cm-tt-income" name="type" value="income" checked>
                            <label class="tt-income" for="cm-tt-income">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M7 17 17 7M17 7h-7M17 7v7"/></svg>
                                Receita
                            </label>
                            <input type="radio" id="cm-tt-expense" name="type" value="expense">
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

                    {{-- Ícone (cada opção se chama pelo próprio emoji) --}}
                    <div class="field">
                        <label id="cm-icone-rotulo">Ícone</label>
                        <div class="icon-picker" data-cat-icons role="radiogroup" aria-labelledby="cm-icone-rotulo">
                            @foreach ($icones as $i => $emoji)
                                <input type="radio" id="cm-ic-{{ $i }}" name="icon" value="{{ $emoji }}" @checked($emoji === '✨')>
                                <label for="cm-ic-{{ $i }}">{{ $emoji }}</label>
                            @endforeach
                        </div>
                    </div>

                    {{-- Cor — a bolinha não tem texto: o NOME da cor vai escondido para o
                         leitor de tela e no title para o mouse (antes o title era o hex,
                         que o leitor soletra). --}}
                    <div class="field">
                        <label id="cm-cor-rotulo">Cor</label>
                        <div class="color-picker" data-cat-colors role="radiogroup" aria-labelledby="cm-cor-rotulo">
                            <input type="radio" id="cm-cor-default" name="color" value="" checked>
                            <label for="cm-cor-default" title="Sem cor (padrão)" style="background: linear-gradient(135deg, var(--surface-3), var(--line))"><span class="sr-only">Sem cor (padrão)</span></label>
                            @foreach ($cores as $i => $cor)
                                @php $nomeCor = \App\Support\NomeDaCor::de($cor); @endphp
                                <input type="radio" id="cm-cor-{{ $i }}" name="color" value="{{ $cor }}">
                                <label for="cm-cor-{{ $i }}" title="{{ $nomeCor }}" style="background: {{ $cor }}"><span class="sr-only">{{ $nomeCor }}</span></label>
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
