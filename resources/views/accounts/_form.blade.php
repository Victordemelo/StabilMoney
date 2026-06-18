{{--
    Formulário compartilhado de conta (create/edit).
    Espera: $account (null no create), $types (rótulos PT-BR dos tipos).
--}}
@php
    $editando = $account !== null;
    $icones = ['💵', '🏦', '💳', '🏠', '📈', '✈️', '🎯', '💰', '🪙', '📦'];
    $cores = ['#0F6B47', '#15795A', '#1C9A70', '#1FA06E', '#59C497', '#18B6BE', '#F0A93B', '#E5604D', '#9FB0A7', '#0B3A28'];
    $iconeAtual = old('icon', $account->icon ?? '💵');
    $corAtual = old('color', $account->color ?? '');
    // Campos de cartão de crédito: limite, dia de fechamento e dia de vencimento.
    $tipoAtual = old('type', $account->type ?? 'wallet');
    $limiteAtual = old('credit_limit', $account && $account->credit_limit !== null
        ? number_format((float) $account->credit_limit, 2, ',', '.') : '');
    $fechamentoAtual = old('closing_day', $account->closing_day ?? '');
    $vencimentoAtual = old('due_day', $account->due_day ?? '');
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

        <form method="POST" action="{{ $editando ? route('accounts.update', $account) : route('accounts.store') }}">
            @csrf
            @if ($editando)
                @method('PUT')
            @endif

            {{-- Nome --}}
            <div class="field">
                <label for="name">Nome da conta</label>
                <input class="input @error('name') input-error @enderror" type="text" id="name" name="name"
                       maxlength="255" placeholder="Ex.: Nubank, Carteira, Poupança…" required
                       value="{{ old('name', $account->name ?? '') }}">
                @error('name')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            <div class="form-row">
                {{-- Tipo --}}
                <div class="field">
                    <label for="type">Tipo</label>
                    <select class="input @error('type') input-error @enderror" id="type" name="type" required>
                        @foreach ($types as $valor => $rotulo)
                            <option value="{{ $valor }}" @selected(old('type', $account->type ?? 'wallet') === $valor)>{{ $rotulo }}</option>
                        @endforeach
                    </select>
                    @error('type')<div class="field-error">{{ $message }}</div>@enderror
                </div>

                {{-- Saldo inicial --}}
                <div class="field">
                    <label for="initial_balance">Saldo inicial (R$)</label>
                    <input class="input @error('initial_balance') input-error @enderror" type="text" inputmode="decimal"
                           id="initial_balance" name="initial_balance" placeholder="0,00" required
                           value="{{ old('initial_balance', $editando ? number_format((float) $account->initial_balance, 2, ',', '.') : '0,00') }}">
                    @error('initial_balance')<div class="field-error">{{ $message }}</div>@enderror
                </div>
            </div>

            {{-- Campos de cartão de crédito (limite + dias) — visíveis só quando o tipo é "credit_card".
                 O JS no rodapé alterna o atributo hidden conforme o <select> de tipo. --}}
            <div data-card-fields @if ($tipoAtual !== 'credit_card') hidden @endif>
                <div class="field">
                    <label for="credit_limit">Limite do cartão (R$)</label>
                    <input class="input @error('credit_limit') input-error @enderror" type="text" inputmode="decimal"
                           id="credit_limit" name="credit_limit" placeholder="Ex.: 5.000,00"
                           value="{{ $limiteAtual }}">
                    @error('credit_limit')<div class="field-error">{{ $message }}</div>@enderror
                </div>

                <div class="form-row">
                    {{-- Dia de fechamento --}}
                    <div class="field">
                        <label for="closing_day">Dia de fechamento</label>
                        <input class="input @error('closing_day') input-error @enderror" type="number" min="1" max="31"
                               id="closing_day" name="closing_day" placeholder="Ex.: 2"
                               value="{{ $fechamentoAtual }}">
                        @error('closing_day')<div class="field-error">{{ $message }}</div>@enderror
                    </div>

                    {{-- Dia de vencimento --}}
                    <div class="field">
                        <label for="due_day">Dia de vencimento</label>
                        <input class="input @error('due_day') input-error @enderror" type="number" min="1" max="31"
                               id="due_day" name="due_day" placeholder="Ex.: 9"
                               value="{{ $vencimentoAtual }}">
                        @error('due_day')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>

            {{-- Ícone --}}
            <div class="field">
                <label>Ícone</label>
                <div class="icon-picker">
                    @foreach ($icones as $i => $emoji)
                        <input type="radio" id="ic-{{ $i }}" name="icon" value="{{ $emoji }}" @checked($iconeAtual === $emoji)>
                        <label for="ic-{{ $i }}">{{ $emoji }}</label>
                    @endforeach
                </div>
                @error('icon')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            {{-- Cor --}}
            <div class="field">
                <label>Cor do cartão</label>
                <div class="color-picker">
                    <input type="radio" id="cor-default" name="color" value="" @checked($corAtual === '' || $corAtual === null)>
                    <label for="cor-default" title="Padrão do tema" style="background: linear-gradient(135deg, #0E5A3F, #0B3A28)"></label>
                    @foreach ($cores as $i => $cor)
                        <input type="radio" id="cor-{{ $i }}" name="color" value="{{ $cor }}" @checked($corAtual === $cor)>
                        <label for="cor-{{ $i }}" title="{{ $cor }}" style="background: {{ $cor }}"></label>
                    @endforeach
                </div>
                @error('color')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            <div class="form-actions">
                <a class="btn-ghost" href="{{ route('accounts.index') }}">Cancelar</a>
                <button class="btn-primary" type="submit">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Mostra os campos de cartão (limite, fechamento, vencimento) só quando o tipo
    // selecionado é "Cartão de crédito". Mesma abordagem inline do transactions/_form.
    (function () {
        var tipo = document.getElementById('type');
        var bloco = document.querySelector('[data-card-fields]');
        if (!tipo || !bloco) return;
        function aplicar() { bloco.hidden = tipo.value !== 'credit_card'; }
        tipo.addEventListener('change', aplicar);
        aplicar();
    })();
</script>
