{{--
    Formulário compartilhado de conta — serve as TRÊS entradas do cadastro:
    a página cheia (`create`/`edit`) e os modais da lista (`accounts/index`).

    Espera: $account (null no create), $types, $banks, $checkingAccounts, $savingsAccounts.

    Opcionais:
      $modal       — `true` troca a moldura: em vez do `.grid > .card`, o formulário
                     vira `.modal-body` + `.modal-foot`, para ser colocado DENTRO de
                     um `.modal`. Os CAMPOS são os mesmos, de propósito: este é o
                     formulário mais complexo do app (5 tipos, campos condicionais,
                     preview do banco, tipo travado) e mantê-lo em dois lugares
                     garantiria que um dos dois ficasse para trás.
      $tipoTravado — quem já sabe se a conta tem dinheiro informa aqui. A lista
                     resolve isso para TODAS as contas numa query só (`withExists`
                     no controller); perguntar `hasMoneyHistory()` dentro do laço
                     dos cards seriam 3 consultas por conta.
      $espelhosPorConta — [id da conta => débitos/Pix que tiram dinheiro dela]. A
                     lista monta com as contas que já carregou; sem ele (página
                     cheia de edição), o formulário pergunta ao banco — uma conta só.
--}}
@php
    $modal = $modal ?? false;
    $editando = $account !== null;
    $tipoAtual = old('type', $account->type ?? 'checking');
    // Banco: NENHUM pré-selecionado no cadastro. Com "Nubank" de padrão, quem não
    // mexia no select cadastrava tudo como Nubank, com a logo errada no card. Sem
    // banco, o select abre em "Selecione o banco" e o `required` (e a validação do
    // servidor, "Escolha o banco.") pedem a escolha.
    $bancoAtual = old('bank', $account->bank ?? null);
    $bancoAtual = is_string($bancoAtual) && isset($banks[$bancoAtual]) ? $bancoAtual : null;
    $limiteAtual = old('credit_limit', $account && $account->credit_limit !== null
        ? number_format((float) $account->credit_limit, 2, ',', '.') : '');
    $fechamentoAtual = old('closing_day', $account->closing_day ?? '');
    $vencimentoAtual = old('due_day', $account->due_day ?? '');
    $chequeAtual = old('overdraft_limit', $account && (float) $account->overdraft_limit > 0
        ? number_format((float) $account->overdraft_limit, 2, ',', '.') : '');
    $checkingAtual = (int) old('checking_account_id', $account->checking_account_id ?? 0);
    $savingsAtual = (int) old('savings_account_id', $account->savings_account_id ?? 0);
    // Pix usa UM select só (a chave vive numa conta), então a conta atual é a que
    // estiver preenchida — o servidor devolve para a coluna certa conforme o tipo.
    $pixAtual = (int) old('pix_account_id', $checkingAtual ?: $savingsAtual);
    // Conta que já tem dinheiro (saldo, lançamentos ou aportes) não troca de
    // tipo: a fórmula do saldo mudaria e o dinheiro sumiria (ou contaria duas
    // vezes). O servidor recusa; aqui o campo já aparece travado.
    $travadoPorHistorico = $tipoTravado ?? ($editando && $account->hasMoneyHistory());
    // Conta que um cartão de débito ou um Pix espelha também não troca de tipo,
    // mesmo zerada: o método passaria a lançar no lugar errado (ex.: num cartão de
    // crédito). Mesma recusa do servidor (`Account::travaDeEspelho`).
    $espelhos = ! $editando ? collect()
        : (isset($espelhosPorConta) ? collect($espelhosPorConta[$account->id] ?? []) : $account->metodosQueEspelham());
    $tipoTravado = $travadoPorHistorico || $espelhos->isNotEmpty();
    // Sufixo único dos `id`: a lista repete este formulário uma vez por conta (um
    // modal cada). Com id repetido, o `<label for>` passa a focar o campo do
    // vizinho e o script de campos condicionais acha o formulário errado.
    $uid = $editando ? 'c' . $account->id : 'novo';
    $formId = 'acct-form-' . $uid;
@endphp

@if (! $modal)
    {{-- Página cheia: o formulário mora num card centralizado. (No modal NÃO pode:
         a `.card` tem overflow:hidden + animação com transform, e transform em
         ancestral vira o bloco de contenção de qualquer position:fixed dentro
         dela — o painel encolhe para a largura do card e é recortado.) --}}
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
@endif

