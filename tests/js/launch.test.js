import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { flush } from './helpers/flush.js';

/**
 * Modal global "Lançar" (`resources/js/sm/launch.js`) — o que ele faz com cada
 * resposta do servidor.
 *
 * Este é o caminho de escrita mais usado do app (botão da topbar + FAB + "Nova
 * transação" do Histórico), e três das respostas que ele trata são exatamente as
 * que ninguém encontra à mão:
 *
 *  - **419** (token CSRF morto): acontece sozinho quando a pessoa troca a senha —
 *    isso derruba as outras sessões e toda aba aberta fica com token velho. Antes
 *    do tratamento, o lançamento não era gravado NEM enfileirado: sumia.
 *  - **409** (o saldo não cobre, mas há fonte): o app NUNCA escolhe cheque
 *    especial sozinho; tem de perguntar. É o invariante do modelo de dinheiro v3.
 *  - **422**: erro de preenchimento — mostra e fica na tela, sem duplicar o
 *    lançamento na fila.
 *
 * O `ModalLancarTrata419Test` (PHP) faz `file_get_contents` no módulo e procura
 * strings: prova que o código está ESCRITO, não que funciona. Aqui o `fetch` é
 * mockado e o que se observa é o comportamento — quantos POSTs saíram, com qual
 * `_token`, o que foi para a fila e com que aviso.
 *
 * `refreshCsrfToken` NÃO é mockada de propósito: é ela que faz o GET
 * `/csrf-token`, e é o resultado desse GET que separa "sessão viva" de "sessão
 * morta". Mockar a função apagaria justamente a bifurcação sob teste. Já
 * `enfileirarLancamento` (IndexedDB) e `pedirFonte` (modal de fonte) são dublês:
 * pertencem a outros módulos, com contrato próprio.
 */

const mocks = vi.hoisted(() => ({
    pedirFonte: vi.fn(),
    enfileirarLancamento: vi.fn(async () => true),
}));

vi.mock('../../resources/js/sm/funding.js', () => ({
    pedirFonte: mocks.pedirFonte,
}));

vi.mock('../../resources/js/sm/offline-queue.js', async (importOriginal) => ({
    ...(await importOriginal()),
    enfileirarLancamento: mocks.enfileirarLancamento,
}));

import { initLaunch } from '../../resources/js/sm/launch.js';

const TOKEN_VELHO = 'token-da-sessao-antiga';
const TOKEN_FRESCO = 'token-da-sessao-viva';
const MSG_SESSAO = 'Sua sessão expirou — lançamento na fila. Entre de novo para sincronizar.';

/** Envelope mínimo de `Response` que o módulo consome. */
function resposta(status, corpo = {}) {
    return { status, ok: status >= 200 && status < 300, json: async () => corpo };
}

/** Corpo de um 409: o que o `SpendingGuard` manda para o modal de fonte. */
function corpo409() {
    return {
        precisa_fonte: true,
        fonte: {
            faltante: 120,
            opcoes: [{ id: 'cheque_especial', rotulo: 'Cheque especial', cobre: true }],
        },
    };
}

let respostasDoPost;
let respostaDoToken;
let chamadas;

