@extends('layouts.app')

@section('title', 'Investimentos')

@section('content')
@php
    // Mapa classe → rótulo + cor (espelha o CLASSE_COL do design v2 → finance.js).
    $classeLabels = [
        'renda_fixa'     => 'Renda fixa',
        'renda_variavel' => 'Renda variável',
        'fundos'         => 'Fundos',
        'cripto'         => 'Cripto',
    ];
    $classeCores = [
        'renda_fixa'     => '#1C9A70',
        'renda_variavel' => '#18B6BE',
        'fundos'         => '#9078D8',
        'cripto'         => '#C77F2A',
    ];

    // Indexadores oferecidos no form (mesma lista do investModal do protótipo).
    $indexadores = ['CDI', 'Selic', 'IPCA+', 'Prefixado'];

    // Bases projetadas por indexador (espelham IDX_BASE do finance.js) — usadas
    // pela preview de rentabilidade do modal "Novo investimento" (data-attrs no JS).
    $idxBase = ['CDI' => 10.65, 'Selic' => 10.5, 'IPCA+' => 4.5, 'Prefixado' => 0.0];

    // Formatadores R$ pt-BR (sem/com centavos).
    $brl0 = fn ($v) => 'R$ ' . number_format((float) $v, 0, ',', '.');
    $brl  = fn ($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');

    // Iniciais para o avatar do ativo (espelha initials() do protótipo).
    $iniciais = function ($nome) {
        $partes = preg_split('/\s+/', trim((string) $nome)) ?: [];
        $partes = array_values(array_filter($partes));
        if (count($partes) === 0) return '?';
        if (count($partes) === 1) return mb_strtoupper(mb_substr($partes[0], 0, 2));
        return mb_strtoupper(mb_substr($partes[0], 0, 1) . mb_substr($partes[count($partes) - 1], 0, 1));
    };

    // Donut de alocação (SVG server-rendered): porte fiel do arcSeg() do finance.js.
    // start/frac são frações 0..1; devolve um <path> de arco.
    $arcSeg = function ($start, $frac, $color) {
        $R = 46; $C = 60; $GAP = $frac < 0.999 ? 3 : 0;
        $a0 = $start * 360 + $GAP / 2;
        $a1 = ($start + $frac) * 360 - $GAP / 2;
        $a1 = max($a0 + 0.1, $a1);
        $pt = function ($deg) use ($R, $C) {
            $a = ($deg - 90) * M_PI / 180;
            return [$C + $R * cos($a), $C + $R * sin($a)];
        };
        [$x0, $y0] = $pt($a0);
        [$x1, $y1] = $pt($a1);
        $large = ($a1 - $a0) > 180 ? 1 : 0;
        return sprintf(
            '<path d="M%.2f %.2f A%d %d 0 %d 1 %.2f %.2f" fill="none" stroke="%s" stroke-width="13" stroke-linecap="round"/>',
            $x0, $y0, $R, $R, $large, $x1, $y1, $color
        );
    };

    $totalInvestido = (float) ($stats['investido'] ?? 0);
    $temFamilia = $familyMembers->count() > 1;
@endphp

<section class="view">
    <div class="section-head">
        <h2>Investimentos</h2>
        <span class="sub">Acompanhe sua carteira e a rentabilidade</span>
        <div class="head-actions">
            <button class="btn-primary" type="button" id="invNovoBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>
                Novo investimento
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

    {{-- 3 stat cards (Patrimônio investido, Ativos, Rentab. média) --}}
    <div class="grid">
        <div class="card stat span4">
            <div class="stat-top">
                <div class="ico g1"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 19V6M4 19h16M8 16v-4M12 16V8M16 16v-7"/></svg></div>
                <span class="label">Patrimônio investido</span>
            </div>
            <div class="value"><span class="cur">R$</span>{{ number_format($totalInvestido, 2, ',', '.') }}</div>
        </div>
        <div class="card stat span4">
            <div class="stat-top">
                <div class="ico g2"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 2v20M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
                <span class="label">Ativos</span>
            </div>
            <div class="value">{{ $stats['ativos'] ?? 0 }}</div>
        </div>
        <div class="card stat span4">
            <div class="stat-top">
                <div class="ico g4"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 12h4l3 8 4-16 3 8h4"/></svg></div>
                <span class="label">Rentab. média estimada</span>
            </div>
            <div class="value" title="Estimativa a partir do indexador e da taxa informados — não é rendimento realizado.">
                ≈ {{ number_format((float) ($stats['rentabMedia'] ?? 0), 1, ',', '.') }}% a.a.
            </div>
            <div style="font-size:11.5px;color:var(--ink-3);margin-top:6px">Projeção, não rendimento realizado</div>
        </div>
    </div>

    @if ($investments->isEmpty())
        {{-- Estado vazio amigável --}}
        <div class="grid" style="margin-top:18px">
            <div class="card span12">
                <div class="empty-block">
                    <div class="eb-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 19V6M4 19h16M8 16v-4M12 16V8M16 16v-7"/></svg></div>
                    <strong>Você ainda não tem investimentos</strong>
                    <span>Cadastre seu primeiro ativo — CDB, tesouro, ações, fundos… — e acompanhe a rentabilidade da carteira.</span>
                    <button class="btn primary" type="button" id="invNovoBtnVazio" style="margin-top:14px">Cadastrar primeiro investimento</button>
                </div>
            </div>
        </div>
    @else
        <div class="grid" style="margin-top:18px">
            {{-- Card: donut de alocação (SVG server-rendered a partir de $allocation) + legenda --}}
            <div class="card span5">
                <div class="card-head"><h3>Alocação da carteira</h3></div>
                @php
                    // Soma das fatias (defensivo: usa o total dos pedaços p/ o donut).
                    $allocTotal = collect($allocation)->sum('value');
                    $acc = 0.0;
                @endphp
                <div class="alloc-wrap">
                    <svg class="alloc-svg" viewBox="0 0 120 120">
                        @foreach ($allocation as $seg)
                            @php
                                $frac = $allocTotal > 0 ? ((float) $seg['value']) / $allocTotal : 0;
                                $arco = $arcSeg($acc, $frac, $seg['color']);
                                $acc += $frac;
                            @endphp
                            {!! $arco !!}
                        @endforeach
                        <g text-anchor="middle">
                            <text x="60" y="57" style="font:700 16px var(--font-head);fill:var(--ink)">R$ {{ number_format($totalInvestido / 1000, 0, ',', '.') }}k</text>
                            <text x="60" y="72" style="font:500 9px var(--font-body);fill:var(--ink-3)">investido</text>
                        </g>
                    </svg>
                    <div class="alloc-legend">
                        @forelse ($allocation as $seg)
                            <div class="al-row">
                                <span class="al-dot" style="background:{{ $seg['color'] }}"></span>
                                <span class="al-name">{{ $seg['classe'] }}</span>
                                <span class="al-val">{{ $seg['pct'] }}%</span>
                            </div>
                        @empty
                            <div class="ivp-hint">Sem dados de alocação ainda.</div>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- Card: lista de ativos --}}
            <div class="card span7">
                <div class="card-head">
                    <h3>Meus ativos</h3>
                    <button class="mini-btn" type="button" id="invAddInline">
                        Novo aporte
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>
                    </button>
                </div>
                <div class="inv-rows">
                    @foreach ($investments as $inv)
                        @php
                            $classeKey   = $inv->classe;
                            $classeLabel = $classeLabels[$classeKey] ?? ucfirst((string) $classeKey);
                            $cor         = $classeCores[$classeKey] ?? '#1C9A70';
                            // Subtítulo: "110% CDI" / "IPCA+ 6%" quando há indexador; senão a classe.
                            if ($inv->indexador) {
                                $taxaFmt = $inv->taxa !== null ? rtrim(rtrim(number_format((float) $inv->taxa, 2, ',', '.'), '0'), ',') : null;
                                $sub = $taxaFmt !== null ? $taxaFmt . '% ' . $inv->indexador : $inv->indexador;
                            } else {
                                $sub = $classeLabel;
                            }
                        @endphp
                        <div class="invr">
                            <div class="invr-l" style="background:{{ $cor }}">{{ $iniciais($inv->name) }}</div>
                            <div class="invr-main">
                                <strong>{{ $inv->name }}</strong>
                                <span>{{ $sub }}@if ($inv->madeBy?->name) · {{ \Illuminate\Support\Str::before(trim($inv->madeBy->name), ' ') }}@endif</span>
                            </div>
                            <div class="invr-val">
                                <b>{{ $brl0($inv->aplicado) }}</b>
                                {{-- Taxa ESTIMADA (premissa de projeção), nunca rendimento realizado:
                                     por isso "≈ … est." e sem a seta de alta, que sugeria ganho real. --}}
                                <span class="pos" style="color:var(--ink-3)" title="Rentabilidade estimada pelo indexador/taxa cadastrados — não é rendimento realizado.">≈ {{ number_format((float) $inv->grossRate, 1, ',', '.') }}% a.a. est.</span>
                            </div>
                            <button class="invr-btn" type="button"
                                    data-inv-aporte
                                    data-id="{{ $inv->id }}"
                                    data-name="{{ $inv->name }}"
                                    data-aplicado="{{ $brl($inv->aplicado) }}">
                                + Aportar
                            </button>
                            <button class="invr-btn invr-btn-out" type="button"
                                    data-inv-resgatar
                                    data-id="{{ $inv->id }}"
                                    data-name="{{ $inv->name }}"
                                    data-aplicado="{{ $brl($inv->aplicado) }}">
                                Resgatar
                            </button>
                            <button class="invr-btn invr-btn-edit" type="button"
                                    data-inv-edit
                                    data-id="{{ $inv->id }}"
                                    aria-label="Editar investimento">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 20h4L18.5 9.5a2 2 0 0 0 0-2.8l-1.2-1.2a2 2 0 0 0-2.8 0L4 16v4Z"/></svg>
                            </button>
                            <button class="inv-rm" type="button"
                                    data-inv-del
                                    data-id="{{ $inv->id }}"
                                    aria-label="Excluir investimento">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
                            </button>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</section>

