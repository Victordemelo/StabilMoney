{{--
    O ícone de uma movimentação (out/2026 — pedido do Victor: "tá fácil de identificar o item?"):
    o emoji da CATEGORIA, num fundo da cor dela, com o ícone do BANCO no canto — no
    uso do dia a dia a pergunta é "o que foi" e "por onde saiu", e o logo responde a segunda sem
    ler a linha de baixo. Sem banco (conta antiga sem banco), fica só o ícone.

    Espera: $t (Transaction) e, opcional, $grande (o ícone da tela de editar).
--}}
@php
    $receitaIco = $t->type === 'income';
    $transfIco = $t->isTransferencia();
    $bancoIco = $t->account?->bankIconUrl();
@endphp
<div class="tx-ico {{ ($grande ?? false) ? 'tx-ico-grande' : '' }}" @if (! $transfIco && $t->category?->color) style="background: color-mix(in srgb, {{ $t->category->color }} 16%, transparent)" @endif>
    @if ($transfIco)
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M7 8h13M7 8l3-3M7 8l3 3M17 16H4M17 16l-3-3M17 16l-3 3"/></svg>
    @elseif ($t->category?->icon)
        <span aria-hidden="true">{{ $t->category->icon }}</span>
    @elseif ($receitaIco)
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M12 19V5M12 5l-6 6M12 5l6 6"/></svg>
    @else
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M5 7h14l-1.2 8.5a2 2 0 0 1-2 1.7H8.2a2 2 0 0 1-2-1.7L5 7Z"/><path d="M9 7a3 3 0 0 1 6 0M9 11v2M15 11v2"/></svg>
    @endif
    @if ($bancoIco)
        <img class="tx-banco" src="{{ $bancoIco }}" alt="" loading="lazy" title="{{ $t->account->bankLabel() }}">
    @endif
</div>