/** Monta o modal como o `partials/launch-modal.blade.php` o entrega e liga o JS. */
function montarModal() {
    document.head.innerHTML = `<meta name="csrf-token" content="${TOKEN_VELHO}">`;
    document.body.innerHTML = `
        <button type="button" data-launch-open>Lançar</button>
        <div class="modal-scrim" id="launchModal">
            <div class="modal">
                <div class="flash-error" data-lm-error hidden><span data-lm-error-msg></span></div>
                <form action="/transactions" data-launch-form data-type="income"
                      data-store-action="/transactions" data-transfer-action="/transactions/transferir">
                    <input type="hidden" name="_token" value="${TOKEN_VELHO}">
                    <input type="radio" id="lm-tt-income" name="type" value="income" checked>
                    <input type="radio" id="lm-tt-expense" name="type" value="expense">
                    <input type="text" inputmode="decimal" id="lm-amount" name="amount">
                    <input type="date" id="lm-date" name="date" value="2026-09-07">
                    <select id="lm-account" name="account_id">
                        <option value="7" data-card="0" data-cash="1" data-saldo="R$ 10,00"
                                data-saldo-rotulo="disponível" data-negativo="0">Corrente</option>
                    </select>
                    <span class="lm-saldo" data-lm-saldo hidden></span>
                    <select id="lm-category" name="category_id">
                        <option value="">Selecione a categoria</option>
                        <optgroup label="Receitas" data-type="income">
                            <option value="1" data-type="income">Salário</option>
                        </optgroup>
                        <optgroup label="Despesas" data-type="expense">
                            <option value="2" data-type="expense">Mercado</option>
                        </optgroup>
                    </select>
                    <input type="text" id="lm-description" name="description">
                    <button type="button" data-close-btn>Cancelar</button>
                    <button type="submit" data-lm-save><span class="btn-label">Salvar</span></button>
                </form>
            </div>
        </div>`;

    initLaunch();
}

const modal = () => document.getElementById('launchModal');
const form = () => document.querySelector('[data-launch-form]');
const botaoSalvar = () => document.querySelector('[data-lm-save]');
const erroVisivel = () => !document.querySelector('[data-lm-error]').hidden;
const mensagemDeErro = () => document.querySelector('[data-lm-error-msg]').textContent;

/**
 * Abre o modal pelo gatilho de verdade e preenche uma DESPESA de R$ 150,00.
 *
 * Despesa e não receita porque é a despesa que passa pelo `FundingService` — é
 * dela que saem o 409 e o resto do que se testa aqui.
 */
function abrirComDespesa(valor = '150,00') {
    document.querySelector('[data-launch-open]').click();

    const expense = document.getElementById('lm-tt-expense');
    expense.checked = true;
    // O handler do radio ZERA o valor, então o preenchimento vem depois dele.
    expense.dispatchEvent(new Event('change', { bubbles: true }));

    document.getElementById('lm-amount').value = valor;
    document.getElementById('lm-category').value = '2';
}

/** Dispara o submit e espera o handler `async` terminar. */
async function salvar() {
    form().dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flush();
}

/** Liga/desliga a conexão para o `navigator.onLine` que o módulo consulta. */
function definirConexao(online) {
    Object.defineProperty(window.navigator, 'onLine', { configurable: true, get: () => online });
}

beforeEach(() => {
    vi.clearAllMocks();
    mocks.enfileirarLancamento.mockImplementation(async () => true);
    mocks.pedirFonte.mockResolvedValue(null);

    respostasDoPost = [];
    respostaDoToken = resposta(200, { token: TOKEN_FRESCO });
    chamadas = { posts: [], token: 0, excedentes: 0 };
    definirConexao(true);
    window.smPjaxReload = vi.fn();

    global.fetch = vi.fn(async (url, init = {}) => {
        if (String(url).includes('/csrf-token')) {
            chamadas.token++;
            return respostaDoToken;
        }

        // Snapshot do FormData: `enviar()` reusa a MESMA instância a cada
        // tentativa, então guardar a referência mostraria o estado FINAL em todas
        // as chamadas — e o teste do "reescreveu o _token" passaria de graça.
        chamadas.posts.push(Object.fromEntries(init.body.entries()));

        const proxima = respostasDoPost.shift();
        if (!proxima) chamadas.excedentes++;

        return proxima || resposta(500);
    });

    montarModal();
});

afterEach(() => {
    delete window.smPjaxReload;
});

