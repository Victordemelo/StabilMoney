@extends('layouts.app')

@section('title', 'Pagar despesas')

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
        <h2>Pagar despesas</h2>
        <span class="sub">Marque a fatura do cartão como paga; débito, Pix e conta já descontam na hora</span>
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
        {{-- Contas fixas EM ABERTO. O valor é o PREVISTO de cada conta: luz e água
             variam todo mês, e o valor real só nasce no pagamento. Por isso o card
             se chama "a pagar" e traz "previsto" no rodapé — prometer exatidão num
             número que muda seria enganar quem se programa por ele. --}}
        <div class="card stat span4">
            <div class="stat-top">
                <div class="ico g4"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 10 12 4l9 6M5 10v9h14v-9M9 19v-5h6v5"/></svg></div>
                <span class="label">Contas a pagar</span>
            </div>
            <div class="value {{ ($stats['contasVencidas'] ?? 0) > 0 ? 'neg' : '' }}">
                <span class="cur">R$</span>{{ number_format((float) ($stats['totalContas'] ?? 0), 2, ',', '.') }}
            </div>
            <span class="stat-nota">
                @if (($stats['numContas'] ?? 0) === 0)
                    Nenhuma conta fixa em aberto
                @else
                    {{ $stats['numContas'] }} em aberto · valor previsto
                    @if (($stats['contasVencidas'] ?? 0) > 0)
                        · <b class="neg">{{ $stats['contasVencidas'] }} vencida{{ $stats['contasVencidas'] > 1 ? 's' : '' }}</b>
                    @endif
                @endif
            </span>
        </div>
    </div>

    {{-- ---------- Contas fixas do mês (condomínio, aluguel, carro…) ---------- --}}
    @php
        $fixasAbertas = $contasFixas->where('paga', false);
        $fixasVencidas = $contasFixas->where('vencida', true);
        $totalFixas = round((float) $fixasAbertas->sum('valor'), 2);
        // Editar/excluir são da CONTA FIXA, não da competência: a mesma conta
        // pode aparecer em várias linhas (julho vencido + agosto a vencer), e
        // repetir os botões em todas confundiria. Só a primeira linha de cada
        // conta os recebe.
        $fixasComAcoes = [];
    @endphp
    <div class="card fatura-card span12" style="margin-top:18px">
        <div class="fatura-head">
            <div class="fh-card" style="background:linear-gradient(135deg,#6B4E9E,#3A2A5C)">
                <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" width="22" height="22"><path d="M3 10 12 4l9 6M5 10v9h14v-9M9 19v-5h6v5"/></svg>
            </div>
            <div class="fh-info">
                <strong>Contas fixas do mês</strong>
                <span>
                    @if ($fixasVencidas->isNotEmpty())
                        <em class="fi-badge recorrente" style="color:var(--neg)">{{ $fixasVencidas->count() }} vencida{{ $fixasVencidas->count() > 1 ? 's' : '' }}</em>
                    @else
                        Condomínio, aluguel, parcelas — o que vence todo mês
                    @endif
                </span>
            </div>
            <div class="fh-total">
                <span>Em aberto</span>
                <b class="{{ $fixasVencidas->isNotEmpty() ? 'neg' : '' }}">@brl($totalFixas)</b>
            </div>
        </div>

        <div class="fatura-items">
            @forelse ($contasFixas as $oc)
                @php $bill = $oc['bill']; @endphp
                <div class="fatura-item">
                    <span class="fi-ico">{{ $bill->category?->icon ?: '🏠' }}</span>
                    <div class="fi-txt">
                        <strong>{{ $bill->name }}</strong>
                        <span>
                            {{ $oc['competence']->translatedFormat('F/Y') }} · vence dia {{ $bill->due_day }}
                            @if ($oc['paga'])
                                · <em class="fi-badge avista" style="color:var(--pos, #1FA06E)">paga</em>
                            @elseif ($oc['vencida'])
                                · <em class="fi-badge recorrente" style="color:var(--neg)">vencida há {{ abs($oc['diasRestantes']) }} {{ abs($oc['diasRestantes']) === 1 ? 'dia' : 'dias' }}</em>
                            @elseif ($oc['diasRestantes'] === 0)
                                · <em class="fi-badge parcelado">vence hoje</em>
                            @else
                                · vence em {{ $oc['diasRestantes'] }} {{ $oc['diasRestantes'] === 1 ? 'dia' : 'dias' }}
                            @endif
                        </span>
                    </div>
                    <div class="fi-val">
                        <b class="{{ $oc['vencida'] ? 'neg' : '' }}">@brl($oc['valor'])</b>
                        <small>{{ $oc['vencimento']->translatedFormat('d M') }}</small>
                    </div>
                    @if (! $oc['paga'] && $accounts->isNotEmpty())
                        <button class="btn primary" type="button" data-fixa-pagar
                                data-action="{{ route('contas-fixas.pagar', [$bill, $oc['competence']->format('Y-m')]) }}"
                                data-nome="{{ $bill->name }}"
                                data-valor="{{ number_format($oc['valor'], 2, ',', '.') }}"
                                data-conta="{{ $bill->account_id }}"
                                {{-- Piso da data de pagamento = 1º dia do mês anterior à
                                     competência (o mesmo do PayFixedBillRequest). --}}
                                data-min="{{ $oc['competence']->subMonthNoOverflow()->startOfMonth()->format('Y-m-d') }}">
                            Pagar
                        </button>
                    @endif

                    @php
                        $primeiraLinhaDaConta = ! in_array($bill->id, $fixasComAcoes, true);
                        if ($primeiraLinhaDaConta) { $fixasComAcoes[] = $bill->id; }
                    @endphp
                    @if ($primeiraLinhaDaConta)
                        {{-- Editar: corrige o previsto (1.800 digitado como 18.000 ficava
                             projetado para sempre e ainda vinha pré-preenchido no pagamento). --}}
                        <button class="fi-rm fi-ed" type="button" data-fixa-editar
                                data-action="{{ route('contas-fixas.update', $bill) }}"
                                data-nome="{{ $bill->name }}"
                                data-valor="{{ number_format((float) $bill->amount, 2, ',', '.') }}"
                                data-dia="{{ $bill->due_day }}"
                                data-conta="{{ $bill->account_id }}"
                                data-categoria="{{ $bill->category_id }}"
                                data-inicio="{{ optional($bill->starts_on)->format('Y-m-d') }}"
                                data-fim="{{ optional($bill->ends_on)->format('Y-m-d') }}"
                                aria-label="Editar conta fixa" title="Editar conta fixa">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 20h4L18.5 9.5a2 2 0 0 0 0-2.8l-1.2-1.2a2 2 0 0 0-2.8 0L4 16v4Z"/></svg>
                        </button>
                        <form method="POST" action="{{ route('contas-fixas.destroy', $bill) }}"
                              onsubmit="return confirm({{ Illuminate\Support\Js::from('Excluir a conta fixa “' . $bill->name . '”? As competências em aberto deixam de aparecer aqui; os pagamentos já feitos continuam no histórico.') }});">
                            @csrf
                            @method('DELETE')
                            <button class="fi-rm" type="submit" aria-label="Excluir conta fixa" title="Excluir conta fixa">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
                            </button>
                        </form>
                    @endif
                </div>
            @empty
                <div class="fi-empty">Nenhuma conta fixa cadastrada. Cadastre o condomínio, o aluguel ou a parcela do carro para nunca perder o vencimento.</div>
            @endforelse
        </div>

        {{-- Fora do `.fatura-pay`: aquele bloco é a linha de PAGAR a fatura (tem
             `margin-left:auto` no botão primário), e o "+ Nova conta fixa" herdava
             o alinhamento dela. O "+" era texto solto colado no rótulo; virou
             ícone, com o espaçamento do próprio botão. --}}
        <div class="fatura-acao">
            <button class="btn ghost" type="button" id="novaContaFixaBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>
                Nova conta fixa
            </button>
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

                {{-- ---- Fatura JÁ FECHADA e não paga ----
                     Bloco próprio, acima da fatura do mês: ela tem vencimento
                     próprio e é paga por outra rota (ciclo=fechado). O ramo do
                     servidor existia desde a auditoria, mas nenhuma tela o
                     acionava — a fatura que fechava ficava sem botão nenhum e a
                     dívida comia o limite do cartão para sempre. --}}
                @php $fechada = $card->closedInvoice; @endphp
                @if ($fechada)
                    <div class="fatura-pay">
                        <span class="fatura-due">
                            @if ($fechada['vencida'])
                                Fatura fechada e <b class="neg">vencida</b>: <b class="neg">{{ $brl($fechada['valor']) }}</b>
                                — venceu em {{ $fechada['vencimento']->translatedFormat('d/m/Y') }},
                                há {{ $fechada['diasAtraso'] }} {{ $fechada['diasAtraso'] === 1 ? 'dia' : 'dias' }}
                            @else
                                Fatura fechada: <b>{{ $brl($fechada['valor']) }}</b>
                                @if ($fechada['vencimento']) — vence em {{ $fechada['vencimento']->translatedFormat('d/m/Y') }}@endif
                            @endif
                        </span>
                        @if ($cashAccounts->isEmpty())
                            <span class="field-hint">Cadastre uma conta corrente/poupança para pagar.</span>
                        @else
                            <button class="btn primary" type="button" data-pay-open
                                    data-action="{{ route('faturas.fatura.pagar', $card->account) }}"
                                    data-name="{{ $card->account->name }}"
                                    data-amount="{{ $brl($fechada['valor']) }}"
                                    data-ciclo="fechado"
                                    data-min="{{ $card->payFloor }}">
                                {{ $fechada['vencida'] ? 'Pagar fatura vencida' : 'Pagar fatura fechada' }}
                            </button>
                        @endif
                    </div>
                @endif

                {{-- Pagar / status da fatura (só cartão de crédito) --}}
                <div class="fatura-pay">
                    @if ($card->isPaid)
                        <span class="fatura-paid"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 5-5.5"/></svg> Fatura paga</span>
                    @elseif ($card->canPay)
                        <span class="fatura-due">A pagar: <b>{{ $brl($card->invoiceDue) }}</b></span>
                        @if ($cashAccounts->isEmpty())
                            <span class="field-hint">Cadastre uma conta corrente/poupança para pagar.</span>
                        @else
                            <button class="btn primary" type="button" data-pay-open
                                    data-action="{{ route('faturas.fatura.pagar', $card->account) }}"
                                    data-name="{{ $card->account->name }}"
                                    data-amount="{{ $brl($card->invoiceDue) }}"
                                    data-ciclo="aberto"
                                    data-min="{{ $card->payFloor }}">
                                Marcar como paga
                            </button>
                        @endif
                    @endif

                    {{-- Desfazer o último pagamento: as compras voltam para a fatura
                         e o dinheiro volta para a conta. É a saída para quem clicou
                         em "Marcar como paga" no cartão ou na conta errada — antes
                         disso, o clique era irreversível pela interface. --}}
                    @if ($card->settlement)
                        <form method="POST" action="{{ route('faturas.fatura.estornar', $card->settlement) }}"
                              class="fatura-estorno"
                              onsubmit="return confirm('Estornar o pagamento de {{ $brl($card->settlement->amount) }}? As compras voltam para a fatura em aberto e o valor volta para a conta.')">
                            @csrf
                            @method('DELETE')
                            <button class="btn ghost" type="submit"
                                    title="Pago em {{ optional($card->settlement->paid_at)->translatedFormat('d/m/Y') }}">
                                Estornar pagamento
                            </button>
                        </form>
                    @endif
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
                            {{-- Recorrência em aberto: pagar gera a próxima (+1 mês).
                                 A rota existia desde sempre, mas sem botão nenhum — então
                                 a recorrência nunca avançava de mês. --}}
                            @if ($item->recurring && ! $item->paid_at)
                                <form method="POST" action="{{ route('faturas.recorrente.pagar', $item->id) }}">
                                    @csrf
                                    <button class="btn primary" type="submit" title="Marcar como paga e gerar a próxima">
                                        Pagar
                                    </button>
                                </form>
                            @endif
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
{{-- Único form que valida nesta tela é o de lançar despesa → o bag padrão já o identifica. --}}
@php $reabreLancar = $errors->any(); @endphp
<div class="modal-scrim" id="lancarModal" data-lancar-modal data-reopen="{{ $reabreLancar ? '1' : '' }}">
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
            {{-- Idempotência: identifica a COMPRA, não a requisição. Duplo clique,
                 "voltar" e reenvio chegam com o mesmo uuid e o servidor devolve o
                 lançamento existente em vez de criar outro (e, no parcelado, outras
                 N parcelas). O `old()` preserva o uuid quando a validação falha e o
                 modal reabre; um lançamento bem-sucedido recarrega a página e sorteia
                 outro. --}}
            <input type="hidden" name="client_uuid" value="{{ old('client_uuid', (string) \Illuminate\Support\Str::uuid()) }}">
            <div class="modal-body">
                @if ($reabreLancar)
                    <div class="flash-error" role="alert">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
                        <ul>@foreach ($errors->all() as $erro)<li>{{ $erro }}</li>@endforeach</ul>
                    </div>
                @endif
                {{-- Descrição --}}
                <div class="field">
                    <label for="lanc-desc">Descrição</label>
                    <input class="input" type="text" id="lanc-desc" name="description"
                           value="{{ $reabreLancar ? old('description') : '' }}" placeholder="Ex.: Supermercado, passagem aérea…" required>
                </div>

                <div class="field-row">
                    {{-- Valor (total) --}}
                    <div class="field">
                        <label for="lanc-valor">Valor</label>
                        <input class="input" type="text" id="lanc-valor" name="amount" inputmode="decimal"
                               value="{{ $reabreLancar ? old('amount') : '' }}" placeholder="R$ 0,00" required>
                    </div>
                    {{-- Data --}}
                    <div class="field">
                        <label for="lanc-data">Data</label>
                        <input class="input" type="date" id="lanc-data" name="date"
                               value="{{ $reabreLancar ? old('date', now()->format('Y-m-d')) : now()->format('Y-m-d') }}" required>
                    </div>
                </div>

                {{-- Método de pagamento (todas as contas da família) --}}
                <div class="field">
                    <label for="lanc-method">Método de pagamento</label>
                    <select class="input" id="lanc-method" name="account_id" required>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}"
                                    data-card="{{ $account->isCard ? '1' : '0' }}"
                                    @selected($reabreLancar && (int) old('account_id') === $account->id)>
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
                            <option value="{{ $categoria->id }}" @selected($reabreLancar && (int) old('category_id') === $categoria->id)>
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

