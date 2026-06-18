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
//  - Só a CRIAÇÃO entra na fila (form com data-offline-queue), nunca edição.

const DB_NAME = 'sm-offline';
const STORE = 'lancamentos';

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
        badge.addEventListener('click', () => drain());
        document.body.appendChild(badge);
    }
    if (count > 0) {
        const plural = count > 1 ? 'lançamentos pendentes' : 'lançamento pendente';
        badge.textContent = `⏳ ${count} ${plural} — tocar p/ sincronizar`;
        badge.style.display = 'inline-flex';
    } else {
        badge.style.display = 'none';
    }
}

async function refreshBadge() {
    try {
        const userId = meta('sm-user');
        const items = (await queueAll()).filter((i) => String(i.userId) === String(userId));
        renderBadge(items.length);
    } catch (_) { /* sem IndexedDB: ignora silenciosamente */ }
}

// ---- Sincronização (replay) ------------------------------------------------

let draining = false;
async function drain() {
    if (draining || !navigator.onLine) return;
    const userId = meta('sm-user');
    const token = meta('csrf-token');
    if (!userId || !token) return; // não logado / sem token

    draining = true;
    try {
        const items = (await queueAll())
            .filter((i) => String(i.userId) === String(userId) && !i.failed);

        for (const item of items) {
            let res;
            try {
                res = await fetch('/transactions', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': token,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(item.payload),
                });
            } catch (_) {
                break; // caiu a rede no meio: tenta de novo no próximo online
            }

            if (res.ok) {
                await queueDelete(item.client_uuid);          // 201 criado ou 200 já existia
            } else if (res.status === 401 || res.status === 419) {
                showToast('Faça login para sincronizar seus lançamentos pendentes.');
                break;                                         // sessão/CSRF expirou: mantém na fila
            } else if (res.status === 422) {
                item.failed = true;                            // dados inválidos: não insiste em loop
                await queueAdd(item);
                showToast('Um lançamento não pôde ser sincronizado (dados inválidos).');
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
function enqueueOffline(payload, toastMsg, form) {
    return queueAdd({
        client_uuid: payload.client_uuid,
        userId: meta('sm-user'),
        csrf: meta('csrf-token'), // token p/ o service worker reenviar em background
        payload,
        createdAt: Date.now(),
    }).then(() => {
        requestBackgroundSync(); // acorda o SW p/ reenviar quando a net voltar
        showToast(toastMsg);
        if (form && typeof form.reset === 'function') form.reset();
        refreshBadge();
    }).catch(() => {
        showToast('Não foi possível salvar o lançamento offline neste aparelho.');
    });
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
async function refreshCsrfToken(form) {
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

function attachForm(form) {
    form.addEventListener('submit', (e) => {
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
                'Sem conexão — lançamento salvo; envio sozinho quando a internet voltar.',
                form
            );
            return;
        }

        // ---- Caminho ONLINE: envia por AJAX, com retry de CSRF em 419. -----
        submitOnline(form, payload);
    });
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
            'Sem conexão — lançamento salvo; envio sozinho quando a internet voltar.',
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
                'Sem conexão — lançamento salvo; envio sozinho quando a internet voltar.',
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

// ---- Init ------------------------------------------------------------------

export function initOfflineQueue() {
    if (!('indexedDB' in window)) return;

    purgeCachedFormIfUserChanged();

    const form = document.querySelector('form[data-offline-queue]');
    if (form) attachForm(form);

    // Sincroniza quando a conexão volta e ao carregar uma página logada.
    window.addEventListener('online', () => drain());
    if (meta('sm-user')) {
        refreshBadge();
        if (navigator.onLine) drain();
        else requestBackgroundSync(); // reabriu offline: re-arma o reenvio em background
    }
}