describe('sucesso', () => {
    it('201 fecha o modal e recarrega a página por pjax', async () => {
        respostasDoPost = [resposta(201)];
        abrirComDespesa();
        expect(modal().classList.contains('open')).toBe(true);

        await salvar();

        expect(chamadas.posts).toHaveLength(1);
        // FECHA antes de recarregar: o pjax troca só o #content e o modal vive no
        // shell, então sem o close() ele ficaria por cima do lançamento novo.
        expect(modal().classList.contains('open')).toBe(false);
        expect(window.smPjaxReload).toHaveBeenCalledTimes(1);
        expect(mocks.enfileirarLancamento).not.toHaveBeenCalled();
        expect(botaoSalvar().disabled).toBe(false);
    });

    it('manda o lançamento com client_uuid e o renova a cada gravação', async () => {
        respostasDoPost = [resposta(201), resposta(201)];

        abrirComDespesa('150,00');
        await salvar();
        abrirComDespesa('20,00');
        await salvar();

        const [primeiro, segundo] = chamadas.posts;
        expect(primeiro.client_uuid).toBeTruthy();
        expect(primeiro.amount).toBe('150,00');
        expect(segundo.amount).toBe('20,00');
        // Chave nova para lançamento novo — senão o dedupe do servidor engoliria
        // o segundo como se fosse repetição do primeiro.
        expect(segundo.client_uuid).not.toBe(primeiro.client_uuid);
    });
});

describe('client_uuid — uma chave por ABERTURA do modal', () => {
    /**
     * O servidor deduplica SÓ pela chave: `TransactionController::store` acha o
     * `client_uuid` na família e devolve a linha que já existe (200), sem comparar
     * valor, conta nem descrição. A chave, portanto, tem de identificar UM lançamento.
     *
     * O defeito aparece quando um envio termina sem resposta de sucesso mas FOI gravado:
     * o 504 do nginx (`proxy_read_timeout` de 60 s, contra até três esperas de trava de
     * ~50 s no `FundingService`) ou o "Cancelar" apertado com o spinner girando — o POST
     * segue para o servidor. A pessoa reabre o modal e lança OUTRA coisa; com a chave de
     * antes, o servidor responde "já existia" com o lançamento ANTERIOR, o modal fecha
     * como se tivesse salvo, e o novo nunca é gravado.
     */
    it('reabrir depois de um envio sem sucesso gera chave nova para o próximo lançamento', async () => {
        // 504 do proxy: o PHP pode ter seguido e gravado depois que o nginx desistiu.
        respostasDoPost = [resposta(504), resposta(200)];

        abrirComDespesa('150,00');
        await salvar();
        expect(erroVisivel()).toBe(true);

        document.querySelector('[data-close-btn]').click();
        abrirComDespesa('20,00');
        await salvar();

        const [primeiro, segundo] = chamadas.posts;
        expect(segundo.amount).toBe('20,00');
        // Com a mesma chave, o 200 acima seria o servidor devolvendo o lançamento de
        // R$ 150,00 — e o de R$ 20,00 sumiria com o modal dizendo que salvou.
        expect(segundo.client_uuid).not.toBe(primeiro.client_uuid);
    });

    it('"Cancelar" com o envio em voo e lançar outro não reaproveita a chave do que ainda está a caminho', async () => {
        let responderPrimeiro;
        respostasDoPost = [new Promise((r) => { responderPrimeiro = r; }), resposta(201)];

        // Digitou R$ 1.500,00 por engano e salvou; a rede está lenta.
        abrirComDespesa('1.500,00');
        form().dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await flush();

        // "Cancelar" com o spinner girando: o modal fecha, o POST continua.
        document.querySelector('[data-close-btn]').click();
        abrirComDespesa('150,00');
        await salvar();

        responderPrimeiro(resposta(201));
        await flush();

        const [emVoo, corrigido] = chamadas.posts;
        expect(corrigido.amount).toBe('150,00');
        // Mesma chave = o servidor grava um dos dois e devolve ESSE como resposta do
        // outro: o valor corrigido nunca é gravado, e o modal diz que salvou.
        expect(corrigido.client_uuid).not.toBe(emVoo.client_uuid);
    });

    it('na MESMA abertura, salvar de novo depois de um erro repete a chave (é o mesmo lançamento)', async () => {
        respostasDoPost = [resposta(504), resposta(200)];

        abrirComDespesa('150,00');
        await salvar();
        await salvar();

        // Aqui a repetição é o que impede a duplicata: se o primeiro envio foi gravado
        // apesar do 504, o segundo recebe o mesmo lançamento de volta.
        expect(chamadas.posts).toHaveLength(2);
        expect(chamadas.posts[1].client_uuid).toBe(chamadas.posts[0].client_uuid);
    });
});

