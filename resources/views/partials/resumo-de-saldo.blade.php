{{--
    Resumo do saldo nas janelas de pagamento (out/2026): saldo de agora − o que sai = como a
    conta fica. Quem preenche é o `sm/resumo-saldo.js` (desenharResumo); sem JS ele fica
    escondido, e o pagamento funciona igual. `$id` só serve para o aria-describedby de quem usa.
--}}
<div class="resumo-saldo" data-resumo-saldo @isset($id) id="{{ $id }}" @endisset role="group" aria-label="Resumo do saldo" aria-live="polite" hidden>
    <div class="rs-item">
        <span class="rs-rotulo" data-rs-rotulo-atual>Saldo atual</span>
        <b class="rs-valor" data-rs-atual></b>
    </div>
    <span class="rs-op" data-rs-operador aria-hidden="true">−</span>
    <div class="rs-item">
        <span class="rs-rotulo" data-rs-rotulo-valor>{{ $rotuloValor ?? 'Este pagamento' }}</span>
        <b class="rs-valor" data-rs-valor></b>
    </div>
    <span class="rs-op" aria-hidden="true">=</span>
    <div class="rs-item rs-depois">
        <span class="rs-rotulo" data-rs-rotulo-depois>Saldo depois</span>
        <b class="rs-valor" data-rs-depois></b>
    </div>
    <p class="rs-aviso" data-rs-aviso hidden></p>
</div>
