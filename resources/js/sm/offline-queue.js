// Fila de lançamentos offline (Fase 2 do PWA).
//
// Quando o usuário salva um lançamento SEM conexão, guardamos no IndexedDB com
// um client_uuid e reenviamos sozinho quando a internet volta. O servidor é
// idempotente (dedupe por client_uuid), então um replay repetido não duplica.
//
// O submit do form é interceptado SEMPRE (online e offline). Online, enviamos
// por AJAX (fetch JSON) em vez de POST de form puro: assim, se o form veio do
// cache do service worker com _token velho e a resposta for 419, buscamos um
// token CSRF fresco em /csrf-token e refazemos o POST uma vez — sem cair na
// página de erro feia. No sucesso (201/200) navegamos para /transactions,
// reproduzindo o redirect que o servidor faz no fluxo web. Offline, mantemos o
// comportamento de fila descrito acima.
//
// Decisões de segurança/robustez (ver spec):
//  - Dois caminhos de reenvio: (1) DIRIGIDO PELA PÁGINA (token CSRF fresco do
//    <meta>; fallback universal) e (2) BACKGROUND SYNC no service worker, que
//    reenvia MESMO com o app fechado (Chromium/Android) usando o token CSRF
//    guardado com o item. O CSRF continua ligado, sem endpoint isento.
//  - O token CSRF guardado também é a trava por usuário: item de um usuário só
//    "passa" na sessão dele (token de outra sessão → 419), então num aparelho
//    compartilhado ninguém reenvia lançamento de outro.
//  - Cada item é CARIMBADO com o id de quem o criou (`userId`). Esse carimbo é a
//    trava principal na troca de usuário: a página só reenvia o que é do usuário
//    logado agora, e desarma (tira o csrf de) o que é de outro dono, o que também
//    impede o reenvio pelo service worker. Ver `revisarFilaDeOutroDono()` no fim
//    do arquivo — inclusive o porquê de NADA ser apagado sozinho.
//  - Só a CRIAÇÃO entra na fila (form com data-offline-queue e modal "Lançar"),
//    nunca edição.

import { pedirFonte } from './funding';

const DB_NAME = 'sm-offline';
const STORE = 'lancamentos';

// Mensagem única do enfileiramento. "na fila", nunca "salvo": enquanto não
// sincroniza, o lançamento NÃO existe no servidor — dizer "salvo" seria mentir.
export const MSG_NA_FILA = 'Sem conexão — lançamento na fila. Envio automático quando a internet voltar.';

// Registro válido da fila (defensivo: item sem payload não é lançamento nenhum).
const ehLancamento = (i) => !!(i && i.payload);

function meta(name) {
    const el = document.querySelector(`meta[name="${name}"]`);
    return el ? el.getAttribute('content') : null;
}

// ---- IndexedDB (promisificado) ---------------------------------------------

function openDb() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, 1);
        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains(STORE)) {
                db.createObjectStore(STORE, { keyPath: 'client_uuid' });
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

async function idb(mode, fn) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, mode);
        const store = tx.objectStore(STORE);
        const result = fn(store);
        tx.oncomplete = () => resolve(result && result.__value !== undefined ? result.__value : undefined);
        tx.onerror = () => reject(tx.error);
        tx.onabort = () => reject(tx.error);
    });
}

const queueAdd = (item) => idb('readwrite', (s) => s.put(item));
const queueDelete = (uuid) => idb('readwrite', (s) => s.delete(uuid));
function queueAll() {
    return idb('readonly', (s) => {
        const box = {};
        s.getAll().onsuccess = (e) => { box.__value = e.target.result || []; };
        return box;
    });
}

// ---- UUID (usa crypto quando disponível) -----------------------------------

function uuid() {
    if (crypto && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        const v = c === 'x' ? r : (r & 0x3) | 0x8;
        return v.toString(16);
    });
}

// ---- Indicador de pendências (autossuficiente, sem depender de CSS externo) -

let badge;
function renderBadge(count) {
    if (!badge) {
        badge = document.createElement('button');
        badge.type = 'button';
        badge.id = 'sm-offline-badge';
        badge.style.cssText = [
            'position:fixed', 'left:50%', 'transform:translateX(-50%)',
            'bottom:calc(72px + env(safe-area-inset-bottom,0px))', 'z-index:60',
            'display:none', 'align-items:center', 'gap:8px',
            'padding:9px 16px', 'border:0', 'border-radius:999px', 'cursor:pointer',
            'font:600 13px/1 system-ui,sans-serif', 'color:#0C3D2B', 'background:#36D38A',
            'box-shadow:0 8px 24px rgba(0,0,0,.25)',
        ].join(';');
        // Travados (precisam de decisão ou falharam) abrem a revisão; o resto
        // tenta sincronizar. Tocar num selo que o drain ia pular não fazia nada.
        badge.addEventListener('click', () => {
            if (travados.length) renderRevisao(travados);
            else drain();
        });
        document.body.appendChild(badge);
    }
    if (count > 0) {
        if (travados.length) {
            const plural = travados.length > 1 ? 'lançamentos precisam' : 'lançamento precisa';
            badge.textContent = `⚠️ ${travados.length} ${plural} de você — tocar p/ resolver`;
            badge.style.background = '#FFD466';
        } else {
            const plural = count > 1 ? 'lançamentos pendentes' : 'lançamento pendente';
            badge.textContent = `⏳ ${count} ${plural} — tocar p/ sincronizar`;
            badge.style.background = '#36D38A';
        }
        badge.style.display = 'inline-flex';
    } else {
        badge.style.display = 'none';
    }
}