{{-- ======================== MODAL: PAGAR CONTA FIXA ======================== --}}
@if ($accounts->isNotEmpty())
<div class="modal-scrim" id="fixaPagarModal" data-fixa-scrim>
    <div class="modal modal-md">
        <div class="modal-head">
            <span class="modal-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 5-5.5"/></svg></span>
            <div>
                <h3>Pagar conta fixa</h3>
                <p><b data-fixa-nome></b> — o valor sai da conta escolhida.</p>
            </div>
            <button class="modal-x" type="button" data-fixa-close aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <form method="POST" action="" data-fixa-form>
            @csrf
            <div class="modal-body">
                <div class="field-row">
                    <div class="field">
                        <label for="fixa-valor">Valor pago</label>
                        <input class="input" type="text" id="fixa-valor" name="amount" inputmode="decimal" required>
                        <small class="field-hint">Vem preenchido com o previsto — ajuste se veio diferente.</small>
                    </div>
                    <div class="field">
                        <label for="fixa-data">Data do pagamento</label>
                        <input class="input" type="date" id="fixa-data" name="paid_on"
                               value="{{ now()->format('Y-m-d') }}" max="{{ now()->format('Y-m-d') }}">
                    </div>
                </div>
                <div class="field">
                    <label for="fixa-conta">Pagar com</label>
                    <select class="input" id="fixa-conta" name="account_id" required>
                        @foreach ($accounts as $acc)
                            <option value="{{ $acc->id }}">{{ $acc->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-fixa-close>Cancelar</button>
                <button class="btn primary" type="submit">Confirmar pagamento</button>
            </div>
        </form>
    </div>
</div>
@endif

{{-- ====================== MODAL: NOVA CONTA FIXA ====================== --}}
<div class="modal-scrim" id="fixaNovaModal" data-fixanova-scrim>
    <div class="modal modal-lg">
        <div class="modal-head">
            <span class="modal-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 10 12 4l9 6M5 10v9h14v-9"/></svg></span>
            <div>
                <h3>Nova conta fixa</h3>
                <p>Ela aparece todo mês aqui, e avisa quando estiver perto de vencer.</p>
            </div>
            <button class="modal-x" type="button" data-fixanova-close aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <form method="POST" action="{{ route('contas-fixas.store') }}">
            @csrf
            <div class="modal-body">
                <div class="field">
                    <label for="cf-nome">Nome</label>
                    <input class="input" type="text" id="cf-nome" name="name" maxlength="255" required
                           placeholder="Ex.: Condomínio, Aluguel, Parcela do carro">
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="cf-valor">Valor mensal</label>
                        <input class="input" type="text" id="cf-valor" name="amount" inputmode="decimal" required placeholder="0,00">
                    </div>
                    <div class="field">
                        <label for="cf-dia">Vence todo dia</label>
                        <input class="input" type="number" id="cf-dia" name="due_day" min="1" max="31" required placeholder="10">
                    </div>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="cf-conta">Pagar com <span class="hint">(opcional)</span></label>
                        <select class="input" id="cf-conta" name="account_id">
                            <option value="">Escolher na hora</option>
                            @foreach ($accounts as $acc)
                                <option value="{{ $acc->id }}">{{ $acc->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="cf-cat">Categoria <span class="hint">(opcional)</span></label>
                        <select class="input" id="cf-cat" name="category_id">
                            <option value="">Sem categoria</option>
                            @foreach ($categories as $categoria)
                                <option value="{{ $categoria->id }}">{{ $categoria->icon ? $categoria->icon . '  ' : '' }}{{ $categoria->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="field-row">
                    <div class="field">
                        {{-- Default = HOJE, não o dia 1 do mês. Com o dia 1, quem
                             cadastrava dia 27 uma conta que vence dia 5 ganhava na
                             hora uma competência "vencida há 22 dias" de um mês que
                             já tinha pago fora do app — e um clique em Pagar tirava o
                             dinheiro de novo (auditoria 28/07/2026, A-10). --}}
                        <label for="cf-inicio">A partir de</label>
                        <input class="input" type="date" id="cf-inicio" name="starts_on" required value="{{ now()->format('Y-m-d') }}">
                        <small class="field-hint">Data do primeiro vencimento que você vai pagar aqui. Vencimentos anteriores a ela não são cobrados.</small>
                    </div>
                    <div class="field">
                        <label for="cf-fim">Até <span class="hint">(opcional)</span></label>
                        <input class="input" type="date" id="cf-fim" name="ends_on">
                        <small class="field-hint">Deixe vazio se não tem fim.</small>
                    </div>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-fixanova-close>Cancelar</button>
                <button class="btn primary" type="submit">Cadastrar</button>
            </div>
        </form>
    </div>
</div>

{{-- ====================== MODAL: EDITAR CONTA FIXA ======================
     Um só modal, preenchido pelos data-* do botão clicado (mesmo padrão do
     "Pagar conta fixa"). Fica FORA da .card de propósito: a .card tem
     overflow:hidden + animação de transform, que prende position:fixed. --}}
<div class="modal-scrim" id="fixaEditarModal" data-fixaedit-scrim>
    <div class="modal modal-lg">
        <div class="modal-head">
            <span class="modal-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 20h4L18.5 9.5a2 2 0 0 0 0-2.8l-1.2-1.2a2 2 0 0 0-2.8 0L4 16v4Z"/></svg></span>
            <div>
                <h3>Editar conta fixa</h3>
                <p>Vale para as próximas competências — os pagamentos já feitos não mudam.</p>
            </div>
            <button class="modal-x" type="button" data-fixaedit-close aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <form method="POST" action="" data-fixaedit-form>
            @csrf
            @method('PATCH')
            <div class="modal-body">
                <div class="field">
                    <label for="cfe-nome">Nome</label>
                    <input class="input" type="text" id="cfe-nome" name="name" maxlength="255" required>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="cfe-valor">Valor mensal</label>
                        <input class="input" type="text" id="cfe-valor" name="amount" inputmode="decimal" required placeholder="0,00">
                    </div>
                    <div class="field">
                        <label for="cfe-dia">Vence todo dia</label>
                        <input class="input" type="number" id="cfe-dia" name="due_day" min="1" max="31" required>
                    </div>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="cfe-conta">Pagar com <span class="hint">(opcional)</span></label>
                        <select class="input" id="cfe-conta" name="account_id">
                            <option value="">Escolher na hora</option>
                            @foreach ($accounts as $acc)
                                <option value="{{ $acc->id }}">{{ $acc->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="cfe-cat">Categoria <span class="hint">(opcional)</span></label>
                        <select class="input" id="cfe-cat" name="category_id">
                            <option value="">Sem categoria</option>
                            @foreach ($categories as $categoria)
                                <option value="{{ $categoria->id }}">{{ $categoria->icon ? $categoria->icon . '  ' : '' }}{{ $categoria->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="cfe-inicio">A partir de</label>
                        <input class="input" type="date" id="cfe-inicio" name="starts_on" required>
                    </div>
                    <div class="field">
                        <label for="cfe-fim">Até <span class="hint">(opcional)</span></label>
                        <input class="input" type="date" id="cfe-fim" name="ends_on">
                        <small class="field-hint">Deixe vazio se não tem fim.</small>
                    </div>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-fixaedit-close>Cancelar</button>
                <button class="btn primary" type="submit">Salvar alterações</button>
            </div>
        </form>
    </div>
</div>

{{-- Abertura/fechamento do modal de edição. Inline de propósito: o resto da
     tela vive em resources/js/sm/faturas.js, mas este bloco é pequeno, roda
     também depois da navegação pjax (nav.js re-executa scripts inline) e evita
     mexer no módulo compartilhado. --}}
<script nonce="{{ Vite::cspNonce() }}">
(function () {
    var modal = document.getElementById('fixaEditarModal');
    if (!modal) return;

    var form = modal.querySelector('[data-fixaedit-form]');
    var campo = function (sel) { return modal.querySelector(sel); };
    var fechar = function () { modal.classList.remove('open'); };

    document.querySelectorAll('[data-fixa-editar]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var d = btn.dataset;
            if (form) form.setAttribute('action', d.action || '');
            campo('#cfe-nome').value = d.nome || '';
            campo('#cfe-valor').value = d.valor || '';
            campo('#cfe-dia').value = d.dia || '';
            campo('#cfe-conta').value = d.conta || '';
            campo('#cfe-cat').value = d.categoria || '';
            campo('#cfe-inicio').value = d.inicio || '';
            campo('#cfe-fim').value = d.fim || '';
            modal.classList.add('open');
        });
    });

    modal.addEventListener('click', function (e) { if (e.target === modal) fechar(); });
    modal.querySelectorAll('[data-fixaedit-close]').forEach(function (b) {
        b.addEventListener('click', fechar);
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fechar(); });
})();
</script>

{{-- ============================ MODAL: PAGAR FATURA ============================ --}}
@if ($cashAccounts->isNotEmpty())
<div class="modal-scrim" id="payInvoiceModal" data-pay-scrim>
    <div class="modal">
        <div class="modal-head">
            <span class="modal-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 5-5.5"/></svg></span>
            <div>
                <h3>Pagar fatura</h3>
                <p>O valor é debitado da conta escolhida (desconta do seu saldo).</p>
            </div>
            <button class="modal-x" type="button" data-pay-close aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <form method="POST" action="" data-pay-form>
            @csrf
            {{-- Qual fatura está sendo paga: a do ciclo aberto ou a que já
                 fechou. Preenchido pelo botão clicado (data-ciclo). --}}
            <input type="hidden" name="ciclo" value="aberto" data-pay-ciclo>
            <div class="modal-body">
                <p class="pay-summary">Fatura de <b data-pay-name></b> — <b data-pay-amount></b></p>
                <div class="field-row">
                    <div class="field">
                        <label for="pay-account">Debitar de</label>
                        <select class="input" id="pay-account" name="pay_account_id" required>
                            @foreach ($cashAccounts as $acc)
                                <option value="{{ $acc->id }}">{{ $acc->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    {{-- Data do pagamento: o servidor já aceitava `paid_on`
                         (PayInvoiceRequest), mas nenhum campo o enviava — todo
                         pagamento gravava HOJE, e quitar uma fatura em atraso
                         registrando o dia certo era impossível pela interface. --}}
                    <div class="field">
                        <label for="pay-data">Data do pagamento</label>
                        <input class="input" type="date" id="pay-data" name="paid_on"
                               value="{{ now()->format('Y-m-d') }}" max="{{ now()->format('Y-m-d') }}">
                        <small class="field-hint">Pagou em outro dia? Ajuste aqui.</small>
                    </div>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-pay-close>Cancelar</button>
                <button class="btn primary" type="submit">Confirmar pagamento</button>
            </div>
        </form>
    </div>
</div>
@endif
@endsection
