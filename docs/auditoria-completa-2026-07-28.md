# Auditoria completa do Stabil Money — 27/28 de julho de 2026

Auditoria de segurança, validação e **cálculos financeiros**, com foco no "modelo de dinheiro
v3" (cheque especial, contas fixas, escolha de fonte), que nunca havia passado por revisão
independente.

**Método:** 8 auditores paralelos, cada um numa frente isolada, mais verificação própria. Todo
achado abaixo foi **executado** — cenário numérico no `tinker`, teste PHPUnit ou requisição HTTP
real —, nunca apenas lido. Os achados que eu mesmo confirmei estão marcados **✔ verificado por
mim**; os demais vêm com o cenário que o auditor rodou.

**Resultado da suíte:** 290 → **357 testes / 1.207 asserções, todos verdes**.
**Nada foi commitado** — tudo está no working tree para você revisar.

---

## ⚠️ LEIA ISTO PRIMEIRO — incidente: dados do banco de dev foram perdidos

**O que aconteceu:** durante a auditoria, os dados do seu banco de **desenvolvimento** foram
apagados. Estado antes (27/07 23:48) e depois:

| | Antes | Agora |
|---|---|---|
| Usuário | `victor.rosa.system@gmail.com` (id **1**) | mesmo e-mail, id **67** (recriado pelo seeder) |
| Contas | 4 (Conta Corrente, Cartao Teste Nubank/Itau/Inter) | 1 (Conta Corrente, do seeder) |
| Transações | 9 | 0 |
| Metas | 1 | 0 |
| Categorias | 14 | 14 (preservadas) |

**A responsabilidade é minha.** Eu lancei os auditores e, para que pudessem *provar* os achados
em vez de especular, autorizei explicitamente que rodassem `tinker` e **requisições HTTP reais
contra o ambiente de desenvolvimento**. Isso criou o risco. Um dos auditores, ao limpar as
próprias fixtures, rodou `php artisan db:seed` — o que restaurou seu login (id 67) mas não os
dados anteriores. O auto-increment continuou de onde estava, então foi `DELETE` em massa, não
`migrate:fresh`.

O que eu **deveria** ter feito: exigir que cada auditor usasse um schema separado ou sqlite
descartável, nunca `DB_DATABASE=stabilmoney`. Dois dos oito fizeram isso por conta própria; os
demais escreveram no banco de dev.

**Seu login está funcionando** — o seeder recriou o usuário com a senha do `.env`.

**A recuperação é possível.** O binlog do MySQL está ativo em formato ROW e contém os `DELETE`
(134 eventos `Delete_rows`). Copiei o arquivo para fora do volume, para que não seja rotacionado:

```
storage/app/backup-incidente-2026-07-28/binlog.000010   (3,6 MB)
```

Não executei a restauração por dois motivos: `mysqlbinlog` não existe na imagem `mysql:8.0` e a
variante Debian não tem build para ARM (seu Mac), **e** point-in-time recovery é uma operação de
alto impacto que não cabe decidir por você enquanto dorme. Para recuperar:

```bash
# Num ambiente x86 (ou com uma imagem ARM que traga mysqlbinlog):
mysqlbinlog --base64-output=DECODE-ROWS --verbose \
  storage/app/backup-incidente-2026-07-28/binlog.000010 > eventos.sql
# Procure os INSERT das contas/transações do user_id=1 antes de 27/07 23:48
# e reaplique ajustando user_id para 67.
```

**Se esses 9 lançamentos eram dados de teste que você recria em 10 minutos, o mais simples é
ignorar o binlog e recriar.** Guardei o arquivo só para que a escolha seja sua.

**Ação preventiva recomendada:** acrescentar ao `CLAUDE.md` que auditoria/teste nunca usa o
banco `stabilmoney` — schema separado ou sqlite. E o item de backup do
`docs/checklist-de-publicacao.md` (hoje inexistente) passou a ser urgente também em dev.

---

## Sumário: o veredito por área