// Itens meus que o drain NÃO consegue reenviar sozinho: esperam uma decisão.
let travados = [];

async function refreshBadge() {
    try {
        const userId = meta('sm-user');
        // Só conta o que é MEU: pendência de outro dono não é minha para resolver.
        const items = (await queueAll())
            .filter(ehLancamento)
            .filter((i) => String(i.userId) === String(userId));
        travados = items.filter((i) => i.needsFunding || i.failed);
        renderBadge(items.length);
        // Item travado sem tela era o buraco antigo: o selo contava, o drain
        // pulava, e não havia onde ver o motivo nem como resolver.
        if (travados.length) renderRevisao(travados, { discreto: true });
    } catch (_) { /* sem IndexedDB: ignora silenciosamente */ }
}

// ---- Sincronização (replay) ------------------------------------------------

/** POST do lançamento no formato que o replay usa (JSON + CSRF do <meta>). */
function enviarLancamento(payload, token = meta('csrf-token')) {
    return fetch('/transactions', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(payload),
    });
}

let draining = false;
async function drain() {
    if (draining || !navigator.onLine) return;
    const userId = meta('sm-user');
    const token = meta('csrf-token');
    if (!userId || !token) return; // não logado / sem token

    draining = true;
    try {
        // O filtro por `userId` é a trava da troca de usuário: lançamento de
        // outro dono NUNCA é reenviado na sessão de quem está logado agora
        // (iria para a família errada, com o autor errado).
        const items = (await queueAll())
            .filter(ehLancamento)
            .filter((i) => String(i.userId) === String(userId) && !i.failed && !i.needsFunding);

        for (const item of items) {
            const enviar = (payload) => enviarLancamento(payload, token);

            let res;
            try {
                res = await enviar(item.payload);
            } catch (_) {
                break; // caiu a rede no meio: tenta de novo no próximo online
            }

            // 409: o saldo não cobre e existe fonte. A fila NÃO escolhe por você.
            //
            // Antes daqui reenviávamos sozinhos com `cheque_especial`, argumentando
            // que "a compra já aconteceu no mundo real". O argumento vale para
            // REGISTRAR a despesa, não para escolher a fonte: cheque especial cobra
            // juros de verdade, e o usuário descobria depois — sem aviso nenhum,
            // porque o sucesso do drain é silencioso. Isso furava o invariante do
            // modelo v3 ("o app NUNCA usa o cheque especial sozinho").
            //
            // Agora o item fica RETIDO com as opções que o servidor mandou, e a
            // próxima carga da página abre o modal de escolha. Nada se perde: o
            // lançamento continua na fila, que é onde ele já estava.
            if (res.status === 409) {
                let fonte = null;
                try {
                    fonte = (await res.json())?.fonte || null;
                } catch (_) { /* corpo não-JSON: o modal é reaberto sem detalhe */ }

                item.needsFunding = true;
                item.fonte = fonte;
                delete item.failed;   // não é falha: é decisão pendente
                delete item.motivo;
                await queueAdd(item);
                continue;             // não bloqueia os outros itens da fila
            }

            if (res.ok) {
                await queueDelete(item.client_uuid);          // 201 criado ou 200 já existia
            } else if (res.status === 401 || res.status === 419) {
                showToast('Faça login para sincronizar seus lançamentos pendentes.');
                break;                                         // sessão/CSRF expirou: mantém na fila
            } else if (res.status === 422) {
                // Dados inválidos ou nenhuma fonte cobre: guarda a mensagem REAL do
                // servidor para o usuário resolver, em vez do texto genérico.
                let motivo = '';
                try {
                    const data = await res.json();
                    motivo = flattenErrors(data && data.errors)[0] || data?.message || '';
                } catch (_) { /* corpo não-JSON */ }

                item.failed = true;                            // não insiste em loop
                item.motivo = motivo;
                await queueAdd(item);
                showToast(motivo || 'Um lançamento não pôde ser sincronizado.');
            } // 5xx e outros: deixa na fila para a próxima tentativa
        }
    } finally {
        draining = false;
        await refreshBadge();
    }
}