describe('envio cancelado com o modal reaberto (outro lançamento na tela)', () => {
    /**
     * "Cancelar" com o spinner girando fecha o modal, mas o POST segue. Se a pessoa reabre e
     * começa OUTRO lançamento, a resposta do antigo não pode mexer no modal: antes ela chegava
     * e fechava e zerava o lançamento novo que estava sendo digitado — ou mostrava o erro, ou
     * perguntava a fonte, de um lançamento que não é o da tela.
     */
    async function cancelarEmVooEReabrir(respostaDoAntigo) {
        let responder;
        respostasDoPost = [new Promise((r) => { responder = r; })];

        abrirComDespesa('1.500,00');
        form().dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await flush();

        document.querySelector('[data-close-btn]').click();
        abrirComDespesa('150,00');

        responder(respostaDoAntigo);
        await flush();
    }

    it('gravado: a tela recarrega os dados, e o lançamento novo continua aberto e intacto', async () => {
        await cancelarEmVooEReabrir(resposta(201));

        expect(modal().classList.contains('open')).toBe(true);
        expect(document.getElementById('lm-amount').value).toBe('150,00');
        expect(botaoSalvar().disabled).toBe(false);
        expect(window.smPjaxReload).toHaveBeenCalledTimes(1);
    });

    it('409: não pergunta a fonte de um lançamento que a pessoa cancelou', async () => {
        await cancelarEmVooEReabrir(resposta(409, corpo409()));

        expect(mocks.pedirFonte).not.toHaveBeenCalled();
        expect(modal().classList.contains('open')).toBe(true);
        expect(window.smPjaxReload).not.toHaveBeenCalled();
    });

    it('422: o erro do antigo não aparece no lançamento novo', async () => {
        await cancelarEmVooEReabrir(resposta(422, { errors: { amount: ['Valor inválido.'] } }));

        expect(erroVisivel()).toBe(false);
        expect(document.getElementById('lm-amount').value).toBe('150,00');
    });

    it('rede caiu no antigo: não vai para a fila (a pessoa desistiu dele)', async () => {
        let falhar;
        respostasDoPost = [new Promise((_, rejeitar) => { falhar = rejeitar; })];

        abrirComDespesa('1.500,00');
        form().dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await flush();
        document.querySelector('[data-close-btn]').click();
        abrirComDespesa('150,00');

        falhar(new TypeError('Failed to fetch'));
        await flush();

        expect(mocks.enfileirarLancamento).not.toHaveBeenCalled();
        expect(modal().classList.contains('open')).toBe(true);
    });
});