| Área | Veredito |
|---|---|
| **Injeção SQL** | ✅ Limpo. Uma única interpolação em SQL no projeto inteiro, entre constantes de código. |
| **IDOR / isolamento entre famílias** | ✅ Sólido. 16 rotas com binding, todas autorizadas; FKs escopados por `ownerId()`. Nenhum atravessamento. |
| **Aritmética dos bolsos** (`balance`/`reserved`/`available`/`spendable`) | ✅ Correta, inclusive centavos, piso exato e valores de 10¹³. |
| **Conservação do dinheiro** em metas/investimentos | ✅ Aporte e resgate fecham em zero, sem perder centavo. |
| **Validação de valores** | ✅ Negativo, zero, texto e SQL recusados em todos os endpoints. |
| **Trava de gasto** (o que a v3 veio resolver) | ⚠️ Funciona no caminho principal, mas **tem duas portas laterais abertas**. |
| **Faturas de cartão** | ⚠️ Ciclo e calendário impecáveis; **pagamento tem falhas graves**. |
| **Contas fixas** | ⚠️ Projeção e clamp de mês corretos; **validação da competência e visibilidade falham**. |
| **Números do dashboard** | ⚠️ Base correta; **três divergências entre telas**. |
| **Integridade / migrations** | ⚠️ Schema e tipos corretos; **um `down()` destrutivo**. |
| **Performance** | ⚠️ N+1 conhecido, medido, não corrigido (por escolha — explicado adiante). |

---

# Parte 1 — Corrigido nesta madrugada (9 correções, todas com teste)

Cada uma tem teste em `tests/Feature/AuditoriaCorrecoesTest.php` que **falhava antes** e passa
agora.

### 1.1 🔴 Parcelamento gerava parcela NEGATIVA ✔ verificado por mim
`app/Http/Controllers/FaturaController.php`

`round($total/$n, 2)` arredondando para cima fazia a última parcela absorver resto negativo:
**R$ 0,36 em 24x produzia uma parcela de −R$ 0,10**. Linha negativa vira crédito no extrato,
**devolve limite do cartão** e viola a regra "dinheiro nunca é negativo".

Agora o rateio é em **centavos inteiros**: o resto é distribuído um centavo por vez nas
primeiras parcelas. Soma exata, nada negativo, diferença máxima de 1 centavo entre parcelas.
Testado em 7 combinações (0,36/24x · 0,54/12x · 1,80/24x · 100/3 · 10/3 · 1.000,01/7 · 19,99/6).

### 1.2 🔴 "Pagar" recorrência de cartão apagava a dívida sem sair dinheiro ✔ verificado por mim
`FaturaController::pay`

`paid_at` é o que tira a despesa da fatura e devolve o limite. O método marcava sem debitar
nada: **três cliques quitavam R$ 149,70 com R$ 0,00 saindo do caixa**.

Regra aplicada: despesa no cartão é quitada **pela fatura**. Para recorrência em cartão, o botão
agora só lança a próxima ocorrência (idempotente) e a atual **permanece em aberto**. Em conta
corrente o comportamento antigo se mantém — ali a despesa já descontou do saldo no lançamento,
então marcar como paga é apenas registro.

### 1.3 🔴 Pagar fatura em paralelo duplicava a saída de caixa
`FaturaController::payInvoice` — auditor provou: **4 POSTs paralelos numa fatura de R$ 300 =
4 pagamentos de R$ 300**, conta a −R$ 1.100.

A lista e o total eram lidos **fora** da transação e a saída de caixa era criada
incondicionalmente — o relock só decidia o que *marcar*, nunca *se havia o que pagar*. Agora a
leitura autoritativa está sob o lock, o valor debitado é o do que foi realmente marcado naquele
instante, e nada é criado se outra requisição pagou primeiro.

### 1.4 🔴 Competência inválida na URL debitava a conta ✔ verificado por mim
`routes/web.php` + `FixedBillController::pay`

A rota aceitava `\d{4}-\d{2}`, então `2026-13` passava e o Carbon convertia por overflow em
**janeiro/2027**; `2026-00` virava dezembro/2025. O dinheiro saía e a competência paga **não
aparecia em tela nenhuma** (a projeção só lista até o mês corrente).

No meu teste isso foi **pior que o relatado**: três competências inválidas debitaram R$ 5.400 e
deixaram a conta em **−R$ 400 sem cheque especial** — furando o piso.

