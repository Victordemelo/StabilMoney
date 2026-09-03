{{--
    Formulário compartilhado de transação (create/edit).
    Espera: $transaction (null no create), $accounts, $categories.
--}}
@php
    $editando = $transaction !== null;
    $tipoAtual = old('type', $transaction->type ?? 'expense');
    // Ponta de transferência: só descrição, data e autor são editáveis — e valem
    // para as DUAS pontas. Valor, conta e tipo viajam em hidden (o servidor recusa
    // qualquer mudança neles); categoria nem existe aqui.
    $transferencia = $editando && $transaction->isTransferencia();
    $outraPonta = $transferencia ? $transaction->contrapartida() : null;
    // Terceiro segmento (só na CRIAÇÃO): transferir entre contas de CAIXA da
    // família. `$accounts` é `paymentOptions()` (Fluent): os métodos espelho
    // carregam o tipo deles (`pix`/`debit_card`) e ficam de fora sozinhos. Com
    // menos de duas contas de caixa não há para onde transferir — nem aparece.
    // Mesma regra do modal "Lançar" (partials/launch-modal).
    $contasCaixa = $editando ? collect() : $accounts->whereIn('type', ['checking', 'savings'])->values();
    $podeTransferir = $contasCaixa->count() >= 2;
    if ($tipoAtual === 'transfer' && ! $podeTransferir) {
        $tipoAtual = 'expense';
    }
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

        <form method="POST" action="{{ $editando ? route('transactions.update', $transaction) : route('transactions.store') }}" data-type="{{ $tipoAtual }}" data-tx-form @if (! $editando) data-offline-queue @endif>
            @csrf
            @if ($editando)
                @method('PUT')
            @endif

            @if ($transferencia)
                <div class="tx-transfer-resumo">
                    <strong>Transferência entre contas</strong>
                    <span>
                        {{ $transaction->type === 'expense' ? 'Saída de ' : 'Entrada de ' }}@brl($transaction->amount)
                        {{ $transaction->type === 'expense' ? 'da conta ' : 'na conta ' }}{{ $transaction->account->name }}
                        @if ($outraPonta) ({{ $transaction->type === 'expense' ? 'para' : 'vinda de' }} {{ $outraPonta->account->name }}) @endif.
                        Valor, conta e tipo não mudam por aqui — para trocar, exclua a transferência e lance de novo. O que você editar abaixo vale para as duas contas.
                    </span>
                </div>
                <input type="hidden" name="type" value="{{ $transaction->type }}">
                <input type="hidden" name="amount" value="{{ number_format((float) $transaction->amount, 2, ',', '.') }}">
                <input type="hidden" name="account_id" value="{{ $transaction->account_id }}">
            @else
            {{-- Tipo (Receita/Despesa/Transferência) --}}
            <div class="field">
                <label>Tipo</label>
                <div class="type-toggle {{ $podeTransferir ? 'tt-3' : '' }}">
                    <span class="tt-pill" aria-hidden="true"></span>
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
                    @if ($podeTransferir)
                        {{-- Mover dinheiro entre as próprias contas: não é receita nem
                             despesa (o dashboard ignora as duas pontas). O `POST /transactions`
                             aceita `type=transfer` — funciona SEM JS, pela mesma rota. --}}
                        <input type="radio" id="tt-transfer" name="type" value="transfer" @checked($tipoAtual === 'transfer')>
                        <label class="tt-transfer" for="tt-transfer">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M7 8h13M7 8l3-3M7 8l3 3M17 16H4M17 16l-3-3M17 16l-3 3"/></svg>
                            Transferência
                        </label>
                    @endif
                </div>
                @error('type')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            @endif

            <div class="form-row">
                @if (! $transferencia)
                {{-- Valor --}}
                <div class="field">
                    <label for="amount">Valor (R$)</label>
                    <input class="input @error('amount') input-error @enderror" type="text" inputmode="decimal"
                           id="amount" name="amount" placeholder="0,00" required
                           value="{{ old('amount', $editando ? number_format((float) $transaction->amount, 2, ',', '.') : '') }}">
                    @error('amount')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                @endif

                {{-- Data --}}
                <div class="field">
                    <label for="date">Data</label>
                    <input class="input @error('date') input-error @enderror" type="date" id="date" name="date" required
                           min="2000-01-01" max="{{ now()->addYears(10)->format('Y-m-d') }}"
                           value="{{ old('date', $editando ? $transaction->date->format('Y-m-d') : now()->format('Y-m-d')) }}">
                    @error('date')<div class="field-error">{{ $message }}</div>@enderror
                </div>
            </div>

            @if (! $transferencia)
            <div class="form-row">
                {{-- Conta --}}
                <div class="field">
                    <label for="account_id" data-tx-account-label>{{ $tipoAtual === 'transfer' ? 'De' : 'Onde' }}</label>
                    {{-- data-card marca os cartões de crédito: em RECEITA eles somem
                         do select (não se recebe dinheiro num cartão de crédito).
                         data-cash marca corrente/poupança: só elas são origem de transferência. --}}
                    <select class="input @error('account_id') input-error @enderror" id="account_id" name="account_id" required>
                        @foreach ($accounts as $conta)
                            {{-- $accounts vem de Account::paymentOptions() e é Fluent:
                                 `isCard` é PROPRIEDADE. Chamar isCard() cairia no __call
                                 do Fluent, que devolve $this (truthy) e marcaria TODA
                                 conta como cartão — em "Receita" o select ficava vazio. --}}
                            <option value="{{ $conta->id }}" data-card="{{ $conta->isCard ? '1' : '0' }}"
                                    data-cash="{{ in_array($conta->type, ['checking', 'savings'], true) ? '1' : '0' }}"
                                    @selected((int) old('account_id', $transaction->account_id ?? 0) === $conta->id)>
                                {{ trim(($conta->icon ?? '') . ' ' . $conta->name) }}
                            </option>
                        @endforeach
                    </select>
                    @error('account_id')<div class="field-error">{{ $message }}</div>@enderror
                </div>

                @if ($podeTransferir)
                    {{-- Destino da transferência. VISÍVEL por padrão, de propósito: sem JS a
                         pessoa precisa enxergá-lo para escolher; o script abaixo o esconde (e
                         DESABILITA, para não viajar no POST) fora do modo Transferência. O
                         servidor ignora o campo em receita/despesa e ignora a categoria em
                         transferência, então a página funciona nos dois casos sem JS. --}}
                    <div class="field" data-tx-transfer-only>
                        <label for="to_account_id">Para</label>
                        <select class="input @error('to_account_id') input-error @enderror" id="to_account_id" name="to_account_id">
                            @foreach ($contasCaixa as $conta)
                                <option value="{{ $conta->id }}" @selected((int) old('to_account_id', 0) === $conta->id)>{{ $conta->name }}</option>
                            @endforeach
                        </select>
                        @error('to_account_id')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                @endif

                {{-- Categoria (agrupada por tipo; filtrada via JS; some em transferência) --}}
                <div class="field" data-tx-not-transfer>
                    <label for="category_id">Categoria</label>
                    <select class="input @error('category_id') input-error @enderror" id="category_id" name="category_id">
                        <option value="">Selecione a categoria</option>
                        <optgroup label="Receitas" data-type="income">
                            @foreach ($categories->where('type', 'income') as $categoria)
                                <option value="{{ $categoria->id }}" data-type="income" @selected((int) old('category_id', $transaction->category_id ?? 0) === $categoria->id)>
                                    {{ trim(($categoria->icon ?? '') . ' ' . $categoria->name) }}
                                </option>
                            @endforeach
                        </optgroup>
                        <optgroup label="Despesas" data-type="expense">
                            @foreach ($categories->where('type', 'expense') as $categoria)
                                <option value="{{ $categoria->id }}" data-type="expense" @selected((int) old('category_id', $transaction->category_id ?? 0) === $categoria->id)>
                                    {{ trim(($categoria->icon ?? '') . ' ' . $categoria->name) }}
                                </option>
                            @endforeach
                        </optgroup>
                    </select>
                    @error('category_id')<div class="field-error">{{ $message }}</div>@enderror
                </div>
            </div>
            @endif

            {{-- Quem fez a compra (só quando a família tem mais de uma pessoa) --}}
            @isset($familyMembers)
                @if ($familyMembers->count() > 1)
                    <div class="field">
                        <label for="made_by_user_id">Quem fez a compra</label>
                        <select class="input" id="made_by_user_id" name="made_by_user_id">
                            @foreach ($familyMembers as $membro)
                                <option value="{{ $membro->id }}"
                                    @selected((int) old('made_by_user_id', $editando ? $transaction->made_by_user_id : request('autor', auth()->id())) === $membro->id)>
                                    {{ $membro->name }}{{ $membro->isTitular() ? ' (titular)' : '' }}
                                </option>
                            @endforeach
                        </select>
                        @error('made_by_user_id')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                @endif
            @endisset

            {{-- Descrição --}}
            <div class="field">
                <label for="description">Descrição <span class="hint">(opcional)</span></label>
                <input class="input @error('description') input-error @enderror" type="text" id="description" name="description"
                       maxlength="255" placeholder="Ex.: Supermercado, salário, aluguel…"
                       value="{{ old('description', $transaction->description ?? '') }}">
                @error('description')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            <div class="form-actions">
                <a class="btn-ghost" href="{{ route('transactions.index') }}">Cancelar</a>
                <button class="btn-primary" type="submit">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script nonce="{{ Vite::cspNonce() }}">
    // Tom do formulário (pílula/accent) + filtro de categorias pelo tipo escolhido.
    // Escopado ao PRÓPRIO form (data-tx-form) p/ não colidir com o modal global "Lançar".
    (function () {
        var form = document.querySelector('form[data-tx-form]');
        if (!form) return;
        var radios = form.querySelectorAll('input[name="type"]');
        if (!radios.length) return;
        var select = form.querySelector('#category_id');
        var contaSel = form.querySelector('#account_id');
        // Transferência (só na criação, e só com ≥ 2 contas de caixa — sem elas o
        // Blade nem renderiza o segmento). Espelha o modal "Lançar" (sm/launch.js).
        var destinoSel = form.querySelector('#to_account_id');
        var soTransfer = form.querySelectorAll('[data-tx-transfer-only]');
        var foraTransfer = form.querySelectorAll('[data-tx-not-transfer]');
        var rotuloConta = form.querySelector('[data-tx-account-label]');
        var subtitulo = document.querySelector('[data-tx-subtitle]');

        // A origem não pode ser o destino: tira a conta escolhida em "De" da lista
        // do "Para" e, se ela estava marcada lá, pula para a primeira outra conta.
        function excluirOrigemDoDestino() {
            if (!destinoSel || !contaSel) return;
            var origem = contaSel.value;
            var trocar = false;
            destinoSel.querySelectorAll('option').forEach(function (opt) {
                var mesma = opt.value === origem;
                opt.hidden = mesma;
                opt.disabled = mesma;
                if (mesma && opt.selected) trocar = true;
            });
            if (trocar) {
                var valida = Array.prototype.find.call(destinoSel.options, function (o) { return !o.disabled; });
                if (valida) destinoSel.value = valida.value;
            }
        }

        // Liga/desliga o modo Transferência: "De"/"Para" no lugar de "Onde"/Categoria.
        // Os selects escondidos ficam DESABILITADOS — campo desabilitado não viaja
        // no POST, então categoria não vai numa transferência nem `to_account_id`
        // numa despesa.
        function aplicarModoTransferencia(ligado) {
            soTransfer.forEach(function (el) { el.hidden = !ligado; });
            foraTransfer.forEach(function (el) { el.hidden = ligado; });
            if (destinoSel) destinoSel.disabled = !ligado;
            if (select) select.disabled = ligado;
            if (rotuloConta) rotuloConta.textContent = ligado ? 'De' : 'Onde';
            if (subtitulo) {
                subtitulo.textContent = ligado
                    ? 'Mova dinheiro entre suas contas — não conta como receita nem despesa'
                    : 'Registre uma receita ou despesa';
            }
            if (ligado) excluirOrigemDoDestino();
        }

        function aplicar() {
            var marcado = form.querySelector('input[name="type"]:checked');
            var tipo = marcado ? marcado.value : 'expense';

            // Atributo no form (em vez de :has()) dirige a cor da pílula e o accent do Valor
            if (form) form.dataset.type = tipo;

            // Mostra só as categorias do tipo escolhido (Receita/Despesa)
            if (select) {
                select.querySelectorAll('optgroup').forEach(function (grupo) {
                    var ativo = grupo.dataset.type === tipo;
                    grupo.hidden = !ativo;
                    grupo.querySelectorAll('option').forEach(function (opt) {
                        opt.hidden = !ativo;
                        opt.disabled = !ativo;
                        // Se a categoria selecionada é do outro tipo, limpa a escolha
                        if (!ativo && opt.selected) select.value = '';
                    });
                });
            }

            // RECEITA não entra em cartão de crédito: some as opções de cartão
            // e, se uma delas estava escolhida, cai na primeira conta válida.
            // TRANSFERÊNCIA só sai de conta de CAIXA: cartões e métodos espelho
            // (débito/Pix) somem do "De".
            if (contaSel) {
                var trocar = false;
                contaSel.querySelectorAll('option').forEach(function (opt) {
                    var soDespesa = opt.dataset.card === '1' && tipo === 'income';
                    var naoEhCaixa = tipo === 'transfer' && opt.dataset.cash !== '1';
                    var fora = soDespesa || naoEhCaixa;
                    opt.hidden = fora;
                    opt.disabled = fora;
                    if (fora && opt.selected) trocar = true;
                });
                if (trocar) {
                    var valida = Array.prototype.find.call(contaSel.options, function (o) { return !o.disabled; });
                    if (valida) contaSel.value = valida.value;
                }
            }

            aplicarModoTransferencia(tipo === 'transfer');
        }

        radios.forEach(function (radio) { radio.addEventListener('change', aplicar); });
        if (contaSel) {
            contaSel.addEventListener('change', function () {
                if (form.dataset.type === 'transfer') excluirOrigemDoDestino();
            });
        }
        aplicar();
    })();
</script>