describe('419 — token CSRF morto', () => {
    it('com a sessão viva: busca token fresco, reescreve o _token e refaz UMA vez', async () => {
        respostasDoPost = [resposta(419), resposta(201)];

        abrirComDespesa();
        await salvar();

        expect(chamadas.token).toBe(1);
        expect(chamadas.posts).toHaveLength(2);
        expect(chamadas.posts[0]._token).toBe(TOKEN_VELHO);
        expect(chamadas.posts[1]._token).toBe(TOKEN_FRESCO);
        // Mesmo lançamento, não um segundo: é o `client_uuid` que impede o
        // servidor de gravar duas linhas se o primeiro POST tiver chegado.
        expect(chamadas.posts[1].client_uuid).toBe(chamadas.posts[0].client_uuid);
        expect(window.smPjaxReload).toHaveBeenCalledTimes(1);
        expect(mocks.enfileirarLancamento).not.toHaveBeenCalled();
    });

    it('o token fresco também fica no <meta> e no _token do form, para os próximos envios', async () => {
        respostasDoPost = [resposta(419), resposta(201)];

        abrirComDespesa();
        await salvar();

        expect(document.querySelector('meta[name="csrf-token"]').getAttribute('content')).toBe(TOKEN_FRESCO);
        expect(form().querySelector('input[name="_token"]').value).toBe(TOKEN_FRESCO);
    });

    it('com a sessão morta (/csrf-token responde 401): não refaz e manda para a FILA', async () => {
        respostasDoPost = [resposta(419)];
        respostaDoToken = resposta(401);

        abrirComDespesa();
        await salvar();

        expect(chamadas.token).toBe(1);
        expect(chamadas.posts).toHaveLength(1); // sem token novo não há o que reenviar
        expect(mocks.enfileirarLancamento).toHaveBeenCalledTimes(1);
        // Nada foi gravado: recarregar a tela seria dizer que foi.
        expect(window.smPjaxReload).not.toHaveBeenCalled();
    });

    it('o aviso diz que a sessão expirou — NUNCA "salvo"', async () => {
        respostasDoPost = [resposta(419)];
        respostaDoToken = resposta(401);

        abrirComDespesa();
        await salvar();

        const [, aviso] = mocks.enfileirarLancamento.mock.calls[0];
        expect(aviso).toBe(MSG_SESSAO);
        expect(aviso).toMatch(/sessão expirou/);
        expect(aviso.toLowerCase()).not.toMatch(/salvo|sucesso/);
    });

    it('se o retry bater 419 de novo, não busca outro token: vai para a fila', async () => {
        respostasDoPost = [resposta(419), resposta(419)];

        abrirComDespesa();
        await salvar();

        expect(chamadas.token).toBe(1); // uma tentativa só, sem laço
        expect(chamadas.posts).toHaveLength(2);
        expect(mocks.enfileirarLancamento).toHaveBeenCalledWith(expect.anything(), MSG_SESSAO);
    });

    it('o que vai para a fila é o lançamento, sem o _token da sessão morta', async () => {
        respostasDoPost = [resposta(419)];
        respostaDoToken = resposta(401);

        abrirComDespesa();
        await salvar();

        const [payload] = mocks.enfileirarLancamento.mock.calls[0];
        expect(payload).toMatchObject({ type: 'expense', amount: '150,00', account_id: '7' });
        expect(payload.client_uuid).toBeTruthy();
        // `_token`/`_method` são controle do Laravel: o reenvio usa o token da
        // sessão viva na hora de sincronizar, não este, que já morreu.
        expect(payload).not.toHaveProperty('_token');
        expect(payload).not.toHaveProperty('_method');
    });
});

