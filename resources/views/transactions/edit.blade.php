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

    @php
        $t = $transaction;
        $conta = $t->account;
        $transf = $t->isTransferencia();
        $outra = $transf ? $t->contrapartida() : null;
        $entrada = $t->type === 'income';
        $tipoRotulo = $transf ? 'Transferência' : ($entrada ? 'Receita' : 'Despesa');
        $situacao = match (true) {
            $t->settles_account_id !== null => 'Pagamento da fatura de um cartão',
            $transf => ($entrada ? 'Veio de ' : 'Foi para ').($outra?->account?->rotulo ?? 'outra conta'),
            $conta?->isCard() && $t->paid_at === null => 'Em aberto na fatura do '.$conta->name,
            $conta?->isCard() && $t->credit_settlement_id !== null => 'Quitada pelo crédito de um estorno',
            $conta?->isCard() => 'Paga com a fatura em '.$t->paid_at->format('d/m/Y'),
            $entrada => 'Recebida na conta',
            default => 'Paga — saiu da conta',
        };
        $totalParcelado = $parcelas->sum('amount');
    @endphp

    {{-- Formulário + painel "Detalhes": antes o formulário era uma coluna estreita no meio
         da tela, com vazio dos dois lados e nada além dos campos. --}}
    <div class="tx-edit">
        <div class="tx-edit-form">
            @include('transactions._form')
        </div>

        <aside class="card tx-detalhe" aria-label="Detalhes da transação">
            <span class="tx-d-tipo {{ $transf ? 'transf' : ($entrada ? 'pos' : 'neg') }}">{{ $tipoRotulo }}</span>
            <div class="tx-d-valor {{ $entrada ? 'pos' : '' }}">
                {{ $transf ? '' : ($entrada ? '+' : '−') }} @brl($parcelas->isNotEmpty() ? $totalParcelado : $t->amount)
            </div>
            <div class="tx-d-sub">
                @if ($parcelas->isNotEmpty())
                    Compra em {{ $t->installments }}x de @brl($t->amount) ·
                @endif
                {{ $t->date->format('d/m/Y') }}
            </div>

            <dl class="tx-d-lista">
                <div><dt>Situação</dt><dd>{{ $situacao }}</dd></div>
                <div><dt>{{ $transf ? ($entrada ? 'Entrou em' : 'Saiu de') : 'Onde' }}</dt><dd>{{ $conta?->rotulo ?? '—' }}</dd></div>
                @unless ($transf)
                    <div><dt>Categoria</dt><dd>{{ $t->category ? trim(($t->category->icon ?? '').' '.$t->category->name) : 'Sem categoria' }}</dd></div>
                @endunless
                <div><dt>Quem fez</dt><dd>{{ $t->madeBy?->name ?? 'Não informado' }}</dd></div>
                @if ($t->funding_source === \App\Support\FundingSource::CHEQUE_ESPECIAL)
                    <div><dt>Cheque especial</dt><dd>@brl($t->funding_amount) vieram do limite</dd></div>
                @elseif ($t->funding_source === \App\Support\FundingSource::RESGATE_INVESTIMENTO)
                    <div><dt>Resgate</dt><dd>@brl($t->funding_amount) vieram de um investimento</dd></div>
                @endif
                <div><dt>Lançada em</dt><dd>{{ $t->created_at ? $t->created_at->format('d/m/Y').' às '.$t->created_at->format('H:i') : '—' }}</dd></div>
            </dl>

            @if ($parcelas->isNotEmpty())
                <div class="tx-d-parcelas">
                    <h4>Parcelas <span>{{ $t->installment_no }} de {{ $t->installments }} · total @brl($totalParcelado)</span></h4>
                    <ol class="scroll">
                        @foreach ($parcelas as $parcela)
                            <li @class(['atual' => $parcela->id === $t->id])>
                                <span>{{ $parcela->installment_no }}/{{ $parcela->installments }}</span>
                                <span>{{ $parcela->date->format('d/m/Y') }}</span>
                                <span>@brl($parcela->amount)</span>
                                <span class="st {{ $parcela->paid_at ? 'paga' : '' }}">{{ $parcela->paid_at ? 'paga' : 'em aberto' }}</span>
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endif

            @if ($travaDeEdicao)
                <p class="tx-d-aviso" role="note">{{ $travaDeEdicao }}</p>
            @endif
        </aside>
    </div>
@endsection
