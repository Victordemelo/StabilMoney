{{-- Uma competência de conta fixa na tela Contas a pagar (o pagar, editar e excluir).
     Espera $oc (FixedBillService) e $accounts. --}}
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
                {{-- Já venceu = obrigação (passa mesmo deixando a conta negativa); antes
                     disso é gasto novo. A mesma régua do FixedBillController::pay. --}}
                data-obrigacao="{{ $oc['vencimento']->lessThanOrEqualTo(today()) ? '1' : '0' }}"
                {{-- Piso da data de pagamento = 1º dia do mês anterior à
                     competência (o mesmo do PayFixedBillRequest). --}}
                data-min="{{ $oc['competence']->subMonthNoOverflow()->startOfMonth()->format('Y-m-d') }}">
            Pagar
        </button>
    @endif

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
            aria-label="Editar a conta fixa {{ $bill->name }}" title="Editar conta fixa">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 20h4L18.5 9.5a2 2 0 0 0 0-2.8l-1.2-1.2a2 2 0 0 0-2.8 0L4 16v4Z"/></svg>
    </button>
    {{-- Pede a SENHA (out/2026), pelo mesmo modal do "Remover despesa". --}}
    <form method="POST" action="{{ route('contas-fixas.destroy', $bill) }}"
          data-remover-despesa data-remover-tipo="fixa" data-titulo="Excluir conta fixa"
          data-pergunta="Excluir a conta fixa “{{ $bill->name }}”? As competências em aberto deixam de aparecer aqui; os pagamentos já feitos continuam no histórico.">
        @csrf
        @method('DELETE')
        <button class="fi-rm" type="submit" aria-label="Excluir a conta fixa {{ $bill->name }}" title="Excluir conta fixa">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
    </form>
</div>
