@extends('layouts.app')

@section('title', 'Categorias — StabilMoney')

@section('content')
    <div class="section-head">
        <h2>Categorias</h2>
        <span class="sub">Arraste para mover entre Despesas e Receitas</span>
        <div class="head-actions">
            <a class="btn-primary" href="{{ route('categories.create') }}">
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
                        <div class="cat-chip" draggable="true"
                             data-id="{{ $categoria->id }}"
                             data-name="{{ $categoria->name }}"
                             data-color="{{ $categoria->color }}"
                             data-icon="{{ $categoria->icon }}"
                             data-update-url="{{ route('categories.update', $categoria) }}">
                            <span class="cc-emoji" style="background:{{ $categoria->color ? $categoria->color . '22' : 'var(--surface-3)' }}">{{ $categoria->icon ?? $grupo['fallback'] }}</span>
                            <span class="cc-name">{{ $categoria->name }}</span>
                            <span class="cc-dot" style="background:{{ $categoria->color ?? 'var(--line)' }}"></span>
                            <a class="cc-act" href="{{ route('categories.edit', $categoria) }}" title="Editar" aria-label="Editar {{ $categoria->name }}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 20h4L19.5 8.5a2.1 2.1 0 0 0-3-3L5 17v3Z"/><path d="m13.5 6.5 3 3"/></svg>
                            </a>
                            <form method="POST" action="{{ route('categories.destroy', $categoria) }}"
                                  onsubmit="return confirm('Excluir esta categoria? As transações dela ficarão sem categoria.')">
                                @csrf
                                @method('DELETE')
                                <button class="cc-del" type="submit" title="Excluir" aria-label="Excluir {{ $categoria->name }}">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
                                </button>
                            </form>
                            <svg class="cc-grip" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="9" cy="6" r="1.4"/><circle cx="15" cy="6" r="1.4"/><circle cx="9" cy="12" r="1.4"/><circle cx="15" cy="12" r="1.4"/><circle cx="9" cy="18" r="1.4"/><circle cx="15" cy="18" r="1.4"/></svg>
                        </div>
                    @endforeach
                    {{-- Some via CSS assim que a coluna ganha um chip (.cat-chip ~ .cat-drop-empty) --}}
                    <div class="cat-drop-empty">
                        Nenhuma categoria de {{ mb_strtolower($grupo['titulo']) }} ainda.
                        <a href="{{ route('categories.create', ['type' => $grupo['tipo']]) }}">Criar agora</a>
                    </div>
                    <div class="cat-drop-hint">Solte aqui para mover para {{ $grupo['titulo'] }}</div>
                </div>
            </div>
        @endforeach
    </div>
@endsection