Agora: regex exige mês 01–12, e o controller recusa competência **futura** ou **anterior ao
`starts_on`**, com mensagem em PT-BR.

### 1.5 🟠 Segundo clique em "pagar conta fixa" devolvia HTTP 500 ✔ verificado por mim
`FixedBillController::pay`

O tratamento de duplicidade procurava **o nome do índice na mensagem do driver** — string que só
o MySQL inclui. Em sqlite (onde a suíte roda) a exceção era relançada: 500 no segundo clique. E
o teste que "cobria" isso só checava a contagem de linhas, então ficava verde **com** o 500.

Agora usa `UniqueConstraintViolationException` (tipada, driver-agnóstica).

### 1.6 🟠 Editar receita para despesa furava o saldo ✔ verificado por mim
`TransactionController::update`

O `$ignore` (folga que a própria linha já ocupa) só era devolvido quando a transação **já era
despesa**. Sendo receita, o disponível consultado ainda continha a receita que estava sendo
destruída — a folga era contada duas vezes. Conta com R$ 100 + receita de R$ 500 aceitava virar
despesa de R$ 600 e ia a **−R$ 500 sem cheque especial**. É o caso comum de "lancei no tipo
errado e corrigi".

Agora o efeito antigo entra **com sinal**: despesa libera, receita retira.

### 1.7 🔴 Divergência MySQL × SQLite: o último dia de toda janela desaparecia nos testes
`app/Models/Transaction.php` — cast `date` → `date:Y-m-d`

O cast padrão **grava** `Y-m-d H:i:s`. O MySQL trunca (a coluna é `DATE`), o sqlite guarda a
string inteira — então `whereBetween('date', …)` comparava texto e **excluía as linhas datadas
exatamente no último dia da janela**. Efeitos medidos pelo auditor, com os mesmos dados:

| Número | MySQL (correto) | SQLite (onde os testes rodam) |
|---|---|---|
| receitas da semana | 300,00 | **200,00** |
| despesas do mês | 410,00 | **400,00** |
| despesas do ano | 835,00 | **435,00** |
| último ponto das sparklines (hoje) | 350,00 | **0,00** |

Duas consequências: em sqlite **as transações de hoje nunca entravam nas sparklines**, e
`DashboardTest` quebraria em todo dia 31. Ou seja: **os testes validavam número errado.**

### 1.8 🔴 Card "Contas a pagar" cobrava fatura já paga ✔ verificado por mim
`DashboardService` — `currentInvoice` → `openInvoiceDue`

`currentInvoice` soma o ciclo e **ignora `paid_at`**. Na mesma tela, o sino dizia "nada a
vencer" e o card cobrava R$ 400 de uma fatura quitada.

### 1.9 🟠 Flecha da tendência apontava para o lado errado
`resources/views/dashboard.blade.php` + `resources/js/sm/dashboard.js`

Um único flag decidia **cor** e **flecha**. Como em despesas "cair é bom", o flag ficava verdadeiro
quando a despesa caía — e a flecha seguia a cor, não o fato. Resultado: **"despesas subiram
1540%" desenhava flecha para baixo**, e como o texto usa `abs()`, o usuário lia exatamente o
contrário do que aconteceu. Agora são duas decisões separadas: `$subiu` (flecha) e `$bom` (cor).

---

# Parte 2 — Achados ABERTOS, por gravidade

## 🔴 CRÍTICOS

### C-1 · Resgate para conta que nunca aportou cria dinheiro que não existe
**Confirmado independentemente por 3 auditores.**
`WithdrawGoalContributionRequest` · `WithdrawInvestmentContributionRequest` ·
`HandlesContributions`

O resgate é validado contra o **total** da meta/investimento, nunca contra o que **aquela conta**
aportou. E o select de destino oferece todas as contas da família.

| Passo | Conta A | Conta B (vazia, sem cheque) |
|---|---|---|
| aporte de R$ 1.000 saindo de A | disp. 0,00 | disp. 0,00 |
| **resgate de R$ 1.000 para B** | disp. **0,00** (R$ 1.000 congelados) | reservado **−1.000**, disp. **+1.000** |

