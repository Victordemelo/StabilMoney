# Spec — Cheque especial, segregação do investido e contas fixas mensais

**Data:** 27/07/2026
**Status:** proposta (não implementada)
**Autor:** Claude Code, a pedido do Victor
**Escopo:** modelo de dinheiro do Stabil Money — limite de cheque especial por conta,
separação real entre "saldo em conta" e "valor investido", escolha da fonte do dinheiro
quando o saldo acaba, e vencimentos (fatura vencida + contas fixas mensais).

> Esta spec também é a **varredura de campos** pedida: a seção 3 documenta como o sistema
> está hoje, a seção 4 lista os defeitos encontrados na auditoria, e a seção 10 é o
> inventário campo a campo do que muda.

---

## 1. O que o Victor pediu (requisitos, na ordem em que foram ditos)

| # | Requisito | Onde está nesta spec |
|---|---|---|
| **A** | Poder digitar o valor do **cheque especial**. Ex.: R$ 2.500. Ele recebe R$ 2.500 → saldo R$ 2.500. Ao chegar a 0, começa a contar **em vermelho** até **−R$ 2.500**; além disso **não pode gastar**. | §5 |
| **B** | **Separar o valor investido do saldo em conta**, para não somar os dois nem descontar gasto do investido. | §6 |
| **C** | Quando o saldo chega a 0 e ele vai gastar, o sistema **pergunta a fonte**: cheque especial (fica negativo até o limite) ou valor investido (vai baixando o investido, **piso 0,00**). | §7 |
| **D** | Avisar quando a **conta de crédito não foi paga** (conta **vencida**) e permitir cadastrar **contas fixas mensais** (condomínio, carro, casa, apartamento) que **não podem vencer**. | §8 |
| **E** | Padrão **BRL R$** em tudo. | §9 |
| **F** | Varrer **todos os campos** do sistema e conferir se estão certos (inclusive se a data de pagamento é obedecida). | §3, §4, §10 |

---

## 2. Modelo de dinheiro proposto — os quatro "bolsos"

Hoje o app tem um conceito só que importa (`saldo`) e dois derivados escondidos.
A proposta é dar nome e visibilidade a **quatro bolsos por conta de caixa**
(conta corrente / poupança):

```
┌─────────────────────────────────────────────────────────────────────┐
│  SALDO BRUTO  (Account::balance)                                    │
│  = saldo inicial + receitas efetivadas − despesas efetivadas        │
│                                                                     │
│  ├── RESERVADO  (Account::reserved)                                 │
│  │   = Σ aportes − Σ resgates (metas + investimentos) desta conta   │
│  │   → dinheiro "carimbado": está na conta, mas NÃO é para gastar   │
│  │                                                                  │
│  └── DISPONÍVEL  (Account::available = bruto − reservado)           │
│      → É ISTO que o usuário chama de "meu saldo".                   │
│        É este número que pode ficar VERMELHO e NEGATIVO.            │
└─────────────────────────────────────────────────────────────────────┘
                    +
┌─────────────────────────────────────────────────────────────────────┐
│  CHEQUE ESPECIAL  (accounts.overdraft_limit)  ← NOVO                │
│  crédito extra do banco; só entra em jogo quando DISPONÍVEL < 0     │
└─────────────────────────────────────────────────────────────────────┘

PODER DE GASTO = DISPONÍVEL + CHEQUE ESPECIAL       ← teto de uma despesa
PISO DO SALDO  = − CHEQUE ESPECIAL                  ← invariante I1
```

**Decisão-chave (resolve o requisito B sem reescrever o app):** o número que a UI
chama de **"Saldo em conta"** passa a ser o **DISPONÍVEL**, não o saldo bruto.
Como o `reservado` já desconta metas + investimentos, gastar nunca mais "come"
o investido: o investido é um bolso separado que só muda por aporte/resgate explícito.

> **Alternativa descartada:** fazer o aporte gerar uma transação de saída da conta
> (o dinheiro sai fisicamente do saldo). Isso mudaria o significado de todo o
> histórico, quebraria o patrimônio, o fluxo de caixa e os gráficos, e obrigaria a
> migrar os dados existentes. O modelo "cofrinho" atual entrega o mesmo resultado
> visível com muito menos risco. Registrado aqui para não ser rediscutido do zero.

### 2.1 Exemplo numérico (o caso que o Victor descreveu)

Conta corrente Nubank, cheque especial R$ 2.500, R$ 1.000 investidos num CDB.

| Evento | Bruto | Reservado | **Disponível (o "saldo")** | Investido | Pode gastar até |
|---|---:|---:|---:|---:|---:|
| Recebe salário R$ 2.500 | 2.500,00 | 0,00 | **2.500,00** | 0,00 | 5.000,00 |
| Aporta R$ 1.000 no CDB | 2.500,00 | 1.000,00 | **1.500,00** | 1.000,00 | 4.000,00 |
| Gasta R$ 1.500 | 1.000,00 | 1.000,00 | **0,00** 🟡 | 1.000,00 | 2.500,00 |
| Gasta R$ 300 → **escolhe cheque especial** | 700,00 | 1.000,00 | **−300,00** 🔴 | 1.000,00 | 2.200,00 |
| Gasta R$ 300 → **escolhe investimento** | 700,00 | 700,00 | **−300,00** 🔴 | **700,00** | 2.200,00 |
| Tenta gastar R$ 2.300 (disp. −300, teto 2.200) | — | — | **bloqueado** | — | — |

Na linha do resgate: o resgate de R$ 300 devolve R$ 300 do CDB para a conta
(reservado cai de 1.000 → 700, disponível sobe de −300 → 0) e **em seguida** a
despesa de R$ 300 leva o disponível de volta a... 0 − 300 = −300? Não:

```
antes:      bruto 1.000  reservado 1.000  disponível    0,00   investido 1.000
resgate 300 bruto 1.000  reservado   700  disponível  300,00   investido   700
despesa 300 bruto   700  reservado   700  disponível    0,00   investido   700
```

**Correto:** ao escolher "investimento", o disponível fica em **0,00** (não negativo)
e o investido cai para 700. A linha da tabela acima está simplificada; a regra real
está em §7.3.

---

## 3. Como o sistema está HOJE (fatos, com arquivo:linha)

### 3.1 Saldo