// Pede um "Background Sync": o navegador acorda o service worker e dispara o
// reenvio assim que a conexão voltar — INCLUSIVE com o app fechado (Chromium/
// Android). Sem suporte (Firefox/Safari/iOS), o reenvio dirigido pela página
// (evento 'online' + abertura do app) segue como fallback.
function requestBackgroundSync() {
    if (!('serviceWorker' in navigator) || !('SyncManager' in window)) return;
    navigator.serviceWorker.ready
        .then((reg) => reg.sync.register('sm-sync-lancamentos'))
        .catch(() => { /* sem permissão/suporte: o fallback da página cobre */ });
}

// ---- Toast simples ---------------------------------------------------------

function showToast(msg) {
    const t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = [
        'position:fixed', 'left:50%', 'transform:translateX(-50%)',
        'bottom:calc(120px + env(safe-area-inset-bottom,0px))', 'z-index:70',
        'max-width:90vw', 'padding:12px 18px', 'border-radius:12px',
        'font:500 14px/1.4 system-ui,sans-serif', 'color:#fff', 'background:#0C3D2B',
        'box-shadow:0 10px 30px rgba(0,0,0,.3)', 'text-align:center',
    ].join(';');
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 4200);
}

// ---- Captura do submit do formulário de lançamento -------------------------

function serializeForm(form) {
    const data = {};
    new FormData(form).forEach((value, key) => {
        if (key === '_token' || key === '_method') return; // controle do Laravel, não do lançamento
        data[key] = value;
    });
    return data;
}

// Salva o lançamento na fila offline e arma o reenvio. Usado tanto no caminho
// claramente OFFLINE quanto quando o envio online cai na rede no meio (ou a
// sessão expira de vez) — em todos os casos: nada se perde. `form` serve só
// para resetar a tela; NÃO entra no que é gravado no IndexedDB.
//
// Devolve `true` se conseguiu enfileirar. Quem chama precisa saber: se o
// IndexedDB falhou, o lançamento se perdeu e a tela tem que dizer isso.
function enqueueOffline(payload, toastMsg, form) {
    return queueAdd({
        client_uuid: payload.client_uuid,
        userId: meta('sm-user'), // CARIMBO do dono — trava da troca de usuário
        csrf: meta('csrf-token'), // token p/ o service worker reenviar em background
        payload,
        createdAt: Date.now(),
    }).then(() => {
        requestBackgroundSync(); // acorda o SW p/ reenviar quando a net voltar
        showToast(toastMsg);
        if (form && typeof form.reset === 'function') form.reset();
        refreshBadge();
        return true;
    }).catch(() => {
        showToast('Não foi possível guardar o lançamento neste aparelho.');
        return false;
    });
}

/**
 * Enfileira um lançamento vindo de OUTRA tela (hoje: o modal global "Lançar").
 *
 * Existe para que todo caminho de criação use a MESMA fila: o modal é o botão
 * mais usado do app (topbar + FAB) e, até aqui, mandava `fetch` direto — sem
 * internet o usuário clicava em "Salvar" e perdia o que tinha digitado.
 *
 * `payload` já deve trazer o `client_uuid` (idempotência no servidor).
 * Devolve Promise<boolean>.
 */
export function enfileirarLancamento(payload, toastMsg = MSG_NA_FILA) {
    return enqueueOffline(payload, toastMsg, null);
}

// Renderiza/atualiza o bloco .flash-error no topo do .form-card com as mensagens
// de validação (422). Sem reload em AJAX, então criamos o bloco se não existir.
function renderFormErrors(form, messages) {
    const card = form.closest('.form-card') || form.parentElement || form;
    let box = card.querySelector('.flash-error');
    if (!box) {
        box = document.createElement('div');
        box.className = 'flash-error';
        box.setAttribute('role', 'alert');
        // Mesmo ícone do _form.blade.php, para casar com o visual server-rendered.
        box.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor">'
            + '<circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg><ul></ul>';
        card.insertBefore(box, card.firstChild);
    }
    const list = box.querySelector('ul') || box.appendChild(document.createElement('ul'));
    list.innerHTML = '';
    (messages.length ? messages : ['Não foi possível salvar. Confira os dados e tente de novo.'])
        .forEach((msg) => {
            const li = document.createElement('li');
            li.textContent = msg;
            list.appendChild(li);
        });
    box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// Achata o objeto { campo: [msg, ...] } que o Laravel devolve em 422 numa lista
// simples de mensagens (a mesma ordem visual do $errors->all() do Blade).
function flattenErrors(errors) {
    const out = [];
    if (errors && typeof errors === 'object') {
        Object.values(errors).forEach((msgs) => {
            (Array.isArray(msgs) ? msgs : [msgs]).forEach((m) => out.push(m));
        });
    }
    return out;
}

// POST do lançamento via fetch (JSON). Reusado no envio inicial e no retry com
// token fresco. Devolve a Response (ou lança em erro de rede, tratado por quem chama).
function postTransaction(action, payload, token) {
    return fetch(action, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
    });
}

