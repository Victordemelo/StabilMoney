@extends('layouts.app')

@section('title', 'Editar transação')

@section('content')
    @php
        // Ocorrência de série recorrente: a exclusão pergunta se a recorrência acaba
        // junto (`TransactionController::ofereceEncerrarRecorrencia`).
        $comRecorrencia = $ofereceEncerrarRecorrencia ?? false;
        $pergunta = match (true) {
            $transaction->isTransferencia() => 'Excluir esta transferência? As duas contas voltam ao que eram. Essa ação não pode ser desfeita.',
            $comRecorrencia => 'Excluir esta cobrança? A recorrência continua ativa — só esta cobrança sai. Essa ação não pode ser desfeita.',
            default => 'Excluir esta transação? Essa ação não pode ser desfeita.',
        };
    @endphp

    <div class="section-head">
        <h2>Editar transação</h2>
        <span class="sub">Atualize ou exclua esta movimentação</span>
        <div class="head-actions">
            {{-- "Tem certeza?" pelo sm/confirmar.js: o `onsubmit` inline de antes era
                 bloqueado pela CSP, e excluía sem perguntar. Com a caixa de encerrar
                 marcada, a pergunta passa a ser a dela (`data-confirmar-marcado`). --}}
            <form method="POST" action="{{ route('transactions.destroy', $transaction) }}"
                  style="display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 10px 16px;"
                  data-confirmar="{{ $pergunta }}">
                @csrf
                @method('DELETE')
                {{-- Dentro do formulário, e desmarcada: funciona sem JS, e o padrão é a
                     exclusão de sempre (só esta cobrança sai; a série continua). --}}
                @if ($comRecorrencia)
                    <label class="check" style="align-items: flex-start;">
                        <input type="checkbox" name="encerrar_recorrencia" value="1"
                               data-confirmar-marcado="Excluir esta cobrança e encerrar a recorrência? Nenhuma cobrança nova será lançada. Essa ação não pode ser desfeita." />
                        <span class="box" style="margin-top: 1px;"><svg viewBox="0 0 24 24" fill="none"><path d="M5 12l4 4 10-10"/></svg></span>
                        <span>Encerrar também a recorrência — nenhuma cobrança nova será lançada</span>
                    </label>
                @endif
                <button class="btn-danger" type="submit">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 7h16M9 7V5a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 5v2M6.5 7l.8 12a2 2 0 0 0 2 1.9h5.4a2 2 0 0 0 2-1.9l.8-12M10 11v6M14 11v6"/></svg>
                    Excluir
                </button>
            </form>
        </div>
    </div>

    @include('transactions._form')
@endsection