| Conceito | Fórmula atual | Onde |
|---|---|---|
| `Account::balance` | `initial_balance + Σ income − Σ expense` de **todas** as transações da conta, **sem filtro de data nem de `paid_at`** | `app/Models/Account.php:140-152` |
| `Account::reserved` | Σ aportes − Σ resgates (metas + investimentos) **originados nesta conta** | `app/Models/Account.php:171-186` |
| `Account::available` | `balance − reserved` | `app/Models/Account.php:189-192` |
| Cartão de débito | `balance` = saldo da corrente + poupança vinculadas (espelho) | `app/Models/Account.php:143-145` |
| Cartão de crédito | não é caixa; tem `credit_limit`, `committed`, `availableLimit` | `app/Models/Account.php:277-300` |

### 3.2 Patrimônio (sidebar) × Saldo (dashboard)

| | Sidebar (`SidebarService`) | Dashboard (`DashboardService`) |
|---|---|---|
| Contas excluídas do total | **só** `credit_card` (`SidebarService.php:34-37`) | `credit_card` **e** `debit_card` (`DashboardService.php:70-72`) |
| Total exibido | `saldoTotal` = bruto (inclui reservado/investido) | `totalBalance` = bruto (inclui reservado/investido) |
| Quebra | `disponivel` / `guardado` / `investido` / `emConta` (`SidebarService.php:93-106`) | não tem quebra |

→ Os dois totais **divergem** quando existe transação lançada direto num cartão de
débito (§4, achado C-01).

### 3.3 Onde há (e onde não há) validação de saldo

| Caminho de escrita | Valida saldo? | Onde |
|---|---|---|
| Aporte em meta/investimento | ✅ contra `account->available`, com relock | `StoreInvestmentContributionRequest.php:44-53` + `HandlesContributions.php:64-76` |
| Resgate de meta/investimento | ✅ contra `parent->saved/aplicado`, com relock | `WithdrawInvestmentContributionRequest.php:44-52` + `HandlesContributions.php:55-63` |
| **Despesa** (`transactions.store`) | ❌ **nenhuma** | `StoreTransactionRequest.php:40` (só `min:0.01`/`max`) |
| **Despesa** (`transactions.update`) | ❌ nenhuma | `UpdateTransactionRequest` |
| **Despesa** (`faturas.lancar`) | ❌ nenhuma | `StoreFaturaLaunchRequest` |
| **Pagar fatura** (cria a saída de caixa) | ❌ nenhuma | `FaturaController.php:145-153` |
| **Limite do cartão de crédito** | ❌ não é validado no lançamento (dá para estourar) | `FaturaController.php:34-69` |

### 3.4 Datas e vencimentos

| Mecanismo | Comportamento atual | Onde |
|---|---|---|
| Ciclo da fatura | `(fechamento anterior, próximo fechamento]`, com clamp de dia curto | `Account::billingCycle()` — `Account.php:220-246` |
| Dias aceitos | `closing_day` e `due_day` entre **1 e 28** (validação) | `StoreAccountRequest.php:67-68` |
| Vencimento | `Account::dueDate` = **próxima** data com `due_day` **≥ hoje** | `Account.php:334-351` |
| Fatura em aberto | só do **ciclo aberto**, despesas com `paid_at = null` | `Account.php:309-328` |
| Pagar fatura | marca `paid_at` das despesas do ciclo + cria 1 despesa na conta de caixa escolhida, datada em **hoje** | `FaturaController.php:120-154` |
| Recorrência | 1 ocorrência em aberto, datada no `dueDate` do cartão; **pagar** gera a próxima (+1 mês) | `FaturaController.php:191-248` |
| Sino (topbar) | faturas com vencimento ≤ 7 dias **e** valor em aberto + recorrências não pagas com `date ≤ hoje+7` | `FaturaService::upcomingDue()` — `FaturaService.php:60-102` |
| "Vencida" na UI | a topbar **já sabe** renderizar `dias < 0` como *vencida* | `partials/topbar.blade.php:59` |
| Agendador | **não existe** — `bootstrap/app.php` sem `withSchedule`, sem `app/Console/` | `bootstrap/app.php` |

### 3.5 Formatação monetária

Padrão dominante: `'R$ ' . number_format($v, 2, ',', '.')` (ex.: `faturas/index.blade.php:17`,
`accounts/index.blade.php:59`). No dashboard o "R$" é um `<span class="cur">` separado do
número (`dashboard.blade.php:86`). Um valor negativo hoje sai como **`R$ -1.234,56`**
(sinal depois do símbolo) — ver §9.

---

## 4. Achados da auditoria

> Preenchido a partir da varredura em 7 subsistemas com verificação cética independente.
> Ver seção "Resultado da varredura" ao final do documento.

---

## 5. Requisito A — Cheque especial

### 5.1 Modelo de dados

Migration **`2026_07_27_100000_add_overdraft_limit_to_accounts.php`**:

```php
Schema::table('accounts', function (Blueprint $table) {
    // Limite do cheque especial (crédito rotativo da conta corrente).
    // null = a conta não tem cheque especial. Só faz sentido em checking.
    $table->decimal('overdraft_limit', 15, 2)->nullable()->after('initial_balance');
});
// down(): $table->dropColumn('overdraft_limit');
```

- **Por que na conta e não no usuário?** Cada banco concede um limite diferente, e o
  bloqueio é por conta: o cheque especial do Nubank não paga uma compra no Itaú.
- **Só `checking`.** Poupança não tem cheque especial no Brasil; cartão de crédito já
  tem `credit_limit`; cartão de débito não tem saldo próprio. → **decisão em aberto D-1**
  caso o Victor queira liberar para poupança também.

### 5.2 Model `Account`

```php
protected $fillable = [..., 'overdraft_limit'];
protected function casts(): array { return [..., 'overdraft_limit' => 'decimal:2']; }

/** Limite de cheque especial efetivo (0 quando a conta não tem). */
public function getOverdraftLimitValueAttribute(): float
{
    return $this->type === 'checking' ? round((float) $this->overdraft_limit, 2) : 0.0;
}

/** Teto de uma despesa nesta conta sem tocar em investimento: disponível + cheque especial. */
public function getSpendingPowerAttribute(): float
{
    return round($this->available + $this->overdraftLimitValue, 2);
}

/** Quanto do cheque especial já está sendo usado (0 quando o disponível é positivo). */
public function getOverdraftUsedAttribute(): float
{
    return round(max(0.0, -$this->available), 2);
}

/** Quanto ainda resta do cheque especial. */
public function getOverdraftAvailableAttribute(): float
{
    return round(max(0.0, $this->overdraftLimitValue - $this->overdraftUsed), 2);
}
```

