# Spec — Cheque especial, segregação do investido e contas fixas mensais

**Data:** 27/07/2026 · **Status:** ✅ **IMPLEMENTADA** (A, B, C, D, E, G) · **Autor:** Claude Code, a pedido do Victor

> **Estado da implementação (27/07/2026, 275 testes verdes):**
>
> | Req | O quê | Onde |
> |---|---|---|
> | **A** | Cheque especial por conta corrente, piso no disponível, vermelho, barra de uso | `accounts.overdraft_limit`, `Account::overdraft*`, `SpendingGuard` |
> | **B** | "Saldo em conta" = disponível em toda a UI; investido nunca é consumido em silêncio | `SidebarService`, `DashboardService`, `accounts/index` |
> | **C** | 409 + modal "de onde sai esse dinheiro?"; resgate atômico do **faltante** | `RequiresFundingChoice`, `FundingService`, `sm/funding.js` |
> | **D** | Fatura vencida deixa de sumir; contas fixas mensais com competências projetadas | `Account::overdueInvoice`, `fixed_bills`, `FixedBillService` |
> | **E** | `Brl::format` + `@brl` com o sinal antes do R$ | `App\Support\Brl` |
> | **G** | Limite do cartão volta **ao pagar**, não com o calendário | `Account::committed` |
>
> **Ainda não feito:** varredura completa das views para `@brl` (só os pontos de
> saldo/vencimento foram convertidos); estorno de pagamento de fatura (D-8);
> guard de parcela isolada no Histórico (D-12); bloqueio de exclusão com saldo
> negativo (D-9/D-10). Ver §14.

**Escopo:** o modelo de dinheiro do Stabil Money — limite de cheque especial por conta,
separação real entre "saldo em conta" e "valor investido", escolha da fonte quando o saldo
acaba, e vencimentos (fatura vencida + contas fixas mensais).

**Como esta spec foi feita:** varredura automatizada em 7 subsistemas (23 agentes em paralelo,
121 achados brutos, 14 verificados por céticos independentes — **14 confirmados, 0 refutados**)
somada a uma prova empírica: `tests/Feature/TmpAuditoriaTest.php`, uma suíte temporária escrita
só para **rodar** cada defeito. Todo número da seção 4 é saída real do PHPUnit, não leitura de
código. A suíte temporária é descartada depois de aprovada esta spec.

---

## 1. O que foi pedido

| # | Requisito | Seção |
|---|---|---|
| **A** | Digitar o valor do **cheque especial**. Ex.: R$ 2.500. Ganha 2.500 → saldo 2.500. Ao chegar a 0, conta **em vermelho** até **−2.500**; além disso **não pode gastar**. | §5 |
| **B** | **Separar o investido do saldo em conta** — não juntar os dois nem descontar gasto do investido. | §6 |
| **C** | Saldo em 0 e ele vai gastar → **perguntar a fonte**: cheque especial (fica negativo até o limite) ou investimento (baixa o investido, **piso 0,00**). | §7 |
| **D** | Avisar **conta de crédito não paga (vencida)** e cadastrar **contas fixas mensais** (condomínio, carro, casa, apartamento) que **não podem vencer**. | §8 |
| **E** | Padrão **BRL R$** em tudo. | §9 |
| **F** | Varrer **todos os campos** e conferir se estão certos — inclusive se a **data de pagamento** é obedecida. | §3, §4, §10 |
| **G** | Conferir se o **parcelado do cartão** gera mesmo as parcelas mensais; o **total da compra fica retido** no limite e o **limite volta conforme ele paga**. | §8.5 |

**Decisões fechadas pelo Victor em 27/07/2026** (não rediscutir):

- **D-3 respondida:** o piso é sobre o **disponível** — cheque especial e investimento são
  **dois potes separados**, e a escolha entre eles é sempre explícita do usuário.
- **Gasto novo × obrigação vencida — regras DIFERENTES:**

  | Situação | Sem fonte que cubra |
  |---|---|
  | **Gasto novo** (compra, despesa avulsa) | **Recusa.** A saída é lançar um recebimento que complete o valor — a mensagem de erro diz isso. |
  | **Obrigação vencida** (fatura de cartão, conta fixa) | **Passa e a conta fica negativa.** A dívida já existe no mundo real; não se recusa um boleto. O flash avisa o novo saldo. |

- **O app NUNCA usa o cheque especial sozinho.** Uma conta que vence não é paga
  automaticamente: ela fica **marcada como vencida** e espera. No momento de pagar, se houver
  cheque especial e/ou investimento, o app **pergunta** (409) e o usuário escolhe. Enquanto ele
  não escolher, nada é pago e a conta continua vencida.

---

## 2. Modelo de dinheiro proposto — os quatro bolsos

Hoje o app tem um número que manda (`saldo`) e dois derivados que quase ninguém consome.
A proposta dá nome e visibilidade a **quatro bolsos por conta de caixa** (corrente/poupança):

```
┌──────────────────────────────────────────────────────────────────────┐
│  SALDO BRUTO  (Account::balance)  — já existe                        │
│  = saldo inicial + receitas − despesas                               │
│                                                                      │
│  ├── RESERVADO  (Account::reserved)  — já existe                     │
│  │   = Σ aportes − Σ resgates (metas + investimentos) desta conta    │
│  │   → dinheiro carimbado: está na conta, mas NÃO é para gastar      │
│  │                                                                   │
│  └── DISPONÍVEL  (Account::available = bruto − reservado) — já existe│
│      → É ISTO que o usuário chama de "meu saldo".                    │
│        É este número que fica VERMELHO e pode ir a negativo.         │
└──────────────────────────────────────────────────────────────────────┘
                    +
┌──────────────────────────────────────────────────────────────────────┐
│  CHEQUE ESPECIAL  (accounts.overdraft_limit)  ← ÚNICA COLUNA NOVA    │
│  crédito do banco; só entra em jogo quando DISPONÍVEL chega a 0      │
└──────────────────────────────────────────────────────────────────────┘

GASTÁVEL  = DISPONÍVEL + cheque especial ainda livre    ← teto de uma despesa
PISO      = − limite do cheque especial                 ← invariante I1
```

**A descoberta que barateia tudo:** `Account::available` (bruto − reservado) **já existe e já
está correto** (`app/Models/Account.php:189-192`). O problema é que **nada o consome** além do
aporte de meta/investimento. Promovendo esse accessor a "Saldo em conta", o requisito B se
resolve **sem tocar no modelo de dados** — nenhuma migration, nenhuma migração de histórico.

> **Alternativa descartada:** fazer o aporte gerar uma transação de saída (o dinheiro sai
> fisicamente da conta). Mudaria o sentido de todo o histórico, quebraria patrimônio, fluxo de
> caixa e gráficos, exigiria migração de dados e derrubaria os testes que afirmam explicitamente
> que aporte **não** cria transação (`GoalCrudTest.php:141`, `InvestmentCrudTest.php:97,199`,
> ambos com `assertDatabaseCount('transactions', 0)`). Registrado para não ser rediscutido.

### 2.1 Onde fica o piso: no disponível, não no saldo bruto

Decisão que muda o resultado prático — vale explicitar:

| Opção | Regra | Com R$ 1.000 investidos e limite R$ 2.500 |
|---|---|---|
| **Escolhida** | `disponível ≥ −limite` | pode chegar a **disponível −2.500** (bruto −1.500). O investido fica **intocado**. |
| Descartada | `bruto ≥ −limite` | pode chegar a **bruto −2.500** (disponível −3.500) — o cheque especial teria **engolido os R$ 1.000 investidos** além do limite. |

