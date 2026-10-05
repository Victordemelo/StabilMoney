@extends('layouts.app')

@section('title', 'Contas e cartões')

@section('content')
    <div class="section-head">
        <h2>Contas e cartões</h2>
        <span class="sub">Seus cartões, contas e carteiras vivem aqui</span>
        <div class="head-actions">
            {{-- Abre o modal na própria tela. O href continua valendo: sem JS (ou se
                 o modal não estiver na página), o clique navega para a página cheia. --}}
            <a class="btn-primary" href="{{ route('accounts.create') }}" data-acct-open="novo">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>
                Nova conta
            </a>
        </div>
    </div>

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

    @if ($accounts->isEmpty())
        <div class="grid">
            <div class="card span12">
                <div class="empty-state">
                    <div class="pico">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 9.5h19M6 15h4"/></svg>
                    </div>
                    <h3>Nenhum método de pagamento ainda</h3>
                    <p>Cadastre um cartão, conta ou carteira para começar a registrar transações.</p>
                    <a class="btn-primary" href="{{ route('accounts.create') }}" data-acct-open="novo">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>
                        Criar primeira conta
                    </a>
                </div>
            </div>
        </div>
    @else
        {{-- Separado por TIPO, cada grupo em ordem alfabética: antes era uma grade única,
             e conta corrente, cartão de crédito e débito apareciam misturados. --}}
        @php
            $alfabetica = fn ($lista) => $lista->sortBy(fn ($c) => mb_strtolower($c->name), SORT_NATURAL)->values();
            $grupos = array_filter([
                ['titulo' => 'Contas', 'sub' => 'Corrente e poupança — onde o dinheiro está', 'itens' => $alfabetica($accounts->whereIn('type', ['checking', 'savings']))],
                ['titulo' => 'Cartões de crédito', 'sub' => 'Viram fatura em Contas a pagar', 'itens' => $alfabetica($accounts->where('type', 'credit_card'))],
                ['titulo' => 'Cartões de débito', 'sub' => 'Saem direto da conta vinculada', 'itens' => $alfabetica($accounts->where('type', 'debit_card'))],
                ['titulo' => 'Pix e TED', 'sub' => 'Saem na hora da conta escolhida', 'itens' => $alfabetica($accounts->whereIn('type', \App\Models\Account::TIPOS_DE_UMA_CONTA))],
            ], fn ($g) => $g['itens']->isNotEmpty());
        @endphp

        @foreach ($grupos as $grupo)
            <section class="acct-grupo" aria-labelledby="acct-grupo-{{ $loop->index }}">
                <div class="acct-grupo-head">
                    <h3 id="acct-grupo-{{ $loop->index }}">{{ $grupo['titulo'] }} <span class="acct-grupo-n">{{ $grupo['itens']->count() }}</span></h3>
                    <span class="acct-grupo-sub">{{ $grupo['sub'] }}</span>
                    <a class="mini-btn acct-grupo-add" href="{{ route('accounts.create') }}" data-acct-open="novo">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>
                        Adicionar
                    </a>
                </div>
                <div class="grid">
                    @foreach ($grupo['itens'] as $conta)
                        @include('accounts._card')
                    @endforeach
                </div>
            </section>
        @endforeach
    @endif

    {{-- =====================================================================
         Modais de cadastro/edição.

         Ficam FORA da `.grid` e de qualquer `.card`: a `.card` tem
         overflow:hidden e animação com `transform`, e transform em ancestral
         vira o BLOCO DE CONTENÇÃO de um `position: fixed` — o painel encolheria
         para a largura do card e sairia recortado.

         O corpo é o `accounts/_form` de sempre (mesmos 5 tipos, mesmos campos
         condicionais, mesmo preview de banco, mesmo tipo travado): a moldura muda,
         o formulário não.
         ===================================================================== --}}

    {{-- Cada `.modal` é um DIÁLOGO, com o nome vindo do título. Os ids levam o sufixo do
         modal (`novo` ou o id da conta): há um modal por conta, e dois `aria-labelledby`
         apontando para o mesmo id dariam a todos o nome do primeiro. --}}
    <div class="modal-scrim" id="acctModal-novo" data-acct-modal data-close>
        <div class="modal modal-lg" role="dialog" aria-modal="true"
             aria-labelledby="acctModal-novo-titulo" aria-describedby="acctModal-novo-descricao">
            <div class="modal-head">
                <span class="modal-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 9.5h19M6 15h4"/></svg>
                </span>
                <div>
                    <h3 id="acctModal-novo-titulo">Novo método de pagamento</h3>
                    <p id="acctModal-novo-descricao">Conta, cartão ou chave Pix</p>
                </div>
                <button class="modal-x" type="button" data-close-btn aria-label="Fechar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>

            {{-- Banner do envio AJAX (a lista é preenchida por script, com textContent) --}}
            <div class="flash-error" data-acct-error role="alert" hidden>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
                <ul data-acct-error-list></ul>
            </div>

            @include('accounts._form', ['account' => null, 'modal' => true])
        </div>
    </div>

    @foreach ($accounts as $conta)
        <div class="modal-scrim" id="acctModal-{{ $conta->id }}" data-acct-modal data-close>
            <div class="modal modal-lg" role="dialog" aria-modal="true"
                 aria-labelledby="acctModal-{{ $conta->id }}-titulo" aria-describedby="acctModal-{{ $conta->id }}-descricao">
                <div class="modal-head">
                    <span class="modal-ico" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 20h4L18.5 9.5a2 2 0 0 0-2.8-2.8L5 17v3zM13.5 6.5l4 4"/></svg>
                    </span>
                    <div>
                        <h3 id="acctModal-{{ $conta->id }}-titulo">Editar {{ $conta->name }}</h3>
                        <p id="acctModal-{{ $conta->id }}-descricao">{{ $conta->typeLabel() }}{{ $conta->bankLabel() ? ' · ' . $conta->bankLabel() : '' }}</p>
                    </div>
                    <button class="modal-x" type="button" data-close-btn aria-label="Fechar">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
                    </button>
                </div>

                <div class="flash-error" data-acct-error role="alert" hidden>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
                    <ul data-acct-error-list></ul>
                </div>

                @include('accounts._form', [
                    'account' => $conta,
                    'modal' => true,
                    'tipoTravado' => in_array($conta->id, $travados, true),
                ])
            </div>
        </div>
    @endforeach

    <script nonce="{{ Vite::cspNonce() }}">
        // Abre/fecha os modais de método de pagamento e envia por AJAX.
        //
        // Este script vive dentro do #content, então o pjax o re-executa a cada
        // navegação — e cada execução liga os elementos NOVOS.
        //
        // Abrir e fechar passam pelo utilitário de diálogo (sm/dialogo.js, pela ponte
        // `window.smDialogo`): foco dentro, Tab preso, Esc, resto da página inerte e o
        // foco de volta ao botão. A ponte é lida na HORA do clique — no primeiro
        // carregamento este script roda antes do módulo; sem ela (módulo que não
        // carregou), o modal ainda abre e fecha pela classe, como antes.
        (function () {
            var modais = Array.prototype.slice.call(document.querySelectorAll('[data-acct-modal]'));
            if (!modais.length) return;

            function painel(modal) { return modal.querySelector('.modal'); }

            function limparErros(modal) {
                var caixa = modal.querySelector('[data-acct-error]');
                if (caixa) caixa.hidden = true;
                modal.querySelectorAll('.input-error').forEach(function (el) {
                    el.classList.remove('input-error');
                });
            }

            // NUNCA innerHTML aqui: as mensagens do servidor embutem o nome da conta,
            // que é dado do usuário (e categorias/contas são compartilhadas na família).
            function mostrarErros(modal, mensagens, campos) {
                var caixa = modal.querySelector('[data-acct-error]');
                var lista = modal.querySelector('[data-acct-error-list]');
                if (!caixa || !lista) return;

                lista.textContent = '';
                mensagens.forEach(function (msg) {
                    var li = document.createElement('li');
                    li.textContent = msg;
                    lista.appendChild(li);
                });
                caixa.hidden = false;

                (campos || []).forEach(function (campo) {
                    var el = modal.querySelector('[name="' + campo + '"]');
                    if (el) el.classList.add('input-error');
                });

                // Treme e rola até o topo do painel, senão o erro pode ficar fora da
                // parte visível de um modal que está rolando por dentro.
                var p = painel(modal);
                if (p) {
                    p.scrollTop = 0;
                    p.classList.remove('shake');
                    void p.offsetWidth;
                    p.classList.add('shake');
                }
            }

            function salvando(modal, on) {
                var btn = modal.querySelector('[data-acct-save]');
                if (!btn) return;
                btn.classList.toggle('is-loading', on);
                btn.disabled = on;
            }

            function abrir(modal, gatilho) {
                if (!modal) return;
                limparErros(modal);
                salvando(modal, false);
                var primeiro = modal.querySelector('.modal-body input:not([type="hidden"])');
                if (window.smDialogo) {
                    window.smDialogo.abrir(modal, { foco: primeiro, retorno: gatilho || null });
                    return;
                }
                modal.classList.add('open');
                if (primeiro) setTimeout(function () { primeiro.focus(); }, 80);
            }
            function fechar(modal) {
                if (!modal) return;
                if (window.smDialogo) window.smDialogo.fechar(modal);
                else modal.classList.remove('open');
            }

            // Gatilhos: "Nova conta", card pontilhado, estado vazio e "Editar" de cada
            // card. Sem o modal correspondente o clique não é interceptado e o href
            // leva para a página cheia — o fallback continua inteiro.
            document.querySelectorAll('[data-acct-open]').forEach(function (gatilho) {
                gatilho.addEventListener('click', function (e) {
                    var alvo = document.getElementById('acctModal-' + gatilho.getAttribute('data-acct-open'));
                    if (!alvo) return;
                    if (e.metaKey || e.ctrlKey || e.shiftKey) return; // abrir em nova aba
                    e.preventDefault();
                    abrir(alvo, gatilho);
                });
            });

            modais.forEach(function (modal) {
                modal.addEventListener('click', function (e) { if (e.target === modal) fechar(modal); });
                modal.querySelectorAll('[data-close-btn]').forEach(function (b) {
                    b.addEventListener('click', function () { fechar(modal); });
                });
                var p = painel(modal);
                if (p) {
                    p.addEventListener('animationend', function (e) {
                        if (e.animationName === 'sm-shake') p.classList.remove('shake');
                    });
                }

                var form = modal.querySelector('form[data-account-form]');
                if (!form) return;

                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    limparErros(modal);
                    salvando(modal, true);

                    // `_method=PUT` viaja no corpo (spoofing do Laravel), então o
                    // verbo aqui é sempre POST — inclusive na edição.
                    fetch(form.action, {
                        method: 'POST',
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: new FormData(form),
                        credentials: 'same-origin',
                    }).then(function (resp) {
                        if (resp.ok) {
                            salvando(modal, false);
                            // Fecha ANTES de recarregar: com pjax o #content é trocado
                            // e um modal aberto piscaria por cima do resultado.
                            fechar(modal);
                            if (typeof window.smPjaxReload === 'function') window.smPjaxReload();
                            else window.location.reload();
                            return;
                        }

                        return resp.json().catch(function () { return {}; }).then(function (dados) {
                            salvando(modal, false);

                            var errs = (dados && dados.errors) || {};
                            var campos = Object.keys(errs);
                            var mensagens = [];
                            campos.forEach(function (k) {
                                (errs[k] || []).forEach(function (m) { mensagens.push(m); });
                            });

                            if (!mensagens.length) {
                                if (resp.status === 419) {
                                    mensagens = ['Sua sessão expirou. Recarregue a página e entre de novo.'];
                                } else if (resp.status === 403 || resp.status === 404) {
                                    mensagens = ['Você não pode editar esta conta.'];
                                } else {
                                    mensagens = [(dados && dados.message) || 'Não foi possível salvar. Confira os campos e tente de novo.'];
                                }
                            }

                            mostrarErros(modal, mensagens, campos);
                        });
                    }).catch(function () {
                        salvando(modal, false);
                        mostrarErros(modal, ['Sem conexão com o servidor. Verifique a internet e tente de novo.'], []);
                    });
                });
            });

            // O Esc é do utilitário de diálogo. O atalho antigo, preso ao documento,
            // fechava TODOS os modais por fora dele — e o foco caía no <body>.
        })();
    </script>
@endsection