### 5.3 Validação (Form Request)

`StoreAccountRequest` (herdado por `UpdateAccountRequest`):

```php
// prepareForValidation()
$this->normalizeMoneyField('overdraft_limit');
if ($this->input('type') !== 'checking') {
    $this->merge(['overdraft_limit' => null]);
}

// rules()
'overdraft_limit' => $type === 'checking'
    ? ['nullable', 'numeric', 'min:0', 'max:9999999999999.99']
    : ['nullable'],
```

`attributes()`: `'overdraft_limit' => 'limite do cheque especial'`
`messages()`:
- `overdraft_limit.numeric` → *"O limite do cheque especial deve ser um número. Use vírgula para os centavos, ex.: 2.500,00."*
- `overdraft_limit.min` → *"O limite do cheque especial não pode ser negativo."*
- `overdraft_limit.max` → *"O limite informado é alto demais."*

### 5.4 UI do cadastro (`resources/views/accounts/_form.blade.php`)

Novo bloco, **só para `checking`** (o bloco `data-fields-account` atual cobre
`checking` **e** `savings`, então precisa de um grupo próprio):

```blade
<div class="field" data-fields-overdraft @if ($tipoAtual !== 'checking') hidden @endif>
    <label for="overdraft_limit">Limite do cheque especial (R$)</label>
    <input class="input @error('overdraft_limit') input-error @enderror" type="text"
           inputmode="decimal" id="overdraft_limit" name="overdraft_limit"
           placeholder="Ex.: 2.500,00" value="{{ $chequeAtual }}">
    <small class="form-hint">Quanto o banco deixa você ficar negativo nesta conta.
        Deixe em branco ou 0,00 se sua conta não tem cheque especial.</small>
    @error('overdraft_limit')<div class="field-error">{{ $message }}</div>@enderror
</div>
```

E no `<script>` do form, mais uma entrada em `grupos`:
`overdraft: form.querySelector('[data-fields-overdraft]')` com
`grupos.overdraft.hidden = t !== 'checking'`.

`inputmode="decimal"` faz o `sm/money.js` formatar em BRL no blur automaticamente.

### 5.5 Invariante e bloqueio — §7.4 (compartilhado com o requisito C)

### 5.6 Exibição em vermelho

| Lugar | Hoje | Depois |
|---|---|---|
| Card "Patrimônio total" (sidebar) | `.sb-value.neg` já existe | mantém, mas o valor passa a ser o **disponível** |
| Stat "Saldo total" (dashboard) | `.value.neg` já existe (`dashboard.blade.php:86`) | mantém |
| Card da conta (`accounts/index`) | ❌ sem tratamento — sai `R$ -1.234,56` em cor normal | ganha `.acct-balance.neg` + formato `−R$ 1.234,56` |
| Cartão visual `.cc-balance` | ❌ sem tratamento | idem |
| Select de conta no modal "Lançar" | não mostra saldo | passa a mostrar `— disponível R$ x` (§7.2) |

Nova barra de cheque especial no card da conta (só quando `overdraft_limit > 0`),
reaproveitando o `.dp-bar` já usado no limite do cartão:

```blade
@if ($conta->overdraftLimitValue > 0)
    <div class="fh-limit">
        <div class="dp-bar cheque"><i style="width:{{ $pctUsado }}%"></i></div>
        <div class="dp-meta">
            <span>Cheque especial usado</span>
            <span>{{ $brl($conta->overdraftUsed) }} de {{ $brl($conta->overdraftLimitValue) }}</span>
        </div>
    </div>
@endif
```

---

## 6. Requisito B — Separar investido do saldo em conta

### 6.1 Serviço único de bolsos

Criar **`app/Services/BalanceService.php`** como fonte única (hoje a mesma conta é
feita em dois lugares que divergem — §4/C-01):

```php
class BalanceService
{
    /** Bolsos consolidados da família (usado por sidebar, dashboard e faturas). */
    public function pockets(int $ownerId): array
    {
        return [
            'bruto'            => float,  // saldo cru das contas de caixa
            'guardadoMetas'    => float,  // Σ aportes − Σ resgates de metas
            'investido'        => float,  // Σ aportes − Σ resgates de investimentos
            'disponivel'       => float,  // bruto − guardado − investido  ← "saldo em conta"
            'chequeEspecial'   => float,  // Σ overdraft_limit das contas correntes
            'chequeUsado'      => float,  // Σ max(0, −disponível) por conta
            'poderDeGasto'     => float,  // Σ max(0, disponível) + (cheque − usado)
            'patrimonio'       => float,  // disponivel + guardadoMetas + investido
        ];
    }

    /** Bolsos de UMA conta (usado na validação e no endpoint de fonte). */
    public function accountPockets(Account $account): array { /* ... */ }
}
```

**Regra de quais contas entram** (unificada — hoje diverge):
`checking` e `savings` **entram**; `credit_card` e `debit_card` **ficam de fora**
(o débito espelha as contas vinculadas; contá-lo duplicaria o dinheiro).

`SidebarService` e `DashboardService` passam a delegar para `BalanceService`.

### 6.2 O que muda na sidebar (`partials/sidebar.blade.php`)

| Rótulo hoje | Valor hoje | Rótulo depois | Valor depois |
|---|---|---|---|
| **Patrimônio total** | bruto (contas, inclui investido) | **Patrimônio total** | `disponivel + guardadoMetas + investido` (mesmo número, agora explicitamente somado) |
| Em conta | `disponivel + guardado` | **Saldo em conta** | `disponivel` (pode ser negativo/vermelho) |
| Guardado em metas | `guardado` | **Guardado em metas** | igual |
| Investido | `investido` | **Investido** | igual |
| — | — | **Cheque especial** *(novo, só se houver limite)* | `chequeUsado` de `chequeEspecial` |

### 6.3 O que muda no dashboard

O stat **"Saldo total"** passa a mostrar o **disponível** (`pockets.disponivel`), com o
sub-rótulo *"livre para gastar"*. O investido e as metas continuam nos seus próprios cards.

**Contrato `#sm-dashboard-data`** — aditivo, sem quebrar o `dashboard.js`:

