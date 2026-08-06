{{--
    Formulário compartilhado de conta (create/edit).
    Espera: $account (null no create), $types, $banks, $checkingAccounts, $savingsAccounts.
--}}
@php
    $editando = $account !== null;
    $tipoAtual = old('type', $account->type ?? 'checking');
    $bancoAtual = old('bank', $account->bank ?? 'nubank');
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
    $tipoTravado = $editando && $account->hasMoneyHistory();
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

        <form method="POST" action="{{ $editando ? route('accounts.update', $account) : route('accounts.store') }}" data-account-form>
            @csrf
            @if ($editando)
                @method('PUT')
            @endif

            {{-- Preview do cartão do banco escolhido --}}
            <div class="bank-preview">
                <img data-bank-preview src="{{ asset('assets/banks/' . $bancoAtual . '.png') }}" alt="Cartão do banco">
            </div>

            {{-- Nome --}}
            <div class="field">
                <label for="name">Nome da conta</label>
                <input class="input @error('name') input-error @enderror" type="text" id="name" name="name"
                       maxlength="255" placeholder="Ex.: Nubank, Conta principal, Poupança…" required
                       value="{{ old('name', $account->name ?? '') }}">
                @error('name')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            <div class="form-row">
                {{-- Tipo --}}
                <div class="field">
                    <label for="type">Tipo</label>
                    <select class="input @error('type') input-error @enderror" id="type" name="{{ $tipoTravado ? '_type_travado' : 'type' }}"
                            data-type required @disabled($tipoTravado) @if ($tipoTravado) data-type-locked @endif>
                        @foreach ($types as $valor => $rotulo)
                            <option value="{{ $valor }}" @selected($tipoAtual === $valor)>{{ $rotulo }}</option>
                        @endforeach
                    </select>
                    @if ($tipoTravado)
                        {{-- Select desabilitado não envia valor: o tipo atual vai no hidden. --}}
                        <input type="hidden" name="type" value="{{ $account->type }}">
                        <p class="form-hint">O tipo não pode mudar: esta conta já tem saldo, lançamentos ou dinheiro guardado. Para mudar, crie um novo método de pagamento.</p>
                    @endif
                    @error('type')<div class="field-error">{{ $message }}</div>@enderror
                </div>

                {{-- Banco (com logo) --}}
                <div class="field">
                    <label for="bank">Banco</label>
                    <select class="input @error('bank') input-error @enderror" id="bank" name="bank" data-bank required>
                        @foreach ($banks as $valor => $rotulo)
                            <option value="{{ $valor }}" @selected($bancoAtual === $valor)>{{ $rotulo }}</option>
                        @endforeach
                    </select>
                    @error('bank')<div class="field-error">{{ $message }}</div>@enderror
                </div>
            </div>

            {{-- Saldo inicial: só conta corrente/poupança --}}
            <div class="field" data-fields-account @if (! in_array($tipoAtual, ['checking', 'savings'])) hidden @endif>
                <label for="initial_balance">Saldo inicial (R$)</label>
                <input class="input @error('initial_balance') input-error @enderror" type="text" inputmode="decimal"
                       id="initial_balance" name="initial_balance" placeholder="0,00"
                       value="{{ old('initial_balance', $editando && $account->initial_balance !== null ? number_format((float) $account->initial_balance, 2, ',', '.') : '0,00') }}">
                @error('initial_balance')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            {{-- Cheque especial: SÓ conta corrente (bloco próprio — o de cima
                 vale também para poupança, que não tem cheque especial) --}}
            <div class="field" data-fields-overdraft @if ($tipoAtual !== 'checking') hidden @endif>
                <label for="overdraft_limit">Limite do cheque especial (R$)</label>
                <input class="input @error('overdraft_limit') input-error @enderror" type="text" inputmode="decimal"
                       id="overdraft_limit" name="overdraft_limit" placeholder="0,00" value="{{ $chequeAtual }}">
                <p class="form-hint">Quanto o banco deixa seu saldo ficar negativo nesta conta. Deixe em branco se sua conta não tem cheque especial.</p>
                @error('overdraft_limit')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            {{-- Cartão de crédito: limite + dias --}}
            <div data-fields-credit @if ($tipoAtual !== 'credit_card') hidden @endif>
                <div class="field">
                    <label for="credit_limit">Limite do cartão (R$)</label>
                    <input class="input @error('credit_limit') input-error @enderror" type="text" inputmode="decimal"
                           id="credit_limit" name="credit_limit" placeholder="Ex.: 5.000,00" value="{{ $limiteAtual }}">
                    @error('credit_limit')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="form-row">
                    <div class="field">
                        <label for="closing_day">Dia de fechamento</label>
                        <input class="input @error('closing_day') input-error @enderror" type="number" min="1" max="28"
                               id="closing_day" name="closing_day" placeholder="Ex.: 2" value="{{ $fechamentoAtual }}">
                        @error('closing_day')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="due_day">Dia de vencimento</label>
                        <input class="input @error('due_day') input-error @enderror" type="number" min="1" max="28"
                               id="due_day" name="due_day" placeholder="Ex.: 9" value="{{ $vencimentoAtual }}">
                        @error('due_day')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>

            {{-- Cartão de débito: vincula corrente e/ou poupança que ele espelha --}}
            <div data-fields-debit @if ($tipoAtual !== 'debit_card') hidden @endif>
                <p class="form-hint">O cartão de débito não tem saldo próprio: ele mostra o saldo da Conta Corrente e/ou Poupança vinculadas (somados).</p>
                <div class="form-row">
                    <div class="field">
                        <label for="checking_account_id">Conta Corrente vinculada</label>
                        <select class="input @error('checking_account_id') input-error @enderror" id="checking_account_id" name="checking_account_id">
                            <option value="">Nenhuma</option>
                            @foreach ($checkingAccounts as $c)
                                <option value="{{ $c->id }}" @selected($checkingAtual === $c->id)>{{ $c->name }}</option>
                            @endforeach
                        </select>
                        @error('checking_account_id')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="savings_account_id">Conta Poupança vinculada</label>
                        <select class="input @error('savings_account_id') input-error @enderror" id="savings_account_id" name="savings_account_id">
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
                    <label for="pix_account_id">Conta da chave Pix</label>
                    <select class="input @error('checking_account_id') input-error @enderror @error('savings_account_id') input-error @enderror"
                            id="pix_account_id" name="pix_account_id">
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

            <div class="form-actions">
                <a class="btn-ghost" href="{{ route('accounts.index') }}">Cancelar</a>
                <button class="btn-primary" type="submit">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Mostra os campos certos conforme o tipo e troca a imagem do banco no preview.
    (function () {
        var form = document.querySelector('[data-account-form]');
        if (!form) return;
        var tipo = form.querySelector('[data-type]');
        var banco = form.querySelector('[data-bank]');
        var preview = form.querySelector('[data-bank-preview]');
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
        function aplicarBanco() {
            if (preview && banco) preview.src = "{{ asset('assets/banks') }}/" + banco.value + '.png';
        }

        tipo.addEventListener('change', aplicarTipo);
        banco.addEventListener('change', aplicarBanco);
        aplicarTipo();
    })();
</script>
