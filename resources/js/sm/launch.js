/* ============ StabilMoney — Modal global "Lançar" (nova transação) ============ */
// Abre/fecha com a animação do .modal-scrim (open E close suaves). Type-toggle
// (receita/despesa) filtra as categorias; envia por AJAX (fetch → JSON) com
// spinner no "Salvar"; sucesso recarrega a página (via pjax, se houver) para
// refletir a transação; erro treme o modal e mostra a mensagem. Sem reload no erro.
//
// OFFLINE: sem internet o envio vai para a MESMA fila do formulário cheio
// (offline-queue.js), em vez de morrer num "sem conexão" que fazia o usuário
// perder o que digitou. Como o modal é o caminho mais usado do app (botão da
// topbar + FAB), sem isso "lançar offline" praticamente não existia.

import { pedirFonte } from './funding';
import { enfileirarLancamento, refreshCsrfToken } from './offline-queue';

// FormData → objeto simples, que é o formato que a fila reenvia (JSON).
// `_token`/`_method` ficam de fora: são controle do Laravel, não do lançamento —
// e o CSRF do reenvio é sempre o da sessão viva na hora de sincronizar.
function paraJson(fd) {
    const obj = {};
    fd.forEach((valor, chave) => {
        if (chave === '_token' || chave === '_method') return;
        obj[chave] = valor;
    });
    return obj;
}