```jsonc
"periods": { "mes": { "stats": {
      "saldo": 0.0,        // ← MUDA DE SIGNIFICADO: agora é o DISPONÍVEL, não o bruto
      "receitas": 0.0, "despesas": 0.0, "economia": 0.0
} } },
"pockets": {               // ← NOVO bloco, no topo do payload
  "bruto": 0.0, "guardadoMetas": 0.0, "investido": 0.0, "disponivel": 0.0,
  "chequeEspecial": 0.0, "chequeUsado": 0.0, "poderDeGasto": 0.0, "patrimonio": 0.0
}
```

`dashboard.js` já anima qualquer `data-count`; a mudança de valor de `saldo` não exige
alteração no JS. **O CLAUDE.md precisa ser atualizado** com o novo contrato.

### 6.4 Aporte continua sem poder usar cheque especial

`StoreInvestmentContributionRequest` / `StoreGoalContributionRequest` continuam validando
contra `account->available` (**sem** somar o cheque especial). Guardar dinheiro usando
crédito do banco não faz sentido e criaria um loop (aportar → estourar → resgatar).

---

## 7. Requisito C — Escolha da fonte quando o saldo acaba

### 7.1 Quando a pergunta aparece

Só quando **todas** estas condições valem no momento do envio de uma **despesa**:

1. `type = expense`;
2. a conta é de **caixa** (`checking` ou `savings`) — cartão de crédito segue o limite de crédito;
3. `amount > max(0, account.available)` — ou seja, o disponível não cobre sozinho;
4. existe pelo menos **uma** fonte viável: cheque especial com saldo, ou investimento com valor aplicado.

Se **nenhuma** fonte cobre o valor → erro de validação direto, sem modal.

### 7.2 Endpoint de consulta

```
GET /accounts/{account}/fontes?amount=300,00     name: accounts.fontes
```

Registrado **antes** do `Route::resource('accounts', ...)` para não colidir.
Autorização: `AccountPolicy@view` (escopo de família). Resposta:

```jsonc
{
  "conta": { "id": 7, "nome": "Nubank", "tipo": "checking" },
  "disponivel": 0.00,
  "faltante": 300.00,                    // amount − max(0, disponivel)
  "fontes": [
    { "id": "cheque_especial", "rotulo": "Usar o cheque especial",
      "teto": 2500.00, "cobre": true,
      "detalhe": "Sua conta fica negativa (em vermelho) até −R$ 2.500,00" },
    { "id": "investimento", "rotulo": "Resgatar de um investimento",
      "teto": 1000.00, "cobre": true,
      "itens": [ { "id": 3, "nome": "CDB Inter", "aplicado": 1000.00 } ] }
  ]
}
```

Só entram no array `itens` os investimentos **com aporte originado nesta conta**
(`investment_contributions.account_id = conta`), porque o resgate devolve o dinheiro
para a conta de onde saiu. Metas **não** entram: meta é objetivo do usuário, resgatar
dela para pagar uma despesa qualquer merece ser um ato deliberado na tela de metas.
→ **decisão em aberto D-2.**

### 7.3 Campos novos no POST de despesa

| Campo | Tipo | Regra |
|---|---|---|
| `funding_source` | string | `nullable`, `in:disponivel,cheque_especial,investimento`. Default `disponivel`. **Obrigatório** (`required_if`) quando o valor estoura o disponível. |
| `funding_investment_id` | int | `nullable`; **obrigatório** quando `funding_source = investimento`; `Rule::exists('investments','id')->where('user_id', $ownerId)`. |

**Semântica de `funding_source = investimento`** (o ponto delicado):
o resgate é do **faltante**, não do valor total.

```
faltante = round(amount − max(0, account.available), 2)
```

E a gravação acontece **numa única `DB::transaction`**, nesta ordem:

```php
DB::transaction(function () {
    $account = Account::whereKey($id)->lockForUpdate()->first();   // 1. trava a conta

    // 2. recheque sob lock (time-of-use)
    $faltante = round($amount - max(0.0, $account->available), 2);

    if ($fonte === 'investimento') {
        $inv = Investment::whereKey($invId)->lockForUpdate()->first();   // ordem: conta → investimento
        if ($faltante > $inv->aplicado + 0.001) { throw ValidationException::withMessages([...]); }

        // 3. resgate primeiro (repõe o disponível)
        $inv->contributions()->create([
            'account_id' => $account->id, 'made_by_user_id' => $autor,
            'type' => 'resgate', 'amount' => $faltante, 'date' => $data,
        ]);
    } elseif ($fonte === 'cheque_especial') {
        if ($amount > $account->spendingPower + 0.001) { throw ValidationException::withMessages([...]); }
    } else {
        if ($amount > max(0.0, $account->available) + 0.001) { throw ValidationException::withMessages([...]); }
    }

    // 4. a despesa
    Transaction::create([...]);
});
```

**Ordem de lock: sempre `Account` → `Investment`.** O `HandlesContributions` de hoje
trava **pai primeiro, conta depois** (`HandlesContributions.php:49-76`). Como os dois
caminhos podem rodar em paralelo, isso é um **deadlock em potencial**. A spec padroniza
**conta → pai** e o `HandlesContributions` deve ser ajustado para a mesma ordem.
→ **item obrigatório da implementação, não opcional.**

**Piso 0,00 do investido:** garantido por `faltante ≤ inv.aplicado`, checado sob lock.

### 7.4 Invariante e camada de bloqueio

**I1 — Piso do saldo.** Para toda conta de caixa, após qualquer escrita:
```
account.available  ≥  − account.overdraftLimitValue   (tolerância 0,001)
```

**I2 — Piso do investido.** Para todo investimento/meta: `aplicado ≥ 0` / `saved ≥ 0`.

**I3 — Limite do cartão.** Para todo cartão de crédito: `committed ≤ credit_limit`.

**Onde cada uma é checada — duas camadas, mesma mensagem:**

| Camada | O quê | Por quê |
|---|---|---|
| **Form Request** (`Concerns\ValidatesSpendingPower`, novo trait) | regra de closure no campo `amount` | mensagem PT-BR no campo certo; funciona no form cheio, no modal (422 JSON) e na fila offline |
| **Controller, dentro da `DB::transaction` com `lockForUpdate`** | recheque de I1/I2/I3 | fecha a corrida entre validar e gravar (mesmo padrão já usado em `HandlesContributions.php:41-87`) |

**Caminhos que precisam da checagem** (todos, sem exceção):