{{-- ============================ MODAIS ============================ --}}

{{-- Modal: Novo investimento (criar) — com preview de rentabilidade líquida/12m via JS --}}
@php $reabreCreate = $errors->any() && old('_form') === 'create'; @endphp
<div class="modal-scrim" id="invCreateModal" data-inv-modal
     data-reopen="{{ $reabreCreate ? '1' : '' }}"
     data-idx-base='@json($idxBase)'
     {{-- Tabelas de IOF e IR vêm do PHP (App\Support\TributosRendaFixa) para que a
          prévia no cliente e qualquer cálculo no servidor NUNCA divirjam — esta tela já
          teve o problema de o card mostrar um número e a prévia outro. --}}
     data-tributos='@json(\App\Support\TributosRendaFixa::tabelasParaOFront())'>
    <div class="modal modal-lg">
        <div class="modal-head">
            <span class="modal-ico ico-in"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 19V6M4 19h16M8 16v-4M12 16V8M16 16v-7"/></svg></span>
            <div>
                <h3>Novo investimento</h3>
                <p>Defina a classe, o indexador e o aporte inicial.</p>
            </div>
            <button class="modal-x" type="button" data-inv-close aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>

        <form method="POST" action="{{ route('investimentos.store') }}">
            @csrf
            {{-- Idempotência: uuid novo a cada render (sem JS) e a cada abertura do modal (com JS). --}}
            <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}" data-client-uuid>
            <input type="hidden" name="_form" value="create">
            <div class="modal-body">
                @if ($reabreCreate)
                    <div class="flash-error" role="alert">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
                        <ul>@foreach ($errors->all() as $erro)<li>{{ $erro }}</li>@endforeach</ul>
                    </div>
                @endif
                <div class="field">
                    <label for="inv-c-name">Nome do ativo</label>
                    <input class="input" type="text" id="inv-c-name" name="name" value="{{ $reabreCreate ? old('name') : '' }}" placeholder="Ex.: CDB Banco XP" required>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="inv-c-classe">Classe</label>
                        <select class="input" id="inv-c-classe" name="classe" data-inv-classe required>
                            @foreach ($classeLabels as $val => $label)
                                <option value="{{ $val }}" @selected(($reabreCreate ? old('classe') : 'renda_fixa') === $val)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="inv-c-indexador">Indexador <span class="hint">(opcional)</span></label>
                        <select class="input" id="inv-c-indexador" name="indexador" data-inv-idx>
                            <option value="">Não indexado</option>
                            @foreach ($indexadores as $idx)
                                <option value="{{ $idx }}" @selected(($reabreCreate ? old('indexador') : '') === $idx)>{{ $idx }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="inv-c-taxa" data-inv-taxa-label>Taxa <span class="hint">(opcional)</span></label>
                        <input class="input" type="text" id="inv-c-taxa" name="taxa" inputmode="decimal" data-no-money data-inv-taxa value="{{ $reabreCreate ? old('taxa') : '' }}" placeholder="110">
                    </div>
                    <div class="field">
                        <label for="inv-c-valor">Valor aplicado <span class="hint">(opcional)</span></label>
                        <input class="input" type="text" id="inv-c-valor" name="valor_inicial" inputmode="decimal" data-inv-valor value="{{ $reabreCreate ? old('valor_inicial') : '' }}" placeholder="R$ 1.000,00">
                    </div>
                </div>

                {{-- Conta de origem: obrigatória só quando há valor inicial > 0 (validado no servidor) --}}
                <div class="field">
                    <label for="inv-c-account">Conta de origem <span class="hint">(do aporte inicial)</span></label>
                    <select class="input" id="inv-c-account" name="account_id">
                        <option value="">Sem aporte inicial</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}" @selected($reabreCreate && (int) old('account_id') === $account->id)>
                                {{ $account->icon ? $account->icon . '  ' : '' }}{{ $account->name }} · {{ $brl($account->available) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                @if ($temFamilia)
                    <div class="field">
                        <label for="inv-c-who">Quem investiu</label>
                        <select class="input" id="inv-c-who" name="made_by_user_id">
                            @foreach ($familyMembers as $member)
                                <option value="{{ $member->id }}" @selected($reabreCreate && (int) old('made_by_user_id') === $member->id)>
                                    {{ $member->name }}{{ $member->isTitular() ? ' (Titular)' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="field">
                    <label for="inv-c-date">Data <span class="hint">(opcional)</span></label>
                    <input class="input" type="date" id="inv-c-date" name="date" max="{{ now()->toDateString() }}" value="{{ $reabreCreate ? old('date') : '' }}">
                </div>

                {{-- Prazo da SIMULAÇÃO. Não é campo do investimento (não vai para o banco):
                     serve para ver o efeito dos impostos, que mudam muito com o tempo.
                     Os prazos curtos existem justamente para mostrar o IOF, que só incide
                     nos primeiros 29 dias e é devastador no começo. --}}
                <div class="field">
                    <label for="inv-c-prazo">Simular resgate em <span class="hint">(só para a projeção)</span></label>
                    <select class="input" id="inv-c-prazo" data-inv-prazo>
                        <option value="1">1 dia</option>
                        <option value="15">15 dias</option>
                        <option value="29">29 dias (último com IOF)</option>
                        <option value="30">30 dias (sem IOF)</option>
                        <option value="180">6 meses</option>
                        <option value="365" selected>12 meses</option>
                        <option value="730">24 meses</option>
                        <option value="1095">36 meses</option>
                    </select>
                </div>

                {{-- Projeção ESTIMADA (recalculada em JS conforme classe/indexador/taxa/valor).
                     Nada aqui vira saldo: é só simulação, com as premissas à mostra. --}}
                <div class="inv-preview" data-inv-preview>
                    <div class="ivp-hint">Preencha o valor para ver a projeção estimada de rendimento.</div>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-inv-close>Cancelar</button>
                <button class="btn primary" type="submit">Adicionar investimento</button>
            </div>
        </form>
    </div>
</div>

{{-- Modal: Editar investimento (um por ativo, pré-preenchido) --}}
@foreach ($investments as $inv)
    @php
        $reabreEdit = $errors->any() && old('_form') === 'edit-' . $inv->id;
        $taxaEdit = $inv->taxa !== null ? number_format((float) $inv->taxa, 2, ',', '.') : '';
    @endphp
    <div class="modal-scrim" id="invEditModal-{{ $inv->id }}" data-inv-modal data-reopen="{{ $reabreEdit ? '1' : '' }}">
        <div class="modal modal-lg">
            <div class="modal-head">
                <span class="modal-ico ico-in"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 20h4L18.5 9.5a2 2 0 0 0 0-2.8l-1.2-1.2a2 2 0 0 0-2.8 0L4 16v4Z"/></svg></span>
                <div>
                    <h3>Editar investimento</h3>
                    <p>Ajuste o nome, a classe ou o indexador.</p>
                </div>
                <button class="modal-x" type="button" data-inv-close aria-label="Fechar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>

            <form method="POST" action="{{ route('investimentos.update', $inv) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="_form" value="edit-{{ $inv->id }}">
                <div class="modal-body">
                    @if ($reabreEdit)
                        <div class="flash-error" role="alert">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
                            <ul>@foreach ($errors->all() as $erro)<li>{{ $erro }}</li>@endforeach</ul>
                        </div>
                    @endif
                    <div class="field">
                        <label for="inv-e-name-{{ $inv->id }}">Nome do ativo</label>
                        <input class="input" type="text" id="inv-e-name-{{ $inv->id }}" name="name"
                               value="{{ $reabreEdit ? old('name') : $inv->name }}" required>
                    </div>
                    <div class="field-row">
                        <div class="field">
                            <label for="inv-e-classe-{{ $inv->id }}">Classe</label>
                            <select class="input" id="inv-e-classe-{{ $inv->id }}" name="classe" required>
                                @php $selClasse = $reabreEdit ? old('classe') : $inv->classe; @endphp
                                @foreach ($classeLabels as $val => $label)
                                    <option value="{{ $val }}" @selected($selClasse === $val)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label for="inv-e-indexador-{{ $inv->id }}">Indexador <span class="hint">(opcional)</span></label>
                            <select class="input" id="inv-e-indexador-{{ $inv->id }}" name="indexador">
                                @php $selIdx = $reabreEdit ? old('indexador') : $inv->indexador; @endphp
                                <option value="">Não indexado</option>
                                @foreach ($indexadores as $idx)
                                    <option value="{{ $idx }}" @selected($selIdx === $idx)>{{ $idx }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="field">
                        <label for="inv-e-taxa-{{ $inv->id }}">Taxa <span class="hint">(opcional)</span></label>
                        <input class="input" type="text" id="inv-e-taxa-{{ $inv->id }}" name="taxa" inputmode="decimal" data-no-money
                               value="{{ $reabreEdit ? old('taxa') : $taxaEdit }}" placeholder="110">
                    </div>
                </div>
                <div class="modal-foot">
                    <button class="btn ghost" type="button" data-inv-close>Cancelar</button>
                    <button class="btn primary" type="submit">Salvar alterações</button>
                </div>
            </form>
        </div>
    </div>
@endforeach

{{-- Modal: Excluir investimento (um por ativo, confirmação) --}}
@foreach ($investments as $inv)
    <div class="modal-scrim" id="invDeleteModal-{{ $inv->id }}" data-inv-modal>
        <div class="modal">
            <div class="modal-head">
                <span class="modal-ico ico-out"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 7h16M9 7V5a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 5v2M6.5 7l.8 12a2 2 0 0 0 2 1.9h5.4a2 2 0 0 0 2-1.9l.8-12"/></svg></span>
                <div>
                    <h3>Excluir investimento</h3>
                    <p>Tem certeza que deseja excluir “{{ $inv->name }}”? Esta ação não pode ser desfeita.</p>
                </div>
                <button class="modal-x" type="button" data-inv-close aria-label="Fechar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>
            <form method="POST" action="{{ route('investimentos.destroy', $inv) }}">
                @csrf
                @method('DELETE')
                <div class="modal-foot">
                    <button class="btn ghost" type="button" data-inv-close>Cancelar</button>
                    <button class="btn-danger" type="submit">Excluir investimento</button>
                </div>
            </form>
        </div>
    </div>
@endforeach

{{-- Modal: Aportar (compartilhado — action e dados preenchidos pelo investimentos.js).
     data-action-base traz a URL com placeholder __ID__ que o JS troca pelo id do ativo. --}}
@php $reabreAporte = $errors->any() && str_contains((string) old('_action'), '/aportes'); @endphp
<div class="modal-scrim" id="invAporteModal" data-inv-modal
     data-action-base="{{ route('investimentos.aportes.store', '__ID__') }}"
     data-reopen="{{ $reabreAporte ? '1' : '' }}"
     data-reopen-action="{{ old('_action') }}">
    <div class="modal">
        <div class="modal-head">
            <span class="modal-ico ico-in"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg></span>
            <div>
                <h3>Aportar <span data-aporte-name></span></h3>
                <p>Adicione um valor à posição.</p>
            </div>
            <button class="modal-x" type="button" data-inv-close aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>

        <form method="POST" action="" data-aporte-form>
            @csrf
            <input type="hidden" name="_action" value="" data-action-field>
            {{-- Idempotência: uuid novo a cada render (sem JS) e a cada abertura do modal (com JS). --}}
            <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}" data-client-uuid>
            <div class="modal-body">
                @if ($reabreAporte)
                    <div class="flash-error" role="alert">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
                        <ul>@foreach ($errors->all() as $erro)<li>{{ $erro }}</li>@endforeach</ul>
                    </div>
                @endif
                <div class="inv-preview">
                    <div class="ivp-row"><span>Posição atual</span><b data-aporte-aplicado>—</b></div>
                </div>

                <div class="field">
                    <label for="inv-aporte-amount">Valor do aporte</label>
                    <input class="input" type="text" id="inv-aporte-amount" name="amount" inputmode="decimal"
                           value="{{ $reabreAporte ? old('amount') : '' }}" placeholder="R$ 500,00" required>
                </div>

                <div class="field">
                    <label for="inv-aporte-account">Conta de origem</label>
                    <select class="input" id="inv-aporte-account" name="account_id" required>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}" @selected($reabreAporte && (int) old('account_id') === $account->id)>
                                {{ $account->icon ? $account->icon . '  ' : '' }}{{ $account->name }} · {{ $brl($account->available) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                @if ($temFamilia)
                    <div class="field">
                        <label for="inv-aporte-who">Quem aportou</label>
                        <select class="input" id="inv-aporte-who" name="made_by_user_id">
                            @foreach ($familyMembers as $member)
                                <option value="{{ $member->id }}" @selected($reabreAporte && (int) old('made_by_user_id') === $member->id)>
                                    {{ $member->name }}{{ $member->isTitular() ? ' (Titular)' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="field">
                    <label for="inv-aporte-date">Data <span class="hint">(opcional)</span></label>
                    <input class="input" type="date" id="inv-aporte-date" name="date" max="{{ now()->toDateString() }}" value="{{ $reabreAporte ? old('date') : '' }}">
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-inv-close>Cancelar</button>
                <button class="btn primary" type="submit">Aportar</button>
            </div>
        </form>
    </div>
</div>

{{-- Modal: Resgatar (compartilhado — action e dados preenchidos pelo investimentos.js) --}}
@php $reabreResgate = $errors->any() && str_contains((string) old('_action'), '/resgates'); @endphp
<div class="modal-scrim" id="invResgateModal" data-inv-modal
     data-action-base="{{ route('investimentos.resgates.store', '__ID__') }}"
     data-reopen="{{ $reabreResgate ? '1' : '' }}"
     data-reopen-action="{{ old('_action') }}">
    <div class="modal">
        <div class="modal-head">
            <span class="modal-ico ico-out"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 19V5M5 12l7 7 7-7"/></svg></span>
            <div>
                <h3>Resgatar <span data-resgate-name></span></h3>
                <p>Devolva parte da posição para uma conta.</p>
            </div>
            <button class="modal-x" type="button" data-inv-close aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>

        <form method="POST" action="" data-resgate-form>
            @csrf
            <input type="hidden" name="_action" value="" data-action-field>
            {{-- Idempotência: uuid novo a cada render (sem JS) e a cada abertura do modal (com JS). --}}
            <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}" data-client-uuid>
            <div class="modal-body">
                @if ($reabreResgate)
                    <div class="flash-error" role="alert">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
                        <ul>@foreach ($errors->all() as $erro)<li>{{ $erro }}</li>@endforeach</ul>
                    </div>
                @endif
                <div class="inv-preview">
                    <div class="ivp-row"><span>Posição atual</span><b data-resgate-aplicado>—</b></div>
                </div>

                <div class="field">
                    <label for="inv-resgate-amount">Valor</label>
                    <input class="input" type="text" id="inv-resgate-amount" name="amount" inputmode="decimal"
                           value="{{ $reabreResgate ? old('amount') : '' }}" placeholder="R$ 200,00" required>
                </div>

                <div class="field">
                    <label for="inv-resgate-account">Conta de destino
                        <span class="hint">(a mesma de onde o dinheiro saiu)</span></label>
                    <select class="input" id="inv-resgate-account" name="account_id" required>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}" @selected($reabreResgate && (int) old('account_id') === $account->id)>
                                {{ $account->icon ? $account->icon . '  ' : '' }}{{ $account->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                @if ($temFamilia)
                    <div class="field">
                        <label for="inv-resgate-who">Quem resgatou <span class="hint">(opcional)</span></label>
                        <select class="input" id="inv-resgate-who" name="made_by_user_id">
                            @foreach ($familyMembers as $member)
                                <option value="{{ $member->id }}" @selected($reabreResgate && (int) old('made_by_user_id') === $member->id)>
                                    {{ $member->name }}{{ $member->isTitular() ? ' (Titular)' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="field">
                    <label for="inv-resgate-date">Data <span class="hint">(opcional)</span></label>
                    <input class="input" type="date" id="inv-resgate-date" name="date" max="{{ now()->toDateString() }}" value="{{ $reabreResgate ? old('date') : '' }}">
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-inv-close>Cancelar</button>
                <button class="btn primary" type="submit">Resgatar</button>
            </div>
        </form>
    </div>
</div>
@endsection