// Pega um token CSRF FRESCO da sessão atual (rota /csrf-token, atrás de auth) e
// o aplica no <meta> e no input _token do form, para o retro do POST e para os
// próximos envios. Devolve o token novo, ou null se a sessão expirou (não-200).
//
// Exportado porque o modal "Lançar" (`sm/launch.js`) precisa do MESMO tratamento
// de 419: era o único caminho de escrita sem ele, e o lançamento se perdia.
export async function refreshCsrfToken(form) {
    let res;
    try {
        res = await fetch('/csrf-token', {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
    } catch (_) {
        return null; // sem rede: quem chama trata como offline
    }
    if (!res.ok) return null; // sessão expirou (redirect p/ login etc.)

    let data;
    try { data = await res.json(); } catch (_) { return null; }
    const fresh = data && data.token;
    if (!fresh) return null;

    const metaEl = document.querySelector('meta[name="csrf-token"]');
    if (metaEl) metaEl.setAttribute('content', fresh);
    const hidden = form.querySelector('input[name="_token"]');
    if (hidden) hidden.value = fresh;
    return fresh;
}

function setSubmitting(btn, on) {
    if (!btn) return;
    if (on) {
        btn.dataset.label = btn.dataset.label || btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Salvando…';
    } else {
        btn.disabled = false;
        if (btn.dataset.label) btn.textContent = btn.dataset.label;
    }
}

/**
 * Liga o envio do formulário cheio de lançamento (`form[data-offline-queue]`).
 *
 * DELEGADO no `document`, e não preso ao elemento: o formulário também chega pelo pjax,
 * que troca o `#content` inteiro — a página cheia remontada pelo `smPjaxReload` depois
 * de salvar no modal "Lançar", ou o "voltar" do navegador até ela. Ligado só no elemento
 * que existia na carga, o formulário novo fazia o POST comum do navegador: sem internet
 * o lançamento morria numa página de erro de rede em vez de ir para a fila, e online
 * perdia o retry do 419.
 *
 * Fase de BOLHA, depois do "Tem certeza?" do `sm/confirmar.js` (captura); envio que outro
 * ouvinte já cancelou não é assunto daqui.
 */
function ligarFormulariosDaFila() {
    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('form[data-offline-queue]')) return;
        if (e.defaultPrevented) return;

        enviarFormulario(e, form);
    });
}

function enviarFormulario(e, form) {
    // Interceptamos SEMPRE (online e offline). O POST de form puro com token
    // velho do cache responderia 419; no AJAX a gente busca um token fresco
    // e refaz o envio, sem página de erro.
    e.preventDefault();

    const payload = serializeForm(form);
    payload.client_uuid = uuid();
    // client_uuid também no envio ONLINE: se a resposta se perder e houver
    // retry, o servidor deduplica por esse uuid em vez de duplicar.

    // ---- Caminho OFFLINE: comportamento original intacto. -------------
    if (!navigator.onLine) {
        enqueueOffline(
            payload,
            MSG_NA_FILA,
            form
        );
        return;
    }

    // ---- Caminho ONLINE: envia por AJAX, com retry de CSRF em 419. -----
    submitOnline(form, payload);
}

async function submitOnline(form, payload) {
    const action = form.getAttribute('action') || '/transactions';
    const btn = form.querySelector('[type="submit"]');
    setSubmitting(btn, true);

    let res;
    try {
        res = await postTransaction(action, payload, meta('csrf-token'));
    } catch (_) {
        // Rede caiu no meio do envio: trata como offline (nada se perde).
        setSubmitting(btn, false);
        await enqueueOffline(
            payload,
            MSG_NA_FILA,
            form
        );
        return;
    }

    // 419: token velho (form veio do cache). Busca um fresco e refaz UMA vez.
    if (res.status === 419) {
        const fresh = await refreshCsrfToken(form);
        if (!fresh) {
            // Sessão expirou de vez: guarda offline para sincronizar no login.
            setSubmitting(btn, false);
            await enqueueOffline(
                payload,
                'Faça login para sincronizar seu lançamento.',
                form
            );
            return;
        }
        try {
            res = await postTransaction(action, payload, fresh);
        } catch (_) {
            setSubmitting(btn, false);
            await enqueueOffline(
                payload,
                MSG_NA_FILA,
                form
            );
            return;
        }
        // Retry ainda 419 (ou voltou a expirar): guarda offline.
        if (res.status === 419) {
            setSubmitting(btn, false);
            await enqueueOffline(
                payload,
                'Faça login para sincronizar seu lançamento.',
                form
            );
            return;
        }
    }

    // 409: o saldo disponível não cobre, mas há fonte (cheque especial ou
    // resgate). Pergunta ao usuário e reenvia com a escolha — mesmo payload,
    // mesmo client_uuid, então não duplica.
    if (res.status === 409) {
        setSubmitting(btn, false);
        let dados = {};
        try { dados = await res.json(); } catch (_) { /* segue com genérico */ }

        const escolha = await pedirFonte(dados.fonte);
        if (!escolha) return; // cancelou: fica na tela com os dados preenchidos

        Object.assign(payload, escolha);
        setSubmitting(btn, true);
        try {
            res = await postTransaction(action, payload, meta('csrf-token'));
        } catch (_) {
            setSubmitting(btn, false);
            await enqueueOffline(
                payload,
                MSG_NA_FILA,
                form
            );
            return;
        }
    }

    // 201 (criado) ou 200 (dedupe): o servidor redireciona no fluxo web; aqui
    // reproduzimos navegando para a lista.
    if (res.status === 201 || res.status === 200) {
        window.location = '/transactions';
        return;
    }

    // 422: validação. Renderiza as mensagens em PT-BR no .flash-error e fica na
    // tela (não navega).
    if (res.status === 422) {
        let errs = [];
        try {
            const data = await res.json();
            errs = flattenErrors(data && data.errors);
        } catch (_) { /* corpo não-JSON: cai na mensagem genérica do render */ }
        renderFormErrors(form, errs);
        setSubmitting(btn, false);
        return;
    }

    // 5xx e quaisquer outros: mensagem genérica, reabilita o botão.
    showToast('Não foi possível registrar agora. Tente de novo.');
    setSubmitting(btn, false);
}

