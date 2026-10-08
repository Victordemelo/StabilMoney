// Datas no padrão brasileiro (out/2026 — pedido do Victor, com um print do celular).
//
// O `<input type="date">` do navegador mostra e pede a data no idioma do APARELHO, não no da
// página: num Android em inglês o "Meu perfil" pedia "mm/dd/yyyy" e recusava 22/12/2003. O
// `lang="pt-BR"` do HTML não muda isso. Então cada campo de data (e de mês) ganha um campo de
// TEXTO com máscara — dd/mm/aaaa (ou mm/aaaa) — e um botão de calendário ao lado. O campo
// original continua no formulário, escondido, com o mesmo `name`, e é ele que vai para o
// servidor, no formato de sempre (AAAA-MM-DD / AAAA-MM): nada muda nas validações nem nos
// controllers. Sem JS, fica o campo do navegador, como antes.
//
// Regras: o texto vale só quando é uma data que existe (31/02 não) e respeita o min/max do
// campo original; senão o campo do servidor fica vazio e o navegador mostra o porquê. Quem
// muda o valor por código (`campo.value = '2026-10-07'`) continua funcionando: o setter do
// campo original foi trocado para atualizar o texto junto. `form.reset()` também.

const FORMATOS = {
    date: { placeholder: 'dd/mm/aaaa', digitos: 8, partes: [2, 2, 4], exemplo: 'dd/mm/aaaa' },
    month: { placeholder: 'mm/aaaa', digitos: 6, partes: [2, 4], exemplo: 'mm/aaaa' },
};

const ICONE = 'M7 3v3M17 3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z';

/** "2003-12-22" → "22/12/2003"; "2027-02" → "02/2027"; qualquer outra coisa → "". */
export function isoParaBr(valor, tipo = 'date') {
    const v = String(valor ?? '');
    if (tipo === 'month') {
        const m = /^(\d{4})-(\d{2})$/.exec(v);
        return m ? `${m[2]}/${m[1]}` : '';
    }
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v);
    return m ? `${m[3]}/${m[2]}/${m[1]}` : '';
}

/** "22/12/2003" → "2003-12-22"; data que não existe (31/02/2026) ou incompleta → null. */
export function brParaIso(texto, tipo = 'date') {
    const d = String(texto ?? '').replace(/\D/g, '');
    if (tipo === 'month') {
        if (d.length !== 6) return null;
        const mes = Number(d.slice(0, 2));
        const ano = Number(d.slice(2));
        if (mes < 1 || mes > 12 || ano < 1000) return null;
        return `${d.slice(2)}-${d.slice(0, 2)}`;
    }
    if (d.length !== 8) return null;
    const dia = Number(d.slice(0, 2));
    const mes = Number(d.slice(2, 4));
    const ano = Number(d.slice(4));
    if (mes < 1 || mes > 12 || dia < 1 || ano < 1000) return null;
    const diasNoMes = new Date(Date.UTC(ano, mes, 0)).getUTCDate();
    if (dia > diasNoMes) return null;
    return `${d.slice(4)}-${d.slice(2, 4)}-${d.slice(0, 2)}`;
}

/** Só dígitos, com as barras no lugar: "2212" → "22/12", "22122003" → "22/12/2003". */
export function mascarar(texto, tipo = 'date') {
    const { digitos, partes } = FORMATOS[tipo];
    const d = String(texto ?? '').replace(/\D/g, '').slice(0, digitos);
    const pedacos = [];
    let i = 0;
    for (const tam of partes) {
        if (i >= d.length) break;
        pedacos.push(d.slice(i, i + tam));
        i += tam;
    }
    return pedacos.join('/');
}

/** O porquê de o texto não valer (ou '' quando vale), conferindo também o min/max do campo. */
export function problemaDaData(texto, tipo, { min = '', max = '', obrigatorio = false } = {}) {
    const t = String(texto ?? '').trim();
    if (t === '') return obrigatorio ? 'Preencha a data.' : '';
    const iso = brParaIso(t, tipo);
    if (iso === null) {
        const completo = t.replace(/\D/g, '').length === FORMATOS[tipo].digitos;
        return completo ? 'Essa data não existe.' : `Complete a data no formato ${FORMATOS[tipo].exemplo}.`;
    }
    if (min && iso < min) return `A data não pode ser antes de ${isoParaBr(min, tipo)}.`;
    if (max && iso > max) return `A data não pode ser depois de ${isoParaBr(max, tipo)}.`;
    return '';
}

/** Troca cada `input[type=date|month]` de `raiz` pelo campo brasileiro. Pode rodar de novo à vontade. */
export function melhorarCamposDeData(raiz = document) {
    raiz.querySelectorAll('input[type="date"], input[type="month"]').forEach((original) => {
        if (original.dataset.dataBr || original.hasAttribute('data-sem-br')) return;
        melhorar(original);
    });
}