Depois disso `SpendingGuard` responde **`ok`** para um gasto de R$ 1.000 em B, e a conta termina
com saldo real **−R$ 1.000** exibindo "saldo R$ 0,00", com cheque especial zero. O `reserved`
negativo mascara o rombo.

É a invariante **I6** da spec, marcada como passo 11 e **não implementada**. Existe um teste de
caracterização no projeto (`ModeloDeDinheiroTest::test_resgatar_para_outra_conta_cria_dinheiro_fantasma`)
documentando o defeito como vigente.

**Correção:** `Goal::reservedFromAccount()` / `Investment::reservedFromAccount()` — a conta já é
calculada por `SpendingGuard::resgatavelDe()`, basta usá-la nos dois Form Requests **e** no
recheque sob lock.

### C-2 · `contas-fixas.pagar` aceita qualquer valor e ignora a trava de gasto
`PayFixedBillRequest` (`amount` livre) + `FundingService` (em `obrigacao: true`, `ESTOURA_LIMITE`
é ignorado)

```
POST /contas-fixas/{conta}/pagar/2026-07     amount=1e12
→ 302 "Aluguel pago. Atenção: a conta ficou em −R$ 999.999.999.900,00."
```

Repetindo com `9999999999999,99` o saldo foi a **−R$ 10.999.999.999.900,00**. O mesmo valor num
gasto novo é recusado com 422 — a trava simplesmente não é consultada no caminho de obrigação.
Um dependente também consegue. Destrói o patrimônio, o gastável de todas as contas e toda decisão
de fonte seguinte.

**Correção:** teto relativo ao previsto da conta fixa, `decimal:0,2` nos campos de dinheiro, e
em obrigação com `ESTOURA_LIMITE` **pedir confirmação** em vez de gravar em silêncio — hoje o app
confirma no caso menos grave e não confirma no mais grave.

### C-3 · Trocar o tipo de uma conta existente faz dinheiro desaparecer (ou contar em dobro)
`AccountController::update` sem trava + `StoreAccountRequest::prepareForValidation` (zera
`initial_balance` fora de corrente/poupança). O `<select name="type">` está editável na tela de
edição, então é alcançável pela UI normal.

- **Corrente com histórico → cartão de débito:** `initial_balance` vira **NULL**
  (irreversível), `balance` vira 0, e o patrimônio da sidebar cai de R$ 1.500 para **R$ 0,00**.
  A transação continua no banco, órfã.
- **Cartão de crédito → corrente:** as despesas do ex-cartão passam a descontar do patrimônio;
  os R$ 500 de uma fatura já paga são descontados **duas vezes** (patrimônio 1.500 → −4.200).

**Correção:** bloquear troca entre a classe "caixa" e a classe "cartão" quando houver histórico —
a mesma defesa que `destroy` já tem.

### C-4 · Pagar a fatura **dobra as despesas do período** no dashboard
`DashboardService` não distingue a transação de quitação de um gasto normal.

Compra de R$ 300 no cartão, fatura paga (R$ 300 saíram do bolso — uma vez):

| Indicador | Correto | Obtido |
|---|---|---|
| Despesas do mês | 300,00 | **600,00** |
| "Sobrou no período" | −300,00 | **−600,00** |
| Donut | 300,00 | **600,00** + fatia fantasma "Sem categoria" |

O saldo fica certo; **todo indicador de gasto infla no valor da fatura**. Quem paga o cartão em
dia vê o dobro do gasto real.

**Correção:** marcar a transação de quitação (coluna tipo `is_settlement`) e excluí-la de
`dailySums`, `totals` e `categoryBreakdown`, mantendo-a no extrato e no saldo.

### C-5 · `down()` da migration que move dados falha pela metade e perde o mapeamento
`2026_07_28_000000_move_debit_card_movements_to_linked_account.php`

