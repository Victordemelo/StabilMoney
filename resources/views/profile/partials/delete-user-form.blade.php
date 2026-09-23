{{-- Card "Excluir conta": ação destrutiva com confirmação por senha em modal.

     Titular e dependente perdem coisas DIFERENTES, e o card diz a verdade a cada um. O
     dinheiro é da família: as contas, o histórico, as metas e os investimentos pertencem ao
     titular (`user_id` = titular) e só somem quando ELE exclui a conta — junto com o login dos
     dependentes (hook `deleting` do User). O dependente que exclui a própria conta leva só o
     acesso e os dados pessoais dele; antes o card lhe dizia que perderia contas, histórico e
     dependentes, o que era falso e assustava à toa quem só queria sair.

     Espera: $user (quem está vendo — incluído por settings/partials/conta). --}}
@php
    $ehDependente = ! $user->isTitular();
@endphp

<div class="card-head">
    <h3>Excluir conta</h3>
    <span class="chip">Zona de perigo</span>
</div>

@if ($ehDependente)
    <p class="sec-card-desc">
        Isto apaga o seu acesso ao Stabil Money e não tem volta. O dinheiro da família não é
        apagado: continua com {{ $user->titular?->name ?? 'o titular' }}, que é quem responde pela conta.
    </p>

    {{-- O que some: só o que é DELE. --}}
    <ul class="conta-resumo perigo">
        <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/></svg>
            <div><strong>O seu login</strong><span>E-mail, senha e verificação em duas etapas: você não entra mais no app.</span></div>
        </li>
        <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="8" r="3.2"/><path d="M5.5 20a6.5 6.5 0 0 1 13 0"/></svg>
            <div><strong>Os seus dados pessoais</strong><span>Nome, foto, telefone, nascimento e os aparelhos conectados.</span></div>
        </li>
    </ul>

    {{-- O que fica: sem isto, "excluir conta" num app de finanças soa como "apagar o dinheiro". --}}
    <p class="sec-card-desc">
        Continuam com a família as contas, o histórico, as metas e os investimentos. Os
        lançamentos que você fez ficam no histórico, sem o seu nome.
    </p>
@else
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
@endif

<div class="conta-perigo-acao">
    <button type="button" id="open-user-deletion" class="btn-danger">Excluir minha conta</button>
</div>
