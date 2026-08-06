@extends('layouts.app')

@section('title', 'Histórico')

@section('content')
    <div class="section-head">
        <h2>Histórico</h2>
        <span class="sub">Todas as movimentações</span>
        <div class="head-actions">
            {{-- Abre o modal GLOBAL de lançamento (partials/launch-modal), o mesmo do
                 botão da topbar e do FAB. Lançar sem sair da lista deixa o resultado
                 aparecer atrás, na hora. O `href` fica como FALLBACK: sem JS, o link
                 continua levando para o formulário em página cheia. --}}
            <a class="btn-primary" href="{{ route('transactions.create') }}" data-launch-open>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>
                Nova transação
            </a>
        </div>
    </div>

    <div class="grid">
        {{-- Filtros (GET) --}}
        <div class="card span12">
            <form class="filter-bar" method="GET" action="{{ route('transactions.index') }}">
                <div class="field">
                    <label for="f-type">Tipo</label>
                    <select class="input" id="f-type" name="type">
                        <option value="">Todos</option>
                        <option value="income" @selected(request('type') === 'income')>Receitas</option>
                        <option value="expense" @selected(request('type') === 'expense')>Despesas</option>
                    </select>
                </div>
                <div class="field">
                    <label for="f-account">Conta</label>
                    <select class="input" id="f-account" name="account">
                        <option value="">Todas</option>
                        @foreach ($accounts as $conta)
                            <option value="{{ $conta->id }}" @selected((int) request('account') === $conta->id)>{{ $conta->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="f-de">De</label>
                    <input class="input" type="date" id="f-de" name="de" value="{{ $filtroDe }}"
                           max="{{ now()->addYears(10)->format('Y-m-d') }}">
                </div>
                <div class="field">
                    <label for="f-ate">Até</label>
                    <input class="input" type="date" id="f-ate" name="ate" value="{{ $filtroAte }}"
                           max="{{ now()->addYears(10)->format('Y-m-d') }}">
                </div>

                <button class="btn-ghost" type="submit">Filtrar</button>

                {{-- Só aparece quando há filtro ativo: um "limpar" permanente vira
                     ruído numa barra que na maioria das visitas está vazia. --}}
                @if (request()->hasAny(['type', 'account', 'de', 'ate']) && collect(request()->only(['type', 'account', 'de', 'ate']))->filter()->isNotEmpty())
                    <a class="btn-ghost filtro-limpar" href="{{ route('transactions.index') }}">Limpar</a>
                @endif
            </form>
        </div>

        {{-- Lista --}}
        <div class="card span12">
            @if ($transactions->isEmpty())
                <div class="empty-state">
                    <div class="pico">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M7 8h13M7 8l3-3M7 8l3 3M17 16H4M17 16l-3-3M17 16l-3 3"/></svg>
                    </div>
                    @if (request()->filled('type') || request()->filled('account'))
                        <h3>Nada por aqui</h3>
                        <p>Nenhuma transação encontrada com esses filtros.</p>
                        <a class="btn-ghost" href="{{ route('transactions.index') }}">Limpar filtros</a>
                    @else
                        <h3>Nenhuma transação ainda</h3>
                        <p>Registre sua primeira movimentação para acompanhar suas finanças.</p>
                        <a class="btn-primary" href="{{ route('transactions.create') }}" data-launch-open>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>
                            Nova transação
                        </a>
                    @endif
                </div>
            @else
                <div class="tx-list">
                    @foreach ($transactions as $transacao)
                        @php
                            $receita = $transacao->type === 'income';
                            $nome = $transacao->description
                                ?: ($transacao->category->name ?? ($receita ? 'Receita' : 'Despesa'));
                        @endphp
                        <a class="tx" href="{{ route('transactions.edit', $transacao) }}">
                            <div class="tx-ico" @if ($transacao->category?->color) style="background: color-mix(in srgb, {{ $transacao->category->color }} 16%, transparent)" @endif>
                                {{ $transacao->category->icon ?? ($receita ? '💰' : '💸') }}
                            </div>
                            <div>
                                <div class="tx-name">{{ $nome }}</div>
                                <div class="tx-meta">{{ $transacao->category->name ?? 'Sem categoria' }} · {{ $transacao->account->name }} · {{ $transacao->date->format('d/m/Y') }}@if (! empty($showAuthor)) · {{ $transacao->madeBy?->name ?? 'Removido' }}@endif</div>
                            </div>
                            <div class="tx-amt {{ $receita ? 'pos' : '' }}">
                                {{ $receita ? '+' : '−' }} R$ {{ number_format($transacao->amount, 2, ',', '.') }}
                            </div>
                        </a>
                    @endforeach
                </div>

                {{ $transactions->onEachSide(1)->links('transactions.pagination') }}
            @endif
        </div>
    </div>

    <script nonce="{{ Vite::cspNonce() }}">
        // Auto-submit dos filtros ao trocar o select (o botão "Filtrar" é o fallback sem JS)
        document.querySelectorAll('#f-type, #f-account').forEach(function (sel) {
            sel.addEventListener('change', function () { sel.form.submit(); });
        });
    </script>
@endsection