function melhorar(original) {
    const doc = original.ownerDocument;
    const tipo = original.type === 'month' ? 'month' : 'date';
    const fmt = FORMATOS[tipo];
    const obrigatorio = original.required;
    // min/max lidos na HORA: Contas a pagar troca o `min` do campo a cada fatura aberta.
    const limites = () => ({ min: original.getAttribute('min') || '', max: original.getAttribute('max') || '' });
    original.dataset.dataBr = tipo;

    // O campo que a pessoa vê e digita.
    const visivel = doc.createElement('input');
    visivel.type = 'text';
    visivel.inputMode = 'numeric';
    visivel.autocomplete = 'off';
    visivel.className = original.className;
    if (original.getAttribute('style')) visivel.setAttribute('style', original.getAttribute('style'));
    visivel.placeholder = fmt.placeholder;
    visivel.maxLength = fmt.placeholder.length;
    visivel.required = obrigatorio;
    visivel.disabled = original.disabled;
    visivel.readOnly = original.readOnly;
    visivel.setAttribute('aria-label', original.getAttribute('aria-label') || '');
    if (!visivel.getAttribute('aria-label')) visivel.removeAttribute('aria-label');
    if (original.getAttribute('aria-describedby')) visivel.setAttribute('aria-describedby', original.getAttribute('aria-describedby'));
    if (original.id) {
        visivel.id = `${original.id}-br`;
        doc.querySelectorAll(`label[for="${CSS.escape(original.id)}"]`).forEach((l) => { l.htmlFor = visivel.id; });
    }
    visivel.defaultValue = isoParaBr(original.defaultValue, tipo);
    visivel.value = isoParaBr(original.value, tipo);

    // O original segue no formulário com o mesmo `name`, escondido. Vira `text` (e não `hidden`)
    // para o `form.reset()` voltar ao valor do servidor; sem `required`, quem valida é o visível.
    const descritor = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
    original.type = 'text';
    original.required = false;
    original.hidden = true;
    original.tabIndex = -1;
    original.setAttribute('aria-hidden', 'true');
    Object.defineProperty(original, 'value', {
        configurable: true,
        get() { return descritor.get.call(this); },
        set(v) {
            descritor.set.call(this, v);
            visivel.value = isoParaBr(v, tipo);
            visivel.setCustomValidity('');
        },
    });

    // Moldura: o texto e o botão do calendário lado a lado.
    const moldura = doc.createElement('span');
    moldura.className = 'data-br';
    original.parentNode.insertBefore(moldura, original);
    moldura.append(visivel, original);

    const avisar = (alvo, nome) => alvo.dispatchEvent(new Event(nome, { bubbles: true }));
    const gravar = (iso) => {
        if (descritor.get.call(original) === iso) return;
        descritor.set.call(original, iso);
        avisar(original, 'input');
        avisar(original, 'change');
    };
    const conferir = () => {
        const problema = problemaDaData(visivel.value, tipo, { ...limites(), obrigatorio });
        visivel.setCustomValidity(problema);
        gravar(problema === '' && visivel.value.trim() !== '' ? brParaIso(visivel.value, tipo) : '');
    };

    visivel.addEventListener('input', () => {
        const antes = visivel.value;
        visivel.value = mascarar(antes, tipo);
        conferir();
    });
    visivel.addEventListener('blur', conferir);

    // O formulário voltando ao começo (o modal Lançar faz `form.reset()` a cada abertura).
    const form = original.form;
    if (form) {
        form.addEventListener('reset', () => setTimeout(() => {
            visivel.value = isoParaBr(descritor.get.call(original), tipo);
            visivel.setCustomValidity('');
        }));
    }

    // O calendário do navegador, aberto por um campo nativo escondido (`showPicker`): escolher
    // ali preenche o texto. Sem `showPicker` (navegador antigo), fica só a digitação.
    const nativo = doc.createElement('input');
    nativo.type = tipo;
    if ('showPicker' in nativo && !original.readOnly && !original.disabled) {
        nativo.tabIndex = -1;
        nativo.className = 'data-br-nativo';
        // Ele também é um campo de data: sem a marca, a próxima passada (toda troca de tela
        // pelo pjax roda de novo) o "melhorava" e aparecia um segundo calendário.
        nativo.setAttribute('data-sem-br', '');
        nativo.setAttribute('aria-hidden', 'true');
        const botao = doc.createElement('button');
        botao.type = 'button';
        botao.className = 'data-br-cal';
        botao.setAttribute('aria-label', 'Abrir o calendário');
        botao.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${ICONE}"/></svg>`;
        botao.addEventListener('click', () => {
            const { min, max } = limites();
            nativo.min = min;
            nativo.max = max;
            nativo.value = descritor.get.call(original);
            try { nativo.showPicker(); } catch (e) { visivel.focus(); }
        });
        nativo.addEventListener('change', () => {
            visivel.value = isoParaBr(nativo.value, tipo);
            conferir();
        });
        moldura.classList.add('com-calendario');
        moldura.append(botao, nativo);
    }
}