A segunda é o que um banco faria (ele não sabe do "investido"), mas contraria diretamente
*"não descontar do valor investido"*. Fica a primeira.

### 2.2 Exemplo numérico

Conta corrente, cheque especial R$ 2.500, R$ 1.000 num CDB.

**Cenário 1 — escolhe o cheque especial:**

| Evento | Bruto | Reservado | **Disponível (o "saldo")** | Investido | Gastável |
|---|---:|---:|---:|---:|---:|
| Recebe salário R$ 2.500 | 2.500,00 | 0,00 | **2.500,00** | 0,00 | 5.000,00 |
| Aporta R$ 1.000 no CDB | 2.500,00 | 1.000,00 | **1.500,00** | 1.000,00 | 4.000,00 |
| Gasta R$ 1.500 | 1.000,00 | 1.000,00 | **0,00** 🟡 | 1.000,00 | 2.500,00 |
| Gasta R$ 300 → cheque especial | 700,00 | 1.000,00 | **−300,00** 🔴 | 1.000,00 | 2.200,00 |
| Tenta gastar R$ 2.300 | — | — | **bloqueado** | — | — |

**Cenário 2 — do mesmo ponto (disponível 0,00), escolhe o investimento:**

| Passo (tudo na mesma transação de banco) | Bruto | Reservado | **Disponível** | Investido |
|---|---:|---:|---:|---:|
| Ponto de partida | 1.000,00 | 1.000,00 | **0,00** 🟡 | 1.000,00 |
| 1. resgate de R$ 300 do CDB para a conta | 1.000,00 | 700,00 | **300,00** | **700,00** |
| 2. a despesa de R$ 300 | 700,00 | 700,00 | **0,00** 🟡 | 700,00 |

Escolhendo **investimento**, o saldo **não fica negativo** e o investido é quem cai — até o piso
de **R$ 0,00**, nunca abaixo. Escolhendo **cheque especial**, o investido fica **intacto** e é o
saldo que fica vermelho. O valor resgatado é o **faltante**, não o total da despesa (§7.3).

---

## 3. Como o sistema está hoje

### 3.1 Saldo

| Conceito | Fórmula atual | Onde |
|---|---|---|
| `Account::balance` | `initial_balance + Σ income − Σ expense`, **sem filtro de data nem de `paid_at`** | `Account.php:140-152` |
| `Account::reserved` | Σ aportes − Σ resgates (metas + investimentos) **desta conta** | `Account.php:171-186` |
| `Account::available` | `balance − reserved` | `Account.php:189-192` |
| Cartão de débito | `balance` = corrente + poupança vinculadas (espelho puro) | `Account.php:143-145` |
| Cartão de crédito | não é caixa; `credit_limit`, `committed`, `availableLimit` | `Account.php:277-300` |

### 3.2 Patrimônio (sidebar) × Saldo (dashboard) — divergem

| | Sidebar (`SidebarService`) | Dashboard (`DashboardService`) |
|---|---|---|
| Contas fora do total | **só** `credit_card` (`:34-37`) | `credit_card` **e** `debit_card` (`:77-79`) |
| Total exibido | bruto (inclui reservado/investido) | bruto (inclui reservado/investido) |
| Quebra | `disponivel`/`guardado`/`investido`/`emConta` (`:93-106`) | nenhuma |

### 3.3 Onde há (e onde não há) validação de saldo

| Caminho | Valida? | Onde |
|---|---|---|
| Aporte em meta/investimento | ✅ contra `available`, com relock sob transação | `StoreInvestmentContributionRequest.php:44-53` + `HandlesContributions.php:64-76` |
| Resgate de meta/investimento | ✅ contra `saved`/`aplicado`, com relock | `WithdrawInvestmentContributionRequest.php:44-52` + `HandlesContributions.php:55-63` |
| **Despesa** (`transactions.store`) | ❌ nenhuma | `StoreTransactionRequest.php:40` |
| **Despesa** (`transactions.update`) | ❌ nenhuma | `UpdateTransactionRequest` (subclasse vazia) |
| **Despesa** (`faturas.lancar`) | ❌ nenhuma | `StoreFaturaLaunchRequest` |
| **Pagar fatura** | ❌ nenhuma | `FaturaController.php:145-153` |
| **Limite do cartão** | ❌ nenhuma | só clampado na exibição (`Account.php:299`) |

### 3.4 Datas e vencimentos

| Mecanismo | Hoje | Onde |
|---|---|---|
| Ciclo da fatura | `(fechamento anterior, próximo]`, com clamp de mês curto | `Account::billingCycle()` — `:220-246` |
| Dias aceitos | `closing_day`/`due_day` entre **1 e 28** | `StoreAccountRequest.php:67-68` |
| Vencimento | `Account::dueDate` = próxima data com `due_day` **≥ hoje** — ignora o ciclo | `:334-351` |
| Fatura em aberto | só do **ciclo aberto** | `:309-328` |
| Pagar fatura | marca `paid_at` + cria saída datada em **`now()`**; **sem campo de data de pagamento** | `FaturaController.php:120-154` |
| Recorrência | 1 ocorrência; pagar gera a próxima — mas **não há botão na UI** | `FaturaController.php:191-248` |
| Sino | faturas a vencer ≤ 7 dias + recorrências não pagas | `FaturaService::upcomingDue()` — `:60-102` |
| "Vencida" | a topbar **já sabe** renderizar `dias < 0` | `topbar.blade.php:59` |
| Agendador | **não existe** | `bootstrap/app.php`, `routes/console.php` |
| Timezone | **UTC**, fixo (não lê `.env`) | `config/app.php:68` |

---

## 4. Achados da auditoria — todos provados rodando

`tests/Feature/TmpAuditoriaTest.php` · 11 casos · saída real do PHPUnit.

### 4.1 Nenhuma trava de gasto existe

| # | Achado | Prova |
|---|---|---|
| **C-01** | **Nenhuma validação de saldo em despesa**, em nenhum dos 6 caminhos de escrita. | conta com R$ 100 → POST de `99.999,99` retorna **302 sucesso**, saldo **−99.899,99** |
| **C-02** | **Gastar come o investido em silêncio.** O `available` fica negativo e nada avisa; o investido continua exibido cheio. | bruto 2.500, aporte 1.000, gasto 2.000 → bruto 500, reservado 1.000, **disponível −500,00**, investido exibido **1.000,00** |
| **C-03** | **Dá para estourar o limite do cartão.** `availableLimit` usa `max(0, …)` — esconde o estouro. | limite 1.000 + compra de 5.000 → comprometido **5.000**, limite disponível exibido **R$ 0,00** |
| **D-02** | **Pagar fatura não checa saldo.** É a maior saída de caixa do app. | conta com 50 paga fatura de 900 → saldo **−850,00** |

> C-02 é literalmente o que foi pedido em (B). A correção é barata porque o accessor certo já existe.

### 4.2 Cartão de débito — o buraco maior

