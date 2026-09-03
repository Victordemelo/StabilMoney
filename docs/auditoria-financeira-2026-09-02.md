# Auditoria da lógica financeira — 02/09/2026

Auditoria **executada**, não só lida: cinco frentes em paralelo, cada uma com um arquivo de
teste temporário (apagado no fim) rodando cenários reais contra o código, em sqlite via
`php artisan test`. Suíte oficial no momento: **995 testes verdes**. Nada foi corrigido
nesta rodada — este documento é só o registro. Cada item traz cenário, esperado × obtido e
o arquivo culpado, para virar teste de regressão na hora da correção.

Frentes: cartão/parcelamento · saldo/fonte/estorno · contas fixas/recorrência ·
dashboard/somas · metas/investimentos.

> **Estado em 02/09/2026 (noite): tudo de 🔴, 🟠 e 🟡 corrigido**, commitado por item, suíte em
> **1.083 testes verdes**. Cada item abaixo leva a marca ✅ e o teste que o cobre. O que ficou
> em 🔵 é decisão de produto e continua em aberto.

---

## 🔴 Defeitos que mexem em DINHEIRO (corrigir antes de publicar)

### ✅ F-1 · Parcelas caem duas na mesma fatura e nenhuma na seguinte — `ParcelasUmaPorCicloTest`
- **Onde:** `app/Http/Controllers/FaturaController.php:426-431` (`createInstallments`).
- **Cenário:** cartão fecha dia **28**; compra de R$ 300 em 3x em **30/01/2027**.
  Parcelas geradas: 30/01 · 28/02 · 30/03.
- **Esperado:** uma parcela por ciclo.
- **Obtido:** ciclo (28/01..28/02] = **2 parcelas**, (28/02..28/03] = **0**, (28/03..28/04] = 1.
  A fatura de fevereiro cobra o dobro e a de março cobra zero. Ocorre com compra em 29, 30 e
  31/01; em ano bissexto passa por acaso (29/02 > 28).
- **Causa:** as parcelas são datadas por mês-calendário (`addMonthsNoOverflow`), sem olhar
  `Account::billingCycle()`. Quando o dia clampado da 2ª parcela fica ≤ `closing_day`, ela
  entra no ciclo da 1ª. O teste `test_parcelamento_no_dia_31_respeita_fevereiro` confere só
  as datas, não o ciclo em que caem.

### ✅ F-2 · Estorno de cartão em ciclo diferente da compra é perdido — `CreditoDeEstornoRolaEntreCiclosTest`
- **Onde:** `app/Models/Account.php:717-732` (`openInvoiceDue`) e `:775-792`
  (`closedInvoiceDue`), `FaturaController.php:257-271` e `:302-317` (`payInvoice`).
- **Cenário:** fecha 10 / vence 20. Compra R$ 1.000 em 01/08 (ciclo jul-ago). Estorno de
  R$ 1.000 em 15/08 e compra de R$ 400 em 16/08 (ciclo ago-set). Compra R$ 700 em 20/09.
  Paga as três faturas.
- **Esperado:** saídas do caixa = 1.000 + 0 + 100 = **R$ 1.100** (crédito de 600 rola).
- **Obtido:** saíram **R$ 1.700**. Setembro respondeu "já estava quitada" (`max(0, 400−1000)`
  = 0) e as linhas `income 1000` e `expense 400` ficam **em aberto para sempre** — nunca
  recebem `paid_at`.
- **Causa:** o piso 0 é aplicado por janela de ciclo e o crédito que sobra não rola nem é
  consumido. O CLAUDE.md documenta o piso para "estorno maior que a dívida", não a perda do
  crédito entre ciclos.

### ✅ F-3 · `funding_max_amount` só valia em `transactions.store` — `TetoDoResgateEmTodosOsCaminhosTest`
- **Onde:** `TransactionController.php:250-297` (update), `FaturaController.php:274-278`
  (`payInvoice`) e `:77-81` (`lancar`), `FixedBillController.php:150-154`;
  `PayInvoiceRequest:70-75` e `PayFixedBillRequest:107-111` nem validam o campo.
- **Cenário:** conta R$ 1.000 com R$ 900 aplicados (disponível 100). Usuário aprovou
  "resgatar R$ 100"; antes de gravar o disponível caiu e o faltante real é 200-300. Reenvio
  com `funding_max_amount=100`.