// ---- Segurança: apaga o /transactions/create em cache quando o DONO muda
//      (logout OU troca de usuário no mesmo aparelho). Sem isso, o form em cache
//      de um usuário (com as contas/categorias da família dele e o CSRF) poderia
//      ser servido offline para outra pessoa no mesmo dispositivo.
//
//      É a SEGUNDA camada. A primeira é o próprio service worker, que apaga o form
//      ao ver o POST do logout e quando o /login responde sem sessão — sem depender
//      deste bundle rodar (ver HTML_AUTENTICADO em pwa/service-worker.blade.php).
//      Esta continua valendo para o que o SW não cobre: SW de versão antiga ainda no
//      controle logo depois de um deploy, e cache que ficou no aparelho depois de o
//      service worker ser desregistrado.

function purgeCachedFormIfUserChanged() {
    if (!('caches' in window)) return;
    const current = meta('sm-user'); // null = deslogado
    let last = null;
    try { last = localStorage.getItem('sm-form-user'); } catch (_) { /* sem storage */ }
    if (current === last) return; // mesmo dono → mantém o cache

    // Dono mudou (ou deslogou): o form em cache é de OUTRO usuário → apaga de tudo.
    caches.keys().then((keys) => keys.forEach((k) =>
        caches.open(k).then((c) => c.delete('/transactions/create'))
    )).catch(() => {});
    try {
        if (current) localStorage.setItem('sm-form-user', current);
        else localStorage.removeItem('sm-form-user');
    } catch (_) { /* sem storage */ }
}

// ---- Fila de OUTRO dono no mesmo aparelho ----------------------------------
//
// O IndexedDB é do NAVEGADOR, não da sessão: a fila sobrevive ao logout. Num
// aparelho de casa, o Victor lança offline, sai, a esposa entra — e o lançamento
// dele não pode virar despesa na conta dela.
//
// ESTRATÉGIA: SEGURAR, NUNCA APAGAR SOZINHO.
// A versão anterior apagava os itens dos outros donos assim que alguém logava.
// Resolvia o vazamento, mas destruía dinheiro: aquele lançamento aconteceu no
// mundo real e ainda não chegou ao servidor — apagá-lo em silêncio some com uma
// despesa que ninguém mais vai lembrar de refazer. Aqui o item FICA na fila,
// trancado: o carimbo `userId` tira ele do reenvio da página, e o desarme (perder
// o `csrf`) tira ele do reenvio do service worker. A pessoa que está usando o
// aparelho recebe um AVISO VISÍVEL explicando o que há e como resolver. Descartar
// existe, mas é decisão explícita dela, com confirmação — nunca efeito colateral
// de um login.
//
// O que É descartado sem perguntar: o token CSRF do item de outro dono. É
// credencial da sessão dele (já morta no logout), não é o lançamento. Sai do
// aparelho; o dado do dinheiro fica.

let avisoOutroDono;
// ---- Revisão dos lançamentos travados --------------------------------------

let revisaoEl = null;
let revisaoDispensada = false; // "×" esconde só nesta visita, não apaga nada

/**
 * Lê o `amount` guardado na fila com as MESMAS regras do servidor: a normalização do
 * `NormalizesMoneyInput::normalizeMoneyField` + as regras `numeric` e `decimal:0,2`.
 *
 * O payload guarda o valor COMO A MÁSCARA DEIXOU ("31.000,00") — é assim que ele vai para
 * o servidor. `Number("31.000,00")` é NaN, e o `|| 0` de antes transformava isso em
 * R$ 0,00 em TODO item do aviso de lançamentos retidos (P-3 da auditoria de 06/09/2026).
 * Ali o valor é o que a pessoa usa para reconhecer um dinheiro que ainda não chegou ao
 * servidor: zero é o pior número possível para errar.
 *
 * Formatos que chegam aqui: o da máscara atual ("1.300,00") e, em item antigo da fila,
 * digitação livre ("1300", "1.300", "1234.5", "R$ 1.234,56").
 * ⚠️ Ponto seguido de EXATAMENTE 3 dígitos é separador de MILHAR: "800.123" é 800 mil,
 * não 800 reais — no servidor também.
 *
 * Devolve `null` quando o servidor não gravaria valor nenhum (vazio, texto, três casas
 * decimais, notação científica): aí não existe número verdadeiro para mostrar.
 */
