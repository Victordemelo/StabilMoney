{{-- Modal global "Lançar" (nova transação). Presente no shell de todas as páginas
     autenticadas; dados via View Composer (lmAccounts/lmCategories/lmFamily).
     Envia por AJAX (fetch → JSON); abre/fecha com a animação do .modal-scrim. --}}
@php
    $hoje = now()->format('Y-m-d');
    // Transferência só entre contas de CAIXA (corrente/poupança). `$lmAccounts` é
    // `paymentOptions()` (Fluent): os métodos espelho carregam o tipo deles
    // (`pix`/`debit_card`), então ficam de fora sozinhos. Com menos de duas contas
    // de caixa não há para onde transferir — o segmento nem aparece.
    $lmContasCaixa = $lmAccounts->whereIn('type', ['checking', 'savings'])->values();
    $lmPodeTransferir = $lmContasCaixa->count() >= 2;
@endphp
{{-- O PAINEL é o diálogo (role/aria-modal/nome do título), e o véu é só o fundo: é o
     que o leitor de tela anuncia ao abrir. Foco, Tab preso, Esc e a página inerte
     vêm do sm/dialogo.js. --}}
<div class="modal-scrim" id="launchModal" data-close>
    <div class="modal modal-wide" data-type="income"
         role="dialog" aria-modal="true" aria-labelledby="launchModal-titulo" aria-describedby="launchModal-descricao">
        <div class="modal-head">
            <span class="modal-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg></span>
            <div>
                <h3 id="launchModal-titulo">Nova transação</h3>
                <p id="launchModal-descricao" data-lm-subtitle>Registre uma receita ou despesa</p>
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

            {{-- `data-transfer-action`: no modo Transferência o JS troca o `action`
                 para a rota própria. `POST /transactions` também aceita `type=transfer`
                 (é para lá que a fila offline reenvia), então nada se perde sem rede. --}}
            <form method="POST" action="{{ route('transactions.store') }}" data-launch-form data-type="income"
                  data-store-action="{{ route('transactions.store') }}" data-transfer-action="{{ route('transactions.transfer') }}">
                @csrf
                <div class="modal-body">
                    {{-- Tipo — grupo de radios com nome: sem o `radiogroup`, o leitor de
                         tela lia "Receita, botão de opção" sem dizer do que é a escolha. --}}
                    <div class="field">
                        <label id="lm-tipo-rotulo">Tipo</label>
                        <div class="type-toggle {{ $lmPodeTransferir ? 'tt-3' : '' }}" role="radiogroup" aria-labelledby="lm-tipo-rotulo">
                            <span class="tt-pill" aria-hidden="true"></span>
                            <input type="radio" id="lm-tt-income" name="type" value="income" checked>
                            <label class="tt-income" for="lm-tt-income">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M7 17 17 7M17 7h-7M17 7v7"/></svg>
                                Receita
                            </label>
                            <input type="radio" id="lm-tt-expense" name="type" value="expense">
                            <label class="tt-expense" for="lm-tt-expense">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M7 7 17 17M17 17h-7M17 17v-7"/></svg>
                                Despesa
                            </label>
                            @if ($lmPodeTransferir)
                                {{-- Terceiro segmento: mover dinheiro entre as próprias contas.
                                     Não é receita nem despesa — o dashboard ignora as duas pontas. --}}
                                <input type="radio" id="lm-tt-transfer" name="type" value="transfer">
                                <label class="tt-transfer" for="lm-tt-transfer">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M7 8h13M7 8l3-3M7 8l3 3M17 16H4M17 16l-3-3M17 16l-3 3"/></svg>
                                    Transferência
                                </label>
                            @endif
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
                            <label for="lm-account" data-lm-account-label>Onde</label>
                            {{-- data-card marca os cartões de crédito: em RECEITA eles somem
                                 do select (não se recebe dinheiro num cartão de crédito). --}}
                            <select class="input" id="lm-account" name="account_id" required>
                                @foreach ($lmAccounts as $conta)
                                    {{-- $lmAccounts vem de Account::paymentOptions() e é Fluent:
                                         `isCard` é PROPRIEDADE, não método. Chamar isCard() aqui
                                         cairia no __call do Fluent, que devolve $this (truthy) e
                                         marcaria TODA conta como cartão. --}}
                                    {{-- data-cash: só corrente/poupança podem ser origem de transferência. --}}
                                    <option value="{{ $conta->id }}" data-card="{{ $conta->isCard ? '1' : '0' }}"
                                            data-cash="{{ in_array($conta->type, ['checking', 'savings'], true) ? '1' : '0' }}"
                                            data-saldo="{{ \App\Support\Brl::format($conta->saldo ?? 0) }}"
                                            data-saldo-rotulo="{{ $conta->saldoRotulo ?? 'disponível' }}"
                                            data-negativo="{{ ($conta->saldo ?? 0) < 0 ? '1' : '0' }}">{{ $conta->name }}</option>
                                @endforeach
                            </select>
                            {{-- Quanto ainda dá para gastar por este método, atualizado ao
                                 trocar o select. É a informação que evita a surpresa: sem
                                 ela a pessoa digita o valor, salva, e só então o servidor
                                 responde 409 perguntando de onde sai o dinheiro. --}}
                            <span class="lm-saldo" data-lm-saldo hidden></span>
                        </div>
                        @if ($lmPodeTransferir)
                            {{-- Destino da transferência. Escondido (e DESABILITADO, para não
                                 viajar no FormData) fora do modo Transferência; o JS tira a
                                 origem da lista, porque transferir para a mesma conta não existe. --}}
                            <div class="field" data-lm-transfer-only hidden>
                                <label for="lm-to-account">Para</label>
                                <select class="input" id="lm-to-account" name="to_account_id" disabled>
                                    @foreach ($lmContasCaixa as $conta)
                                        <option value="{{ $conta->id }}">{{ $conta->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        <div class="field" data-lm-not-transfer>
                            <label for="lm-category">Categoria</label>
                            <select class="input" id="lm-category" name="category_id">
                                <option value="">Selecione a categoria</option>
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
