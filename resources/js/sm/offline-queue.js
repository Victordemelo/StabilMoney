// Fila de lançamentos offline (Fase 2 do PWA).
//
// Quando o usuário salva um lançamento SEM conexão, guardamos no IndexedDB com
// um client_uuid e reenviamos sozinho quando a internet volta. O servidor é
// idempotente (dedupe por client_uuid), então um replay repetido não duplica.
//
// Decisões de segurança/robustez (ver spec):
//  - Replay é DIRIGIDO PELA PÁGINA (não pelo service worker) para usar o token
//    CSRF fresco do <meta>. O CSRF continua ligado, sem endpoint isento.
//  - Cada item é marcado com o id do usuário (meta sm-user); só sincronizamos
//    itens do usuário logado — num aparelho compartilhado, ninguém reenvia
//    lançamento de outro.
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

function attachForm(form) {
    form.addEventListener('submit', (e) => {
        // Só interceptamos quando está claramente OFFLINE. Online segue o fluxo
        // normal (POST + redirect do servidor), sem mudar nada do comportamento.
        if (navigator.onLine) return;

        e.preventDefault();
        const payload = serializeForm(form);
        payload.client_uuid = uuid();

        queueAdd({
            client_uuid: payload.client_uuid,
            userId: meta('sm-user'),
            payload,
            createdAt: Date.now(),
        }).then(() => {
            showToast('Sem conexão — lançamento salvo e será enviado quando você voltar a ficar online.');
            form.reset();
            refreshBadge();
        }).catch(() => {
            showToast('Não foi possível salvar o lançamento offline neste aparelho.');
        });
    });
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
    }
}