| # | Achado | Prova |
|---|---|---|
| **B-01** | Despesa **no cartão de débito** não debita a conta vinculada. O cartão não tem saldo próprio e suas transações são ignoradas pelo espelho: **o dinheiro não sai de lugar nenhum**. | corrente 1.000 + despesa 300 no débito → corrente **1.000,00**, espelho **1.000,00** |
| **B-02** | Por causa disso **sidebar e dashboard divergem** (exclusões diferentes). | patrimônio **700,00** vs saldo **1.000,00** — e o stat "Despesas" mostra 300 ao lado de um saldo que não mudou |
| **B-03** | **Aporte a partir do cartão de débito duplica o guardado.** O `reserved` fica na linha do cartão; o `available` dele vem das contas espelhadas. Nenhum dos dois enxerga a reserva do outro. | corrente 5.000 + débito vinculado → aporta 5.000 pelo débito **e** 5.000 pela corrente → **aplicado no investimento: R$ 10.000,00** com R$ 5.000 reais |
| **B-04** | Sem teste cobrindo gasto em débito. | `DebitCardTest` só testa espelho e vínculos |

**Consequência para esta spec:** enquanto o cartão de débito puder ser `account_id`, **qualquer**
limite de gasto é contornável só escolhendo o cartão no select. Corrigir isso é **pré-requisito**,
não item opcional (§11, PASSO 0).

### 4.3 Datas

| # | Achado | Prova |
|---|---|---|
| **D-01** | **Fatura vencida desaparece.** `openInvoiceDue` só olha o ciclo aberto e `dueDate` só devolve data futura. | fecha 10/vence 20, compra de 750 em 05/07, hoje 25/07 → **a pagar R$ 0,00**, vencimento exibido **20/08/2026**, sino com **0 itens** |
| **E-01** | **Parcelamento no dia 31 pula fevereiro.** Carbon 3 tem `monthOverflow` ligado e `createInstallments` usa `addMonths`. | compra em **31/01/2027** em 3x → parcelas em `31/01`, **`03/03`**, `31/03` — fevereiro vazio, **março com duas** |
| **D-03** | **Timezone é UTC** com público brasileiro. A partir das 21h BRT `now()` já é o dia seguinte: a data padrão do lançamento, o ciclo da fatura e o "gasto do mês" saem trocados. | `config/app.php:68` — literal, nem lê `.env` |
| **D-04** | **`due_day`/`closing_day` sem regra cruzada** e `dueDate` ignora o ciclo. Com fechamento 20 e vencimento 5, o app exibe vencimento **anterior** ao fechamento da fatura que ele deveria pagar. | `StoreAccountRequest.php:67-68` + `Account.php:334-351` |
| **D-05** | **Dias travados em 1..28** impedem cadastrar cartão que vence dia 30 (comum). O clamp de mês curto já existe no model — a trava é desnecessária. | `_form.blade.php:94-101` (`max="28"`) + servidor |
| **D-06** | **Não existe campo de data de pagamento.** `payInvoice` força `now()`. Respondendo (F): **a data de pagamento não é obedecida porque não há onde informá-la** — pagar uma fatura atrasada registra a saída no dia do clique. | `FaturaController.php:151` |
| **D-07** | **Não existe estorno** de pagamento de fatura. Um clique errado em "Marcar como paga" é irreversível pela UI. | `routes/web.php:88-97` |

### 4.4 Reserva e cofrinho

| # | Achado | Prova |
|---|---|---|
| **E-02** | **Resgate para uma conta diferente da do aporte quebra a distribuição.** O `reserved` da conta destino fica **negativo** e ela passa a oferecer dinheiro que não tem. | aporta 1.000 de A, resgata 1.000 para B → A: saldo 1.000/reservado 1.000/**disp. 0**; B: saldo **0,00**/reservado **−1.000**/**disp. 1.000**. O total se conserva (1.000), mas **B oferece R$ 1.000 gastáveis com a conta vazia** |
| **E-03** | **Excluir meta/investimento "cura" um saldo negativo.** O cascade apaga as contributions, o `reserved` cai e o disponível sobe — sem entrar dinheiro. Interage direto com (A)/(B). | migrations `…100100:12`, `…110100:12` |

> A varredura tinha classificado E-02 como "dinheiro dobrado". **Rodando, o total se conserva** —
> o defeito é de distribuição, não de soma. Corrigido aqui.

### 4.5 Saldo × previsto (motiva as decisões de §8)

| # | Achado | Prova |
|---|---|---|
| **A-01** | Despesa com **data futura** já derruba o saldo de hoje. | 1.000 + despesa de 400 datada em **+6 meses** → saldo **600,00** |
| **A-02** | Despesa **recorrente não paga** também já derruba. | 1.000 − condomínio de 800 **não pago** → saldo **200,00** |
| **A-03** | `paid_at` **não é lido por nenhuma agregação de saldo** — só serve para sumir da fatura. | consequência de A-01/A-02 |

### 4.6 Portas laterais que escapariam de uma validação nova

| # | Achado | Onde |
|---|---|---|
| **P-01** | **O modal "Lançar" não gera `client_uuid`** — o caminho mais usado do app (topbar + FAB) **não tem idempotência**. Duplo toque cria duas transações. | `launch.js:87-119` vs `offline-queue.js:147-148` |
| **P-02** | **`store` não tem lock nenhum**: o dedupe é `first()` e depois `create()` fora de `DB::transaction`. Dois lançamentos simultâneos passam os dois em qualquer regra escrita só no Form Request. | `TransactionController.php:78-89` |
| **P-03** | **O service worker trata 422 diferente da página**: não apaga, não marca — o item vira **lixo morto no IndexedDB**, sem tela para revisar. | `service-worker.blade.php:103-108` vs `offline-queue.js:293-296` |
| **P-04** | **`transactions.destroy` apaga UMA parcela**; `faturas.compra.destroy` apaga o grupo. Pelo Histórico dá para apagar a parcela 2/6 e ficar com `installments=6` e 5 linhas. | `TransactionController.php:142-150` |
| **P-05** | **`transactions.update` reescreve qualquer campo de uma parcela** ou da transação de pagamento de fatura (identificada só pela descrição). | `UpdateTransactionRequest.php:9` |
| **P-06** | **Trocar o tipo da conta na edição apaga `initial_balance`/`credit_limit`/dias** sem aviso — `UpdateAccountRequest` é subclasse vazia e herda o `merge(null)` da criação. | `StoreAccountRequest.php:32-42` |
| **P-07** | **Vínculo do cartão de débito nunca é revalidado**: a conta apontada pode virar cartão de crédito depois e o débito passa a espelhar um saldo negativo. | `Account.php:155-164` |
| **P-08** | **Excluir a conta do perfil dissolve a família e apaga todo o histórico** (dois `cascadeOnDelete`), sem checar dinheiro pendente. | `ProfileController.php:53-69` |
| **P-09** | Docblock do `DependentController` promete **limite de gasto por dependente que não existe** — resquício da coluna `spending_limit` criada e removida no mesmo dia. Não confundir com (A): o cheque especial é por **conta**, não por pessoa. | `DependentController.php:14-16` |
| **P-10** | **Sem sino no mobile.** A `.topbar` some em ≤920px e a `.mobile-top` não tem sino — num app PWA-first, **nenhum aviso de vencimento no celular**. E o `nav.js` só troca `#content`, então o badge fica defasado após navegação pjax. | `design-system.css:979`, `topbar.blade.php:9-18` |
| **P-11** | `amount` valida `numeric` sem `decimal:0,2`: em sqlite (testes) `10.999` grava com 3 casas; em MySQL a coluna arredonda. Uma invariante com epsilon `0,001` pode passar no teste e falhar em produção. | `StoreTransactionRequest.php:40` |
| **P-12** | Fora do tipo, `initial_balance`/`credit_limit` caem em `['nullable']` puro — **sem `numeric`, sem `max`**. Só o `merge(null)` do `prepareForValidation` salva. Um `overdraft_limit` adicionado às rules sem entrar no bloco de merge herda um caminho **sem validação nenhuma**. | `StoreAccountRequest.php:59-68` |

### 4.7 O que está certo (não mexer)

- Aporte/resgate com **validação + relock + recheque sob transação** (`HandlesContributions.php:41-87`) — é o padrão a copiar.
- Divisão de centavos do parcelamento (última parcela absorve o resto) e atomicidade das N linhas (`FaturaController.php:163-184`).
- Idempotência da fila offline por `client_uuid` e do pagamento de recorrência por update condicional (`:222-229`).
- Clamp de mês curto no ciclo (`Account.php:201-207`).
- Escopo de família por `ownerId()` consistente em todos os Form Requests conferidos.
- Compatibilidade sqlite/MySQL por driver no agregado anual (`DashboardService.php:116-118`).

---

## 5. Requisito A — Cheque especial

### 5.1 Migration

`database/migrations/2026_07_28_000100_add_overdraft_limit_to_accounts.php`

```php
Schema::table('accounts', function (Blueprint $table) {
    // Limite do cheque especial. NOT NULL default 0 (≠ initial_balance, que é
    // nullable): assim (float) $account->overdraft_limit nunca precisa de coalesce
    // e as linhas existentes nascem com 0 = sem cheque especial.
    $table->decimal('overdraft_limit', 15, 2)->default(0)->after('initial_balance');
});
// down(): $table->dropColumn('overdraft_limit');
```

> A data **28/07** evita colisão com `2026_07_27_100000_add_is_locked_to_categories.php`, que
> entrou no repo hoje (commit `1080957`).

**Semântica:** só faz sentido em `checking`. Não em `savings` (poupança no Brasil não tem),
não em `debit_card` (não tem saldo próprio), não em `credit_card` (já tem `credit_limit`).
O zeramento por tipo vai no `prepareForValidation`, não no banco.

### 5.2 Model `Account`

```php
protected $fillable = [..., 'overdraft_limit'];
protected function casts(): array { return [..., 'overdraft_limit' => 'decimal:2']; }

public function isCash(): bool { return in_array($this->type, ['checking', 'savings'], true); }

/** Limite efetivo (0 fora de conta corrente). */
public function getOverdraftLimitValueAttribute(): float
{
    return $this->type === 'checking' ? round((float) $this->overdraft_limit, 2) : 0.0;
}

