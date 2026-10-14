{{--
    "Já paguei essa fatura fora do app" (out/2026 — pedido do Victor): compra no cartão com data
    no passado caía numa fatura que já venceu e aparecia VENCIDA, cobrando de novo o que a pessoa
    pagou antes de usar o app. Aparece só em despesa no cartão com data até o corte das faturas
    vencidas (`data-corte-vencidas` na opção do cartão), marcada; escondida, fica desabilitada e
    não viaja no envio. Quem liga e desliga é o `sm/faturas-vencidas.js`.

    Espera: $conta (id do select do método), $data (id do campo de data) e, opcional, $modo (id
    do campo de modo de Contas a pagar — recorrente não usa).
--}}
<div class="vencidas-pagas" data-vencidas-pagas data-conta="{{ $conta }}" data-data="{{ $data }}" @isset($modo) data-modo="{{ $modo }}" @endisset hidden>
    <label class="check">
        <input type="checkbox" name="faturas_vencidas_pagas" value="1" checked disabled>
        <span class="box"><svg viewBox="0 0 24 24" fill="none"><path d="M5 12l4 4 10-10"/></svg></span>
        <span>Já paguei essa fatura fora do app</span>
    </label>
    <small class="field-hint">Essa data cai numa fatura que já venceu. Marcado, o que cair em faturas vencidas entra como pago, sem tirar dinheiro de nenhuma conta.</small>
</div>