- **Esperado:** 409 com opções recalculadas (é o que o `store` faz).
- **Obtido:** gravou. Aplicado 900 → **700** (edição), → **600** (fatura), → **600**
  (conta fixa). O front envia o teto em todos os caminhos (`funding.js:249`,
  `funding-modal.blade.php:91`); só um consome. O cenário que motivou o teto (fila offline
  na tela de faturas) é justamente um dos descobertos.

### ✅ F-4 · Editar `initial_balance` fura o piso do cheque especial — `SaldoInicialRespeitaOPisoTest`
- **Onde:** `app/Http/Requests/UpdateAccountRequest.php:36-42` (só protege a redução do
  `overdraft_limit`), `AccountController::update:138-146`.
- **Cenário A:** conta R$ 1.000 com R$ 800 aplicados, cheque 0. PUT `initial_balance=100`.
  Obtido: 200 OK, `available = −700` **sem cheque especial** — estado que nenhum lançamento
  consegue produzir.
- **Cenário B:** conta R$ 1.000, cheque 500, despesa de 1.300 via cheque (disp. −300). PUT
  `initial_balance=0`. Obtido: `available = −1.300` com piso prometido de −500 (uso 260%).
- **Esperado:** 422 nos dois, com a mesma regra já aplicada ao limite
  (`regraDoChequeEspecialEmUso`), só que projetando o `available` com o novo saldo inicial.

---

## 🟠 Defeitos de NÚMERO NA TELA (não somem dinheiro, mas mentem)

### ✅ T-1 · Piso 0 do estorno em três granularidades — `EstornoEmDiaDiferenteNoDashboardTest`
- **Onde:** `DashboardService.php:783-790` (`dailySums`, piso **por dia**), `:187` (ano,
  piso **por mês**), `:820` (`totals`, piso **por período**).
- **Cenário:** compra R$ 1.000 no cartão em 12/08, estorno R$ 400 em **13/08**.
- **Obtido:** card "Mês" = **1.000**, "Semana" = 1.000, barra de agosto no "Ano" = **600**,
  e em setembro o trend usa agosto = 600 como base. O estorno num dia sem compra vira
  `−400 → 0` e desaparece.
- **Agravante:** estorno de compra de julho lançado em agosto + mercado de R$ 300 no débito
  no mesmo dia → despesas de agosto = **0** (o estorno do cartão engoliu a despesa de caixa).
- Testes existentes só cobrem estorno **no mesmo dia** da compra.

### ✅ T-2 · Donut não abate o estorno do cartão — `EstornoEmDiaDiferenteNoDashboardTest`
- **Onde:** `DashboardService::categoryBreakdown` (`:685-695`), filtra `type='expense'`.
- **Obtido:** donut 1.000 × stat de despesas 600, na mesma tela.

### ✅ T-3 · "Total das faturas" contava fatura já paga — `TotalDasFaturasSoEmAbertoTest`
- **Onde:** `FaturaService.php:42-45` soma `currentInvoice` (`Account.php:669`, sem filtro
  de `paid_at`); o dashboard usa `openInvoiceDue` e acerta.
- **Obtido:** depois de pagar o ciclo aberto, dashboard 3.400 (correto) × `/faturas` 900
  (ainda com os 200 pagos). O card do cartão diz `isPaid=true` ao lado.

### ✅ T-4 · Competência do mês corrente recusada com "Pagar" à vista — `ContasFixasCorrecoesTest`
- **Onde:** `FixedBillController.php:118` (guarda `vencimento > hoje+7` aplicada a toda
  competência), `FixedBillService.php:139-144` (projeção exibe a do mês corrente sem essa
  condição), view `faturas/index.blade.php:144-155`.
- **Cenário:** hoje 01/08, condomínio dia 10, saldo de sobra. Clica Pagar → 422 "fica
  disponível a partir de 03/08". Pagar no dia 1 o que vence no dia 10 é o caso mais comum.
- **Regra coerente:** recusar só se `competence > mês corrente && vencimento > hoje+7`.
- ⚠️ `ContasFixasCorrecoesTest::test_competencia_ainda_nao_vencida_respeita_a_trava_de_gasto`
  **passa pelo motivo errado** (o 422 vem desta guarda, não da trava de gasto). Ao corrigir,
  ajustar o teste para vencimento dentro da janela, senão perde a cobertura original.