describe('409 — de onde sai esse dinheiro', () => {
    /**
     * O servidor recalcula na hora de gravar: se o disponível caiu desde a pergunta, o valor
     * aprovado ficou pequeno e ele devolve OUTRO 409, com as opções recalculadas. Antes o
     * modal mostrava "Confira os campos" — agora pergunta de novo, com os números novos, e a
     * escolha anterior não sobra no reenvio.
     */
    it('se o 409 voltar depois da escolha, pergunta de novo com as opções recalculadas', async () => {
        const recalculado = { precisa_fonte: true, fonte: { faltante: 300, opcoes: [{ id: 'cheque_especial', cobre: true }] } };
        respostasDoPost = [resposta(409, corpo409()), resposta(409, recalculado), resposta(201)];
        mocks.pedirFonte
            .mockResolvedValueOnce({ funding_source: 'resgate_investimento', funding_investment_id: '3', funding_max_amount: '120.00' })
            .mockResolvedValueOnce({ funding_source: 'cheque_especial', funding_max_amount: '300.00' });

        abrirComDespesa();
        await salvar();

        expect(mocks.pedirFonte).toHaveBeenCalledTimes(2);
        expect(mocks.pedirFonte.mock.calls[1][0]).toEqual(recalculado.fonte);
        expect(chamadas.posts).toHaveLength(3);
        expect(chamadas.posts[2]).toMatchObject({ funding_source: 'cheque_especial', funding_max_amount: '300.00' });
        // Do resgate escolhido antes não sobra nada.
        expect(chamadas.posts[2]).not.toHaveProperty('funding_investment_id');
        // Mesmo lançamento do começo ao fim.
        expect(new Set(chamadas.posts.map((p) => p.client_uuid)).size).toBe(1);
        expect(erroVisivel()).toBe(false);
        expect(window.smPjaxReload).toHaveBeenCalledTimes(1);
    });

    it('cancelar na segunda pergunta não grava nada', async () => {
        respostasDoPost = [resposta(409, corpo409()), resposta(409, corpo409())];
        mocks.pedirFonte
            .mockResolvedValueOnce({ funding_source: 'cheque_especial', funding_max_amount: '120.00' })
            .mockResolvedValueOnce(null);

        abrirComDespesa();
        await salvar();

        expect(chamadas.posts).toHaveLength(2);
        expect(window.smPjaxReload).not.toHaveBeenCalled();
        expect(mocks.enfileirarLancamento).not.toHaveBeenCalled();
        expect(botaoSalvar().disabled).toBe(false);
    });

    it('pergunta a fonte e, se o usuário cancelar, NÃO grava nada sozinho', async () => {
        respostasDoPost = [resposta(409, corpo409())];
        mocks.pedirFonte.mockResolvedValue(null);

        abrirComDespesa();
        await salvar();

        // O "Salvar" vai junto como `retorno`: fechando o de fonte, é nele que o foco volta
        // (o Chrome tira o foco do botão enquanto ele está desabilitado no envio).
        expect(mocks.pedirFonte).toHaveBeenCalledWith(corpo409().fonte, { retorno: botaoSalvar() });
        // O invariante do modelo v3: o app nunca decide cheque especial por conta
        // própria. Cancelou, nada acontece — nem POST, nem fila.
        expect(chamadas.posts).toHaveLength(1);
        expect(mocks.enfileirarLancamento).not.toHaveBeenCalled();
        expect(window.smPjaxReload).not.toHaveBeenCalled();
        // Modal fica aberto e destravado: o lançamento digitado continua na tela.
        expect(modal().classList.contains('open')).toBe(true);
        expect(botaoSalvar().disabled).toBe(false);
    });

    it('com a escolha, reenvia o MESMO lançamento acrescido da fonte', async () => {
        respostasDoPost = [resposta(409, corpo409()), resposta(201)];
        mocks.pedirFonte.mockResolvedValue({
            funding_source: 'resgate_investimento',
            funding_investment_id: '3',
            funding_max_amount: '120.00',
        });

        abrirComDespesa();
        await salvar();

        expect(chamadas.posts).toHaveLength(2);
        expect(chamadas.posts[1]).toMatchObject({
            funding_source: 'resgate_investimento',
            funding_investment_id: '3',
            // Teto do que o usuário aprovou: sob lock o servidor recalcula o
            // faltante e, se ele estourar isto, devolve 409 em vez de sacar mais.
            funding_max_amount: '120.00',
        });
        expect(chamadas.posts[1].client_uuid).toBe(chamadas.posts[0].client_uuid);
        expect(chamadas.posts[1].amount).toBe('150,00');
        expect(window.smPjaxReload).toHaveBeenCalledTimes(1);
    });

    it('não pergunta a fonte quando a resposta é 201', async () => {
        respostasDoPost = [resposta(201)];

        abrirComDespesa();
        await salvar();

        expect(mocks.pedirFonte).not.toHaveBeenCalled();
    });
});

describe('422 e outros erros', () => {
    it('422 mostra a mensagem de validação e NÃO enfileira', async () => {
        respostasDoPost = [resposta(422, { errors: { amount: ['O valor deve ser maior que zero.'] } })];

        abrirComDespesa();
        await salvar();

        expect(erroVisivel()).toBe(true);
        expect(mensagemDeErro()).toBe('O valor deve ser maior que zero.');
        // Enfileirar aqui criaria um lançamento inválido que o servidor recusaria
        // para sempre, sem ninguém ver.
        expect(mocks.enfileirarLancamento).not.toHaveBeenCalled();
        expect(modal().classList.contains('open')).toBe(true);
        expect(botaoSalvar().disabled).toBe(false);
    });

    it('422 sem lista de erros cai na mensagem do servidor', async () => {
        respostasDoPost = [resposta(422, { message: 'Essa conta não é sua.' })];

        abrirComDespesa();
        await salvar();

        expect(mensagemDeErro()).toBe('Essa conta não é sua.');
    });

    it('erro genérico (500) mostra aviso e não enfileira', async () => {
        respostasDoPost = [resposta(500)];

        abrirComDespesa();
        await salvar();

        expect(erroVisivel()).toBe(true);
        expect(mensagemDeErro()).toMatch(/Não foi possível salvar/);
        expect(mocks.enfileirarLancamento).not.toHaveBeenCalled();
    });
});

