{{-- Modal global "Lançar" (nova transação). Presente no shell de todas as páginas
     autenticadas; dados via View Composer (lmAccounts/lmCategories/lmFamily).
     Envia por AJAX (fetch → JSON); abre/fecha com a animação do .modal-scrim. --}}
@php($hoje = now()->format('Y-m-d'))
<div class="modal-scrim" id="launchModal" data-close>
    <div class="modal modal-wide" data-type="expense">
        <div class="modal-head">
            <span class="modal-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg></span>
            <div>
                <h3>Nova transação</h3>
                <p>Registre uma receita ou despesa</p>
            </div>
            <button class="modal-x" type="button" data-close-btn aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>

        @if ($lmAccounts->isEmpty())
            {{-- Sem conta não dá para lançar --}}
            <div class="modal-body">
                <div class="empty-state" style="padding:22px 10px">
                    <div class="pico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 9.5h19M6 15h4"/></svg></div>
                    <h3>Crie uma conta primeiro</h3>
                    <p>Você precisa de pelo menos uma conta para registrar transações.</p>
                    <a class="btn-primary" href="{{ route('accounts.create') }}">Criar conta</a>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-close-btn>Fechar</button>
            </div>
        @else
            {{-- Banner de erro do envio AJAX --}}
            <div class="flash-error" data-lm-error role="alert" hidden>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
                <span data-lm-error-msg></span>
            </div>

            <form method="POST" action="{{ route('transactions.store') }}" data-launch-form data-type="expense">
                @csrf
                <div class="modal-body">
                    {{-- Tipo --}}
                    <div class="field">
                        <label>Tipo</label>
                        <div class="type-toggle">
                            <span class="tt-pill" aria-hidden="true"></span>
                            <input type="radio" id="lm-tt-income" name="type" value="income">
                            <label class="tt-income" for="lm-tt-income">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M7 17 17 7M17 7h-7M17 7v7"/></svg>
                                Receita
                            </label>
                            <input type="radio" id="lm-tt-expense" name="type" value="expense" checked>
                            <label class="tt-expense" for="lm-tt-expense">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M7 7 17 17M17 17h-7M17 17v-7"/></svg>
                                Despesa
                            </label>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label for="lm-amount">Valor (R$)</label>
                            <input class="input" type="text" inputmode="decimal" id="lm-amount" name="amount" placeholder="0,00" required>
                        </div>
                        <div class="field">
                            <label for="lm-date">Data</label>
                            <input class="input" type="date" id="lm-date" name="date" required
                                   min="2000-01-01" max="{{ now()->addYears(10)->format('Y-m-d') }}" value="{{ $hoje }}">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label for="lm-account">Conta</label>
                            <select class="input" id="lm-account" name="account_id" required>
                                @foreach ($lmAccounts as $conta)
                                    <option value="{{ $conta->id }}">{{ $conta->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label for="lm-category">Categoria</label>
                            <select class="input" id="lm-category" name="category_id">
                                <option value="">Sem categoria</option>
                                <optgroup label="Receitas" data-type="income">
                                    @foreach ($lmCategories->where('type', 'income') as $categoria)
                                        <option value="{{ $categoria->id }}" data-type="income">{{ trim(($categoria->icon ?? '') . ' ' . $categoria->name) }}</option>
                                    @endforeach
                                </optgroup>
                                <optgroup label="Despesas" data-type="expense">
                                    @foreach ($lmCategories->where('type', 'expense') as $categoria)
                                        <option value="{{ $categoria->id }}" data-type="expense">{{ trim(($categoria->icon ?? '') . ' ' . $categoria->name) }}</option>
                                    @endforeach
                                </optgroup>
                            </select>
                        </div>
                    </div>

                    @if ($lmFamily->count() > 1)
                        <div class="field">
                            <label for="lm-author">Quem fez a compra</label>
                            <select class="input" id="lm-author" name="made_by_user_id">
                                @foreach ($lmFamily as $membro)
                                    <option value="{{ $membro->id }}" @selected($membro->id === auth()->id())>{{ $membro->name }}{{ $membro->isTitular() ? ' (titular)' : '' }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div class="field">
                        <label for="lm-description">Descrição <span class="hint">(opcional)</span></label>
                        <input class="input" type="text" id="lm-description" name="description" maxlength="255" placeholder="Ex.: Supermercado, salário, aluguel…">
                    </div>
                </div>

                <div class="modal-foot">
                    <button class="btn ghost" type="button" data-close-btn>Cancelar</button>
                    <button class="btn primary" type="submit" data-lm-save>
                        <span class="btn-label">Salvar</span>
                        <span class="btn-spin" aria-hidden="true"></span>
                    </button>
                </div>
            </form>
        @endif
    </div>
</div>