### ✅ T-5 · Sparkline do saldo era o bruto — `EstornoEmDiaDiferenteNoDashboardTest`
- **Onde:** `DashboardService.php:629` → `saldoSpark()` (`:651-670`) não desconta reservas.
- **Obtido:** stat 6.300 × último ponto da spark 8.050. Aporte de meta derruba o número e não
  move a linha. Na sidebar os dois são brutos e não há problema.

### ✅ T-6 · Meta com prazo vencido ineditável — `MetaComPrazoVencidoEditavelTest`
- **Onde:** `StoreGoalRequest.php:47` (`after_or_equal:hoje`), herdado por
  `UpdateGoalRequest`. O modal pré-preenche a data antiga e o servidor a recusa. Validar só
  na criação, ou só quando a data mudou.

---

## 🟡 Falta de idempotência e lacunas de proteção

- ✅ **Aporte/resgate/investimento com valor inicial não tinham `client_uuid`** (`AporteResgateIdempotenteTest`)
  (`HandlesContributions.php:56-80`, os 4 Form Requests de aporte/resgate,
  `InvestmentController::store`; o JS não desabilita o botão). Dois POSTs iguais = 2 aportes.
  Não cria dinheiro (o disponível cai de verdade nas duas), mas registra em dobro o que a
  pessoa fez uma vez. Convenção do projeto diz que toda escrita por clique leva uuid.
- ✅ **Parcelas de R$ 0,00** (`ParcelaMinimaDeUmCentavoTest`)**:** `amount` só exige `min:0.01` e `installments` vai até 24
  (`FaturaController.php:407-409`, `StoreFaturaLaunchRequest`). R$ 0,10 em 24x gera 14
  parcelas zeradas. Soma bate, nada negativo, mas nascem linhas de despesa de zero.
  Regra sugerida: `amount >= installments / 100`.
- ✅ **Editar SÓ a descrição de despesa financiada por resgate desfazia o resgate** (`EdicaoNeutraPreservaResgateTest`)
  (`TransactionController.php:250-262`, `reconciliarFonte` sempre recalcula do zero). Após um
  depósito, corrigir um typo devolveu R$ 300 ao investimento. É coerente e avisado, mas no
  mundo real o resgate já aconteceu no banco. Decisão: reconciliar só quando valor, conta ou
  tipo mudarem.
- **Obrigação vencida paga sem fonte grava `funding_source = null`** mesmo levando a conta
  ao vermelho (`FundingService.php:89-91`). Documentado como aceito; a trilha de auditoria
  do cheque especial fica em branco nesse caminho.
- Na troca de conta de uma despesa financiada, a conta **antiga** não é travada durante a
  reconciliação (só a nova). Sem reprodução de corrida, mas é assimetria.

---

## 🔵 Comportamentos confusos (decisão de produto, não defeito)

- **Recorrência de cartão nasce datada no `dueDate`** (`FaturaController.php:447-457`): fica
  fora do ciclo aberto e **não aparece na lista** do cartão até o ciclo virar. Clicar "Pagar"
  na ocorrência mais nova gera ocorrências indefinidamente no futuro.
- **Estornos do cartão nunca aparecem na lista de itens** (`FaturaService.php:255` filtra
  `expense`), embora abatam a fatura.
- **Segundo clique em competência já paga com cheque especial responde 409** em vez de "já
  estava paga" (guard roda antes do UNIQUE). Nada é gravado.
- **`destroy` apaga uma ocorrência recorrente isolada** (recorrente tem `group_id` mas
  `installments=null`; a guarda de parcela não dispara). Inofensivo em caixa.
- **Select do débito com corrente E poupança** (`Account.php:197-212`): submete só a corrente
  e mostra o saldo dela (700), enquanto o card do método exibe 1.200.
- **Transferência entre contas não existe:** despesa em A + receita em B infla receitas e
  despesas do mês (300/300, economia 0). Saldo total fica certo.
- **Projeção de investimento é linear, não composta** (`investimentos.js:247`): 24 meses a
  10% a.a. mostra 20% (composto 21%); IPCA+ é aditivo. Rotulada "estimativa".
- **Ao excluir investimento que financiou despesa**, a transação mantém
  `funding_source=resgate_investimento` apontando para algo que não existe. Dinheiro íntegro.
