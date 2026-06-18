@extends('layouts.app')

@section('title', 'Faturas / Despesas')

@section('content')
@php
    // Iniciais para o "cartão" miniatura de cada fatura (espelha initials() do finance.js).
    $iniciais = function ($nome) {
        $partes = preg_split('/\s+/', trim((string) $nome)) ?: [];
        $partes = array_values(array_filter($partes));
        if (count($partes) === 0) return '?';
        if (count($partes) === 1) return mb_strtoupper(mb_substr($partes[0], 0, 2));
        return mb_strtoupper(mb_substr($partes[0], 0, 1) . mb_substr($partes[1], 0, 1));
    };

    // Formatadores R$ pt-BR (com/sem centavos).
    $brl  = fn ($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');
    $brl0 = fn ($v) => 'R$ ' . number_format((float) $v, 0, ',', '.');

    // Cor padrão de cartão quando a conta não tem cor definida.
    $corPadrao = '#0E5A3F';

    // Há mais de uma pessoa na família? (controla o seletor "quem fez a compra")
    $temFamilia = $familyMembers->count() > 1;
@endphp

<section class="view">
    <div class="section-head">
        <h2>Faturas / Despesas</h2>
        <span class="sub">Faturas de cartão e despesas pagas em conta</span>
        <div class="head-actions">
            <button class="btn-primary" type="button" id="lancarBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>
                Lançar despesa
            </button>
        </div>
    </div>

    {{-- Banner de erro (validação do servidor) — o JS reabre o modal de lançar via data-reopen --}}
    @if ($errors->any())
        <div class="flash-error" role="alert">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
            <ul>@foreach ($errors->all() as $erro)<li>{{ $erro }}</li>@endforeach</ul>
        </div>
    @endif

    {{-- 3 stat cards (Total das faturas / Cartões / Limite disponível) --}}
    <div class="grid">
        <div class="card stat span4">
            <div class="stat-top">
                <div class="ico g3"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 3h9l3 3v15l-2-1.3L13 21l-2-1.3L9 21l-2-1.3L5 21V5a2 2 0 0 1 1-2Z"/><path d="M9 8h6M9 12h6"/></svg></div>
                <span class="label">Total das faturas</span>
            </div>
            <div class="value"><span class="cur">R$</span>{{ number_format((float) ($stats['totalFaturas'] ?? 0), 2, ',', '.') }}</div>
        </div>
        <div class="card stat span4">
            <div class="stat-top">
                <div class="ico g1"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 9.5h19"/></svg></div>
                <span class="label">Cartões</span>
            </div>
            <div class="value">{{ $stats['numCartoes'] ?? 0 }}</div>
        </div>
        <div class="card stat span4">
            <div class="stat-top">
                <div class="ico g4"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 2v4M5 9a7 7 0 1 1 14 0c0 4-3 5-3 8H8c0-3-3-4-3-8Z"/></svg></div>
                <span class="label">Limite disponível</span>
            </div>
            <div class="value"><span class="cur">R$</span>{{ number_format((float) ($stats['limiteDisponivel'] ?? 0), 2, ',', '.') }}</div>
        </div>
    </div>

    @if ($cards->isEmpty() && $accountExpenses->isEmpty())
        {{-- Estado vazio amigável (nenhum cartão e nenhuma despesa avulsa) --}}
        <div class="grid" style="margin-top:18px">
            <div class="card span12">
                <div class="empty-block">
                    <div class="eb-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 9.5h19"/></svg></div>
                    <strong>Nenhuma despesa por aqui ainda</strong>
                    <span>Cadastre um cartão de crédito em Métodos de Pagamento e lance suas despesas — elas viram fatura aqui.</span>
                    <button class="btn primary" type="button" id="lancarBtnVazio" style="margin-top:14px">Lançar primeira despesa</button>
                </div>
            </div>
        </div>
    @else
        {{-- ---------- Uma fatura-card por cartão ---------- --}}
        @foreach ($cards as $card)
            @php
                $acc        = $card->account;
                $cor        = $acc->color ?: $corPadrao;
                $limite     = (float) ($acc->credit_limit ?? 0);
                $usadoPct   = max(0, min(100, (float) ($card->limitUsedPct ?? 0)));
                $usadoValor = max(0, $limite - (float) ($card->availableLimit ?? 0));
            @endphp
            <div class="card fatura-card span12">
                <div class="fatura-head">
                    <div class="fh-card" style="background:linear-gradient(135deg,{{ $cor }},color-mix(in srgb,{{ $cor }} 55%,#000))">
                        @if ($acc->icon){{ $acc->icon }}@else{{ $iniciais($acc->name) }}@endif
                    </div>
                    <div class="fh-info">
                        <strong>{{ $acc->name }}</strong>
                        <span>
                            @if ($acc->due_day)vence dia {{ $acc->due_day }}@endif
                            @if ($card->dueDate) · {{ $card->dueDate->translatedFormat('d \d\e M') }}@endif
                        </span>
                    </div>
                    <div class="fh-total">
                        <span>Fatura atual</span>
                        <b>{{ $brl($card->currentInvoice) }}</b>
                    </div>
                </div>

                @if ($limite > 0)
                    <div class="fh-limit">
                        <div class="dp-bar"><i style="width:{{ $usadoPct }}%"></i></div>
                        <div class="dp-meta">
                            <span>Limite usado</span>
                            <span>{{ $brl($usadoValor) }} de {{ $brl($limite) }}</span>
                        </div>
                    </div>
                @endif

                <div class="fatura-items">
                    @forelse ($card->items as $item)
                        @php
                            $catIcon  = $item->category?->icon;
                            $catName  = $item->category?->name ?? 'Sem categoria';
                            $badge    = $item->badge;
                            // Classe do badge p/ a cor (espelha .fi-badge.parcelado / .recorrente)
                            $badgeCls = 'avista';
                            if ($badge === 'Recorrente') $badgeCls = 'recorrente';
                            elseif ($badge && str_contains($badge, '/')) $badgeCls = 'parcelado';
                        @endphp
                        <div class="fatura-item">
                            <span class="fi-ico">{{ $catIcon ?: '📦' }}</span>
                            <div class="fi-txt">
                                <strong>{{ $item->description ?: $catName }}</strong>
                                <span>
                                    {{ $catName }}
                                    @if ($badge) · <em class="fi-badge {{ $badgeCls }}">{{ $badge }}</em>@endif
                                    @if ($item->madeBy?->name) · <em class="fi-who"><span class="fw-av" style="background:{{ $cor }}">{{ $iniciais($item->madeBy->name) }}</span>{{ \Illuminate\Support\Str::before(trim($item->madeBy->name), ' ') }}</em>@endif
                                </span>
                            </div>
                            <div class="fi-val">
                                <b>{{ $brl($item->amount) }}</b>
                                <small>{{ $item->date?->translatedFormat('d M') }}</small>
                            </div>
                            <form method="POST" action="{{ route('faturas.compra.destroy', $item->id) }}"
                                  onsubmit="return confirm('Remover esta compra da fatura?');">
                                @csrf
                                @method('DELETE')
                                <button class="fi-rm" type="submit" aria-label="Remover" title="Remover">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
                                </button>
                            </form>
                        </div>
                    @empty
                        <div class="fi-empty">Nenhuma despesa neste cartão ainda.</div>
                    @endforelse
                </div>
            </div>
        @endforeach

        {{-- ---------- Despesas pagas em conta (não-cartão) ---------- --}}
        @if ($accountExpenses->isNotEmpty())
            @php $totalAvulso = $accountExpenses->sum('amount'); @endphp
            <div class="card fatura-card span12">
                <div class="fatura-head">
                    <div class="fh-card" style="background:linear-gradient(135deg,#1B4D89,#0E2A4D)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" width="22" height="22"><path d="M3 10 12 4l9 6M5 10v9h14v-9M9 19v-5h6v5"/></svg>
                    </div>
                    <div class="fh-info">
                        <strong>Despesas em conta</strong>
                        <span>Pagas via débito, Pix ou conta corrente</span>
                    </div>
                    <div class="fh-total">
                        <span>Total no período</span>
                        <b>{{ $brl($totalAvulso) }}</b>
                    </div>
                </div>
                <div class="fatura-items">
                    @foreach ($accountExpenses as $exp)
                        <div class="fatura-item">
                            <span class="fi-ico">{{ $exp->category?->icon ?: '📦' }}</span>
                            <div class="fi-txt">
                                <strong>{{ $exp->description ?: ($exp->category?->name ?? 'Despesa') }}</strong>
                                <span>
                                    {{ $exp->category?->name ?? 'Sem categoria' }} · {{ $exp->account->name }}
                                    @if ($exp->madeBy?->name) · <em class="fi-who"><span class="fw-av" style="background:#1B4D89">{{ $iniciais($exp->madeBy->name) }}</span>{{ \Illuminate\Support\Str::before(trim($exp->madeBy->name), ' ') }}</em>@endif
                                </span>
                            </div>
                            <div class="fi-val">
                                <b>{{ $brl($exp->amount) }}</b>
                                <small>{{ $exp->date?->translatedFormat('d M') }}</small>
                            </div>
                            <form method="POST" action="{{ route('faturas.compra.destroy', $exp->id) }}"
                                  onsubmit="return confirm('Remover esta despesa?');">
                                @csrf
                                @method('DELETE')
                                <button class="fi-rm" type="submit" aria-label="Remover" title="Remover">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
                                </button>
                            </form>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Aviso quando ainda não há cartões, mas há despesas avulsas --}}
        @if ($cards->isEmpty())
            <div class="grid" style="margin-top:18px">
                <div class="card span12">
                    <div class="empty-block">
                        <div class="eb-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 9.5h19"/></svg></div>
                        <strong>Nenhum cartão cadastrado</strong>
                        <span>Adicione um cartão de crédito em Métodos de Pagamento para acompanhar faturas, parcelas e limite.</span>
                        <a class="btn primary" href="{{ route('accounts.create') }}" style="margin-top:14px">Cadastrar cartão</a>
                    </div>
                </div>
            </div>
        @endif
    @endif