/** Quanto do cheque especial está sendo usado — medido no DISPONÍVEL (§2.1). */
public function getOverdraftUsedAttribute(): float
{
    return round(max(0.0, -$this->available), 2);
}

public function getOverdraftAvailableAttribute(): float
{
    return round(max(0.0, $this->overdraftLimitValue - $this->overdraftUsed), 2);
}

/** Teto de uma despesa sem tocar em investimento. */
public function getSpendableAttribute(): float
{
    return round(max(0.0, $this->available) + $this->overdraftAvailable, 2);
}
```

Todos memoizados no padrão de `$balanceCache` (`Account.php:128-134`) — sem N+1 nas views.

### 5.3 Validação

`StoreAccountRequest` (herdado por `UpdateAccountRequest`):

```php
// prepareForValidation() — OBRIGATÓRIO entrar no bloco de merge (achado P-12)
$this->normalizeMoneyField('overdraft_limit');
if ($this->input('type') !== 'checking') {
    $this->merge(['overdraft_limit' => 0]);
}

// rules()
'overdraft_limit' => $type === 'checking'
    ? ['nullable', 'numeric', 'min:0', 'max:9999999999999.99']
    : ['nullable', 'numeric'],   // nunca ['nullable'] puro — ver P-12
```

`attributes()`: `'overdraft_limit' => 'limite do cheque especial'`. Mensagens:
*"O limite do cheque especial deve ser um número. Use vírgula para os centavos, ex.: 2.500,00."* /
*"O limite do cheque especial não pode ser negativo."* / *"O limite informado é alto demais."*

### 5.4 Cadastro (`accounts/_form.blade.php`)

Bloco **próprio** `data-fields-overdraft` — o `data-fields-account` atual vale para `checking`
**e** `savings`, e o cheque especial é só corrente:

```blade
<div class="field" data-fields-overdraft @if ($tipoAtual !== 'checking') hidden @endif>
    <label for="overdraft_limit">Limite do cheque especial (R$)</label>
    <input class="input @error('overdraft_limit') input-error @enderror" type="text"
           inputmode="decimal" id="overdraft_limit" name="overdraft_limit"
           placeholder="0,00" value="{{ $chequeAtual }}">
    <small class="form-hint">Quanto o banco deixa seu saldo ficar negativo nesta conta.
        Deixe 0,00 se sua conta não tem cheque especial.</small>
    @error('overdraft_limit')<div class="field-error">{{ $message }}</div>@enderror
</div>
```

E no script inline: `grupos.overdraft.hidden = t !== 'checking'`.
`inputmode="decimal"` herda a máscara BRL do `sm/money.js`.

### 5.5 Vermelho

O token `--neg: #E5604D` já existe (`design-system.css:29`) e o padrão `.neg` já é usado em
dois lugares (`.stat .value.neg:322`, `.sb-value.neg:720`). Falta aplicar em:

| Lugar | Hoje |
|---|---|
| `accounts/index.blade.php:59` (`.cc-balance`), `:75` (total do débito), `:79` (`.acct-balance`) | ❌ sai `R$ -1.234,56` em cor normal |
| Cards de conta do dashboard | ❌ |
| `.fatura-pay` (`faturas/index.blade.php:113-129`) | ❌ |

CSS novo: `.acct-balance.neg, .cc-balance.neg { color: var(--neg); }`

**Chip de cheque especial** no card da conta: quando `overdraftUsed > 0`, linha vermelha
*"Cheque especial: R$ X usados de R$ Y"*; quando há limite sem uso, *"Cheque especial disponível:
R$ Y"* em texto secundário. Sem isso o usuário não entende por que uma despesa foi recusada.

---

## 6. Requisito B — Separar investido do saldo

### 6.1 Serviço único

`app/Services/SpendingGuard.php` — fonte única do que pode ser gasto:

```php
public function snapshot(Account $account): array
// ['saldo','reservado','disponivel','chequeLimite','chequeUsado','chequeDisponivel','gastavel']

public function check(Account $account, float $amount, float $ignore = 0.0): string
// 'ok' | 'precisa_fonte' | 'estoura_limite'
// $ignore = valor ANTIGO da própria transação no update (senão editar 100,00
//           para 100,00 seria recusado)

public function assertHardLimit(Account $account, float $amount, float $ignore = 0.0): void
// lança ValidationException com mensagem PT-BR
```

Tudo com `round(…, 2)` e epsilon `0,001`, coerente com `HandlesContributions.php:59,71`.

### 6.2 Unificar as exclusões — **antes** de tudo

`SidebarService.php:34-37` passa a excluir `['credit_card','debit_card']`, igual ao
`DashboardService.php:77-79`. **Obrigatório antes de (A)**: senão o cheque especial herda dois
saldos diferentes (achado B-02).

### 6.3 Sidebar

| Rótulo hoje | Valor hoje | Depois |
|---|---|---|
| Patrimônio total | bruto | **Patrimônio total** = `disponivel + guardado + investido` (mesmo número, agora somado explicitamente) |
| Em conta | `disponivel + guardado` | **Saldo em conta** = `disponivel` (vermelho quando negativo) |
| Guardado em metas | `guardado` | igual |
| Investido | `investido` | igual |
| — | — | **Cheque especial: R$ X de R$ Y** (novo, condicional, vermelho) |

