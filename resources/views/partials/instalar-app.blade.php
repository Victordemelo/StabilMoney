{{-- "Instalar o app" (PWA). Tudo nasce escondido: quem decide o que aparece é o
     `sm/instalar.js`, conforme o navegador (convite do Chrome/Android, instrução no iPhone,
     ou "já instalado"). Sem JS — ou em http, sem HTTPS — nada aparece. --}}
<div class="instalar-app">
    <button type="button" class="btn-ghost" data-instalar-app hidden>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="6" y="2.5" width="12" height="19" rx="2.5"/><path d="M12 7v7M9 11l3 3 3-3"/></svg>
        Instalar o app
    </button>
    <span class="hint" data-instalar-ios hidden>No iPhone: toque em <strong>Compartilhar</strong> e depois em <strong>Adicionar à Tela de Início</strong>.</span>
    <span class="hint" data-instalado hidden>O app já está instalado neste aparelho.</span>
</div>