O `down()` faz *update + dropColumn* por tabela, em sequência. Se algum `legacy_account_id`
apontar para um cartão já apagado (nada impede — não há FK), o `UPDATE` estoura FK **depois** de
a tabela anterior já ter perdido a coluna. MySQL não tem DDL transacional. Provado em schema
separado: `transactions.legacy_account_id` dropada (mapeamento **irrecuperável**),
`goal_contributions` com `account_id` errado, a linha continua em `migrations` → `migrate:rollback`
travado para sempre naquele ponto.

**Correção:** os 3 `UPDATE` primeiro, os 3 `dropColumn` depois, filtrando ponteiros mortos com
`whereIn('legacy_account_id', DB::table('accounts')->select('id'))`.

## 🟠 ALTOS

### A-1 · Fatura vencida (ciclo fechado) não tem como ser paga — dívida e limite travados
`payInvoice` só conhece `billingCycle()` (ciclo aberto). `closedInvoiceDue`/`overdueInvoice`
existem apenas para *exibir* no sino. Com R$ 750 vencidos há 7 dias: a tela mostra "Fatura atual
R$ 0,00", nenhum botão, e `payInvoice` responde "Esta fatura já estava quitada". O limite fica
comprometido **para sempre**.

Pior: dívida de **2+ ciclos atrás** desaparece até do sino (`closedCycle` olha só um ciclo para
trás) — invisível, impagável, limite bloqueado.

### A-2 · O 409 "de onde sai o dinheiro?" não tem consumidor em /faturas nem em contas fixas
**Confirmado por 3 auditores.** `RequiresFundingChoice` grava `session('fonteNecessaria')` e
**nenhuma view lê essa chave**. Os dois forms são POST comum, sem `pedirFonte`.

Efeito prático **invertido**: quem **tem** cheque especial clica em "Marcar como paga", a página
volta sem mensagem nenhuma e a fatura continua vencida. Quem **não tem** fonte alguma consegue
pagar (a conta negativa, como decidido).

### A-3 · Excluir/editar a despesa não estorna o resgate — dinheiro migra sozinho
`TransactionController::destroy` e `update`. O `FundingService` grava o resgate com
`transaction_id`, mas nada lê esse vínculo de volta e não há FK para cascatear.

```
gasto de 800 com resgate → investido 200, disponível 0   (correto)
APÓS EXCLUIR a despesa   → investido 200, disponível 800
esperado                 → investido 500, disponível 500
```

R$ 300 saem do investimento sem resgate solicitado. Repetir o ciclo (lançar com resgate →
excluir) **drena o investido indefinidamente** e libera saldo fantasma.

### A-4 · `faturas.recorrente.pagar` cria despesa sem passar pela trava
A próxima ocorrência é gravada com `Transaction::create` cru. Alcançável em 2 passos: editar a
recorrência do cartão para uma conta de caixa (o update é guardado só para o valor da hora) e
clicar em pagar — cada clique gera uma ocorrência nova, **sem teto**: disponível 0 → −100 →
−200 → −300. (Corrigi o `paid_at` em 1.2, mas a geração da ocorrência segue fora do guard.)

### A-5 · `InvestmentController::store` não recheca sob lock
Tem `DB::transaction`, mas nenhum `lockForUpdate` — ao contrário do trait de aportes. Duas
requisições validadas antes de gravar (duplo submit, duas abas): as duas passam, e a conta fica
com `reserved` R$ 2.000 tendo R$ 1.000. Agrava: o formulário **não gera `client_uuid`**, então
não há idempotência alguma.

### A-6 · Receita (estorno) em cartão de crédito é engolida
`committed`/`currentInvoice`/`openInvoiceDue` somam só `type='expense'`. Um estorno de R$ 300 no
cartão não devolve limite, não abate a fatura e não entra em saldo nenhum — **mas aparece em
"receitas do mês"** no dashboard. Receita que não existe em bolso algum.

### A-7 · Ordem de lock contraditória entre os dois caminhos (deadlock ABBA)
**Confirmado por 3 auditores.** `FundingService`: conta → pai ("SEMPRE"). `HandlesContributions`:
pai → conta ("SEMPRE"). As duas docs afirmam o contrário uma da outra, e o `CLAUDE.md` repete a
versão errada. Deadlock reproduzido em MySQL com duas sessões (`1213 Deadlock found`); nenhuma
das transações usa `attempts > 1`, então o usuário recebe **500**.

