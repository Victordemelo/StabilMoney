@extends('layouts.app')

@section('title', 'Categorias — StabilMoney')

@section('content')
    <div class="section-head">
        <h2>Categorias</h2>
        <span class="sub">Organize suas receitas e despesas</span>
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

    <div class="grid">
        @foreach ([
            ['titulo' => 'Receitas', 'tipo' => 'income', 'lista' => $incomeCategories, 'fallback' => '💰'],
            ['titulo' => 'Despesas', 'tipo' => 'expense', 'lista' => $expenseCategories, 'fallback' => '💸'],
        ] as $grupo)
            <div class="card span6">
                <div class="card-head">
                    <h3>{{ $grupo['titulo'] }}</h3>
                    <a class="mini-btn" href="{{ route('categories.create', ['type' => $grupo['tipo']]) }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>
                        Adicionar
                    </a>
                </div>

                @if ($grupo['lista']->isEmpty())
                    <div class="empty-state">
                        <div class="pico">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3.5 13.5V6a2.5 2.5 0 0 1 2.5-2.5h7.5l7.1 7.1a2 2 0 0 1 0 2.8Z"/><circle cx="8.5" cy="8.5" r="1.5"/></svg>
                        </div>
                        <h3>Nenhuma categoria de {{ mb_strtolower($grupo['titulo']) }}</h3>
                        <p>Crie categorias para organizar suas {{ mb_strtolower($grupo['titulo']) }}.</p>
                        <a class="btn-ghost" href="{{ route('categories.create', ['type' => $grupo['tipo']]) }}">Criar categoria</a>
                    </div>
                @else
                    @foreach ($grupo['lista'] as $categoria)
                        <div class="cat-line">
                            <div class="ci" @if ($categoria->color) style="background: color-mix(in srgb, {{ $categoria->color }} 18%, transparent)" @endif>
                                {{ $categoria->icon ?? $grupo['fallback'] }}
                            </div>
                            <div class="cname">{{ $categoria->name }}</div>
                            <div class="cactions">
                                <a class="row-btn" href="{{ route('categories.edit', $categoria) }}" title="Editar" aria-label="Editar {{ $categoria->name }}">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 20h4L19.5 8.5a2.1 2.1 0 0 0-3-3L5 17v3Z"/><path d="m13.5 6.5 3 3"/></svg>
                                </a>
                                <form method="POST" action="{{ route('categories.destroy', $categoria) }}"
                                      onsubmit="return confirm('Excluir esta categoria? As transações dela ficarão sem categoria.')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="row-btn danger" type="submit" title="Excluir" aria-label="Excluir {{ $categoria->name }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 7h16M9 7V5a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 5v2M6.5 7l.8 12a2 2 0 0 0 2 1.9h5.4a2 2 0 0 0 2-1.9l.8-12M10 11v6M14 11v6"/></svg>
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                @endif
            </div>
        @endforeach
    </div>
@endsection