Chaves novas no retorno do `SidebarService`, **sem remover nenhuma existente**:
`chequeLimite`, `chequeUsado`, `chequeDisponivel`, `gastavel` — somadas com **uma query
agregada** sobre as contas `checking`, nunca accessor por conta dentro de laço.

### 6.4 Dashboard — contrato aditivo

⚠️ **Restrição descoberta na varredura:** `dashboard.js:61-63` casa card ↔ valor **por índice**
(`STAT_ORDER[i]`). Portanto:

- `periods.*.stats` **continua exatamente** `saldo|receitas|despesas|economia`, mesma ordem;
- o valor de `saldo` **passa a ser o disponível** (mudança semântica, não estrutural);
- acrescentar **uma chave de topo nova** `caixa` com
  `saldo|guardado|investido|disponivel|chequeLimite|chequeUsado|chequeDisponivel|gastavel`;
- **não** acrescentar um 5º stat card sem antes migrar o casamento para `data-stat="chave"`.
  Isso é um sub-passo explícito, não um efeito colateral.

O `DashboardService` calcula `reserved` por conta com **uma query agregada** (no estilo da
query de `$deltas`, `:53-56`), nunca `$account->reserved` dentro do laço (são 4 queries por conta).

### 6.5 Aporte continua sem cheque especial

`StoreGoalContributionRequest.php:49`, `StoreInvestmentContributionRequest.php:49`,
`StoreInvestmentRequest.php:72` e `HandlesContributions.php:71` **não mudam**. Existem dois tetos
distintos e eles **não devem ser unificados**:

- `available` (piso 0) → aporte. Pegar emprestado do banco para investir não faz sentido.
- `spendable = disponível + cheque livre` (piso `−limite`) → gasto.

---

## 7. Requisito C — Escolha da fonte

### 7.1 Protocolo: 409, não 422

A pergunta não é um erro. Novo `app/Exceptions/RequiresFundingChoice.php`:

- `wantsJson()` → **HTTP 409** + payload de opções (modal e fila offline);
- fluxo web sem JS → `back()->withInput()->with('fonteNecessaria', $payload)`.

Assim a regra vive num lugar só e chega aos três front-ends. **Atenção:** o `FaturaController`
**não usa** o trait `RespondsToAjax` hoje — a detecção de JSON precisa virar helper compartilhado.

**Quando o 409 dispara:** despesa (`type=expense`) + conta de **caixa** + `amount > max(0, disponível)`
+ existe pelo menos uma fonte viável. Se **nenhuma** fonte cobre → **422** direto, sem modal.

### 7.2 Payload

```jsonc
{
  "conta": { "id": 7, "nome": "Nubank" },
  "disponivel": 0.00,
  "faltante": 300.00,
  "fontes": [
    { "id": "cheque_especial", "rotulo": "Usar o cheque especial", "cobre": true,
      "teto": 2500.00, "detalhe": "A conta fica em −R$ 300,00 (limite R$ 2.500,00)" },
    { "id": "resgate_investimento", "rotulo": "Resgatar de um investimento", "cobre": true,
      "teto": 1000.00, "itens": [ { "id": 3, "nome": "CDB Inter", "aplicado": 1000.00 } ] }
  ]
}
```

Só entram investimentos **com aporte originado nesta conta** — o resgate devolve o dinheiro para
a conta de onde saiu (e evita o defeito E-02). Metas **não** entram (decisão D-2).
Opção inviável aparece **desabilitada com o motivo**, nunca escondida.

### 7.3 Campos novos e semântica

Migration `2026_07_28_000200_add_funding_source_to_transactions.php`:

```php
$table->string('funding_source', 24)->nullable()->after('paid_at');
$table->decimal('funding_amount', 15, 2)->nullable()->after('funding_source');
```

Valores validados **na aplicação**, não como enum de banco (enum diverge entre MySQL e sqlite):
`cheque_especial`, `resgate_investimento`, ou null (gasto normal).
`funding_amount` = quanto veio da fonte (pode ser < `amount` quando parte veio do disponível).

Migration `2026_07_28_000300_add_transaction_id_to_contributions.php`: `transaction_id`
nullable + índice em `goal_contributions` e `investment_contributions`, **deliberadamente sem FK**
(`dropForeign` em sqlite exige recriar a tabela e deixa o `down()` frágil). Responde
"qual resgate cobriu esta despesa".

**Quanto se resgata:** o **faltante**, não o total.
`faltante = round(amount − max(0, available), 2)`

### 7.4 `FundingService` — atomicidade

```php
public function spend(Account $account, float $amount, ?string $source,
                      ?int $sourceId, callable $write): Transaction
```

Uma única `DB::transaction`:

1. relock **na ordem pai (Goal/Investment) → conta** — **idêntica** à de
   `HandlesContributions.php:48-76`. Ordem divergente entre os dois caminhos = deadlock.
2. `SpendingGuard::check()`; se `precisa_fonte` e `$source` é null → lança `RequiresFundingChoice`.
3. se `resgate_investimento`: recheca `aplicado ≥ faltante` sob lock e grava a contribution
   `type='resgate'` **reusando** o bloco de escrita de `HandlesContributions.php:78-85`
   (extrair para método compartilhado, não copiar).
4. `$write()` cria a despesa; recheca **I1** sob lock.

### 7.5 Invariantes

| # | Regra | Onde é checada |
|---|---|---|
| **I1** (dura) | `available ≥ −overdraftLimitValue` para conta de caixa | Form Request (erro bonito) **+** recheque sob `lockForUpdate` no `FundingService` (autoritativo) |
| **I2** (suave) | despesa que faria `available < 0` → **409** pedindo a fonte, não erro | mesmo par |
| **I3** | `investment.aplicado ≥ 0` e `goal.saved ≥ 0`, piso exato 0,00 | já existe — o fluxo (C) **reusa**, não recria |
| **I4** | aporte limitado a `available`, **sem** cheque especial | 4 pontos existentes, nenhum muda |
| **I5** | `debit_card` **nunca** é `account_id` de transação nem de contribuição | `Rule::exists(...)->whereIn('type', …)` em todos os Requests + selects passam a mandar a conta vinculada |
| **I6** | resgate para conta Y ≤ aportes de Y naquele pai (corrige E-02) | novos `Investment::reservedFromAccount()` / `Goal::reservedFromAccount()` + recheque sob lock |
| **I7** | valor nunca negativo (o sinal vem do `type`) | já vigente |
| **I8** | mesmo `client_uuid` nunca gera duas despesas **nem dois resgates** | dedupe **antes** do guard (senão o replay resgata de novo) |
| **I9** | `committed ≤ credit_limit` no cartão | novo — hoje só clampado na exibição |

**Caminhos que precisam do guard:** `transactions.store`, `transactions.update` (com `$ignore`),
`faturas.lancar`, `faturas.fatura.pagar`, `faturas.recorrente.pagar`, `contas-fixas.pagar`.

**Mensagens PT-BR:**

- *"Saldo insuficiente: a conta Nubank tem R$ 120,00 disponíveis e esta despesa é de R$ 300,00."*
- *"Não dá: com esta despesa a conta ficaria em −R$ 2.800,00, e o limite do cheque especial é R$ 2.500,00. O máximo agora é R$ 2.200,00."*
- *"O investimento CDB Inter tem só R$ 700,00 aplicados — não cobre R$ 900,00."*
- *"Esta compra passa do limite do cartão Nubank: restam R$ 340,00 de R$ 5.000,00."*