### A-8 · Contas fixas: o sino nunca avisa **antes** quando o vencimento cai no mês seguinte
A projeção para em "hoje", então para todo vencimento nos dias 1–7 (aluguel/condomínio típicos) a
janela de "próximos 7 dias" **nunca** funciona — a competência de agosto só aparece em 01/08.

### A-9 · Dashboard diz "Nada a pagar 🎉" com aluguel vencido
`faturasResumo` soma só cartões. Com 3 competências de aluguel vencidas (R$ 5.400 em aberto), o
card mostra o estado vazio enquanto o sino conta 3.

### A-10 · Primeira competência pode nascer "vencida" indevidamente — e cobrar duas vezes no mundo real
`FixedBillService` usa `starts_on->startOfMonth()` sem comparar o vencimento calculado com
`starts_on`. Conta cadastrada hoje (27/07) com o **default do formulário** (`starts_on = 01/07`)
aparece como "vencida há 22 dias", com badge vermelho e botão **Pagar** de um mês que o usuário
quase certamente já pagou fora do app. Se ele clicar, **sai dinheiro em duplicidade**.

### A-11 · Divergência de saldo entre telas
Dashboard "Minhas contas" mostra o saldo **cru**; `/accounts` mostra o **disponível**. No mesmo
card do dashboard, o headline diz R$ 1.000 e as contas listadas somam R$ 1.400.

## 🟡 MÉDIOS (resumo)

| # | Achado |
|---|---|
| M-1 | **IR/IOF errado:** 15% fixo para qualquer prazo (correto em 12 meses: 17,5%); faixas regressivas não existem; IOF não é modelado apesar do rótulo "IR/IOF"; classe do ativo ignorada. Superestima ~R$ 29 por R$ 10.000/ano. O card mostra "▲ 11,7% a.a." em verde **sem a palavra "estimativa"**. |
| M-2 | **Vazamento entre famílias:** a mensagem de erro do resgate revela o valor guardado de outra família (`"…maior que o valor guardado na meta (R$ 87.345,67)"`). Nada é gravado, mas o saldo do vizinho vaza. Corrigir movendo a posse para o `authorize()` do Form Request. |
| M-3 | **Resgate não sana o negativo, mas a mensagem afirma que sana.** Com disponível −100, `max(0, disp)` descarta o buraco: resgata só o valor da despesa e a conta segue negativa — enquanto o 409 dizia "seu saldo não fica negativo". |
| M-4 | **409 sem saída:** a soma dos investimentos cobre o faltante, nenhum sozinho cobre → o modal abre com todas as opções desabilitadas e confirmar devolve 422. |
| M-5 | **Dedupe de `client_uuid` sem lock:** 4 POSTs paralelos com o mesmo uuid → 1×201 + 3×**500** (integridade preservada pelo unique; o problema é o erro). |
| M-6 | **`paid_on` sem piso relativo à dívida:** aceita 2001 numa compra de 2026. O saldo desconta hoje, mas a despesa sai do mês nos relatórios. |
| M-7 | **`paid_on` de fatura é código morto:** validado no Request, ausente do formulário. |
| M-8 | **Sino duplica:** mesma dívida contada como "recorrente" e como "fatura"; e conta fixa + recorrência legada de mesmo nome aparecem duas vezes. |
| M-9 | **Excluir compra parcelada já parcialmente paga** apaga a parcela paga e deixa o pagamento órfão no extrato. |
| M-10 | **Desativar/excluir conta fixa** apaga do radar as competências **vencidas não pagas**, sem aviso. |
| M-11 | **Conta fixa desativada continua pagável** (a rota não checa `active`) e a competência paga não aparece em tela nenhuma. |
| M-12 | **`whereDate()` sobre coluna que já é `DATE`** anula o índice (15 ocorrências): `type=ref rows=50032` vs `type=range rows=1`. Falta índice `(account_id, date)`. |
| M-13 | **`down()` de `make_initial_balance_nullable` não roda no MySQL** (`1138 Invalid use of NULL`) — e passa no sqlite, então a suíte nunca pega. |
| M-14 | **Pagar fatura não tem constraint de idempotência** (não há unique equivalente ao `(fixed_bill_id, competence)`). |
| M-15 | **Não existe UI para editar nem excluir conta fixa** — as rotas existem, nenhuma view as chama. Valor digitado errado fica projetado para sempre. |
| M-16 | **`$ignore` não chega aos accessors de cheque especial:** editar despesa numa conta no vermelho é recusada indevidamente (erra para o lado seguro). |
| M-17 | **Cartão de débito exibe saldo bruto**, não o disponível. |