</section>

{{-- ============================ MODAL: LANÇAR DESPESA ============================ --}}
<div class="modal-scrim" id="lancarModal" data-lancar-modal data-reopen="{{ $errors->any() ? '1' : '' }}">
    <div class="modal modal-lg">
        <div class="modal-head">
            <span class="modal-ico ico-out" id="lancarIco"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M7 7 17 17M17 17h-7M17 17v-7"/></svg></span>
            <div>
                <h3>Lançar despesa</h3>
                <p>Registre uma compra no cartão ou um gasto em conta.</p>
            </div>
            <button class="modal-x" type="button" data-lancar-close aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>

        <form method="POST" action="{{ route('faturas.lancar') }}" id="lancarForm">
            @csrf
            <div class="modal-body">
                {{-- Descrição --}}
                <div class="field">
                    <label for="lanc-desc">Descrição</label>
                    <input class="input" type="text" id="lanc-desc" name="description"
                           value="{{ old('description') }}" placeholder="Ex.: Supermercado, passagem aérea…" required>
                </div>

                <div class="field-row">
                    {{-- Valor (total) --}}
                    <div class="field">
                        <label for="lanc-valor">Valor</label>
                        <input class="input" type="text" id="lanc-valor" name="amount" inputmode="decimal"
                               value="{{ old('amount') }}" placeholder="R$ 0,00" required>
                    </div>
                    {{-- Data --}}
                    <div class="field">
                        <label for="lanc-data">Data</label>
                        <input class="input" type="date" id="lanc-data" name="date"
                               value="{{ old('date', now()->format('Y-m-d')) }}" required>
                    </div>
                </div>

                {{-- Método de pagamento (todas as contas da família) --}}
                <div class="field">
                    <label for="lanc-method">Método de pagamento</label>
                    <select class="input" id="lanc-method" name="account_id" required>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}"
                                    data-card="{{ $account->isCard() ? '1' : '0' }}"
                                    @selected((int) old('account_id') === $account->id)>
                                {{ $account->icon ? $account->icon . '  ' : '' }}{{ $account->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- Categoria (despesas) --}}
                <div class="field">
                    <label for="lanc-cat">Categoria <span class="hint">(opcional)</span></label>
                    <select class="input" id="lanc-cat" name="category_id">
                        <option value="">Sem categoria</option>
                        @foreach ($categories as $categoria)
                            <option value="{{ $categoria->id }}" @selected((int) old('category_id') === $categoria->id)>
                                {{ $categoria->icon ? $categoria->icon . '  ' : '' }}{{ $categoria->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- Quem fez a compra (só quando a família tem mais de uma pessoa) --}}
                @if ($temFamilia)
                    <div class="field">
                        <label for="lanc-who">Quem fez essa compra?</label>
                        <select class="input" id="lanc-who" name="made_by_user_id">
                            @foreach ($familyMembers as $member)
                                <option value="{{ $member->id }}"
                                        @selected((int) old('made_by_user_id', auth()->id()) === $member->id)>
                                    {{ $member->name }}{{ $member->isTitular() ? ' (Titular)' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                {{-- Modo (À vista / Parcelado / Recorrente) — habilitado conforme o método.
                     O JS mostra "parcelas" só em parcelado e força "à vista" se o método não for cartão. --}}
                <div class="field" data-lanc-modewrap>
                    <label>Forma de lançamento</label>
                    <div class="pay-mode" id="lancModes">
                        <button type="button" class="active" data-m="avista">À vista</button>
                        <button type="button" data-m="parcelado">Parcelado</button>
                        <button type="button" data-m="recorrente">Recorrente</button>
                    </div>
                    {{-- valor real enviado ao servidor (atualizado pelos botões acima) --}}
                    <input type="hidden" name="mode" id="lanc-mode" value="{{ old('mode', 'avista') }}">
                    <small class="field-hint" data-lanc-modehint></small>
                </div>

                {{-- Número de parcelas (visível só no modo parcelado) --}}
                <div class="field" id="lancParcelasField" hidden>
                    <label for="lanc-parcelas">Número de parcelas</label>
                    <select class="input" id="lanc-parcelas" name="installments">
                        @for ($i = 2; $i <= 24; $i++)
                            <option value="{{ $i }}" @selected((int) old('installments') === $i)>{{ $i }}x</option>
                        @endfor
                    </select>
                    <small class="field-hint" id="lancParcelaHint"></small>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-lancar-close>Cancelar</button>
                <button class="btn primary" type="submit">Lançar despesa</button>
            </div>
        </form>
    </div>
</div>
@endsection
