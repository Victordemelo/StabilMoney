// Vitrine da página inicial (resources/views/inicio.blade.php, `[data-vitrine]`).
//
// No lugar de uma ilustração, a abertura mostra o app funcionando: um painel com o saldo
// disponível, o gasto do mês e os últimos lançamentos. A cada poucos segundos entra um
// lançamento novo no topo da lista, e o saldo e o medidor do gasto acompanham — é o que o
// app faz, em pequeno. O roteiro soma ZERO: depois de uma volta inteira o saldo é o mesmo
// do começo, então a animação pode repetir para sempre sem o número ir parar no infinito.
//
// Sem JS (ou com "reduzir movimento"), fica o estado que o servidor desenhou, parado.
// Os números são de exemplo — não há dado de ninguém aqui.

const MENOS = '−';

export const ROTEIRO = [
    { nome: 'Mercado do bairro', categoria: 'Alimentação', valor: -182.4 },
    { nome: 'Pix recebido de Ana', categoria: 'Receita', valor: 250 },
    { nome: 'Conta de luz', categoria: 'Contas', valor: -143.9 },
    { nome: 'Farmácia', categoria: 'Saúde', valor: -64.3 },
    { nome: 'Freela de design', categoria: 'Receita', valor: 420 },
    { nome: 'Corrida por aplicativo', categoria: 'Transporte', valor: -38.6 },
    { nome: 'Restaurante', categoria: 'Alimentação', valor: -96.5 },
    { nome: 'Internet', categoria: 'Contas', valor: -144.3 },
];

const ITENS_NA_LISTA = 3;
const INTERVALO_MS = 3400;

