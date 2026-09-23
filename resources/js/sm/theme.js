/* ============ StabilMoney — Tema claro/escuro ============ */
// O atributo data-theme já é aplicado por um script inline no <head> dos
// layouts (anti-flash). Aqui sincronizamos o ícone, ligamos os toggles e
// acompanhamos o tema do SISTEMA enquanto a pessoa não escolheu um.

const SUN = '<circle cx="12" cy="12" r="4.5"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5 19 19M19 5l-1.5 1.5M6.5 17.5 5 19"/>';
const MOON = '<path d="M20 14.5A8 8 0 1 1 9.5 4a6.3 6.3 0 0 0 10.5 10.5Z"/>';

const CHAVE = 'sm-theme';
const CONSULTA_ESCURO = '(prefers-color-scheme: dark)';

/**
 * A regra ÚNICA de qual tema vale.
 *
 * A escolha explícita (o botão de tema, gravada em `sm-theme`) manda sempre. Sem ela,
 * vale o tema do sistema — antes valia o claro, e o app abria branco num celular no
 * modo escuro (observação da auditoria de PWA de 06/09/2026).
 *
 * O inline anti-flash dos layouts app e legal repete esta regra (ele roda antes do
 * bundle, não pode importá-la); o tests/js/theme.test.js executa aqueles scripts e
 * confere que as duas pontas concordam em todos os casos.
 */
export function resolverTema(salvo, sistemaEscuro) {
    if (salvo === 'dark' || salvo === 'light') return salvo;
    return sistemaEscuro ? 'dark' : 'light';
}

function temaSalvo() {
    try { return localStorage.getItem(CHAVE); } catch (e) { return null; /* storage indisponível */ }
}

// `null` em navegador sem matchMedia: aí a regra cai no claro, como sempre foi.
function consultaDoSistema() {
    try { return window.matchMedia ? window.matchMedia(CONSULTA_ESCURO) : null; } catch (e) { return null; }
}

// Troca o desenho sol/lua nos botões de tema (desktop e mobile)
function syncIcons(theme) {
    const ic = theme === 'dark' ? MOON : SUN;
    const themeIcon = document.getElementById('themeIcon');
    if (themeIcon) themeIcon.innerHTML = ic;
    const mt = document.querySelector('#mTheme svg');
    if (mt) mt.innerHTML = ic;
}

/* As "bordas do sistema" — barra do navegador/status no celular — não são
   pintadas pelo CSS: elas leem metas do <head>. O inline anti-flash do layout já
   as acerta na primeira pintura; aqui refazemos a mesma conta quando o usuário
   TROCA o tema, senão a barra ficava com a cor do tema anterior até um reload.

   Os hex vêm dos data-light/data-dark da própria meta (fonte única: o layout, que
   copia os tokens `--bg` do design-system.css). Só mexemos na meta marcada com
   `data-sm-theme` — as telas de auth têm cor fixa (o topo delas é o painel verde
   do vídeo) e ficam de fora. */
function syncSystemColors(theme) {
    const cor = document.querySelector('meta[name="theme-color"][data-sm-theme]');
    if (cor) {
        const valor = cor.getAttribute(theme === 'dark' ? 'data-dark' : 'data-light');
        if (valor) cor.setAttribute('content', valor);
    }

    // iOS (app instalado): `black` = glifos claros, `default` = glifos escuros.
    // Nem toda versão do iOS reaplica esta meta sem recarregar a página — por
    // isso o valor certo também sai do servidor/inline; ver partials/pwa-head.
    const barra = document.querySelector('meta[name="apple-mobile-web-app-status-bar-style"]');
    if (barra) barra.setAttribute('content', theme === 'dark' ? 'black' : 'default');
}

// Mostra o tema, sem gravar nada: é o que o sistema e a carga da página usam.
function aplicar(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    syncIcons(theme);
    syncSystemColors(theme);
}

// Escolha EXPLÍCITA (botão): grava, e a partir daí o sistema não manda mais.
function setTheme(theme) {
    aplicar(theme);
    try { localStorage.setItem(CHAVE, theme); } catch (e) { /* storage indisponível */ }
}

export function initTheme() {
    // Só a página que SEGUE o tema da pessoa entra na regra: a que tem a meta marcada com
    // `data-sm-theme` (layouts app e legal — os mesmos que têm o anti-flash). As telas de
    // auth são sempre claras e as do painel sempre escuras, DE PROPÓSITO; acompanhar o
    // sistema ali as trocaria de tema no meio do uso.
    const segueOTema = !!document.querySelector('meta[name="theme-color"][data-sm-theme]');

    if (segueOTema) {
        const sistema = consultaDoSistema();

        // Rede de segurança: se o inline do <head> não tiver rodado (página servida de
        // um cache antigo, storage bloqueado), a regra se cumpre aqui — e as metas junto.
        aplicar(resolverTema(temaSalvo(), sistema ? sistema.matches : false));

        // O sistema mudou com a página aberta (o celular entrou no modo escuro ao
        // anoitecer): acompanha — a menos que a pessoa tenha escolhido um tema, e aí a
        // `resolverTema` devolve a escolha dela e nada muda.
        if (sistema) {
            const acompanhar = (e) => aplicar(resolverTema(temaSalvo(), e.matches));
            if (sistema.addEventListener) sistema.addEventListener('change', acompanhar);
            else if (sistema.addListener) sistema.addListener(acompanhar); // Safari < 14
        }
    } else {
        const atual = document.documentElement.getAttribute('data-theme') || 'light';
        syncIcons(atual);
        syncSystemColors(atual);
    }

    const toggle = () =>
        setTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');

    const themeBtn = document.getElementById('themeBtn');
    if (themeBtn) themeBtn.addEventListener('click', toggle);
    const mTheme = document.getElementById('mTheme');
    if (mTheme) mTheme.addEventListener('click', toggle);
}