export function initLaunch() {
    const modal = document.getElementById('launchModal');
    if (!modal) return;

    const card = modal.querySelector('.modal');
    const form = modal.querySelector('[data-launch-form]');
    const errorBox = modal.querySelector('[data-lm-error]');
    const errorMsg = modal.querySelector('[data-lm-error-msg]');
    const saveBtn = modal.querySelector('[data-lm-save]');

    const hideError = () => { if (errorBox) errorBox.hidden = true; };
    const setSaving = (on) => {
        if (!saveBtn) return;
        saveBtn.classList.toggle('is-loading', on);
        saveBtn.disabled = on;
    };
    const shake = () => {
        if (!card) return;
        card.classList.remove('shake');
        void card.offsetWidth; // reflow p/ reiniciar
        card.classList.add('shake');
    };
    const showError = (msg) => {
        if (errorMsg) errorMsg.textContent = msg;
        if (errorBox) errorBox.hidden = false;
        shake();
    };

    const open = () => {
        hideError();
        setSaving(false);
        modal.classList.add('open');
        const amount = modal.querySelector('#lm-amount');
        if (amount) setTimeout(() => amount.focus(), 80);
    };
    const close = () => modal.classList.remove('open');

    // Abrir: botão "Lançar" (topbar), FAB (bottom-nav) e "Nova transação" (Histórico).
    // O href de cada um fica como fallback sem JS.
    //
    // DELEGAÇÃO no document, não bind elemento a elemento: `initLaunch` roda UMA vez
    // (o modal vive no shell), mas há gatilhos DENTRO do #content — e o pjax troca o
    // #content inteiro. Com bind direto, o botão do Histórico funcionava no primeiro
    // carregamento e virava um link comum depois de qualquer navegação.
    document.addEventListener('click', (e) => {
        const gatilho = e.target.closest?.('[data-launch-open]');
        if (!gatilho) return;
        e.preventDefault();
        open();
    });

    // Fechar: clique no fundo, botões de fechar, Esc — tudo com transição do scrim.
    modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
    modal.querySelectorAll('[data-close-btn]').forEach((b) => b.addEventListener('click', close));
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.classList.contains('open')) close();
    });
    if (card) {
        card.addEventListener('animationend', (e) => {
            if (e.animationName === 'sm-shake') card.classList.remove('shake');
        });
    }

    if (!form) return; // estado "crie uma conta primeiro" não tem form

    // Type-toggle: cor da pílula/accent + filtro das categorias E das contas
    // pelo tipo escolhido (escopado ao modal).
    const radios = form.querySelectorAll('input[name="type"]');
    const select = form.querySelector('#lm-category');
    const contaSel = form.querySelector('#lm-account');
    const applyType = () => {
        const marcado = form.querySelector('input[name="type"]:checked');
        const tipo = marcado ? marcado.value : 'expense';
        form.dataset.type = tipo;
        if (card) card.dataset.type = tipo;

        if (select) {
            select.querySelectorAll('optgroup').forEach((g) => {
                const ativo = g.dataset.type === tipo;
                g.hidden = !ativo;
                g.querySelectorAll('option').forEach((opt) => {
                    opt.hidden = !ativo;
                    opt.disabled = !ativo;
                    if (!ativo && opt.selected) select.value = '';
                });
            });
        }

        // RECEITA não entra em cartão de crédito — some as opções de cartão e,
        // se uma delas estava escolhida, cai na primeira conta válida.
        if (contaSel) {
            let trocar = false;
            contaSel.querySelectorAll('option').forEach((opt) => {
                const soDespesa = opt.dataset.card === '1' && tipo === 'income';
                opt.hidden = soDespesa;
                opt.disabled = soDespesa;
                if (soDespesa && opt.selected) trocar = true;
            });
            if (trocar) {
                const valida = Array.from(contaSel.options).find((o) => !o.disabled);
                if (valida) contaSel.value = valida.value;
            }
        }
    };
    radios.forEach((r) => r.addEventListener('change', applyType));
    applyType();

    // Idempotência: o mesmo lançamento pode ser reenviado (duplo toque, retry de
    // rede, confirmação da escolha de fonte). O servidor deduplica por
    // client_uuid, então geramos um por ABERTURA do modal e só trocamos depois
    // de um envio bem-sucedido.
    let clientUuid = novoUuid();

    function novoUuid() {
        if (window.crypto?.randomUUID) return window.crypto.randomUUID();
        // Fallback p/ navegador sem randomUUID (contexto não-seguro).
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
            const r = (Math.random() * 16) | 0;
            return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
        });
    }

    // Envio por AJAX.
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        hideError();
        setSaving(true);

        const payload = new FormData(form);
        payload.set('client_uuid', clientUuid);

        const enviar = () => fetch(form.action, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: payload,
        });

        // Manda o lançamento para a fila offline e conta a VERDADE ao usuário:
        // está pendente de envio, não salvo. Nada de "sucesso" aqui — o toast da
        // fila diz "na fila" e o selo de pendências fica visível até sincronizar.
        const enfileirar = async (msgFila) => {
            const guardou = await enfileirarLancamento(paraJson(payload), msgFila);
            setSaving(false);
            if (!guardou) {
                // IndexedDB indisponível/cheio: o lançamento se perderia em
                // silêncio se a gente fechasse o modal — então fica na tela.
                showError('Não deu para guardar o lançamento neste aparelho. Mantenha esta tela aberta e tente de novo.');
                return;
            }
            clientUuid = novoUuid(); // este lançamento já tem chave própria na fila
            form.reset();
            applyType();
            // Fecha: o .modal-scrim (z-index 90) cobre o toast da fila.
            close();
        };

        // Sem rede: nem tenta o POST.
        if (!navigator.onLine) {
            await enfileirar();
            return;
        }

        let resp;
        try {
            resp = await enviar();
        } catch (_) {
            // A rede caiu no meio do envio: mesmo destino, nada se perde.
            await enfileirar();
            return;
        }

        // 409 = o saldo não cobre, mas há fonte. Pergunta e reenvia a MESMA
        // requisição (mesmo client_uuid) com a escolha do usuário.
        if (resp.status === 409) {
            setSaving(false);
            const dados = await resp.json().catch(() => ({}));
            const escolha = await pedirFonte(dados.fonte);
            if (!escolha) return; // cancelou

            Object.entries(escolha).forEach(([k, v]) => payload.set(k, v));
            setSaving(true);
            try {
                resp = await enviar();
            } catch (_) {
                // Caiu a rede depois da escolha: enfileira JÁ COM a fonte
                // escolhida. A fila só decide sozinha por cheque especial; um
                // resgate de investimento nunca é automático — mas este aqui foi
                // o próprio usuário quem pediu, então vai junto no payload.
                await enfileirar();
                return;
            }
        }

        // 419 = token CSRF morto. Não é erro de preenchimento, e cair no texto de
        // validação ("confira os campos") numa tela sem campo errado deixava o
        // usuário reenviando para sempre — e o lançamento se perdia, nem gravado nem
        // enfileirado. Acontece com ou sem PWA: trocar a senha derruba as outras
        // sessões, e qualquer aba aberta fica com token velho (a página vinda do
        // cache do service worker é só um dos casos).
        //
        // Mesmo tratamento do formulário cheio: busca um token fresco e refaz UMA vez.
        if (resp.status === 419) {
            const fresco = await refreshCsrfToken(form);

            if (fresco) {
                payload.set('_token', fresco);
                try {
                    resp = await enviar();
                } catch (_) {
                    await enfileirar();
                    return;
                }
            }

            // Sessão morreu de vez (ou o retry bateu 419 de novo): o lançamento NÃO
            // se perde — vai para a fila e sobe depois do login.
            if (resp.status === 419) {
                await enfileirar('Sua sessão expirou — lançamento na fila. Entre de novo para sincronizar.');
                return;
            }
        }

        if (resp.ok) {
            // Salvou: o próximo lançamento é outro, então renova a chave.
            clientUuid = novoUuid();
            // FECHA antes de recarregar. O recarregamento é por pjax, que troca só o
            // #content — o modal vive no shell e SOBREVIVE. Sem isto ele ficava
            // aberto por cima do resultado, escondendo justamente o lançamento que
            // acabou de entrar. (Com `location.reload()` sumiria por tabela, mas o
            // caminho normal é o pjax.)
            setSaving(false);
            form.reset();
            applyType();
            close();
            // Transação criada — recarrega a página atual p/ refletir os novos dados.
            if (typeof window.smPjaxReload === 'function') window.smPjaxReload();
            else window.location.reload();
            return;
        }

        setSaving(false);
        let msg = 'Não foi possível salvar. Confira os campos e tente de novo.';
        if (resp.status === 422) {
            const data = await resp.json().catch(() => ({}));
            const errs = data?.errors || {};
            msg = Object.values(errs)[0]?.[0] || data?.message || msg;
        }
        showError(msg);
    });
}