- **`transactions.update` não aplica o teto de 3×** ao editar um pagamento de conta fixa.
- Corte do donut "top 5 + Outros" só dispara com **7+** categorias (com 6 mostra as 6).
- **CLAUDE.md desatualizado:** diz "IOF não é modelado", mas `App\Support\TributosRendaFixa`
  modela a tabela de 1-29 dias (conferido: `decompor(100, 1)` → IOF 96, IR 0,90).

---

## ✅ O que foi verificado e está correto

- **Rateio de parcelas:** 100/12x (8,33/8,34), 100/3x, 1.000,01/12x, 0,10/3x — soma exata,
  sobra nas primeiras parcelas, nenhuma negativa.
- **Datas com fechamento dia 10:** 31/01, 30/01, 29/02/2028 → uma parcela por ciclo. Compra
  no dia do fechamento cai na fatura fechada; no dia seguinte, na aberta.
- **Limite do cartão:** 12x1.200 → comprometido 1.200; pagar 1ª fatura → 1.100; estornar o
  pagamento → volta a 1.200 e caixa restaurado; excluir a compra → 0 e `estornarFonte`
  chamado antes do delete. Duplo POST de quitação não cobra dobrado; duplo POST de 12x gera
  12 linhas, não 24.
- **Dívida de 3 meses:** aparece, não duplica no sino, vencimento mais antigo correto,
  `ciclo=fechado` quita só o fechado.
- **Bolsos:** `balance/reserved/available/spendable/overdraftAvailable` corretos com meta,
  investimento, resgate parcial e cheque especial; piso respeitado; `PRECISA_FONTE` ×
  `ESTOURA_LIMITE` nos limiares certos.
- **409 e fontes:** sem fonte → 422 sem gravar; cheque especial → `funding_amount` com clamp
  e conta no valor exato; resgate → só o faltante (inclui o vermelho existente), conta em
  0,00, `transaction_id` ligado; teto funciona no `store`.
- **Estorno:** apagar despesa financiada devolve o resgate sem dobrar; apagar despesa de
  cheque só devolve o saldo. Edição troca de conta reconcilia dos dois lados.
- **Débito/Pix:** `available` = soma das espelhadas; lançamento direto recusado; patrimônio
  não dobra.
- **Contas fixas:** projeção com `due_day` 31 gera 15 competências sem duplicar/pular (clamp
  28/02, 30/04); `ends_on` inclusivo por mês; pagar cria uma linha, idempotente pelo UNIQUE,
  teto 3×, valor real prevalece; vencida sem saldo negativa (obrigação), não vencida → 422/409;
  excluir com pagamentos só desativa; editar não altera pagamentos passados; sino sem duplicar.
- **Dashboard:** saldo do stat = Σ `available` de caixa = sidebar; quitação não é despesa mas
  desconta saldo; compra em cartão conta na data da compra, 12x uma por mês; despesa
  financiada é despesa e o resgate não é receita; aporte não é despesa; `economia` =
  receitas − despesas; limites 31/08 23:59 e 01/09 00:00 corretos; trends com base zero →
  null; `featureResumos` batem com as telas; "Limite disponível" = `availableLimitDisplay`.
- **Metas/investimentos:** aporte > disponível recusado mesmo com cheque livre; resgate só do
  que a conta aportou (A=100, B=50 → 120 em A recusado); excluir devolve às contas certas e
  bloqueia com conta no vermelho; meta acima do alvo clampa 100%; `StoreInvestmentRequest`
  grava uma contribuição só; `grossRate` e IR regressivo (22,5/20/17,5/15) conferidos nas
  fronteiras; datas futuras recusadas nas 5 portas; lock conta → pai nos três caminhos;
  dependente aporta com `user_id` do titular.

---

## Ordem sugerida de correção (executada nesta ordem, exceto F-2 por último)

1. **F-3** (teto do resgate nos 3 caminhos) — menor esforço, maior dano potencial.
2. **F-1** (parcela por ciclo, não por mês-calendário).
3. **F-4** (piso do cheque ao editar `initial_balance`).
4. **F-2** (crédito de estorno rolando entre ciclos) — o mais delicado; precisa de decisão de
   modelo (crédito rola para a fatura seguinte, como no cartão real).
5. **T-1 + T-2** juntos (um único ponto de "despesa líquida do cartão" para dailySums, ano,
   totals e donut).
6. T-3, T-4, T-5, T-6 e os itens de idempotência.