### 7.6 Front-end

**Modal global "Lançar"** (caminho principal): vira duas etapas. O `fetch` recebe **409**,
`launch.js` esconde o passo 1 e mostra `<div data-lm-step="fonte" hidden>`, preenchido **pelo JS
com os dados do 409** — assim o View Composer **não** precisa mandar saldos em toda página
(evita N+1 no shell inteiro). "Confirmar" reenvia o **mesmo FormData** + `funding_source`
(+ `funding_investment_id`, `funding_amount`) e o **mesmo `client_uuid`**.

⚠️ **Pré-requisito P-01:** hoje o modal **não gera `client_uuid`**. Sem isso, o reenvio da
etapa 2 pode duplicar. Gerar o uuid no modal é parte deste passo.

**Página cheia**: `offline-queue.js` já intercepta o submit sempre; entra um ramo
`if (res.status === 409)` chamando `renderFundingChoice(form, payload)`, irmã de
`renderFormErrors`. Fallback sem JS: o Blade renderiza o bloco a partir de `session('fonteNecessaria')`.

**Tela /faturas**: o modal já sabe se reabrir com erro (`data-reopen`); reabre no passo 2 quando
houver `session('fonteNecessaria')`. O select "Debitar de" do modal de pagar fatura passa a
mostrar o disponível por conta.

**Fila offline** (não tem como perguntar): no **409**, reenvia **uma vez** com
`funding_source: 'cheque_especial'` — a compra já aconteceu no mundo real; **nunca** resgatar
investimento sozinho. Se vier 422, marca `failed`, guarda a **mensagem real do servidor** e o
badge passa a mostrar *"N lançamento(s) precisam da sua atenção"*.
⚠️ Corrigir junto o **P-03**: hoje o service worker ignora 422 e o item vira lixo morto.

---

## 8. Requisito D — Vencidos e contas fixas

### 8.1 Fatura vencida

`Account` ganha:

- `dueDateForCycle(CarbonImmutable $cycleEnd)` — vencimento **derivado do fechamento**:
  se `due_day > closing_day`, é `dayInMonth($cycleEnd, $due_day)`; senão, o mês seguinte.
  Corrige **D-04**. O `getDueDateAttribute` atual passa a delegar para cá.
- `closedCycle()` — o ciclo imediatamente anterior, com o mesmo `dayInMonth`.
- `getClosedInvoiceDueAttribute()` — não pagas do ciclo fechado.
- `getOverdueInvoiceAttribute()` — `['valor','vencimento','diasAtraso']` quando há dívida fechada vencida.

`payInvoice` recebe **qual ciclo** (`ciclo=aberto|fechado`), vira `PayInvoiceRequest`
(convenção do CLAUDE.md) e **ganha o campo de data do pagamento** — respondendo a **D-06**.

`upcomingDue` passa a devolver três fontes com flag `vencida`, vencidas primeiro. A topbar já
sabe renderizar (`topbar.blade.php:59`).
⚠️ **P-10:** resolver junto o sino no mobile — hoje não existe.
⚠️ **Performance:** esse serviço roda em **View Composer de toda página autenticada** e já faz
N+1 por cartão. Não piorar.

### 8.2 Contas fixas — decisão central: **não materializar**

Tabela `fixed_bills` (migration `2026_07_28_000400`):

| Coluna | Tipo | Regra |
|---|---|---|
| `user_id` | FK users cascade | titular (`ownerId`) |
| `made_by_user_id` | FK nullable nullOnDelete | quem cadastrou |
| `name` | string(255) | obrigatório |
| `amount` | decimal(15,2) | valor **esperado** |
| `due_day` | tinyint | **1..31** (clamp em PHP — ao contrário do 1..28 dos cartões) |
| `account_id` | FK nullable nullOnDelete | método padrão |
| `category_id` | FK nullable nullOnDelete | |
| `starts_on` | date | primeira competência |
| `ends_on` | date nullable | null = sem fim |
| `active` | boolean default true | pausar sem apagar histórico |

Índice `['user_id','active']`.

Migration `2026_07_28_000500`: em `transactions`, `fixed_bill_id` nullable + `competence` date
(sempre dia 01) + **`unique(['fixed_bill_id','competence'])`** — a trava de idempotência.
Nos dois drivers o índice único **ignora linhas com NULL**, então as transações comuns não colidem.

**A decisão:** as competências mensais são **calculadas na leitura**; só existe linha em
`transactions` quando a conta fixa é **paga**.

> **Por que isso importa tanto:** os achados A-01/A-02/A-03 mostram que uma transação em aberto
> **já reduz o saldo hoje**. Materializar 12 competências futuras derrubaria o saldo em 12
> aluguéis de uma vez e sabotaria as invariantes de (A) e (C), que dependem de um saldo confiável.
> Não materializando, **não é preciso mexer em `Account::balance`** — e a versão anterior desta
> spec, que propunha reescrever a semântica do saldo ("regra R-SALDO"), fica **descartada**. Essa
> era a mudança de maior risco do projeto inteiro; ela deixou de ser necessária.

**Corolário:** **não é preciso scheduler.** A ocorrência do mês existe sempre porque é projetada
de `starts_on` até hoje de forma determinística. Um comando agendado entra depois, só para
**notificar** (push/e-mail), nunca para criar dado.

**Estados** (derivados por competência): `vencimento = dayInMonth(competência, due_day)`;
`paga` = existe transação com aquele `fixed_bill_id`+`competence`;
`vencida` = `!paga && vencimento < hoje`.

`app/Services/FixedBillService.php`:
`occurrences($ownerId, $from, $to)` marca `paga` com **uma query só** (group by
`fixed_bill_id, competence`) — nada de N+1. `currentAndOverdue($ownerId, $maxMesesAtras = 12)`
agrega acima do limite (*"Aluguel — 14 competências em aberto"*), senão uma conta criada em 2020
geraria 70 linhas no sino. `pay()` passa pelo `FundingService::spend()` — herda (A)/(B)/(C).

**Valor real ≠ esperado:** o modal de pagamento vem pré-preenchido com `amount` mas é **editável**
(conta de luz varia). O real vai na transação; o esperado fica na `fixed_bills` para projeção.
Rótulo: *"Valor previsto R$ 1.800,00 — ajuste se veio diferente."*

Rotas: `POST /contas-fixas`, `PATCH|DELETE /contas-fixas/{bill}`,
`POST /contas-fixas/{bill}/pagar/{competencia}`. **Sem rota de listagem** — entra como terceiro
bloco de `/faturas`. Policy no molde da `AccountPolicy`.

### 8.3 Convivência com a recorrência de cartão

A recorrência atual (`transactions.recurring`) **não é migrada nesta rodada**: continua só para
cartão de crédito. `fixed_bills` cobre corrente/poupança. **Mas o botão que falta (achado G-01)
precisa entrar** — hoje a rota `faturas.recorrente.pagar` existe, o controller está implementado
e **nenhuma view a chama**, então a recorrência nunca avança de mês.
Documentar a diferença no CLAUDE.md para não nascer uma terceira forma de recorrência. (D-5)

### 8.5 Requisito G — parcelado do cartão: retenção e liberação do limite

**Validado rodando** (`test_parcelamento_retencao_de_limite_e_liberacao_ao_pagar`), compra de
R$ 600 em 6x num cartão de limite R$ 5.000 que fecha dia 10:

| Comportamento | Hoje |
|---|---|
| Gera 1 parcela por mês | ✅ 6 transações — `05/01 05/02 05/03 05/04 05/05 05/06`, R$ 100 cada |
| Total da compra fica retido | ✅ comprometido **600,00**, limite disponível **4.400,00** |
| A fatura do mês cobra só a parcela do mês | ✅ **100,00** |
| **O limite volta ao PAGAR** | ❌ paguei a 1ª fatura → comprometido **continua 600,00** |
| **O limite volta sozinho com o tempo** | ❌ 10 dias depois, **sem pagar nada**, comprometido caiu para **500,00** |

**Causa:** `Account::getCommittedAttribute` (`:277-293`) filtra por **data**
(`date >= início do ciclo atual`) e **ignora `paid_at`**. O limite é liberado pela passagem do
tempo, não pelo pagamento — quem nunca paga recebe limite de volta; quem paga não recebe nada.

**Correção:** comprometido passa a ser o que **ainda não foi pago**, sem filtro de data:

```php
public function getCommittedAttribute(): float
{
    return $this->committedCache ??= round((float) $this->transactions()
        ->where('type', 'expense')
        ->whereNull('paid_at')      // ← a liberação passa a ser dirigida pelo PAGAMENTO
        ->sum('amount'), 2);
}
```

Resultado: 600 na compra → 500 ao pagar a 1ª fatura → 400 na 2ª → … → 0 na última.
Sem pagar, continua 600 para sempre. **E `availableLimit` deixa de esconder o estouro**
(hoje `max(0, …)`): passa a devolver o valor real, e a exibição é que clampa em 0.

Isto também alimenta a invariante **I9** (`committed ≤ credit_limit`): sem a correção, a
validação de limite usaria uma base que encolhe sozinha.

### 8.4 Dashboard

O card "Contas a pagar" passa a usar `openInvoiceDue` (em vez de `currentInvoice`) e a somar
faturas vencidas + contas fixas do mês, em vermelho com a contagem (*"2 vencidas"*).

---

## 9. Requisito E — Padrão BRL

`app/Support/Brl.php` + directive `@brl($valor)`:

```php
/** "R$ 1.234,56" · negativo: "−R$ 1.234,56" (menos ANTES do símbolo, traço U+2212). */
public static function format(float|string|null $v, int $dec = 2): string
{
    $n = round((float) $v, $dec);
    return ($n < 0 ? '−' : '') . 'R$ ' . number_format(abs($n), $dec, ',', '.');
}
```

Hoje um negativo sai como **`R$ -1.234,56`**. Passa a **`−R$ 1.234,56`**.
No dashboard o `R$` é um `<span class="cur">` separado do número — o sinal precisa entrar
**antes** do `.cur` (ajuste em `dashboard.blade.php:86` e no `dashboard.js`).

Entrada (`sm/money.js`) **não muda**: continua descartando o menos, porque valor digitado nunca
é negativo.

---

## 10. Inventário de campos — o que muda

### 10.1 Tabelas

| Tabela | Coluna | Ação | Tipo |
|---|---|---|---|
| `accounts` | `overdraft_limit` | novo | `decimal(15,2)` NOT NULL default 0 |
| `transactions` | `funding_source` | novo | `string(24)` nullable |
| `transactions` | `funding_amount` | novo | `decimal(15,2)` nullable |
| `transactions` | `fixed_bill_id` | novo | bigint nullable (sem FK) |
| `transactions` | `competence` | novo | `date` nullable + unique com `fixed_bill_id` |
| `transactions` | `legacy_account_id` | novo (temporário) | bigint nullable — reversão do PASSO 0 |
| `goal_contributions` / `investment_contributions` | `transaction_id` | novo | bigint nullable + índice |
| `fixed_bills` | tabela inteira | nova | §8.2 |

### 10.2 Form Requests

| Request | Campo | Ação |
|---|---|---|
| `StoreAccountRequest` (+ Update, herda) | `overdraft_limit` | novo — **e entrar no bloco de merge** (P-12) |
| `StoreTransactionRequest` | `funding_source`, `funding_investment_id`, `funding_amount` | novos |
| `StoreTransactionRequest` / `UpdateTransactionRequest` | `account_id` | passa a recusar `debit_card` (I5) |
| `StoreTransactionRequest` / `UpdateTransactionRequest` | `amount` | guard I1/I2/I9 (`$ignore` no update) |
| `StoreFaturaLaunchRequest` | idem | idem |
| **`PayInvoiceRequest`** | `pay_account_id`, `ciclo`, **`paid_on`** | novo (hoje é `validate()` inline) |
| `StoreFixedBillRequest` / `UpdateFixedBillRequest` | todos de §8.2 | novos |
| todos os 4 Requests de aporte/resgate | `account_id` | recusar `debit_card` (I5); resgate ganha I6 |

### 10.3 Views e JS

| Arquivo | Mudança |
|---|---|
| `accounts/_form.blade.php` | bloco `data-fields-overdraft` + grupo no JS |
| `accounts/index.blade.php` | saldo = disponível; `.neg`; chip de cheque especial |
| `partials/sidebar.blade.php` | rótulos de §6.3 + linha do cheque especial |
| `dashboard.blade.php` | stat "Saldo" = disponível; sinal antes do `R$`; card "Contas a pagar" |
| `partials/launch-modal.blade.php` | passo 2 (fonte) + **gerar `client_uuid`** (P-01) |
| `transactions/_form.blade.php` | idem |
| `faturas/index.blade.php` | bloco "Contas fixas"; faturas vencidas; **botão de pagar recorrência** (G-01); data de pagamento |
| `partials/topbar.blade.php` | seções "Vencidas"/"A vencer"; badge vermelho; **sino no mobile** (P-10) |
| `sm/launch.js` | tratar 409 |
| `sm/offline-queue.js` | tratar 409 + mensagem real no 422 |
| `pwa/service-worker.blade.php` | tratar 422 (P-03) |
| `sm/nav.js` | atualizar o badge do sino na navegação pjax (P-10) |
| `config/app.php` | `timezone` → `env('APP_TIMEZONE', 'America/Sao_Paulo')` + `.env`, `.env.example`, `phpunit.xml` |

---

## 11. Ordem de implementação

Cada passo deixa a suíte verde antes do próximo.

**PASSO 0 — higiene (pré-requisito, sem o qual nada abaixo se sustenta):**
1. `timezone` → `America/Sao_Paulo` (D-03).
2. `addMonths` → `addMonthsNoOverflow` em `createInstallments` e `pay` (E-01).
3. Unificar a exclusão de contas: `SidebarService` também exclui `debit_card` (B-02).
4. Migration de dados movendo transações e contribuições de `debit_card` para a conta vinculada
   (`COALESCE(checking_account_id, savings_account_id)`), com `legacy_account_id` para reverter.
5. Bloquear `debit_card` como `account_id` (I5); nos selects, o rótulo é o cartão e o `value` é a
   conta vinculada.
6. Gerar `client_uuid` no modal "Lançar" (P-01).

**Depois:**
7. `SpendingGuard` + `Account` (accessors) — sem bloquear nada ainda.
8. Migration `overdraft_limit` + Request + campo no form.
9. `RequiresFundingChoice` + `FundingService` + guard nos 6 caminhos, com lock.
   ⚠️ Aqui a suíte quebra: factories/testes que gastam mais que o saldo precisam de ajuste.