<form method="POST" action="{{ $editando ? route('accounts.update', $account) : route('accounts.store') }}"
      id="{{ $formId }}" data-account-form>
    @csrf
    @if ($editando)
        @method('PUT')
    @endif

    {{-- No modal os campos vivem no .modal-body, que é quem rola quando o
         conteúdo passa da altura da tela (o .modal já tem max-height + overflow). --}}
    @if ($modal)
        <div class="modal-body">
    @endif

    {{-- Preview do cartão do banco escolhido. Sem banco ainda, fica escondido — um
         `src` montado com banco vazio seria uma imagem quebrada no topo do form. --}}
    <div class="bank-preview" data-bank-preview-box @if (! $bancoAtual) hidden @endif>
        <img data-bank-preview @if ($bancoAtual) src="{{ asset('assets/banks/' . $bancoAtual . '.png') }}" @endif alt="Cartão do banco">
    </div>

    {{-- Nome --}}
    <div class="field">
        <label for="name-{{ $uid }}">Nome da conta</label>
        <input class="input @error('name') input-error @enderror" type="text" id="name-{{ $uid }}" name="name"
               maxlength="255" placeholder="Ex.: Nubank, Conta principal, Poupança…" required
               value="{{ old('name', $account->name ?? '') }}">
        @error('name')<div class="field-error">{{ $message }}</div>@enderror
    </div>

    <div class="form-row">
        {{-- Tipo --}}
        <div class="field">
            <label for="type-{{ $uid }}">Tipo</label>
            <select class="input @error('type') input-error @enderror" id="type-{{ $uid }}" name="{{ $tipoTravado ? '_type_travado' : 'type' }}"
                    data-type required @disabled($tipoTravado) @if ($tipoTravado) data-type-locked @endif>
                @foreach ($types as $valor => $rotulo)
                    <option value="{{ $valor }}" @selected($tipoAtual === $valor)>{{ $rotulo }}</option>
                @endforeach
            </select>
            @if ($tipoTravado)
                {{-- Select desabilitado não envia valor: o tipo atual vai no hidden. --}}
                <input type="hidden" name="type" value="{{ $account->type }}">
                @if ($travadoPorHistorico)
                    <p class="form-hint">O tipo não pode mudar: esta conta já tem saldo, lançamentos ou dinheiro guardado. Para mudar, crie um novo método de pagamento.</p>
                @else
                    <p class="form-hint" data-type-locked-by-mirror>O tipo não pode mudar: {{ \App\Models\Account::descreverEspelhos($espelhos) }} {{ $espelhos->count() === 1 ? 'tira' : 'tiram' }} dinheiro desta conta. Para mudar, vincule {{ $espelhos->count() === 1 ? 'esse método' : 'esses métodos' }} a outra conta primeiro.</p>
                @endif
            @endif
            @error('type')<div class="field-error">{{ $message }}</div>@enderror
        </div>

        {{-- Banco (com logo) --}}
        <div class="field">
            <label for="bank-{{ $uid }}">Banco</label>
            <select class="input @error('bank') input-error @enderror" id="bank-{{ $uid }}" name="bank" data-bank required>
                {{-- Placeholder de valor vazio como 1ª opção: com o `required`, o
                     navegador não deixa enviar sem escolher um banco de verdade. --}}
                <option value="" @selected(! $bancoAtual)>Selecione o banco</option>
                @foreach ($banks as $valor => $rotulo)
                    <option value="{{ $valor }}" @selected($bancoAtual === $valor)>{{ $rotulo }}</option>
                @endforeach
            </select>
            @error('bank')<div class="field-error">{{ $message }}</div>@enderror
        </div>
    </div>

    {{-- Saldo inicial: só conta corrente/poupança --}}
    <div class="field" data-fields-account @if (! in_array($tipoAtual, ['checking', 'savings'])) hidden @endif>
        <label for="initial_balance-{{ $uid }}">Saldo inicial (R$)</label>
        <input class="input @error('initial_balance') input-error @enderror" type="text" inputmode="decimal"
               id="initial_balance-{{ $uid }}" name="initial_balance" placeholder="0,00"
               value="{{ old('initial_balance', $editando && $account->initial_balance !== null ? number_format((float) $account->initial_balance, 2, ',', '.') : '0,00') }}">
        @error('initial_balance')<div class="field-error">{{ $message }}</div>@enderror
    </div>

    {{-- Cheque especial: SÓ conta corrente (bloco próprio — o de cima
         vale também para poupança, que não tem cheque especial) --}}
    <div class="field" data-fields-overdraft @if ($tipoAtual !== 'checking') hidden @endif>
        <label for="overdraft_limit-{{ $uid }}">Limite do cheque especial (R$)</label>
        <input class="input @error('overdraft_limit') input-error @enderror" type="text" inputmode="decimal"
               id="overdraft_limit-{{ $uid }}" name="overdraft_limit" placeholder="0,00" value="{{ $chequeAtual }}">
        <p class="form-hint">Quanto o banco deixa seu saldo ficar negativo nesta conta. Deixe em branco se sua conta não tem cheque especial.</p>
        @error('overdraft_limit')<div class="field-error">{{ $message }}</div>@enderror
    </div>

    {{-- Cartão de crédito: limite + dias --}}
    <div data-fields-credit @if ($tipoAtual !== 'credit_card') hidden @endif>
        <div class="field">
            <label for="credit_limit-{{ $uid }}">Limite do cartão (R$)</label>
            <input class="input @error('credit_limit') input-error @enderror" type="text" inputmode="decimal"
                   id="credit_limit-{{ $uid }}" name="credit_limit" placeholder="Ex.: 5.000,00" value="{{ $limiteAtual }}">
            @error('credit_limit')<div class="field-error">{{ $message }}</div>@enderror
        </div>
        <div class="form-row">
            <div class="field">
                <label for="closing_day-{{ $uid }}">Dia de fechamento</label>
                <input class="input @error('closing_day') input-error @enderror" type="number" min="1" max="28"
                       id="closing_day-{{ $uid }}" name="closing_day" placeholder="Ex.: 2" value="{{ $fechamentoAtual }}">
                @error('closing_day')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="due_day-{{ $uid }}">Dia de vencimento</label>
                <input class="input @error('due_day') input-error @enderror" type="number" min="1" max="28"
                       id="due_day-{{ $uid }}" name="due_day" placeholder="Ex.: 9" value="{{ $vencimentoAtual }}">
                @error('due_day')<div class="field-error">{{ $message }}</div>@enderror
            </div>
        </div>
    </div>

    {{-- Cartão de débito: vincula corrente e/ou poupança que ele espelha --}}
    <div data-fields-debit @if ($tipoAtual !== 'debit_card') hidden @endif>
        <p class="form-hint">O cartão de débito não tem saldo próprio: ele mostra o saldo da Conta Corrente e/ou Poupança vinculadas (somados).</p>
        <div class="form-row">
            <div class="field">
                <label for="checking_account_id-{{ $uid }}">Conta Corrente vinculada</label>
                <select class="input @error('checking_account_id') input-error @enderror" id="checking_account_id-{{ $uid }}" name="checking_account_id">
                    <option value="">Nenhuma</option>
                    @foreach ($checkingAccounts as $c)
                        <option value="{{ $c->id }}" @selected($checkingAtual === $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
                @error('checking_account_id')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="savings_account_id-{{ $uid }}">Conta Poupança vinculada</label>
                <select class="input @error('savings_account_id') input-error @enderror" id="savings_account_id-{{ $uid }}" name="savings_account_id">
                    <option value="">Nenhuma</option>
                    @foreach ($savingsAccounts as $s)
                        <option value="{{ $s->id }}" @selected($savingsAtual === $s->id)>{{ $s->name }}</option>
                    @endforeach
                </select>
                @error('savings_account_id')<div class="field-error">{{ $message }}</div>@enderror
            </div>
        </div>
        @if ($checkingAccounts->isEmpty() && $savingsAccounts->isEmpty())
            <p class="form-hint">Você ainda não tem contas corrente/poupança. Crie uma primeiro para vincular ao cartão de débito.</p>
        @endif
    </div>

    <div data-fields-pix @if ($tipoAtual !== 'pix') hidden @endif>
        <p class="form-hint">
            O Pix não tem saldo próprio: o dinheiro sai da conta em que a chave está
            registrada, na hora. Se o saldo acabar, o cheque especial daquela conta entra —
            igual a débito. Uma chave Pix fica em <strong>uma</strong> conta só.
        </p>
        <div class="field">
            <label for="pix_account_id-{{ $uid }}">Conta da chave Pix</label>
            <select class="input @error('checking_account_id') input-error @enderror @error('savings_account_id') input-error @enderror"
                    id="pix_account_id-{{ $uid }}" name="pix_account_id">
                <option value="">Selecione a conta</option>
                @if ($checkingAccounts->isNotEmpty())
                    <optgroup label="Conta Corrente">
                        @foreach ($checkingAccounts as $c)
                            <option value="{{ $c->id }}" @selected($pixAtual === $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </optgroup>
                @endif
                @if ($savingsAccounts->isNotEmpty())
                    <optgroup label="Conta Poupança">
                        @foreach ($savingsAccounts as $sv)
                            <option value="{{ $sv->id }}" @selected($pixAtual === $sv->id)>{{ $sv->name }}</option>
                        @endforeach
                    </optgroup>
                @endif
            </select>
            @error('checking_account_id')<div class="field-error">{{ $message }}</div>@enderror
            @error('savings_account_id')<div class="field-error">{{ $message }}</div>@enderror
        </div>
        @if ($checkingAccounts->isEmpty() && $savingsAccounts->isEmpty())
            <p class="form-hint">Você ainda não tem contas corrente/poupança. Crie uma primeiro para registrar a chave Pix.</p>
        @endif
    </div>

    @if ($modal)
        </div>{{-- /.modal-body --}}
        <div class="modal-foot">
            <button class="btn ghost" type="button" data-close-btn>Cancelar</button>
            <button class="btn primary" type="submit" data-acct-save>
                <span class="btn-label">{{ $editando ? 'Salvar alterações' : 'Salvar' }}</span>
                <span class="btn-spin" aria-hidden="true"></span>
            </button>
        </div>
    @else
        <div class="form-actions">
            <a class="btn-ghost" href="{{ route('accounts.index') }}">Cancelar</a>
            <button class="btn-primary" type="submit">Salvar</button>
        </div>
    @endif
</form>

@if (! $modal)
        </div>
    </div>
@endif

<script nonce="{{ Vite::cspNonce() }}">
    // Mostra os campos certos conforme o tipo e troca a imagem do banco no preview.
    //
    // Escopado pelo ID do formulário, não por `querySelector('[data-account-form]')`:
    // a lista de métodos tem VÁRIOS destes na mesma página (um modal por conta), e a
    // busca genérica pegaria sempre o primeiro — todo modal editaria os campos do
    // vizinho.
    (function () {
        var form = document.getElementById('{{ $formId }}');
        if (!form) return;
        var tipo = form.querySelector('[data-type]');
        var banco = form.querySelector('[data-bank]');
        var preview = form.querySelector('[data-bank-preview]');
        var caixaPreview = form.querySelector('[data-bank-preview-box]');
        var grupos = {
            account: form.querySelector('[data-fields-account]'),
            overdraft: form.querySelector('[data-fields-overdraft]'),
            credit: form.querySelector('[data-fields-credit]'),
            debit: form.querySelector('[data-fields-debit]'),
            pix: form.querySelector('[data-fields-pix]'),
        };

        function aplicarTipo() {
            var t = tipo.value;
            if (grupos.account) grupos.account.hidden = !(t === 'checking' || t === 'savings');
            // Cheque especial é só de conta corrente (poupança não tem).
            if (grupos.overdraft) grupos.overdraft.hidden = t !== 'checking';
            if (grupos.credit) grupos.credit.hidden = t !== 'credit_card';
            if (grupos.debit) grupos.debit.hidden = t !== 'debit_card';
            if (grupos.pix) grupos.pix.hidden = t !== 'pix';
        }
        // Sem banco escolhido (o placeholder), o preview some em vez de virar uma
        // imagem quebrada apontando para ".png".
        function aplicarBanco() {
            if (!preview || !banco) return;
            if (banco.value) preview.src = "{{ asset('assets/banks') }}/" + banco.value + '.png';
            if (caixaPreview) caixaPreview.hidden = !banco.value;
        }

        tipo.addEventListener('change', aplicarTipo);
        banco.addEventListener('change', aplicarBanco);
        aplicarTipo();
    })();
</script>