| Caminho | Arquivo | Invariante |
|---|---|---|
| `transactions.store` | `TransactionController@store` + `StoreTransactionRequest` | I1 (caixa) / I3 (crédito) |
| `transactions.update` | `TransactionController@update` + `UpdateTransactionRequest` | I1/I3, **recalculando sem a linha antiga** |
| `faturas.lancar` | `FaturaController@store` + `StoreFaturaLaunchRequest` | I1/I3; parcelado valida **a soma** contra I3 |
| `faturas.fatura.pagar` | `FaturaController@payInvoice` | I1 na conta de caixa escolhida |
| `faturas.recorrente.pagar` | `FaturaController@pay` | I1 |
| aportes de meta/investimento | `HandlesContributions` | disponível **sem** cheque especial (§6.4) |

**Mensagens PT-BR** (formato BRL em todas):

- Sem cheque especial:
  *"Saldo insuficiente: a conta Nubank tem R$ 120,00 disponíveis e esta despesa é de R$ 300,00."*
- Com cheque especial, dentro do limite (só quando a fonte não foi escolhida):
  *"Esta despesa deixa a conta negativa. Escolha se quer usar o cheque especial ou resgatar de um investimento."*
- Estourando o cheque especial:
  *"Não dá: com esta despesa a conta ficaria em −R$ 2.800,00, e o limite do cheque especial é R$ 2.500,00. O máximo que você pode gastar agora é R$ 2.200,00."*
- Resgate maior que o aplicado:
  *"O investimento CDB Inter tem só R$ 700,00 aplicados — não dá para cobrir R$ 900,00."*
- Cartão estourando o limite:
  *"Esta compra passa do limite do cartão Nubank: restam R$ 340,00 de R$ 5.000,00."*

### 7.5 Front-end

**Novo módulo `resources/js/sm/funding.js`**, inicializado pelo `initContent` do `app.js`
(para sobreviver à navegação pjax).

Fluxo no `sm/launch.js` (modal global) e no form cheio de transação:

1. Cada `<option>` do select de conta ganha `data-disponivel`, `data-cheque`,
   `data-tipo` (server-rendered — funciona **offline**).
2. No `submit`, se `type=expense` + conta de caixa + `amount > disponivel` → **previne**
   o envio e abre o modal `#fundingModal`.
3. O modal é montado com os dados do `data-*` (sem rede). Quando **online**, um
   `fetch` para `accounts.fontes` refina a lista de investimentos; **offline**, mostra só
   o cheque especial (o resgate exige saber o aplicado real).
4. A escolha preenche dois `<input type="hidden">` (`funding_source`,
   `funding_investment_id`) e reenvia o form.
5. Se o servidor devolver 422 mesmo assim (saldo mudou entre a tela e o envio), o banner
   de erro do modal mostra a mensagem e reabre a escolha.

**Marcação do modal** — `resources/views/partials/funding-modal.blade.php`, usando os
primitivos já portados (`.modal-scrim` / `.modal` / `.field` / `.input` do `forms.css`):

```
┌──────────────────────────────────────────────┐
│ ⚠  De onde sai esse dinheiro?                │
│    Sua conta Nubank tem R$ 0,00 disponíveis  │
│    e esta despesa é de R$ 300,00.            │
├──────────────────────────────────────────────┤
│ ○ Usar o cheque especial                     │
│   A conta fica em −R$ 300,00 (vermelho).     │
│   Limite: R$ 2.500,00 · usado: R$ 0,00       │
│                                              │
│ ○ Resgatar de um investimento                │
│   [ CDB Inter — R$ 1.000,00 aplicados   ▾ ]  │
│   Vamos resgatar R$ 300,00. Sobram R$ 700,00.│
├──────────────────────────────────────────────┤
│              [ Cancelar ]  [ Confirmar ]     │
└──────────────────────────────────────────────┘
```

**Fila offline (`offline-queue.js`)**: o payload já carrega `funding_source`. Se o replay
receber **422**, o item **não** pode ser descartado nem reenviado em loop: vai para um
estado `precisa_atencao` no IndexedDB e o badge de fila mostra
*"1 lançamento precisa da sua atenção"*, abrindo a lista com a mensagem do servidor.
Hoje o `offline-queue.js` trata só 201/200/419 — **isso precisa ser implementado**.

---

## 8. Requisito D — Vencimentos, faturas vencidas e contas fixas mensais

### 8.1 Fatura de cartão vencida

**Problema (§4/F-01, F-02):** `Account::dueDate` devolve sempre uma data **≥ hoje**
(`Account.php:334-351`), e `openInvoiceDue` só olha o **ciclo aberto**
(`Account.php:309-328`). Consequência: uma fatura fechada e não paga **desaparece** da
tela e do sino no dia em que o ciclo vira — o oposto do que o Victor pediu.

**Correção:**

```php
/** Ciclos com despesas NÃO pagas, do mais antigo ao atual. Cada um com seu vencimento. */
public function openInvoices(?CarbonImmutable $today = null): Collection
// item: ['cycleStart','cycleEnd','dueDate','total','isOverdue','daysLate','items']
```

- `dueDate` **do ciclo**: primeiro dia `due_day` **após** `cycleEnd` (se `due_day ≤ closing_day`,
  cai no mês seguinte ao fechamento).
- `isOverdue` = `paid_at` null **e** `dueDate < hoje`.
- `FaturaService::build()` passa a listar **todas** as faturas em aberto por cartão
  (a vencida primeiro, com selo vermelho), não só a do ciclo atual.
- `FaturaService::upcomingDue()` passa a incluir os **vencidos** (dias negativos) — a
  topbar já renderiza "vencida" (`topbar.blade.php:59`), então é só alimentar.
- `payInvoice` recebe **qual ciclo** está sendo pago (`cycle_end` no POST), em vez de
  assumir o ciclo aberto.

### 8.2 Contas fixas mensais (condomínio, aluguel, carro…)

**Hoje não existe cadastro.** O que existe é o modo "recorrente" do lançamento de despesa
(`FaturaController@createRecurring`, `FaturaController.php:191-201`), que:
- cria **uma** ocorrência em aberto;
- a próxima só nasce quando alguém chama `faturas.recorrente.pagar` — e **essa rota não
  tem nenhum botão na UI** (§4/G-01), então a recorrência **nunca** avança;
- a ocorrência em aberto **já desconta do saldo**, mesmo sem ter sido paga (§4/B-01).

**Modelo novo** — migration `2026_07_27_100100_create_fixed_bills_table.php`:

| Coluna | Tipo | Regra |
|---|---|---|
| `id` | bigint | |
| `user_id` | FK users, cascade | dono = **titular** da família |
| `made_by_user_id` | FK users nullable, nullOnDelete | quem cadastrou |
| `name` | string 255 | obrigatório — "Condomínio", "Financiamento do carro" |
| `amount` | decimal(15,2) | `min:0.01` — valor mensal previsto |
| `category_id` | FK categories nullable, nullOnDelete | categoria da despesa gerada |
| `account_id` | FK accounts nullable, nullOnDelete | método de pagamento padrão |
| `due_day` | tinyint unsigned | `between:1,28` (mesma faixa dos cartões) |
| `start_month` | date | 1º dia do mês em que a conta começa |
| `end_month` | date nullable | null = sem fim |
| `auto_debit` | boolean default false | débito automático → marca paga sozinha no dia |
| `active` | boolean default true | pausar sem apagar o histórico |
| `timestamps` | | |

Índice: `['user_id', 'active']`.

Migration **`2026_07_27_100200_add_fixed_bill_to_transactions.php`**:

```php
$table->foreignId('fixed_bill_id')->nullable()->after('group_id')
      ->constrained('fixed_bills')->nullOnDelete();
$table->date('due_date')->nullable()->after('date');   // vencimento da ocorrência
$table->index(['fixed_bill_id', 'due_date']);
```

**As ocorrências continuam sendo `transactions`** — o histórico, o fluxo de caixa, o
donut por categoria e os filtros continuam funcionando sem duplicar modelo.

### 8.3 Geração das ocorrências

`app/Services/FixedBillService.php`:

```php
/** Garante que existam as ocorrências do mês corrente e do próximo. Idempotente. */
public function ensureOccurrences(int $ownerId, ?CarbonImmutable $today = null): int
```

Idempotência: chave lógica `(fixed_bill_id, ano-mês do due_date)` — antes de criar,
`whereBetween('due_date', [início do mês, fim do mês])`.

**Dois gatilhos, mesma função** (o app **não tem cron hoje** — §3.4):

1. **Preguiçoso (funciona em dev, sem cron):** `FaturaService::build()`, o
   `DashboardController` e o View Composer do sino chamam `ensureOccurrences()` antes de
   montar os dados.
2. **Agendado (produção):** comando `app/Console/Commands/GerarContasFixas.php`
   (`contas-fixas:gerar`) + `bootstrap/app.php`:

```php
->withSchedule(function (Schedule $schedule) {
    $schedule->command('contas-fixas:gerar')->dailyAt('03:00');
})
```

E, para a VPS, um serviço `scheduler` no `docker-compose.yml`
(`php artisan schedule:work`). → **decisão em aberto D-3.**

### 8.4 Estados de uma ocorrência

| Estado | Condição | UI |
|---|---|---|
| **A vencer** | `paid_at` null e `due_date ≥ hoje` | selo neutro, "vence em N dias" |
| **Vence hoje** | `paid_at` null e `due_date = hoje` | selo âmbar |
| **VENCIDA** | `paid_at` null e `due_date < hoje` | selo **vermelho**, "vencida há N dias", sino, card no dashboard |
| **Paga** | `paid_at` preenchido | selo verde |

### 8.5 Regra R-SALDO — conta não paga não desconta do saldo

**Esta é a mudança de comportamento mais delicada da spec.** Hoje `Account::balance`
soma **todas** as transações (`Account.php:147-150`), então uma parcela de dezembro ou um
condomínio ainda não pago já derrubam o saldo de hoje.

**Regra proposta:** uma despesa entra no saldo quando está **efetivada**:

| Tipo de despesa | Efetivada quando |
|---|---|
| Avulsa em conta de caixa, `date ≤ hoje` | sempre (o usuário lança o que já gastou) |
| Avulsa em conta de caixa, `date > hoje` | **não** — é uma despesa programada |
| Com `fixed_bill_id` ou `recurring = true` | só quando `paid_at` não é null |
| Em cartão de crédito | nunca entra no caixa (já é assim); entra quando a **fatura** é paga |

Implementação: um **scope** `Transaction::scopeEfetivadas()` usado por `Account::balance`,
`BalanceService`, `DashboardService::signedSumUntil/dailySums` e `SidebarService`.