describe('sem rede', () => {
    it('offline nem tenta o POST: manda direto para a fila', async () => {
        definirConexao(false);

        abrirComDespesa();
        await salvar();

        expect(global.fetch).not.toHaveBeenCalled();
        expect(mocks.enfileirarLancamento).toHaveBeenCalledTimes(1);
        // Sem mensagem própria: vale o padrão da fila ("na fila", nunca "salvo").
        expect(mocks.enfileirarLancamento.mock.calls[0][1]).toBeUndefined();
        expect(modal().classList.contains('open')).toBe(false);
    });

    it('a rede cai no meio do envio: o lançamento não se perde', async () => {
        global.fetch = vi.fn(async () => { throw new TypeError('Failed to fetch'); });

        abrirComDespesa();
        await salvar();

        expect(mocks.enfileirarLancamento).toHaveBeenCalledTimes(1);
        expect(modal().classList.contains('open')).toBe(false);
    });

    it('se nem a fila aceitar (IndexedDB indisponível), o modal FICA aberto avisando', async () => {
        definirConexao(false);
        mocks.enfileirarLancamento.mockResolvedValue(false);

        abrirComDespesa();
        await salvar();

        // Fechar aqui perderia o lançamento em silêncio: não está no servidor nem
        // no aparelho. O que resta é manter a tela e o que foi digitado.
        expect(modal().classList.contains('open')).toBe(true);
        expect(erroVisivel()).toBe(true);
        expect(mensagemDeErro()).toMatch(/Não deu para guardar/);
        expect(document.getElementById('lm-amount').value).toBe('150,00');
    });
});

describe('é um diálogo (achado A-2 da auditoria de acessibilidade)', () => {
    const gatilho = () => document.querySelector('[data-launch-open]');
    const tecla = (key) => document.activeElement.dispatchEvent(
        new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }),
    );

    it('abre com o foco no Valor e o resto da página inerte', () => {
        gatilho().click();

        expect(modal().classList.contains('open')).toBe(true);
        expect(document.activeElement).toBe(document.getElementById('lm-amount'));
        // O botão da topbar (irmão do modal, como no shell) sai do alcance do Tab.
        expect(gatilho().hasAttribute('inert')).toBe(true);
        expect(modal().hasAttribute('inert')).toBe(false);
    });

    it('Esc fecha e devolve o foco ao botão que abriu, sem nada inerte', () => {
        gatilho().click();

        tecla('Escape');

        expect(modal().classList.contains('open')).toBe(false);
        expect(document.activeElement).toBe(gatilho());
        expect(document.querySelectorAll('[inert]')).toHaveLength(0);
    });

    it('Tab no "Salvar" volta ao primeiro controle, em vez de sair do modal', () => {
        gatilho().click();
        botaoSalvar().focus();

        tecla('Tab');

        // Não há X neste DOM de teste: o primeiro controle é o tipo (Receita marcada).
        expect(document.activeElement).toBe(document.getElementById('lm-tt-income'));
    });

    it('depois de salvar, fecha e o foco volta ao gatilho', async () => {
        respostasDoPost = [resposta(201)];
        abrirComDespesa();

        await salvar();

        expect(modal().classList.contains('open')).toBe(false);
        expect(document.activeElement).toBe(gatilho());
        expect(document.querySelectorAll('[inert]')).toHaveLength(0);
    });
});