function lerValor(bruto) {
    if (typeof bruto === 'number') return Number.isFinite(bruto) ? bruto : null;
    if (typeof bruto !== 'string') return null;

    // Na ordem do servidor: o middleware TrimStrings (que apara espaço Unicode — pelo
    // menos tudo o que o `trim()` do JS apara), depois `str_replace(['R$', ' '], '')` e o
    // `trim()` do PHP, que só conhece " \n\r\t\v\0". Aparar ali com o `trim()` do JS
    // aceitaria o espaço não separável (U+00A0) que o `Intl` põe depois do "R$" — e o
    // servidor recusa esse valor.
    let valor = bruto.trim().split('R$').join('').split(' ').join('')
        .replace(/^[ \n\r\t\v\0]+|[ \n\r\t\v\0]+$/g, '');

    if (valor.includes(',')) {
        valor = valor.split('.').join('').split(',').join('.'); // "1.234,56" → "1234.56"
    } else if (/^-?\d{1,3}(\.\d{3})+$/.test(valor)) {
        valor = valor.split('.').join(''); // só milhar: "1.234" → "1234"
    }

    // `numeric` + `decimal:0,2`: sinal opcional, dígitos e no máximo duas casas. Conferir
    // o formato, e não só o `Number()`: o JS aceitaria "1e3", "0x10" e "Infinity", que o
    // servidor recusa — e o aviso mostraria um valor que nunca vai existir.
    if (!/^[+-]?(\d+(\.\d{0,2})?|\.\d{1,2})$/.test(valor)) return null;

    return Number(valor);
}

