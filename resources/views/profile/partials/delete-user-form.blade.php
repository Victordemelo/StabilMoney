{{-- Card "Excluir conta": ação destrutiva com confirmação por senha em modal --}}
<div class="card-head">
    <h3>Excluir conta</h3>
    <span class="chip">Zona de perigo</span>
</div>

<p class="sec-card-desc">
    Isto não tem volta. Antes de continuar, salve o que quiser guardar — depois de
    confirmar, não há como recuperar.
</p>

{{-- O que some, item a item. Antes era uma frase corrida com tudo enfileirado; a
     lista deixa o tamanho do estrago visível de relance. --}}
<ul class="conta-resumo perigo">
    <li>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 9.5h19"/></svg>
        <div><strong>Contas e cartões</strong><span>Saldos, limites e faturas.</span></div>
    </li>
    <li>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
        <div><strong>Todo o histórico</strong><span>Lançamentos, categorias, metas e investimentos.</span></div>
    </li>
    <li>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M17 6.2a3.2 3.2 0 0 1 0 6M18.5 20a6.4 6.4 0 0 0-2-4.6"/></svg>
        <div><strong>Os dependentes</strong><span>O login e os dados de quem você cadastrou.</span></div>
    </li>
</ul>

<div class="conta-perigo-acao">
    <button type="button" id="open-user-deletion" class="btn-danger">Excluir minha conta</button>
</div>
