@extends('layouts.app')

@section('title', 'Editar transação')

{{--
    Editar movimentação (out/2026 — pedido do Victor: "a tela do edit está muito simples").

    Esquerda: o COMPROVANTE (ícone com o banco, o nome, o valor grande, a situação) e, logo
    abaixo, o formulário com só o que dá para corrigir — sem o seletor Receita/Despesa (o tipo não
    muda depois de lançado) e com o "Onde" filtrado pelo tipo. Direita: as parcelas da compra,
    os detalhes e a exclusão, que diz o que sai junto antes do clique.

    Compra parcelada: descrição, categoria e quem fez valem para as N parcelas; valor, data e
    cartão aparecem como fatos (não mudam por aqui). O mesmo para a compra paga fora do app.
--}}
@section('content')
    @php
        $t = $transaction;
        $conta = $t->account;
        $transf = $t->isTransferencia();
        $outra = $transf ? $t->contrapartida() : null;
        $entrada = $t->type === 'income';
        $compraParcelada = $t->isParcelaDeCompra();
        $foraDoApp = (bool) $t->pago_fora_do_app;
        // O que não muda por aqui vira FATO (texto + hidden), não campo travado.
        $fatosFixos = $compraParcelada || $foraDoApp;
        $tipoRotulo = $transf ? 'Transferência' : ($entrada ? 'Receita' : 'Despesa');
        $totalParcelado = $parcelas->sum('amount');
        $valorDoTopo = $compraParcelada ? $totalParcelado : (float) $t->amount;
        $pagas = $parcelas->whereNotNull('paid_at')->count();
        $situacao = match (true) {
            $t->settles_account_id !== null => ['Pagamento da fatura de um cartão', 'neutra'],
            $transf => [($entrada ? 'Veio de ' : 'Foi para ').($outra?->account?->rotulo ?? 'outra conta'), 'neutra'],
            $compraParcelada => [$pagas === 0 ? 'Nenhuma parcela paga ainda' : $pagas.' de '.$t->installments.' parcelas pagas', $pagas === (int) $t->installments ? 'ok' : 'aberta'],
            $foraDoApp => ['Paga fora do app (a fatura já tinha vencido)', 'ok'],
            $conta?->isCard() && $t->paid_at === null => ['Em aberto na fatura do '.$conta->name, 'aberta'],
            $conta?->isCard() && $t->credit_settlement_id !== null => ['Quitada pelo crédito de um estorno', 'ok'],
            $conta?->isCard() => ['Paga com a fatura em '.$t->paid_at->format('d/m/Y'), 'ok'],
            // Agendada (data futura): ainda não aconteceu — e o valor ainda pode mudar.
            ! $t->jaFoiPagaOuRecebida() => [($entrada ? 'Agendada — entra na conta em ' : 'Agendada — sai da conta em ').$t->date->format('d/m/Y'), 'aberta'],
            $entrada => ['Recebida na conta', 'ok'],
            default => ['Paga — saiu da conta', 'ok'],
        };
        $nome = $t->description ?: ($t->category->name ?? $tipoRotulo);
        $valorFormatado = number_format((float) $t->amount, 2, ',', '.');

        // O "Onde" marca UMA opção: débito e Pix submetem o id da conta que espelham, e o mesmo
        // valor aparece em mais de uma opção — marcar todas deixaria o navegador escolher a última.
        $contaEscolhida = (int) old('account_id', $t->account_id);
        $opcaoEscolhida = null;
        foreach ($gruposDeOnde as $gi => $grupo) {
            foreach ($grupo['opcoes'] as $oi => $op) {
                if ($opcaoEscolhida === null && (int) $op->id === $contaEscolhida) {
                    $opcaoEscolhida = $gi.'-'.$oi;
                }
            }
        }
        $categoriasDoTipo = $categories->filter(fn ($c) => $c->type === $t->type || $c->id === $t->category_id);

        // Exclusão: a pergunta do "tem certeza?" diz o que sai junto.
        $comRecorrencia = $ofereceEncerrarRecorrencia ?? false;
        $apagaveis = $compraParcelada ? $parcelas->filter(fn ($p) => $p->paid_at === null || $p->pago_fora_do_app)->count() : 0;
        $ficam = $compraParcelada ? $parcelas->count() - $apagaveis : 0;
        $pergunta = match (true) {
            $transf => 'Excluir esta transferência? As duas contas voltam ao que eram. Essa ação não pode ser desfeita.',
            $compraParcelada => 'Excluir a compra inteira? '.($apagaveis === 1 ? 'A parcela sai' : 'As '.$apagaveis.' parcelas saem').' da fatura'
                .($ficam > 0 ? ' (as já pagas numa fatura continuam no histórico)' : '').'. Para corrigir, você vai precisar lançar a compra de novo. Essa ação não pode ser desfeita.',
            $comRecorrencia => 'Excluir esta cobrança? A recorrência continua ativa — só esta cobrança sai. Essa ação não pode ser desfeita.',
            default => 'Excluir esta transação? Essa ação não pode ser desfeita.',
        };
    @endphp

    <div class="section-head">
        <h2>Editar movimentação</h2>
        <span class="sub">Corrija o que estiver errado. O que já mexeu no dinheiro fica travado.</span>
        <div class="head-actions">
            <a class="btn-ghost" href="{{ route('transactions.index') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
                Movimentações
            </a>
        </div>
    </div>

    <div class="tx-edit">
        <section class="card tx-ed" aria-labelledby="tx-ed-nome">
            {{-- O comprovante: o que foi, por onde, quanto e em que pé está. --}}
            <header class="tx-ed-topo">
                @include('transactions._icone', ['t' => $t, 'grande' => true])
                <div class="tx-ed-quem">
                    <span class="tx-ed-tipo {{ $transf ? 'transf' : ($entrada ? 'pos' : 'neg') }}">{{ $tipoRotulo }}@if ($compraParcelada) · em {{ $t->installments }}x @endif</span>
                    <h3 class="tx-ed-nome" id="tx-ed-nome">{{ $nome }}</h3>
                    <p class="tx-ed-meta">
                        @unless ($transf){{ $t->category ? $t->category->name : 'Sem categoria' }} · @endunless{{ $conta?->rotulo ?? 'Conta removida' }}
                    </p>
                </div>
                <div class="tx-ed-valor {{ $entrada ? 'pos' : '' }}">
                    <strong>{{ $transf ? '' : ($entrada ? '+' : '−') }} @brl($valorDoTopo)</strong>
                    <small>
                        @if ($compraParcelada){{ $t->installments }}x de @brl($t->amount) · @endif{{ ($compraParcelada ? $parcelas->first()?->date : $t->date)?->format('d/m/Y') }}
                    </small>
                </div>
            </header>
            <p class="tx-ed-situacao {{ $situacao[1] }}"><span aria-hidden="true"></span>{{ $situacao[0] }}</p>

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

            <form method="POST" action="{{ route('transactions.update', $t) }}" class="tx-ed-form" data-type="{{ $t->type }}" data-tx-form
                  data-tx-travavel @if ($errors->any()) data-tx-liberado @endif>
                @csrf
                @method('PUT')
                {{-- O tipo não muda depois de lançado: vai num hidden (o servidor confere). --}}
                <input type="hidden" name="type" value="{{ $t->type }}">

                @if ($transf)
                    <div class="tx-transfer-resumo">
                        <strong>Transferência entre contas</strong>
                        <span>
                            {{ $t->type === 'expense' ? 'Saída de ' : 'Entrada de ' }}@brl($t->amount)
                            {{ $t->type === 'expense' ? 'da conta ' : 'na conta ' }}{{ $conta->name }}
                            @if ($outra) ({{ $t->type === 'expense' ? 'para' : 'vinda de' }} {{ $outra->account->name }}) @endif.
                            Valor e contas não mudam por aqui — para trocar, exclua a transferência e lance de novo. O que você editar abaixo vale para as duas contas.
                        </span>
                    </div>
                    <input type="hidden" name="amount" value="{{ $valorFormatado }}">
                    <input type="hidden" name="account_id" value="{{ $t->account_id }}">
                @elseif ($fatosFixos)
                    {{-- Compra parcelada (ou paga fora do app): valor, data e cartão são fatos. --}}
                    <input type="hidden" name="amount" value="{{ $valorFormatado }}">
                    <input type="hidden" name="date" value="{{ $t->date->format('Y-m-d') }}">
                    <input type="hidden" name="account_id" value="{{ $t->account_id }}">
                    <dl class="tx-ed-fatos">
                        <div><dt>Valor</dt><dd>@if ($compraParcelada){{ $t->installments }}x de @brl($t->amount)@else @brl($t->amount)@endif</dd></div>
                        <div><dt>{{ $compraParcelada ? 'Comprado em' : 'Data' }}</dt><dd>{{ ($compraParcelada ? $parcelas->first()?->date : $t->date)?->format('d/m/Y') }}</dd></div>
                        <div><dt>Cartão</dt><dd>{{ $conta?->rotulo ?? '—' }}</dd></div>
                    </dl>
                    <p class="field-hint tx-ed-fatos-dica">
                        @if ($compraParcelada)
                            Cada parcela já está na fatura dela, então valor, data e cartão não mudam por aqui. O que você editar abaixo vale para as {{ $t->installments }} parcelas. Para corrigir o resto, exclua a compra e lance de novo.
                        @else
                            {{ \App\Http\Controllers\TransactionController::MENSAGEM_PAGA_FORA_DO_APP }}
                        @endif
                    </p>
                @else
                    <div class="form-row">
                        <div class="field">
                            <label for="amount">Valor (R$)</label>
                            <input class="input @error('amount') input-error @enderror" type="text" inputmode="decimal"
                                   id="amount" name="amount" placeholder="0,00" required
                                   @if ($valorTravado) readonly aria-describedby="amount-travado" @endif
                                   value="{{ $valorTravado ? $valorFormatado : old('amount', $valorFormatado) }}">
                            @if ($valorTravado)
                                <small class="field-hint" id="amount-travado">Já {{ $entrada ? 'recebida' : 'paga' }}: o valor não muda mais.</small>
                            @endif
                            @error('amount')<div class="field-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="field">
                            <label for="date">Data</label>
                            <input class="input @error('date') input-error @enderror" type="date" id="date" name="date" required
                                   min="2000-01-01" max="{{ now()->addYears(10)->format('Y-m-d') }}"
                                   value="{{ old('date', $t->date->format('Y-m-d')) }}">
                            @error('date')<div class="field-error">{{ $message }}</div>@enderror
                        </div>
                    </div>
                @endif

                @if ($transf)
                    <div class="field">
                        <label for="date">Data</label>
                        <input class="input @error('date') input-error @enderror" type="date" id="date" name="date" required
                               min="2000-01-01" max="{{ now()->addYears(10)->format('Y-m-d') }}"
                               value="{{ old('date', $t->date->format('Y-m-d')) }}">
                        @error('date')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                @endif

                <div class="field">
                    <label for="description">Descrição <span class="hint">(opcional)</span></label>
                    <input class="input @error('description') input-error @enderror" type="text" id="description" name="description"
                           maxlength="255" placeholder="Ex.: Supermercado, salário, aluguel…"
                           value="{{ old('description', $t->description ?? '') }}">
                    @error('description')<div class="field-error">{{ $message }}</div>@enderror
                </div>

                @unless ($transf)
                    <div class="form-row">
                        <div class="field">
                            <label for="category_id">Categoria</label>
                            <select class="input @error('category_id') input-error @enderror" id="category_id" name="category_id">
                                <option value="">Sem categoria</option>
                                @foreach ($categoriasDoTipo as $categoria)
                                    <option value="{{ $categoria->id }}" @selected((int) old('category_id', $t->category_id ?? 0) === $categoria->id)>
                                        {{ trim(($categoria->icon ?? '') . ' ' . $categoria->name) }}
                                    </option>
                                @endforeach
                            </select>
                            @error('category_id')<div class="field-error">{{ $message }}</div>@enderror
                        </div>

                        @unless ($fatosFixos)
                            <div class="field">
                                {{-- Só o que vale para o tipo (`TransactionController::gruposDaEdicao`). --}}
                                <label for="account_id">{{ $entrada ? 'Onde entrou' : 'Por onde saiu' }}</label>
                                <select class="input @error('account_id') input-error @enderror" id="account_id" name="account_id" required>
                                    @foreach ($gruposDeOnde as $gi => $grupo)
                                        <optgroup label="{{ $grupo['rotulo'] }}" data-para="{{ $grupo['para'] }}">
                                            @foreach ($grupo['opcoes'] as $oi => $op)
                                                {{-- Fluent: `isCard` é PROPRIEDADE, nunca isCard(). --}}
                                                <option value="{{ $op->id }}" data-para="{{ $grupo['para'] }}" data-card="{{ $op->isCard ? '1' : '0' }}"
                                                        @selected($opcaoEscolhida === $gi.'-'.$oi)>
                                                    {{ $op->rotuloCurto ?? $op->rotulo ?? $op->name }}
                                                </option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                                @error('account_id')<div class="field-error">{{ $message }}</div>@enderror
                            </div>
                        @endunless
                    </div>
                @endunless

                {{-- Quem fez (só quando a família tem mais de uma pessoa) --}}
                @if ($familyMembers->count() > 1)
                    @php
                        // O autor GRAVADO (ou o do reenvio). Um autor que não está na lista
                        // (dependente excluído, lançamento antigo) ganha "Não informado": sem
                        // essa opção o navegador marcaria a PRIMEIRA pessoa e salvar trocaria o
                        // autor em silêncio (A-11). Valor vazio: o servidor mantém o gravado.
                        $autorAtual = old('made_by_user_id', $t->made_by_user_id);
                        $autorAtual = is_numeric($autorAtual) ? (int) $autorAtual : null;
                        $semAutorNaLista = $autorAtual === null || ! $familyMembers->contains('id', $autorAtual);
                    @endphp
                    <div class="field">
                        <label for="made_by_user_id">{{ $transf ? 'Quem fez a transferência' : ($entrada ? 'Quem recebeu' : 'Quem fez a compra') }}</label>
                        <select class="input" id="made_by_user_id" name="made_by_user_id">
                            @if ($semAutorNaLista)
                                <option value="" selected>Não informado</option>
                            @endif
                            @foreach ($familyMembers as $membro)
                                <option value="{{ $membro->id }}" @selected($autorAtual === $membro->id)>
                                    {{ $membro->name }}{{ $membro->isTitular() ? ' (titular)' : '' }}
                                </option>
                            @endforeach
                        </select>
                        @error('made_by_user_id')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                @endif

                @if ($travaDeEdicao)
                    <p class="tx-d-aviso" role="note">{{ $travaDeEdicao }}</p>
                @elseif ($valorTravado && ! $fatosFixos)
                    <p class="tx-d-aviso" role="note">{{ \App\Http\Controllers\TransactionController::mensagemDoValorTravado($t) }}</p>
                @endif

                {{-- Sem JS os campos já vêm liberados; com JS eles nascem travados e o "Editar" do lado
                     os libera (`sm/edicao-travada.js`). O "Cancelar" volta a travar e desfaz o digitado. --}}
                <div class="form-actions" data-tx-acoes>
                    <a class="btn-ghost" href="{{ route('transactions.index') }}" data-tx-cancelar>Cancelar</a>
                    <button class="btn-primary" type="submit">Salvar {{ $compraParcelada ? 'a compra' : 'alterações' }}</button>
                </div>
            </form>
        </section>

        <aside class="tx-lado" aria-label="Detalhes da transação">
            @if ($compraParcelada)
                <section class="card tx-lado-card tx-d-parcelas">
                    <h4>Parcelas <span>{{ $pagas }} de {{ $t->installments }} pagas · total @brl($totalParcelado)</span></h4>
                    <div class="tx-parc-barra" role="img" aria-label="{{ $pagas }} de {{ $t->installments }} parcelas pagas">
                        <span style="width: {{ $t->installments > 0 ? round($pagas / $t->installments * 100) : 0 }}%"></span>
                    </div>
                    {{-- Cada parcela com a FATURA em que cai (o mês do vencimento dela) — o que a pessoa
                         confere com o app do banco (out/2026 — pedido do Victor). --}}
                    <div class="tx-parc-cab" aria-hidden="true"><span>Parcela</span><span>Fatura</span><span>Valor</span><span></span></div>
                    <ol class="scroll">
                        @foreach ($parcelas as $parcela)
                            @php
                                $cicloDaParcela = $conta?->billingCycle(\Carbon\CarbonImmutable::parse($parcela->date));
                                $vencDaParcela = $cicloDaParcela ? $conta->dueDateForCycle($cicloDaParcela[1]) : null;
                            @endphp
                            <li @class(['atual' => $parcela->id === $t->id])>
                                <span>{{ $parcela->installment_no }}/{{ $parcela->installments }}</span>
                                <span title="Compra em {{ $parcela->date->format('d/m/Y') }}{{ $vencDaParcela ? ' · vence em '.$vencDaParcela->format('d/m/Y') : '' }}">{{ $vencDaParcela ? $vencDaParcela->translatedFormat('M/y') : $parcela->date->format('d/m/Y') }}</span>
                                <span>@brl($parcela->amount)</span>
                                <span class="st {{ $parcela->paid_at ? 'paga' : '' }}">{{ $parcela->pago_fora_do_app ? 'paga fora' : ($parcela->paid_at ? 'paga' : 'em aberto') }}</span>
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endif

            <section class="card tx-lado-card">
                <h4>Detalhes</h4>
                <dl class="tx-d-lista">
                    <div><dt>Situação</dt><dd>{{ $situacao[0] }}</dd></div>
                    <div><dt>{{ $transf ? ($entrada ? 'Entrou em' : 'Saiu de') : 'Onde' }}</dt><dd>{{ $conta?->rotulo ?? '—' }}</dd></div>
                    <div><dt>Quem fez</dt><dd>{{ $t->madeBy?->name ?? 'Não informado' }}</dd></div>
                    @if ($t->funding_source === \App\Support\FundingSource::CHEQUE_ESPECIAL)
                        <div><dt>Cheque especial</dt><dd>@brl($t->funding_amount) vieram do limite</dd></div>
                    @elseif ($t->funding_source === \App\Support\FundingSource::RESGATE_INVESTIMENTO)
                        <div><dt>Resgate</dt><dd>@brl($t->funding_amount) vieram de um investimento</dd></div>
                    @endif
                    <div><dt>Lançada em</dt><dd>{{ $t->created_at ? $t->created_at->format('d/m/Y').' às '.$t->created_at->format('H:i') : '—' }}</dd></div>
                </dl>
            </section>

            {{-- Editar: os campos nascem travados (nada muda sem querer) — com JS; o botão os libera.
                 Escondido sem JS, quando o formulário já vem liberado. --}}
            <section class="card tx-lado-card tx-editar" data-tx-editar hidden>
                <h4>Editar</h4>
                <p class="tx-excluir-texto">Os campos ficam travados para nada mudar sem querer.</p>
                <button class="btn-primary" type="button" data-tx-liberar>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M4 20h4L19.5 8.5a2.1 2.1 0 0 0-3-3L5 17v3Z"/><path d="m13.5 6.5 3 3"/></svg>
                    Editar movimentação
                </button>
            </section>

            {{-- Excluir: o que sai junto, dito ANTES do clique. --}}
            <section class="card tx-lado-card tx-excluir">
                <h4>{{ $transf ? 'Excluir a transferência' : ($compraParcelada ? 'Excluir a compra inteira' : 'Excluir') }}</h4>
                @if ($travaDeExclusao)
                    <p class="tx-excluir-texto">{{ $travaDeExclusao }}</p>
                @else
                    <p class="tx-excluir-texto">
                        @if ($transf)
                            Sai das duas contas: o dinheiro volta para a conta de origem.
                        @elseif ($compraParcelada)
                            {{ $apagaveis === 1 ? 'A parcela sai' : 'As '.$apagaveis.' parcelas saem' }} da fatura do cartão de uma vez{{ $ficam > 0 ? ' — '.($ficam === 1 ? 'a já paga numa fatura continua' : 'as '.$ficam.' já pagas numa fatura continuam').' no histórico' : '' }}. Para corrigir valor, data ou cartão, exclua e lance a compra de novo.
                        @elseif ($comRecorrencia)
                            Só esta cobrança sai. Marque a caixa para parar as próximas também.
                        @else
                            A movimentação sai do histórico e {{ $entrada ? 'o valor deixa de contar no saldo' : 'o valor volta para o saldo' }}.
                        @endif
                    </p>
                    {{-- "Tem certeza?" pelo sm/confirmar.js (o `onsubmit` inline é bloqueado pela CSP).
                         Com a caixa de encerrar marcada, a pergunta passa a ser a dela. --}}
                    <form method="POST" action="{{ route('transactions.destroy', $t) }}" class="tx-excluir-form" data-confirmar="{{ $pergunta }}">
                        @csrf
                        @method('DELETE')
                        @if ($comRecorrencia)
                            <label class="check" style="align-items: flex-start;">
                                <input type="checkbox" name="encerrar_recorrencia" value="1"
                                       data-confirmar-marcado="Excluir esta cobrança e encerrar a recorrência? Nenhuma cobrança nova será lançada. Essa ação não pode ser desfeita." />
                                <span class="box" style="margin-top: 1px;"><svg viewBox="0 0 24 24" fill="none"><path d="M5 12l4 4 10-10"/></svg></span>
                                <span>Encerrar também a recorrência — nenhuma cobrança nova será lançada</span>
                            </label>
                        @endif
                        <button class="btn-danger" type="submit">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M4 7h16M9 7V5a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 5v2M6.5 7l.8 12a2 2 0 0 0 2 1.9h5.4a2 2 0 0 0 2-1.9l.8-12M10 11v6M14 11v6"/></svg>
                            {{ $compraParcelada ? 'Excluir a compra' : ($transf ? 'Excluir a transferência' : 'Excluir') }}
                        </button>
                    </form>
                @endif
            </section>
        </aside>
    </div>
@endsection
