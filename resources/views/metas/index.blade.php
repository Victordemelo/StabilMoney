@extends('layouts.app')

@section('title', 'Metas')

@section('content')
@php
    // Paleta e emojis de referência do protótipo (design v2 → finance.js).
    $metaEmojis = ['✈️', '🏠', '🚗', '🏍️', '🛡️', '🎓', '💍', '🏖️', '💻', '👶', '🏥', '🎸'];
    $metaCores  = ['#1FA06E', '#18B6BE', '#F0A93B', '#9078D8', '#0F6B47', '#E5604D', '#59C497', '#3E84D8', '#C77F2A', '#7C8C84'];

    // Formata moeda em R$ pt-BR sem casas decimais (igual aos cards do protótipo).
    $brl0 = fn ($v) => 'R$ ' . number_format((float) $v, 0, ',', '.');
    // Versão com centavos (usada nos resumos dos modais).
    $brl  = fn ($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');
@endphp

<section class="view">
    <div class="section-head">
        <h2>Metas</h2>
        <span class="sub">Defina objetivos e acompanhe o progresso</span>
        <div class="head-actions">
            <button class="btn-primary" type="button" id="metaNovaBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>
                Nova meta
            </button>
        </div>
    </div>

    {{-- Banner de erro (validação do servidor) — o JS reabre o modal certo via data-reopen --}}
    @if ($errors->any())
        <div class="flash-error" role="alert">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
            <ul>@foreach ($errors->all() as $erro)<li>{{ $erro }}</li>@endforeach</ul>
        </div>
    @endif

    {{-- 3 stat cards (Metas ativas, Total guardado, Progresso geral) --}}
    <div class="grid">
        <div class="card stat span4">
            <div class="stat-top">
                <div class="ico g1"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r=".7" fill="currentColor"/></svg></div>
                <span class="label">Metas ativas</span>
            </div>
            <div class="value">{{ $stats['ativas'] }}</div>
        </div>
        <div class="card stat span4">
            <div class="stat-top">
                <div class="ico g2"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 2v4M5 9a7 7 0 1 1 14 0c0 4-3 5-3 8H8c0-3-3-4-3-8Z"/></svg></div>
                <span class="label">Total guardado</span>
            </div>
            <div class="value"><span class="cur">R$</span>{{ number_format((float) $stats['guardado'], 2, ',', '.') }}</div>
        </div>
        <div class="card stat span4">
            <div class="stat-top">
                <div class="ico g4"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 12h4l3 8 4-16 3 8h4"/></svg></div>
                <span class="label">Progresso geral</span>
            </div>
            <div class="value">{{ $stats['progresso'] }}%</div>
        </div>
    </div>

    @if ($goals->isEmpty())
        {{-- Estado vazio amigável --}}
        <div class="grid" style="margin-top:18px">
            <div class="card span12">
                <div class="empty-block">
                    <div class="eb-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/></svg></div>
                    <strong>Você ainda não tem metas</strong>
                    <span>Crie sua primeira meta — viagem, carro, reserva de emergência… — e acompanhe quanto falta.</span>
                    <button class="btn primary" type="button" id="metaNovaBtnVazio" style="margin-top:14px">Criar primeira meta</button>
                </div>
            </div>
        </div>
    @else
        {{-- Grid de cards de meta + card "Nova meta" tracejado --}}
        <div class="grid meta-grid">
            @foreach ($goals as $goal)
                @php
                    $cor    = $goal->color ?: '#1FA06E';
                    $pct    = (int) $goal->progress;
                    $emoji  = $goal->emoji ?: '🎯';
                    // Anel: circunferência de r=38 ≈ 238.7
                    $dash   = number_format(min(100, max(0, $pct)) / 100 * 238.7, 1, '.', '');
                @endphp
                <div class="card span4 meta-card">
                    {{-- Ações (editar / resgatar / excluir) aparecem no hover --}}
                    <div class="meta-actions">
                        <button class="meta-act" type="button"
                                data-meta-edit
                                data-id="{{ $goal->id }}"
                                data-name="{{ $goal->name }}"
                                data-target="{{ $goal->target_amount }}"
                                data-date="{{ optional($goal->target_date)->format('Y-m-d') }}"
                                data-emoji="{{ $emoji }}"
                                data-color="{{ $cor }}"
                                aria-label="Editar meta">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 20h4L18.5 9.5a2 2 0 0 0 0-2.8l-1.2-1.2a2 2 0 0 0-2.8 0L4 16v4Z"/></svg>
                        </button>
                        <button class="meta-act" type="button"
                                data-meta-resgatar
                                data-id="{{ $goal->id }}"
                                data-name="{{ $goal->name }}"
                                data-saved="{{ $brl($goal->saved) }}"
                                aria-label="Resgatar valor">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 19V5M5 12l7 7 7-7"/></svg>
                        </button>
                        <button class="meta-act meta-act-del" type="button"
                                data-meta-del
                                data-id="{{ $goal->id }}"
                                data-name="{{ $goal->name }}"
                                aria-label="Excluir meta">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
                        </button>
                    </div>

                    <div class="meta-emoji" style="background:{{ $cor }}1f">{{ $emoji }}</div>
                    <h3 class="meta-name">{{ $goal->name }}</h3>
                    @if ($goal->target_date)
                        <span class="meta-prazo">🎯 {{ $goal->target_date->translatedFormat('M Y') }}</span>
                    @endif
                    @if ($goal->madeBy?->name)
                        <span class="meta-by">por {{ \Illuminate\Support\Str::before(trim($goal->madeBy->name), ' ') }}</span>
                    @endif

                    {{-- Anel de progresso (SVG server-rendered) --}}
                    <div class="meta-ring">
                        <svg viewBox="0 0 90 90">
                            <circle cx="45" cy="45" r="38" fill="none" stroke="var(--surface-3)" stroke-width="9"/>
                            <circle cx="45" cy="45" r="38" fill="none" stroke="{{ $cor }}" stroke-width="9" stroke-linecap="round"
                                    stroke-dasharray="{{ $dash }} 238.7" transform="rotate(-90 45 45)"/>
                            <text x="45" y="50" text-anchor="middle" style="font:700 18px var(--font-head);fill:var(--ink)">{{ $pct }}%</text>
                        </svg>
                    </div>

                    <div class="meta-amounts">
                        <div><span>Guardado</span><b>{{ $brl0($goal->saved) }}</b></div>
                        <div style="text-align:right"><span>Faltam</span><b>{{ $brl0($goal->remaining) }}</b></div>
                    </div>

                    <button class="btn primary meta-aporte" type="button"
                            data-meta-aporte
                            data-id="{{ $goal->id }}"
                            data-name="{{ $goal->name }}"
                            data-saved="{{ $brl($goal->saved) }}"
                            data-remaining="{{ $brl($goal->remaining) }}">
                        + Aportar
                    </button>
                </div>
            @endforeach

            {{-- Card "Nova meta" tracejado --}}
            <button class="card span4 meta-add" type="button" id="metaAddCard">
                <div class="pm-add-inner">
                    <span class="pm-plus"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg></span>
                    <strong>Nova meta</strong>
                    <span>Viagem, carro, reserva…</span>
                </div>
            </button>
        </div>
    @endif
</section>

{{-- ============================ MODAIS ============================ --}}

{{-- Modal: Nova meta (criar) --}}
@php $reabreCreate = $errors->any() && old('_form') === 'create'; @endphp
<div class="modal-scrim" id="metaCreateModal" data-meta-modal data-reopen="{{ $reabreCreate ? '1' : '' }}">
    <div class="modal modal-lg">
        <div class="modal-head">
            <span class="modal-ico ico-in"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/></svg></span>
            <div>
                <h3>Nova meta</h3>
                <p>Defina seu objetivo e acompanhe o progresso.</p>
            </div>
            <button class="modal-x" type="button" data-meta-close aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>

        <form method="POST" action="{{ route('metas.store') }}">
            @csrf
            <input type="hidden" name="_form" value="create">
            <div class="modal-body">
                @if ($reabreCreate)
                    <div class="flash-error" role="alert">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
                        <ul>@foreach ($errors->all() as $erro)<li>{{ $erro }}</li>@endforeach</ul>
                    </div>
                @endif
                <div class="field">
                    <label for="meta-c-name">Nome da meta</label>
                    <input class="input" type="text" id="meta-c-name" name="name" value="{{ $reabreCreate ? old('name') : '' }}" placeholder="Ex.: Viagem para a Europa" required>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="meta-c-target">Valor alvo</label>
                        <input class="input" type="text" id="meta-c-target" name="target_amount" inputmode="decimal" value="{{ $reabreCreate ? old('target_amount') : '' }}" placeholder="R$ 15.000,00" required>
                    </div>
                    <div class="field">
                        <label for="meta-c-date">Prazo <span class="hint">(opcional)</span></label>
                        <input class="input" type="month" id="meta-c-date" name="target_date" value="{{ $reabreCreate ? old('target_date') : '' }}">
                    </div>
                </div>

                {{-- Emoji picker (radios nativos → acessível sem JS) --}}
                <div class="field">
                    <label>Ícone</label>
                    <div class="icon-picker meta-emoji-picker">
                        @foreach ($metaEmojis as $i => $em)
                            <input type="radio" name="emoji" id="meta-c-emoji-{{ $i }}" value="{{ $em }}"
                                   @checked(($reabreCreate ? old('emoji') : null) === $em || ((!$reabreCreate || !old('emoji')) && $i === 0))>
                            <label for="meta-c-emoji-{{ $i }}">{{ $em }}</label>
                        @endforeach
                    </div>
                </div>

                {{-- Cor picker --}}
                <div class="field">
                    <label>Cor</label>
                    <div class="color-picker">
                        @foreach ($metaCores as $i => $cor)
                            <input type="radio" name="color" id="meta-c-color-{{ $i }}" value="{{ $cor }}"
                                   @checked(($reabreCreate ? old('color') : null) === $cor || ((!$reabreCreate || !old('color')) && $i === 0))>
                            <label for="meta-c-color-{{ $i }}" style="background:{{ $cor }}"></label>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-meta-close>Cancelar</button>
                <button class="btn primary" type="submit">Criar meta</button>
            </div>
        </form>
    </div>
</div>

{{-- Modal: Editar meta (um por meta, pré-preenchido) --}}
@foreach ($goals as $goal)
    @php
        $gCor   = $goal->color ?: '#1FA06E';
        $gEmoji = $goal->emoji ?: '🎯';
        $reabreEdit = $errors->any() && old('_form') === 'edit-' . $goal->id;
    @endphp
    <div class="modal-scrim" id="metaEditModal-{{ $goal->id }}" data-meta-modal data-reopen="{{ $reabreEdit ? '1' : '' }}">
        <div class="modal modal-lg">
            <div class="modal-head">
                <span class="modal-ico ico-in"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 20h4L18.5 9.5a2 2 0 0 0 0-2.8l-1.2-1.2a2 2 0 0 0-2.8 0L4 16v4Z"/></svg></span>
                <div>
                    <h3>Editar meta</h3>
                    <p>Ajuste o nome, valor alvo ou prazo.</p>
                </div>
                <button class="modal-x" type="button" data-meta-close aria-label="Fechar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>

            <form method="POST" action="{{ route('metas.update', $goal) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="_form" value="edit-{{ $goal->id }}">
                <div class="modal-body">
                    @if ($reabreEdit)
                        <div class="flash-error" role="alert">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
                            <ul>@foreach ($errors->all() as $erro)<li>{{ $erro }}</li>@endforeach</ul>
                        </div>
                    @endif
                    <div class="field">
                        <label for="meta-e-name-{{ $goal->id }}">Nome da meta</label>
                        <input class="input" type="text" id="meta-e-name-{{ $goal->id }}" name="name"
                               value="{{ $reabreEdit ? old('name') : $goal->name }}" required>
                    </div>
                    <div class="field-row">
                        <div class="field">
                            <label for="meta-e-target-{{ $goal->id }}">Valor alvo</label>
                            <input class="input" type="text" id="meta-e-target-{{ $goal->id }}" name="target_amount" inputmode="decimal"
                                   value="{{ $reabreEdit ? old('target_amount') : number_format((float) $goal->target_amount, 2, ',', '.') }}" required>
                        </div>
                        <div class="field">
                            <label for="meta-e-date-{{ $goal->id }}">Prazo <span class="hint">(opcional)</span></label>
                            <input class="input" type="month" id="meta-e-date-{{ $goal->id }}" name="target_date"
                                   value="{{ $reabreEdit ? old('target_date') : optional($goal->target_date)->format('Y-m') }}">
                        </div>
                    </div>

                    <div class="field">
                        <label>Ícone</label>
                        <div class="icon-picker meta-emoji-picker">
                            @foreach ($metaEmojis as $i => $em)
                                @php $sel = $reabreEdit ? old('emoji') : $gEmoji; @endphp
                                <input type="radio" name="emoji" id="meta-e-emoji-{{ $goal->id }}-{{ $i }}" value="{{ $em }}" @checked($sel === $em)>
                                <label for="meta-e-emoji-{{ $goal->id }}-{{ $i }}">{{ $em }}</label>
                            @endforeach
                            {{-- Caso o emoji atual não esteja na paleta padrão, garante uma opção marcada --}}
                            @php $emojiNaLista = in_array($reabreEdit ? old('emoji') : $gEmoji, $metaEmojis, true); @endphp
                            @unless ($emojiNaLista)
                                <input type="radio" name="emoji" id="meta-e-emoji-{{ $goal->id }}-x" value="{{ $reabreEdit ? old('emoji') : $gEmoji }}" checked>
                                <label for="meta-e-emoji-{{ $goal->id }}-x">{{ $reabreEdit ? old('emoji') : $gEmoji }}</label>
                            @endunless
                        </div>
                    </div>

                    <div class="field">
                        <label>Cor</label>
                        <div class="color-picker">
                            @foreach ($metaCores as $i => $cor)
                                @php $selC = $reabreEdit ? old('color') : $gCor; @endphp
                                <input type="radio" name="color" id="meta-e-color-{{ $goal->id }}-{{ $i }}" value="{{ $cor }}" @checked($selC === $cor)>
                                <label for="meta-e-color-{{ $goal->id }}-{{ $i }}" style="background:{{ $cor }}"></label>
                            @endforeach
                            @php $corNaLista = in_array($reabreEdit ? old('color') : $gCor, $metaCores, true); @endphp
                            @unless ($corNaLista)
                                <input type="radio" name="color" id="meta-e-color-{{ $goal->id }}-x" value="{{ $reabreEdit ? old('color') : $gCor }}" checked>
                                <label for="meta-e-color-{{ $goal->id }}-x" style="background:{{ $reabreEdit ? old('color') : $gCor }}"></label>
                            @endunless
                        </div>
                    </div>
                </div>
                <div class="modal-foot">
                    <button class="btn ghost" type="button" data-meta-close>Cancelar</button>
                    <button class="btn primary" type="submit">Salvar alterações</button>
                </div>
            </form>
        </div>
    </div>
@endforeach

{{-- Modal: Excluir meta (um por meta, confirmação) --}}
@foreach ($goals as $goal)
    <div class="modal-scrim" id="metaDeleteModal-{{ $goal->id }}" data-meta-modal>
        <div class="modal">
            <div class="modal-head">
                <span class="modal-ico ico-out"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 7h16M9 7V5a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 5v2M6.5 7l.8 12a2 2 0 0 0 2 1.9h5.4a2 2 0 0 0 2-1.9l.8-12"/></svg></span>
                <div>
                    <h3>Excluir meta</h3>
                    <p>Tem certeza que deseja excluir “{{ $goal->name }}”? Esta ação não pode ser desfeita.</p>
                </div>
                <button class="modal-x" type="button" data-meta-close aria-label="Fechar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>
            <form method="POST" action="{{ route('metas.destroy', $goal) }}">
                @csrf
                @method('DELETE')
                <div class="modal-foot">
                    <button class="btn ghost" type="button" data-meta-close>Cancelar</button>
                    <button class="btn-danger" type="submit">Excluir meta</button>
                </div>
            </form>
        </div>
    </div>
@endforeach

{{-- Modal: Aportar (compartilhado — action e dados preenchidos pelo metas.js).
     data-action-base traz a URL com placeholder __ID__ que o JS troca pelo id da meta. --}}
@php $reabreAporte = $errors->any() && str_contains((string) old('_action'), '/aportes'); @endphp
<div class="modal-scrim" id="metaAporteModal" data-meta-modal
     data-action-base="{{ route('metas.aportes.store', '__ID__') }}"
     data-reopen="{{ $reabreAporte ? '1' : '' }}"
     data-reopen-action="{{ old('_action') }}">
    <div class="modal">
        <div class="modal-head">
            <span class="modal-ico ico-in"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg></span>
            <div>
                <h3>Aportar <span data-aporte-name></span></h3>
                <p>Guarde um valor para esta meta.</p>
            </div>
            <button class="modal-x" type="button" data-meta-close aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>

        <form method="POST" action="" data-aporte-form>
            @csrf
            {{-- _action: ajuda a reabrir o modal com a URL certa após erro de validação --}}
            <input type="hidden" name="_action" value="" data-action-field>
            <div class="modal-body">
                @if ($reabreAporte)
                    <div class="flash-error" role="alert">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
                        <ul>@foreach ($errors->all() as $erro)<li>{{ $erro }}</li>@endforeach</ul>
                    </div>
                @endif
                {{-- Resumo guardado / faltam (preenchido via data-* no clique) --}}
                <div class="inv-preview">
                    <div class="ivp-row"><span>Guardado</span><b data-aporte-saved>—</b></div>
                    <div class="ivp-row"><span>Faltam</span><b data-aporte-remaining>—</b></div>
                </div>

                <div class="field">
                    <label for="meta-aporte-amount">Valor</label>
                    <input class="input" type="text" id="meta-aporte-amount" name="amount" inputmode="decimal"
                           value="{{ $reabreAporte ? old('amount') : '' }}" placeholder="R$ 500,00" required>
                </div>

                <div class="field">
                    <label for="meta-aporte-account">Conta de origem</label>
                    <select class="input" id="meta-aporte-account" name="account_id" required>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}" @selected($reabreAporte && (int) old('account_id') === $account->id)>
                                {{ $account->icon ? $account->icon . '  ' : '' }}{{ $account->name }} · {{ $brl($account->available) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                @if ($familyMembers->count() > 1)
                    <div class="field">
                        <label for="meta-aporte-who">Quem aportou</label>
                        <select class="input" id="meta-aporte-who" name="made_by_user_id">
                            @foreach ($familyMembers as $member)
                                <option value="{{ $member->id }}" @selected($reabreAporte && (int) old('made_by_user_id') === $member->id)>
                                    {{ $member->name }}{{ $member->isTitular() ? ' (Titular)' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="field">
                    <label for="meta-aporte-date">Data <span class="hint">(opcional)</span></label>
                    <input class="input" type="date" id="meta-aporte-date" name="date" value="{{ $reabreAporte ? old('date') : '' }}">
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-meta-close>Cancelar</button>
                <button class="btn primary" type="submit">Guardar</button>
            </div>
        </form>
    </div>
</div>

{{-- Modal: Resgatar (compartilhado — action e dados preenchidos pelo metas.js) --}}
@php $reabreResgate = $errors->any() && str_contains((string) old('_action'), '/resgates'); @endphp
<div class="modal-scrim" id="metaResgateModal" data-meta-modal
     data-action-base="{{ route('metas.resgates.store', '__ID__') }}"
     data-reopen="{{ $reabreResgate ? '1' : '' }}"
     data-reopen-action="{{ old('_action') }}">
    <div class="modal">
        <div class="modal-head">
            <span class="modal-ico ico-out"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 19V5M5 12l7 7 7-7"/></svg></span>
            <div>
                <h3>Resgatar <span data-resgate-name></span></h3>
                <p>Devolva parte do valor guardado para uma conta.</p>
            </div>
            <button class="modal-x" type="button" data-meta-close aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>

        <form method="POST" action="" data-resgate-form>
            @csrf
            <input type="hidden" name="_action" value="" data-action-field>
            <div class="modal-body">
                @if ($reabreResgate)
                    <div class="flash-error" role="alert">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
                        <ul>@foreach ($errors->all() as $erro)<li>{{ $erro }}</li>@endforeach</ul>
                    </div>
                @endif
                <div class="inv-preview">
                    <div class="ivp-row"><span>Guardado</span><b data-resgate-saved>—</b></div>
                </div>

                <div class="field">
                    <label for="meta-resgate-amount">Valor</label>
                    <input class="input" type="text" id="meta-resgate-amount" name="amount" inputmode="decimal"
                           value="{{ $reabreResgate ? old('amount') : '' }}" placeholder="R$ 200,00" required>
                </div>

                <div class="field">
                    <label for="meta-resgate-account">Conta de destino</label>
                    <select class="input" id="meta-resgate-account" name="account_id" required>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}" @selected($reabreResgate && (int) old('account_id') === $account->id)>
                                {{ $account->icon ? $account->icon . '  ' : '' }}{{ $account->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                @if ($familyMembers->count() > 1)
                    <div class="field">
                        <label for="meta-resgate-who">Quem resgatou <span class="hint">(opcional)</span></label>
                        <select class="input" id="meta-resgate-who" name="made_by_user_id">
                            @foreach ($familyMembers as $member)
                                <option value="{{ $member->id }}" @selected($reabreResgate && (int) old('made_by_user_id') === $member->id)>
                                    {{ $member->name }}{{ $member->isTitular() ? ' (Titular)' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="field">
                    <label for="meta-resgate-date">Data <span class="hint">(opcional)</span></label>
                    <input class="input" type="date" id="meta-resgate-date" name="date" value="{{ $reabreResgate ? old('date') : '' }}">
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-meta-close>Cancelar</button>
                <button class="btn primary" type="submit">Resgatar</button>
            </div>
        </form>
    </div>
</div>
@endsection