10. Padronizar a ordem de lock conta ↔ pai entre `FundingService` e `HandlesContributions`.
11. I6 (resgate limitado à conta de origem) — corrige E-02.
12. Modal de escolha (3 front-ends) + 409 na fila offline e no service worker.
13. Segregação na UI (sidebar, dashboard, cards, contrato `caixa`) + CLAUDE.md.
14. `Brl::format` + `@brl` + varredura das views.
15. Faturas vencidas: `closedCycle`, `dueDateForCycle`, `PayInvoiceRequest` com data, sino.
16. Contas fixas: tabela, model, service, controller, bloco em `/faturas`.
17. Botão de pagar recorrência (G-01) e sino no mobile (P-10).
18. Atualizar CLAUDE.md (contrato do dashboard, modelo de dados, rotas, convenção BRL,
    contagem de testes — hoje diz 184/610, o real é **218 testes / 723 asserções**).

---

## 12. Casos de teste

**Cheque especial:** conta sem limite recusa R$ 0,01 acima do disponível · limite 2.500 e
disponível 0 aceita 2.500 com `funding_source=cheque_especial` · recusa 2.500,01 com o máximo na
mensagem · sem `funding_source` devolve **409** (não 422) · limite só persiste em `checking` ·
limite negativo recusado · `"2.500,00"` normaliza para `2500.00`.

**Segregação:** aporte 1.000 sobre bruto 2.500 → disponível 1.500 no dashboard **e** na sidebar
(regressão B-02, inclusive com cartão de débito na família) · gasto de 1.500 → disponível 0 e
investido **1.000,00** · `patrimonio == disponivel + guardado + investido`.

**Escolha de fonte:** payload do 409 com `faltante` correto · `resgate_investimento` com faltante
300 cria **1** resgate + a despesa na mesma transação (disponível 0, aplicado 700) · faltante >
aplicado → 422 e **nada** gravado (rollback) · investimento de outra família → 422 · disponível
parcial 50 e despesa 300 → resgate de **250** · duas despesas de 2.000 simultâneas com limite
2.500 → só uma passa · aporte **não** usa cheque especial · mesmo `client_uuid` reenviado não
duplica despesa **nem** resgate.

**Débito (PASSO 0):** despesa em `debit_card` é recusada · a migration move as transações
legadas e o `down()` devolve · aporte pelo débito não duplica o guardado (B-03).

**Datas:** compra em 31/01 em 3x cai em **31/01, 28/02, 31/03** (E-01) · fatura de ciclo fechado
não paga aparece como **vencida há N dias** (D-01) · `upcomingDue` traz vencidos primeiro ·
fechamento 20 + vencimento 5 → vencimento **posterior** ao fechamento (D-04) · `due_day=31` numa
conta fixa em fevereiro cai em 28/29 · pagar fatura com data retroativa grava a data informada (D-06).

**Contas fixas:** dia 10, hoje 13, não paga → **vencida**, sino conta 1, badge vermelho ·
`occurrences` chamado duas vezes não duplica · `ends_on` passado não gera mais · pagar respeita I1
· pagar duas vezes a mesma competência é idempotente (unique) · ocorrência não paga **não**
desconta do saldo (garantido por construção — não há linha).

**Reserva:** resgatar para conta sem aporte é recusado (E-02) · excluir investimento com saldo
negativo na conta (E-03 — definir em D-9).

**BRL:** `Brl::format(-1234.56)` → `−R$ 1.234,56` · nenhuma view renderiza valor sem `R$` e
vírgula decimal.

---

## 13. Riscos

| # | Risco | Mitigação |
|---|---|---|
| R1 | A validação de saldo quebra dezenas de testes | Ajustar factories (`initial_balance` alto, state `overdraft()`) num commit **antes** do passo 9. `TransactionFactory` hoje cria `user_id` e `account_id` de donos diferentes por padrão. |
| R2 | Deadlock entre despesa-com-resgate e aporte concorrente | Ordem de lock padronizada (passo 10), **obrigatória**. |
| R3 | Fila offline rejeitada em massa | Retry único com `cheque_especial` + estado "precisa da sua atenção", nunca descartar em silêncio. Corrigir o service worker junto (P-03). |
| R4 | Migration do PASSO 0 mexe em dados reais | `legacy_account_id` torna o `down()` real. Rodar em cópia do banco antes. |
| R5 | `upcomingDue` roda em toda página e já tem N+1 | Refatorar para query agregada **no mesmo passo** em que ganha contas fixas. |
| R6 | Dependente consome todo o cheque especial | Acesso total na família é o modelo atual. Permissão por dependente é subprojeto à parte — **não** reviver `spending_limit` (P-09). |
| R7 | Outra sessão está mexendo no repo agora (LGPD/legal, sem commit) | Não commitar trabalho alheio; conferir `git status` antes de qualquer commit. |
| R8 | `amount` sem `decimal:0,2` diverge entre sqlite e MySQL (P-11) | Acrescentar `decimal:0,2` no passo 9, junto do guard com epsilon. |

---

## 14. Decisões em aberto (só o Victor responde)

| # | Pergunta | Recomendação |
|---|---|---|
| **D-1** | Cheque especial só em **conta corrente** ou também poupança? | Só corrente. |
| **D-2** | O modal pode oferecer **resgate de meta**, ou só investimento? | Só investimento — meta é objetivo, resgatar dela deve ser deliberado. |
| **D-3** | O piso é sobre o **disponível** (protege o investido) ou sobre o **saldo bruto** (como o banco faz)? Ver §2.1. | **Disponível** — é o que foi pedido. |
| **D-4** | Pagar fatura entra no fluxo de escolha de fonte, ou é sempre permitido? | Entra — é a maior saída de caixa do app (D-02). |
| **D-5** | Migrar a recorrência de cartão para `fixed_bills` ou manter as duas? | Manter separadas nesta rodada, documentar, e **adicionar o botão que falta**. |
| **D-6** | Despesa em **cartão de débito**: bloquear no select, ou o select manda a conta vinculada? | Select mostra o cartão e envia a conta vinculada — menos atrito. |
| **D-7** | Cobrar **juros** do cheque especial? | Não nesta rodada — só limite e vermelho. |
| **D-8** | Criar **estorno** de pagamento de fatura (D-07)? | Sim, é barato e hoje o clique é irreversível. |
| **D-9** | Excluir investimento com cheque especial em uso "cura" o negativo (E-03). Bloquear a exclusão, ou permitir? | Bloquear enquanto `available < 0`. |
| **D-10** | Excluir conta/perfil com cheque especial usado ou fatura em aberto (P-08)? | Bloquear com mensagem explicando. |
| **D-11** | Liberar `closing_day`/`due_day` de cartão para **1..31** (D-05)? O clamp já existe no model. | Sim. |
| **D-12** | Editar/excluir **parcela isolada** pelo Histórico continua permitido (P-04, P-05)? | Não — guard por `group_id` + selo "faz parte de uma compra parcelada". |

---

## 15. Procedência

- Varredura: 23 agentes, 7 subsistemas, 121 achados brutos, 87 de severidade alta/média.
  14 submetidos a verificação cética independente → **14 confirmados, 0 refutados**.
  **73 achados alta/média não passaram por verificação cética** e continuam como hipótese.
- Prova empírica: `tests/Feature/TmpAuditoriaTest.php` (11 casos, todos verdes contra o código
  atual). **Apagar antes do commit final** — é instrumento de auditoria, não teste de regressão.
  Os casos úteis viram testes de verdade na §12.
- Baseline no dia da auditoria: **218 testes / 723 asserções** verdes.
