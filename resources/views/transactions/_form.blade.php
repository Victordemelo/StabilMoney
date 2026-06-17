{{--
    Formulário compartilhado de transação (create/edit).
    Espera: $transaction (null no create), $accounts, $categories.
--}}
@php
    $editando = $transaction !== null;
    $tipoAtual = old('type', $transaction->type ?? 'expense');
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

        <form method="POST" action="{{ $editando ? route('transactions.update', $transaction) : route('transactions.store') }}" data-type="{{ $tipoAtual }}">
            @csrf
            @if ($editando)
                @method('PUT')
            @endif

            {{-- Tipo (Receita/Despesa) --}}
            <div class="field">
                <label>Tipo</label>
                <div class="type-toggle">
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
                </div>
                @error('type')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            <div class="form-row">
                {{-- Valor --}}
                <div class="field">
                    <label for="amount">Valor (R$)</label>
                    <input class="input @error('amount') input-error @enderror" type="text" inputmode="decimal"
                           id="amount" name="amount" placeholder="0,00" required
                           value="{{ old('amount', $editando ? number_format((float) $transaction->amount, 2, ',', '.') : '') }}">
                    @error('amount')<div class="field-error">{{ $message }}</div>@enderror
                </div>

                {{-- Data --}}
                <div class="field">
                    <label for="date">Data</label>
                    <input class="input @error('date') input-error @enderror" type="date" id="date" name="date" required
                           min="2000-01-01" max="{{ now()->addYears(10)->format('Y-m-d') }}"
                           value="{{ old('date', $editando ? $transaction->date->format('Y-m-d') : now()->format('Y-m-d')) }}">
                    @error('date')<div class="field-error">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="form-row">
                {{-- Conta --}}
                <div class="field">
                    <label for="account_id">Conta</label>
                    <select class="input @error('account_id') input-error @enderror" id="account_id" name="account_id" required>
                        @foreach ($accounts as $conta)
                            <option value="{{ $conta->id }}" @selected((int) old('account_id', $transaction->account_id ?? 0) === $conta->id)>
                                {{ trim(($conta->icon ?? '') . ' ' . $conta->name) }}
                            </option>
                        @endforeach
                    </select>
                    @error('account_id')<div class="field-error">{{ $message }}</div>@enderror
                </div>

                {{-- Categoria (agrupada por tipo; filtrada via JS) --}}
                <div class="field">
                    <label for="category_id">Categoria</label>
                    <select class="input @error('category_id') input-error @enderror" id="category_id" name="category_id">
                        <option value="">Sem categoria</option>
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

            {{-- Quem fez a compra (só quando a família tem mais de uma pessoa) --}}
            @isset($familyMembers)
                @if ($familyMembers->count() > 1)
                    <div class="field">
                        <label for="made_by_user_id">Quem fez a compra</label>
                        <select class="input" id="made_by_user_id" name="made_by_user_id">
                            @foreach ($familyMembers as $membro)
                                <option value="{{ $membro->id }}"
                                    @selected((int) old('made_by_user_id', $transaction->made_by_user_id ?? auth()->id()) === $membro->id)>
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

<script>
    // Tom do formulário (pílula/accent) + filtro de categorias pelo tipo escolhido
    (function () {
        var radios = document.querySelectorAll('input[name="type"]');
        if (!radios.length) return;
        var form = radios[0].closest('form');
        var select = document.getElementById('category_id');

        function aplicar() {
            var marcado = document.querySelector('input[name="type"]:checked');
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
                        // Se a categoria selecionada é do outro tipo, volta para "Sem categoria"
                        if (!ativo && opt.selected) select.value = '';
                    });
                });
            }
        }

        radios.forEach(function (radio) { radio.addEventListener('change', aplicar); });
        aplicar();
    })();
</script>
