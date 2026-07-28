{{-- Modal "De onde sai esse dinheiro?" — passo 2 de um lançamento cujo saldo
     disponível não cobre. O conteúdo é montado pelo JS (sm/funding.js) com o
     payload do 409, e NÃO por View Composer: assim o shell de toda página não
     precisa carregar saldos e investimentos que quase nunca serão usados. --}}
<div class="modal-scrim" id="fundingModal" data-funding-scrim data-close>
    <div class="modal">
        <div class="modal-head">
            <span class="modal-ico ico-out">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5h.01"/></svg>
            </span>
            <div>
                <h3>De onde sai esse dinheiro?</h3>
                <p data-funding-resumo>Seu saldo não cobre esta despesa.</p>
            </div>
            <button class="modal-x" type="button" data-funding-close aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>

        <div class="modal-body">
            {{-- Opções injetadas pelo JS: uma <label class="fonte-opt"> por fonte --}}
            <div class="fonte-lista" data-funding-opcoes></div>

            {{-- Quando nenhuma fonte cobre: explica a saída (lançar um recebimento) --}}
            <div class="flash-error" data-funding-sem-saida role="alert" hidden>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
                <span data-funding-sem-saida-msg></span>
            </div>
        </div>

        <div class="modal-foot">
            <button class="btn ghost" type="button" data-funding-close>Cancelar</button>
            <button class="btn primary" type="button" data-funding-confirm>
                <span class="btn-label">Confirmar</span>
                <span class="btn-spin" aria-hidden="true"></span>
            </button>
        </div>
    </div>
</div>