/** "R$ 1.234,56" · negativo: "−R$ 1.234,56" — o mesmo formato do Brl::format do servidor. */
function brl(n) {
    return (n < 0 ? '−' : '') + 'R$ ' + Math.abs(n).toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

/** O valor de um item no aviso — ou a verdade, quando não há número para mostrar. */
function rotuloDoValor(bruto) {
    if (bruto === undefined || bruto === null || String(bruto).trim() === '') return 'sem valor';

    const n = lerValor(bruto);
    return n === null ? 'valor não reconhecido' : brl(n);
}

function botao(rotulo, cor, onClick) {
    const b = document.createElement('button');
    b.type = 'button';
    b.textContent = rotulo;
    b.style.cssText = 'border:0;border-radius:999px;padding:7px 12px;cursor:pointer;'
        + 'font:600 12px/1 system-ui,sans-serif;color:#fff;background:' + cor;
    b.addEventListener('click', onClick);
    return b;
}

/**
 * Tela dos lançamentos que a fila NÃO resolve sozinha.
 *
 * Existem dois motivos para um item parar aqui, e nenhum dos dois tinha tela antes:
 *
 *  - `needsFunding`: o servidor devolveu 409 (o disponível não cobre). A fila
 *    deixou de escolher a fonte por conta própria — ver o bloco do 409 no
 *    `drain()`. Aqui a pessoa escolhe, e só então o lançamento é gravado.
 *  - `failed`: 422 do servidor (dados inválidos, ou nenhuma fonte cobre). Antes o
 *    item só sumia do drain e o selo ficava preso para sempre, sem nada explicando.
 *
 * NADA é apagado sozinho: descartar é sempre um clique consciente, porque o
 * lançamento nunca chegou ao servidor e é a única cópia que existe.
 */
function renderRevisao(itens, { discreto = false } = {}) {
    if (!itens.length || (discreto && revisaoDispensada)) {
        if (revisaoEl) revisaoEl.style.display = 'none';
        return;
    }

    if (!revisaoEl) {
        revisaoEl = document.createElement('div');
        revisaoEl.id = 'sm-fila-revisao';
        revisaoEl.setAttribute('role', 'status');
        revisaoEl.style.cssText = [
            'position:fixed', 'left:50%', 'transform:translateX(-50%)',
            'top:calc(12px + env(safe-area-inset-top,0px))', 'z-index:71',
            'display:flex', 'flex-direction:column', 'gap:8px',
            'max-width:min(92vw,520px)', 'padding:12px 14px', 'border-radius:12px',
            'font:500 13px/1.35 system-ui,sans-serif', 'color:#3A2A00', 'background:#FFD466',
            'box-shadow:0 10px 30px rgba(0,0,0,.25)',
        ].join(';');
        document.body.appendChild(revisaoEl);
    }

    // textContent em tudo: nada de innerHTML com dado do usuário (regra do projeto).
    revisaoEl.textContent = '';

    const topo = document.createElement('div');
    topo.style.cssText = 'display:flex;align-items:center;gap:10px';
    const titulo = document.createElement('strong');
    titulo.style.flex = '1 1 auto';
    titulo.textContent = itens.length > 1
        ? `${itens.length} lançamentos precisam de você`
        : 'Um lançamento precisa de você';
    topo.appendChild(titulo);

    const fechar = document.createElement('button');
    fechar.type = 'button';
    fechar.setAttribute('aria-label', 'Dispensar aviso');
    fechar.textContent = '×';
    fechar.style.cssText = 'border:0;background:transparent;cursor:pointer;'
        + 'font:700 18px/1 system-ui,sans-serif;color:#3A2A00;padding:0 2px';
    fechar.addEventListener('click', () => { revisaoDispensada = true; renderRevisao([]); });
    topo.appendChild(fechar);
    revisaoEl.appendChild(topo);

    itens.forEach((item) => {
        const linha = document.createElement('div');
        linha.style.cssText = 'display:flex;align-items:center;gap:8px;flex-wrap:wrap';

        const texto = document.createElement('span');
        texto.style.flex = '1 1 200px';
        const desc = (item.payload?.description || '').trim() || 'Lançamento';
        texto.textContent = item.needsFunding
            ? `${desc} (${rotuloDoValor(item.payload?.amount)}) — o saldo não cobre: escolha de onde sai o dinheiro.`
            : `${desc} (${rotuloDoValor(item.payload?.amount)}) — ${item.motivo || 'não foi possível sincronizar.'}`;
        linha.appendChild(texto);

        if (item.needsFunding) {
            linha.appendChild(botao('Escolher fonte', '#0C3D2B', () => resolverFonte(item)));
        } else {
            linha.appendChild(botao('Tentar de novo', '#0C3D2B', () => tentarDeNovo(item)));
        }

        linha.appendChild(botao('Descartar', '#8A1C1C', () => descartar(item)));
        revisaoEl.appendChild(linha);
    });

    revisaoEl.style.display = 'flex';
}

/** Abre o modal de fonte para um item retido e reenvia com a escolha. */
async function resolverFonte(item) {
    const escolha = await pedirFonte(item.fonte);
    if (!escolha) return; // cancelou: o lançamento continua retido, intacto

    let res;
    try {
        res = await enviarLancamento({ ...item.payload, ...escolha });
    } catch (_) {
        showToast('Sem conexão agora. O lançamento continua guardado.');
        return;
    }

    if (res.ok) {
        await queueDelete(item.client_uuid);
        showToast('Lançamento sincronizado.');
    } else if (res.status === 409) {
        // O disponível mudou de novo (ou o teto aprovado não cobre mais): guarda as
        // opções NOVAS e pergunta outra vez, nunca grava por conta própria.
        try {
            item.fonte = (await res.json())?.fonte || item.fonte;
        } catch (_) { /* mantém as opções anteriores */ }
        await queueAdd(item);
        showToast('O saldo mudou desde a sua escolha — confira as opções de novo.');
    } else {
        let motivo = '';
        try {
            const data = await res.json();
            motivo = flattenErrors(data && data.errors)[0] || data?.message || '';
        } catch (_) { /* corpo não-JSON */ }
        delete item.needsFunding;
        item.failed = true;
        item.motivo = motivo;
        await queueAdd(item);
        showToast(motivo || 'Não foi possível sincronizar este lançamento.');
    }

    await refreshBadge();
}

/** Devolve um item `failed` para a fila normal e tenta sincronizar de novo. */
async function tentarDeNovo(item) {
    delete item.failed;
    delete item.motivo;
    await queueAdd(item);
    await refreshBadge();
    await drain();
}

async function descartar(item) {
    const desc = (item.payload?.description || '').trim() || 'este lançamento';
    if (!window.confirm(`Descartar ${desc}? Ele nunca chegou ao servidor e será perdido para sempre.`)) return;
    await queueDelete(item.client_uuid).catch(() => {});
    await refreshBadge();
}

let dispensado = false; // "×" esconde só nesta visita, não apaga nada

function renderAvisoOutroDono(itens) {
    if (dispensado || !itens.length) {
        if (avisoOutroDono) avisoOutroDono.style.display = 'none';
        return;
    }

    if (!avisoOutroDono) {
        avisoOutroDono = document.createElement('div');
        avisoOutroDono.id = 'sm-fila-outro-dono';
        avisoOutroDono.setAttribute('role', 'status');
        avisoOutroDono.style.cssText = [
            'position:fixed', 'left:50%', 'transform:translateX(-50%)',
            'top:calc(12px + env(safe-area-inset-top,0px))', 'z-index:70',
            'display:flex', 'align-items:center', 'gap:10px', 'flex-wrap:wrap',
            'max-width:min(92vw,520px)', 'padding:10px 14px', 'border-radius:12px',
            'font:500 13px/1.35 system-ui,sans-serif', 'color:#3A2A00', 'background:#FFD466',
            'box-shadow:0 10px 30px rgba(0,0,0,.25)',
        ].join(';');
        document.body.appendChild(avisoOutroDono);
    }

    // textContent em tudo: nada de innerHTML com dado do usuário (regra do projeto).
    avisoOutroDono.textContent = '';
    const texto = document.createElement('span');
    texto.style.flex = '1 1 220px';
    texto.textContent = itens.length > 1
        ? `${itens.length} lançamentos feitos offline por outra conta estão guardados neste aparelho. Eles não serão enviados agora — quem os criou precisa entrar para sincronizar.`
        : 'Há 1 lançamento feito offline por outra conta guardado neste aparelho. Ele não será enviado agora — quem o criou precisa entrar para sincronizar.';
    avisoOutroDono.appendChild(texto);

    const descartar = document.createElement('button');
    descartar.type = 'button';
    descartar.textContent = 'Descartar';
    descartar.style.cssText = 'border:0;border-radius:999px;padding:7px 12px;cursor:pointer;'
        + 'font:600 12px/1 system-ui,sans-serif;color:#fff;background:#8A1C1C';
    descartar.addEventListener('click', () => {
        const pergunta = itens.length > 1
            ? `Descartar ${itens.length} lançamentos de outra conta? Eles nunca chegaram ao servidor e serão perdidos para sempre.`
            : 'Descartar o lançamento de outra conta? Ele nunca chegou ao servidor e será perdido para sempre.';
        if (!window.confirm(pergunta)) return;
        Promise.all(itens.map((i) => queueDelete(i.client_uuid).catch(() => {})))
            .then(() => { dispensado = true; renderAvisoOutroDono([]); });
    });
    avisoOutroDono.appendChild(descartar);

    const fechar = document.createElement('button');
    fechar.type = 'button';
    fechar.setAttribute('aria-label', 'Dispensar aviso');
    fechar.textContent = '×';
    fechar.style.cssText = 'border:0;background:transparent;cursor:pointer;'
        + 'font:700 18px/1 system-ui,sans-serif;color:#3A2A00;padding:0 2px';
    fechar.addEventListener('click', () => { dispensado = true; renderAvisoOutroDono([]); });
    avisoOutroDono.appendChild(fechar);

    avisoOutroDono.style.display = 'flex';
}

/**
 * Roda no início de cada carga logada:
 *
 *  1. ARMA os itens do próprio usuário — grava neles o token CSRF da sessão atual.
 *     Depois de um novo login o token guardado está morto; sem renovar, o
 *     Background Sync bateria 419 para sempre e só o reenvio pela página funcionaria.
 *  2. DESARMA os itens de outro dono — apaga o `csrf` deles. Duas coisas de uma vez:
 *     tira do aparelho uma credencial que é da sessão de outra pessoa, e sinaliza
 *     ao service worker (que não enxerga sessão nem localStorage) que aquele item
 *     NÃO é para ser reenviado agora — lá a regra é "sem csrf, não envio".
 *  3. AVISA na tela que existem lançamentos de outra conta guardados aqui.
 *
 * Nada é apagado. Ver o bloco de comentário acima.
 */
function revisarFilaDeOutroDono() {
    const atual = meta('sm-user');
    if (!atual) return Promise.resolve(); // ninguém logado: nada a decidir agora
    const token = meta('csrf-token');

    return queueAll().then((itens) => {
        const fila = (itens || []).filter(ehLancamento);
        const meus = fila.filter((i) => String(i.userId) === String(atual));
        const alheios = fila.filter((i) => String(i.userId) !== String(atual));

        const gravacoes = [];

        // (1) token fresco nos meus (re-arma inclusive o que foi desarmado antes).
        if (token) {
            meus.filter((i) => i.csrf !== token)
                .forEach((i) => gravacoes.push(queueAdd({ ...i, csrf: token }).catch(() => {})));
        }

        // (2) o lançamento do outro fica; a credencial dele, não.
        alheios.filter((i) => i.csrf)
            .forEach((i) => gravacoes.push(queueAdd({ ...i, csrf: null }).catch(() => {})));

        // (3) aviso visível — a pessoa decide o que fazer, o app não decide por ela.
        renderAvisoOutroDono(alheios);

        return Promise.all(gravacoes);
    }).catch(() => {});
}

// ---- Init ------------------------------------------------------------------

export function initOfflineQueue() {
    if (!('indexedDB' in window)) return;

    purgeCachedFormIfUserChanged();

    // Vale para o formulário desta carga E para o que chegar depois, pelo pjax.
    ligarFormulariosDaFila();

    // Sincroniza quando a conexão volta e ao carregar uma página logada.
    window.addEventListener('online', () => drain());
    if (meta('sm-user')) {
        // A revisão vem ANTES do reenvio, de propósito: é ela que decide o que
        // pode ser enviado nesta sessão (e o que fica trancado). Encadeado para
        // não haver corrida entre a regravação do item e o drain.
        revisarFilaDeOutroDono().then(() => {
            refreshBadge();
            if (navigator.onLine) drain();
            else requestBackgroundSync(); // reabriu offline: re-arma o reenvio em background
        });
    }
}
