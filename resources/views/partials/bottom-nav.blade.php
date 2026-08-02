{{-- Navegação inferior mobile (shell v2; links reais, FAB abre nova transação).

     São 4 destinos + FAB, de propósito: o `.bn-item` do design-system tem padding
     lateral de 14px, então um 5º item já estoura a largura num aparelho de 360px e
     os rótulos passam a se espremer/quebrar. Com o teto de 4, a barra espelha o que
     se usa TODO dia do grupo "Menu" da sidebar: ver o resumo, conferir o extrato,
     pagar o que venceu e acompanhar as metas.

     Ficaram de fora (e não somem do app — a sidebar inteira abre pelo hamburger da
     .mobile-top, e o dashboard tem atalho para as duas):
       • Métodos de Pagamento — é "Preferências": mexe-se no cadastro e quase nunca mais.
       • Investimentos — aporte é esporádico; o card "Investimentos" do dashboard leva lá.
     Antes, "Pagar despesas" não tinha NENHUM caminho na barra, num app que é
     PWA-first: quem usa pelo celular não alcançava a tela de pagar conta. --}}
<nav class="bottom-nav">
    <a data-pjax class="bn-item {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>
        Início
    </a>
    <a data-pjax class="bn-item {{ request()->routeIs('transactions.index') ? 'active' : '' }}" href="{{ route('transactions.index') }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M7 8h13M7 8l3-3M7 8l3 3M17 16H4M17 16l-3-3M17 16l-3 3"/></svg>
        Extrato
    </a>
    <a class="bn-item fab" href="{{ route('transactions.create') }}" data-launch-open aria-label="Novo lançamento">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>
    </a>
    {{-- Ícones iguais aos da sidebar (recibo / alvo), para o mesmo destino ter o mesmo símbolo --}}
    <a data-pjax class="bn-item {{ request()->routeIs('faturas.*') ? 'active' : '' }}" href="{{ route('faturas.index') }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 3h9l3 3v15l-2-1.3L13 21l-2-1.3L9 21l-2-1.3L5 21V5a2 2 0 0 1 1-2Z"/><path d="M9 8h6M9 12h6M9 16h3"/></svg>
        Pagar
    </a>
    <a data-pjax class="bn-item {{ request()->routeIs('metas.*') ? 'active' : '' }}" href="{{ route('metas.index') }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r=".7" fill="currentColor"/></svg>
        Metas
    </a>
</nav>