## 🔵 BAIXOS (seleção)

- **N+1 medido** (3 fontes): `balance` faz 2 SUM e `reserved` faz 4 → **~6 queries por conta**.
  `/accounts`: 3 contas = 33 queries, **30 contas = 195**. Dashboard: 87 queries; página inteira
  com 10 metas + 10 investimentos = **100**.
- Aporte/resgate com **data futura** já derruba o disponível hoje.
- `is_admin`/`account_owner_id` em `$fillable` (sem sink hoje, armadilha para o futuro).
- **Dependente pode conceder cheque especial à família** (`overdraft_limit` é o único campo que
  define quanta dívida a família pode contrair — talvez deva ser só do titular).
- PHP e JS discordam da rentabilidade quando não há indexador (card mostra 0,0%, prévia mostra 30%).
- `refresh()` não limpa os caches de dinheiro do model (só `fresh()` funciona).
- Campos de dinheiro aceitam notação científica (`1e12`) e >2 decimais.
- `FundingService`: conta que não é cartão nem caixa recebe **passe livre** sem checagem.
- `FixedBill` **não tem factory** (dificulta testes).
- Rótulos de tipo de conta em inglês no dashboard ("Checking", "Debit_card").
- `precision=14` do PHP: valor no máximo da validação vira `1e13` e estoura a coluna → 500.

---

# Parte 3 — O que foi verificado e está CORRETO

Vale registrar, porque é a maior parte do sistema e foi testado com números:

**Dinheiro se conserva.** Aporte de R$ 100 + resgate de R$ 100 devolve o saldo ao estado
original, ao centavo. Três aportes de R$ 0,01 e um resgate de R$ 0,03 fecham em zero. Nenhum
centavo criado ou perdido em nenhuma sequência testada.

**Sem dupla contagem no aporte** — o ponto que eu mais suspeitava. A migration que ligou aportes
a transações **não** duplicou o efeito: o disponível cai uma vez só, e o aporte comum continua
sem criar transação (não aparece como despesa no histórico).

**A trava de gasto funciona no caminho principal.** 6 despesas de R$ 60 em paralelo numa conta de
R$ 100: **1 passa, 5 recusadas**, saldo final R$ 40. O `lockForUpdate` protege de verdade.

**Calendário impecável.** Nenhum overflow de Carbon: `closing_day=31` gera 31/12 → 31/01 → 28/02
→ 31/03; em 2028 (bissexto) 29/02. `due_day=31` em fevereiro cai em 28 (ou 29). Exatamente uma
competência por mês, sem pular nem duplicar na virada de ano.

**Cheque especial exato.** Piso respeitado ao centavo: disponível 0 + limite 500 aceita R$ 500,00
e recusa R$ 500,01. Conta sem cheque especial nunca fica negativa por gasto novo — inclusive
quando o cliente forja `funding_source`. E o app **nunca** usa o cheque especial sozinho.

**Parcelas somam exatamente o total** em todos os casos testados (100/3, 0,05/3, 1.000,01/7…).

**Limite do cartão volta ao pagar, não com o tempo** — a correção D-04 da spec funciona.

**Isolamento entre famílias sólido.** 403 em toda tentativa cruzada; FKs escopados; dependente
não se promove a titular; conta de outra família em `account_id` → 422 sem tocar no saldo alheio.

**Sem SQL injection.** Uma única interpolação no projeto (`DashboardService`, `$monthExpr`), entre
duas constantes de código. Nenhum `orderBy($request->…)`, nenhum `whereIn` com array do cliente.