/** "4.218,30" — sem o símbolo (o "R$" é desenhado à parte, menor). */
export function numeroBrl(valor) {
    return Math.abs(valor).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** "+R$ 250,00" / "−R$ 182,40" — o sinal antes do símbolo, como no app. */
export function valorComSinal(valor) {
    return (valor < 0 ? MENOS : '+') + 'R$ ' + numeroBrl(valor);
}

/** O estado depois de um lançamento: a conta corrente recebe ou paga; receitas/despesas do mês somam. */
export function aplicar(estado, lancamento) {
    const centavos = (n) => Math.round(n * 100);
    const corrente = (centavos(estado.corrente) + centavos(lancamento.valor)) / 100;
    return {
        ...estado,
        corrente,
        receitas: lancamento.valor > 0 ? (centavos(estado.receitas) + centavos(lancamento.valor)) / 100 : estado.receitas,
        despesas: lancamento.valor < 0 ? (centavos(estado.despesas) - centavos(lancamento.valor)) / 100 : estado.despesas,
    };
}

const ICONE = {
    receita: 'M17 7 7 17M7 9v8h8',
    despesa: 'M7 17 17 7M9 7h8v8',
};

/** Uma linha da lista — o mesmo desenho que o Blade usa no estado inicial. */
export function criarItem(doc, lancamento) {
    const tipo = lancamento.valor > 0 ? 'receita' : 'despesa';
    const li = doc.createElement('li');
    li.className = 'vt-item vt-' + tipo;

    const ic = doc.createElement('span');
    ic.className = 'vt-ic';
    const svg = doc.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    const path = doc.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', ICONE[tipo]);
    svg.appendChild(path);
    ic.appendChild(svg);

    const txt = doc.createElement('span');
    txt.className = 'vt-txt';
    const b = doc.createElement('b');
    b.textContent = lancamento.nome;
    const small = doc.createElement('small');
    small.textContent = lancamento.categoria;
    txt.append(b, small);

    const valor = doc.createElement('span');
    valor.className = 'vt-valor';
    valor.textContent = valorComSinal(lancamento.valor);

    li.append(ic, txt, valor);
    return li;
}

function lerEstado(root) {
    const n = (chave) => Number(root.dataset[chave]);
    return { corrente: n('corrente'), poupanca: n('poupanca'), receitas: n('receitas'), despesas: n('despesas') };
}

/**
 * Liga a vitrine. `opcoes` existe para os testes: `agendar` troca o setInterval,
 * `duracao` (ms) a contagem dos números (0 = escreve direto).
 * Devolve { passo, parar } ou null quando não há vitrine na página.
 */
export function iniciarVitrine(root = document.querySelector('[data-vitrine]'), opcoes = {}) {
    if (!root || root.dataset.vitrineLigada) return null;
    root.dataset.vitrineLigada = '1';

    const win = root.ownerDocument.defaultView;
    const reduzir = opcoes.reduzir ?? win.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
    const duracao = opcoes.duracao ?? (reduzir ? 0 : 700);
    const doc = root.ownerDocument;

    const base = lerEstado(root);
    let estado = { ...base };
    let indice = 0;

    const el = {
        saldo: root.querySelector('[data-vt-saldo]'),
        corrente: root.querySelector('[data-vt-corrente]'),
        gasto: root.querySelector('[data-vt-gasto]'),
        renda: root.querySelector('[data-vt-renda]'),
        barra: root.querySelector('[data-vt-barra]'),
        lista: root.querySelector('[data-vt-lista]'),
    };

    // Conta o número de `de` até `para` (ease-out), ou escreve direto sem duração.
    function contar(alvo, de, para, formato = numeroBrl) {
        if (!alvo) return;
        if (!duracao || !win.requestAnimationFrame) {
            alvo.textContent = formato(para);
            return;
        }
        const inicio = win.performance.now();
        const quadro = (agora) => {
            const t = Math.min(1, (agora - inicio) / duracao);
            const k = 1 - Math.pow(1 - t, 3);
            alvo.textContent = formato(de + (para - de) * k);
            if (t < 1) win.requestAnimationFrame(quadro);
        };
        win.requestAnimationFrame(quadro);
    }

    function desenhar(anterior) {
        contar(el.saldo, anterior.corrente + anterior.poupanca, estado.corrente + estado.poupanca);
        contar(el.corrente, anterior.corrente, estado.corrente);
        contar(el.gasto, anterior.despesas, estado.despesas);
        if (el.renda) el.renda.textContent = numeroBrl(estado.receitas);
        if (el.barra) {
            const pct = estado.receitas > 0 ? Math.min(100, (estado.despesas / estado.receitas) * 100) : 0;
            el.barra.style.width = pct.toFixed(1) + '%';
        }
    }

    // Um lançamento novo entra no topo; o mais antigo sai pelo fim.
    function passo() {
        if (indice === 0 && estado !== base && estado.corrente !== base.corrente) {
            // nunca acontece com o roteiro somando zero; protege contra um roteiro editado
            estado = { ...base };
        }
        const anterior = estado;
        const lancamento = ROTEIRO[indice];
        estado = aplicar(estado, lancamento);
        indice = (indice + 1) % ROTEIRO.length;
        // Volta completa = mês novo: receitas e despesas recomeçam do mês de exemplo.
        if (indice === 0) estado = { ...estado, receitas: base.receitas, despesas: base.despesas };

        if (el.lista) {
            const novo = criarItem(doc, lancamento);
            if (duracao) novo.classList.add('vt-entra');
            el.lista.prepend(novo);
            const itens = [...el.lista.children].filter((li) => !li.classList.contains('vt-sai'));
            itens.slice(ITENS_NA_LISTA).forEach((velho) => {
                if (!duracao) {
                    velho.remove();
                    return;
                }
                velho.classList.add('vt-sai');
                win.setTimeout(() => velho.remove(), 500);
            });
            if (duracao) win.requestAnimationFrame?.(() => win.requestAnimationFrame(() => novo.classList.remove('vt-entra')));
        }
        desenhar(anterior);
    }

    // Abertura: os números contam a partir de zero uma vez.
    if (duracao) {
        desenhar({ corrente: 0, poupanca: 0, receitas: base.receitas, despesas: 0 });
    }
    root.classList.add('vt-viva');

    if (reduzir) return { passo, parar() {} };

    const agendar = opcoes.agendar ?? ((fn, ms) => win.setInterval(fn, ms));
    const cancelar = opcoes.cancelar ?? ((id) => win.clearInterval(id));
    let id = null;
    const ligar = () => { if (id === null) id = agendar(passo, INTERVALO_MS); };
    const desligar = () => { if (id !== null) { cancelar(id); id = null; } };

    // Só anda com a aba visível e a vitrine na tela — nada roda à toa.
    let naTela = true;
    const avaliar = () => (naTela && !doc.hidden ? ligar() : desligar());
    doc.addEventListener('visibilitychange', avaliar);
    if (win.IntersectionObserver) {
        new win.IntersectionObserver((entradas) => {
            naTela = entradas.some((e) => e.isIntersecting);
            avaliar();
        }).observe(root);
    }
    avaliar();

    return { passo, parar: desligar };
}

export function initVitrine() {
    iniciarVitrine();
}