**Impacto:** muda o saldo exibido de quem tem parcelas futuras. Os testes de
`DashboardTest`, `AccountCrudTest` e `FaturaCrudTest` precisam ser revistos.
→ **decisão em aberto D-4** (recomendação: **sim**, adotar — sem isso, "contas que não
podem vencer" não faz sentido, porque a conta já teria saído do saldo antes de ser paga).

### 8.6 Tela

Reaproveitar **`/faturas` ("Pagar despesas")**, que já é a tela de pagar coisas. Nova
seção no topo, acima das faturas de cartão:

```
┌── Contas fixas do mês ─────────────────── R$ 2.340,00 ──┐
│ 🏢 Condomínio        vence dia 10  🔴 vencida há 3 dias │
│    R$ 800,00                       [Marcar como paga]   │
│ 🚗 Financiamento     vence dia 15  🟡 vence em 2 dias   │
│    R$ 1.240,00                     [Marcar como paga]   │
│ 🏠 Aluguel           vence dia 05  ✅ paga em 05/07     │
│    R$ 300,00                                            │
│                                    [+ Nova conta fixa]  │
└─────────────────────────────────────────────────────────┘
```

Rotas novas:

```php
Route::get   ('/contas-fixas',            [FixedBillController::class, 'index'])  ->name('contas-fixas.index');
Route::post  ('/contas-fixas',            [FixedBillController::class, 'store'])  ->name('contas-fixas.store');
Route::patch ('/contas-fixas/{fixedBill}',[FixedBillController::class, 'update']) ->name('contas-fixas.update');
Route::delete('/contas-fixas/{fixedBill}',[FixedBillController::class, 'destroy'])->name('contas-fixas.destroy');
Route::post  ('/contas-fixas/ocorrencia/{transaction}/pagar',
              [FixedBillController::class, 'pay'])->name('contas-fixas.pagar');
```

`pay` faz o mesmo que `FaturaController@pay` (update condicional atômico em `paid_at`),
**mais** a checagem I1 na conta escolhida (pagar uma conta fixa é uma saída de caixa real).

**E o `faturas.recorrente.pagar` órfão?** Ganha um botão "Marcar como paga" nas despesas
recorrentes da tela (§4/G-01) **ou** as recorrências são migradas para `fixed_bills`.
→ **decisão em aberto D-5** (recomendação: migrar — dois mecanismos para a mesma coisa é
o que causou o bug).

### 8.7 Sino e dashboard

`FaturaService::upcomingDue()` passa a agregar três fontes, ordenadas por vencimento
(vencidas primeiro):

1. faturas de cartão em aberto (**incluindo as de ciclos fechados vencidos**);
2. ocorrências de contas fixas não pagas com `due_date ≤ hoje + 7`;
3. recorrências legadas (até a migração de D-5).

O badge do sino passa a distinguir: `.notif-badge.late` (vermelho) quando há **algum**
item vencido. O card "Contas a pagar" do dashboard ganha a contagem de vencidas.

---

## 9. Requisito E — Padrão BRL R$

**Regra única do projeto** (a ser adicionada ao CLAUDE.md):

```php
// app/Support/Brl.php  (novo)
final class Brl
{
    /** "R$ 1.234,56" / negativo: "−R$ 1.234,56" (menos ANTES do símbolo, com o traço U+2212). */
    public static function format(float|string|null $v, int $dec = 2): string
    {
        $n = round((float) $v, $dec);
        $sinal = $n < 0 ? '−' : '';
        return $sinal . 'R$ ' . number_format(abs($n), $dec, ',', '.');
    }
}
```

E um Blade directive `@brl($valor)` para as views.

| Situação | Hoje | Depois |
|---|---|---|
| Positivo | `R$ 1.234,56` | `R$ 1.234,56` (igual) |
| Negativo | `R$ -1.234,56` | `−R$ 1.234,56` |
| Zero | `R$ 0,00` | `R$ 0,00` |
| Sem centavos (stat compacto) | `R$ 1.235` | `R$ 1.235` |

No JS, `dashboard.js` continua animando o número e o `<span class="cur">R$</span>` fica
fora — para negativos o sinal precisa entrar **antes** do `.cur`. Ajuste no
`dashboard.blade.php:86` e no `dashboard.js`.

Entrada de dados (`sm/money.js`) **não muda**: continua descartando o sinal de menos,
porque valor digitado nunca é negativo (o sinal vem do `type`).

---

## 10. Inventário de campos — o que muda

### 10.1 Tabelas

| Tabela | Coluna | Ação | Tipo | Regra |
|---|---|---|---|---|
| `accounts` | `overdraft_limit` | **novo** | `decimal(15,2)` nullable | só `checking`; `min:0` |
| `transactions` | `fixed_bill_id` | **novo** | FK nullable, nullOnDelete | |
| `transactions` | `due_date` | **novo** | `date` nullable | vencimento da ocorrência |
| `transactions` | `funding_source` | **novo** | `string(20)` nullable | `disponivel\|cheque_especial\|investimento` — auditoria de como a despesa foi coberta |
| `fixed_bills` | (tabela inteira) | **nova** | — | §8.2 |

> `funding_source` na transação é opcional mas recomendado: sem ela, não há como
> reconstituir depois por que o saldo ficou negativo.

### 10.2 Form Requests

| Request | Campo | Ação |
|---|---|---|
| `StoreAccountRequest` (e `Update`, que herda) | `overdraft_limit` | novo: normalização BRL + `nullable\|numeric\|min:0\|max` quando `checking`; zerado nos outros tipos |
| `StoreTransactionRequest` | `funding_source`, `funding_investment_id` | novos |
| `StoreTransactionRequest` | `amount` | nova closure I1/I3 (trait `ValidatesSpendingPower`) |
| `UpdateTransactionRequest` | idem | idem, **descontando a linha atual** do cálculo |
| `StoreFaturaLaunchRequest` | `funding_source`, `funding_investment_id`, `amount` | idem |
| `StoreFixedBillRequest` / `UpdateFixedBillRequest` | todos de §8.2 | novos |
| `PayInvoiceRequest` (extrair do `payInvoice` inline) | `pay_account_id`, `cycle_end` | `cycle_end` novo |

### 10.3 Views

| View | Mudança |
|---|---|
| `accounts/_form.blade.php` | bloco `data-fields-overdraft` + grupo no JS |
| `accounts/index.blade.php` | saldo = **disponível**; `.neg` em vermelho; barra de cheque especial |
| `partials/sidebar.blade.php` | rótulos de §6.2 + linha do cheque especial |
| `dashboard.blade.php` | stat "Saldo total" = disponível; sinal antes do `R$` |
| `partials/launch-modal.blade.php` | `data-disponivel`/`data-cheque` nos `<option>`; hiddens de funding |
| `transactions/_form.blade.php` | idem |
| `partials/funding-modal.blade.php` | **novo** (§7.5) |
| `faturas/index.blade.php` | seção "Contas fixas do mês"; faturas vencidas; botão de pagar recorrência |
| `partials/topbar.blade.php` | badge vermelho quando há vencidos |

### 10.4 JS

| Arquivo | Mudança |
|---|---|
| `sm/funding.js` | **novo** — modal de escolha de fonte |
| `sm/launch.js` | intercepta o submit e delega para `funding.js` |
| `sm/offline-queue.js` | trata 422 → estado `precisa_atencao` |
| `sm/dashboard.js` | sinal antes do `R$` nos negativos |
| `app.js` | registra `initFunding()` no `initContent` |

---

## 11. Ordem de implementação

Cada passo deixa a suíte verde antes do próximo.

1. **`BalanceService` + unificação** (§6.1). Sem mudar nenhum número exibido ainda:
   só extrair a conta duplicada e corrigir a divergência sidebar × dashboard (§4/C-01).
   *Testes: os atuais continuam passando.*
2. **`overdraft_limit`**: migration + model + Form Request + campo no form (§5.1-5.4).
   *Sem bloqueio ainda — só guarda o número.*
3. **Trait `ValidatesSpendingPower` + recheque sob lock** nos 6 caminhos de escrita
   (§7.4), com `funding_source` default `disponivel`.
   ⚠️ Aqui a suíte quebra: factories/testes que criam despesa maior que o saldo
   precisam de saldo inicial ou de `funding_source`.
4. **Padronizar a ordem de lock** conta → pai no `HandlesContributions` (§7.3).
5. **Endpoint `accounts.fontes` + modal de escolha** (§7.2, §7.5) + tratamento de 422 na
   fila offline.
6. **Segregação na UI** (§6.2, §6.3): sidebar, dashboard, cards de conta, contrato JSON.
   Atualizar o CLAUDE.md.
7. **`Brl::format` + `@brl`** e varredura das views (§9).
8. **Faturas vencidas**: `openInvoices()`, `payInvoice` por ciclo, sino (§8.1).
9. **Contas fixas**: tabela, model, service, controller, tela, comando + schedule (§8.2-8.7).
10. **Regra R-SALDO** (§8.5) — por último, porque mexe em todos os agregados.
11. Atualizar **CLAUDE.md** (contrato do dashboard, modelo de dados, rotas, convenção BRL).

---

## 12. Casos de teste

### Cheque especial (I1)
1. Conta sem `overdraft_limit`: despesa de R$ 0,01 acima do disponível → **422**.
2. Limite R$ 2.500, disponível R$ 0: despesa R$ 2.500 com `funding_source=cheque_especial` → **201**, disponível = −2.500,00.
3. Mesma conta: despesa R$ 2.500,01 → **422** com o valor máximo na mensagem.
4. Despesa que estoura o disponível **sem** `funding_source` → **422** pedindo a escolha.
5. `overdraft_limit` só persiste em `checking`: salvar `savings`/`credit_card`/`debit_card` com o campo preenchido → grava `null`.
6. `overdraft_limit` negativo → **422**.
7. Entrada `"2.500,00"` (BRL) normaliza para `2500.00`.

### Segregação (B)
8. Bruto 2.500, aporte 1.000 → `available = 1.500`; dashboard e sidebar mostram **1.500**.
9. Despesa de 1.500 → `available = 0`, `investido` continua **1.000,00**.
10. Sidebar e dashboard devolvem o **mesmo** disponível (regressão do C-01), inclusive com cartão de débito na família.
11. `patrimonio == disponivel + guardadoMetas + investido`.

### Escolha de fonte (C)
12. `GET accounts.fontes` com disponível 0, cheque 2.500, CDB 1.000 → duas fontes, `faltante` correto.
13. `funding_source=investimento` com faltante 300 → cria **1** resgate de 300 **e** a despesa, na mesma transação; `available = 0`; `aplicado = 700`.
14. Faltante maior que o aplicado → **422** e **nada** é gravado (rollback).
15. `funding_investment_id` de outra família → **422**.
16. Disponível parcial (50) e despesa 300 → resgate de **250**, não de 300.
17. Concorrência: duas despesas de R$ 2.000 simultâneas com limite 2.500 → só uma passa.
18. Aporte **não** pode usar cheque especial (disponível 0, limite 2.500, aporte 100 → 422).

### Vencimentos (D)
19. Cartão fecha dia 10, vence dia 20; despesa em 05/06 não paga; hoje 25/07 → aparece como **vencida há 5 dias** (hoje some).
20. `upcomingDue` inclui itens vencidos, ordenados antes dos a vencer.
21. Conta fixa dia 10, hoje dia 13, não paga → estado **vencida**; sino conta 1; badge vermelho.
22. `ensureOccurrences` roda duas vezes no mesmo dia → **não** duplica ocorrência.
23. Conta fixa com `end_month` passado → não gera mais ocorrência.
24. `due_day = 28` em fevereiro → 28/02 (a faixa 1..28 evita o clamp, mas o teste fica de guarda).
25. Pagar conta fixa cria a saída de caixa e respeita I1.
26. Ocorrência não paga **não** desconta do saldo (R-SALDO); pagar desconta.

### Formatação (E)
27. `Brl::format(-1234.56)` → `−R$ 1.234,56`.
28. Nenhuma view renderiza valor monetário sem `R$` + vírgula decimal (teste de varredura em `view:cache` + regex nas respostas HTTP das telas principais).

---

## 13. Riscos

| # | Risco | Mitigação |
|---|---|---|
| R1 | A regra R-SALDO (§8.5) muda o saldo de todo mundo que tem parcela futura | Feature-flag não; adotar de uma vez, com teste de regressão e nota no CHANGELOG. Passo **10** da ordem, isolado. |
| R2 | Validação de saldo quebra ~dezenas de testes existentes | Ajustar factories (`AccountFactory` com `initial_balance` alto) num commit próprio antes do passo 3. |
| R3 | Deadlock entre despesa-com-resgate e aporte | Ordem de lock padronizada conta → pai (§7.3), obrigatória. |
| R4 | Fila offline rejeitada em massa ao sincronizar | Estado `precisa_atencao` + badge, nunca descartar silenciosamente (§7.5). |
| R5 | Dependente estourando o cheque especial do titular | Round atual = acesso total na família (CLAUDE.md). Permissão por dependente fica para o subprojeto de permissões. Documentar. |
| R6 | Cartão de débito continua sendo um buraco (§4/A-01) | Resolver junto: ou bloquear no select, ou redirecionar para a conta vinculada. Decisão D-6. |
| R7 | Sem cron em dev, contas fixas não avançam | Geração preguiçosa (§8.3), que funciona sem scheduler. |

---

## 14. Decisões em aberto (só o Victor responde)

| # | Pergunta | Recomendação |
|---|---|---|
| **D-1** | Cheque especial só em **conta corrente**, ou também em poupança? | Só corrente. |
| **D-2** | O modal de fonte pode oferecer **resgate de meta**, ou só investimento? | Só investimento (meta é objetivo, resgatar dela deve ser deliberado). |
| **D-3** | Adicionar um serviço `scheduler` no docker-compose para o cron? | Sim para produção; em dev a geração preguiçosa cobre. |
| **D-4** | Adotar a regra R-SALDO (conta não paga **não** desconta do saldo)? | **Sim** — sem isso "não pode vencer" não faz sentido. |
| **D-5** | Migrar as recorrências atuais para `fixed_bills`, ou manter os dois? | Migrar (dois mecanismos causaram o bug G-01). |
| **D-6** | Despesa lançada num **cartão de débito**: bloquear no select ou redirecionar para a conta corrente vinculada? | Bloquear e explicar ("escolha a conta que o cartão espelha"). |
| **D-7** | Quando a conta entra no cheque especial, cobrar **juros**? | Não nesta rodada — só o limite e o vermelho. |
| **D-8** | Guardar `funding_source` na transação (auditoria)? | Sim. |

---

## 15. Resultado da varredura

> Preenchido pela auditoria automatizada (7 subsistemas, verificação cética independente).