**Fuso correto.** Às 23:30 do dia do vencimento a conta ainda não está vencida; à meia-noite vira.
Teste às 23:30 de 31/07 (= 02:30 UTC de 01/08): data, competência e ciclo do cartão todos no dia
civil brasileiro correto.

**Tipos do banco corretos:** todo campo de dinheiro é `decimal(15,2)`. Zero float/double/string.

**Integridade dos dados:** 29 verificações no banco de dev, **0 problemas** (órfãos, valores
negativos, `funding_source` inválido, competência fora do dia 1, etc.).

**XSS das telas novas seguro** — `funding.js` e `faturas.js` usam `createElement`/`textContent`; o
padrão que gerou o XSS do `charts.js` não se repete.

---

# Parte 4 — Testes que ficaram no repositório

Todos novos, nenhum commitado:

| Arquivo | O que cobre |
|---|---|
| `AuditoriaCorrecoesTest.php` | As 8 correções desta madrugada (cada teste falhava antes) |
| `JornadaFinanceiraCompletaTest.php` | Jornada de 9 etapas com o dinheiro conferido à mão; 4 invariantes de bolso |
| `JornadaChequeEspecialEFamiliaTest.php` | Cheque especial (piso, limite exato, 1 centavo acima), família e isolamento |
| `ValidacaoDeValoresTest.php` | 34 casos: negativo, zero, texto, SQL, científica, 3 decimais, formato pt-BR |
| `SmokeAllRoutesTest.php` | **Toda** rota GET como titular, dependente e visitante — pega 5xx em telas futuras também |
| `PerformanceQueryCountTest.php` | Contagem de queries por tela + trava de não-piorar do N+1 |

---

# Parte 5 — O que eu recomendo fazer, em ordem

**Hoje, antes de qualquer coisa:** decidir sobre o binlog (recuperar ou recriar os 9 lançamentos)
e adicionar a regra de "auditoria nunca usa o banco de dev" ao `CLAUDE.md`.

**Esta semana — os 5 críticos abertos.** Na ordem: C-2 (valor livre na obrigação, é o mais
destrutivo e o mais barato de corrigir), C-1 (resgate cross-conta), C-3 (troca de tipo de conta),
C-4 (dashboard dobrando despesa), C-5 (o `down()` — antes de qualquer rollback).

**Depois:** A-1/A-2 juntos (fatura vencida impagável + 409 sem consumidor) — são a mesma tela e
hoje deixam o usuário sem saída. Em seguida A-3 (estorno de resgate) e A-7 (ordem de lock).

**Quando sobrar tempo:** o N+1 (solução: `Account::preloadMoney(Collection)` com 3 queries
agregadas por família, preenchendo os caches privados dos models; chamar no `AccountController@index`
e nos dois services) e as faixas de IR/IOF.

**Não corrigi de propósito:** o N+1. A correção mexe nos accessors de saldo, e um erro ali
corrompe dinheiro — risco desproporcional para um ganho de performance num app que hoje tem 4
contas. Deixei a trava de não-piorar no teste e a solução descrita.

---

## Metodologia e limites

**O que foi feito:** 8 auditores paralelos (bolsos/saldo · trava de gasto e funding · faturas ·
contas fixas · metas e investimentos · dashboard · segurança da feature nova · integridade e
migrations), com prova numérica obrigatória; mais minha própria bateria (smoke de rotas, jornada
com dinheiro conferido à mão, ataque a campos de valor, medição de N+1) e verificação
independente dos achados graves antes de corrigir.

**Limites honestos:**

- Não foi um teste de invasão contra ambiente publicado — é revisão de código com execução local.
- A correção do `charts.js` (XSS da rodada anterior) e as do `dashboard.js` estão verificadas por
  leitura e pelo lado servidor: **o projeto não tem suíte de testes JS**. Rode `npm run build`.
- O deadlock ABBA (A-7) foi reproduzido por um auditor com duas sessões MySQL, mas não por mim.
- Alguns achados vieram de agentes e eu **não** reverifiquei um por um — os que verifiquei estão
  marcados. Os não marcados vêm com o cenário do auditor para você conferir.
- Durante a auditoria outra sessão editava o repositório e o banco; alguns achados citam linhas
  que podem ter se movido.
