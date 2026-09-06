# Auditoria: MySQL de verdade e ciclo de migrations — 05/09/2026

A suíte roda em sqlite `:memory:`; produção é MySQL 8.0. Isso já escondeu erro 1406 (varchar
estourado) duas vezes. Esta rodada rodou **a suíte inteira contra MySQL**, um **harness de
concorrência com processos reais** e o **ciclo completo de migrations** (fresh → rollback passo a
passo → migrate → comparação de schema), em bancos temporários dropados no fim. O banco de dev
ficou intacto. **Nada foi corrigido** — só registro.

## 🔴 Defeitos que só o MySQL revela (sqlite passa verde)

- **M-1 · Pagar fatura com cartão de nome longo → HTTP 500 (erro 1406).**
  `FaturaController.php:306` monta `'Pagamento da fatura — '.$account->name`; `accounts.name`
  aceita 255 e `transactions.description` é `varchar(255)`. Nome com mais de ~233 caracteres
  estoura. Rollback preserva o dinheiro, mas a pessoa vê tela branca. Em sqlite grava 279 chars.
- **M-2 · Banir usuário com nome + e-mail longos falha inteiro (erro 1406).**
  `AdminAudit.php:43` monta `name <email>` (até 513 chars) em `alvo_descricao varchar(255)`. O log
  é gravado dentro da ação de banir, então o banimento não acontece.

## 🟠 Testes que só passam em sqlite (o app está certo; o teste assume a gramática do sqlite)

- **M-3 · 7 testes falham no MySQL por quoting.** `CorridaNoClientUuidTest.php:71` filtra
  `str_contains($sql, '"client_uuid"')` e `EdicaoNeutraPreservaResgateTest.php:236` procura
  `delete from "investment_contributions"` — o MySQL usa crase, o listener nunca dispara. Corrigir
  comparando sem quoting (`str_replace(['"','`'], '', $sql)`) ou com `getQueryGrammar()->wrap()`.
  Consequência: **o CI não roda em MySQL**, e a suíte nunca exercita `lockForUpdate` (no-op em
  sqlite). O harness desta rodada é hoje a única prova de concorrência — vale versionar.

## 🔵 Observações

- `FundingService::spend()` (`FundingService.php:66`) abre `DB::transaction` **sem `attempts`**,
  ao contrário do `HandlesContributions` (3). Não houve deadlock em 30 rodadas porque a ordem de
  lock está consistente, mas um `1213` esporádico viraria 500 em vez de retry.
- `RequiresFundingChoice` é logado como **ERROR com stack trace a cada 409**
  (`app/Exceptions/RequiresFundingChoice.php`): não implementa `ShouldntReport`. Em produção cada
  pergunta de fonte vira ruído no log.
- **Flaky não reproduzido:** na 1ª de 3 rodadas da suíte no MySQL, 12 testes contíguos
  (`DoisFatoresTest` ×11 + um de "esqueci a senha") falharam com 419 ou `two_factor_secret` nulo
  após `save()->fresh()`. Passaram nas rodadas 2 e 3 e isolados 5 vezes. Causa desconhecida.
- `DB_ROOT_PASSWORD` não está no `.env`; o root do MySQL de dev usa o default do compose.
- Rollback profundo com dados (`encrypt_terms_accepted_ip::down` volta `text` → `varchar(45)` com
  cifrado de 200+ chars) não foi testado com dados — só o ciclo vazio e as 3 migrations de setembro.
- Comportamento REAL de produção que diverge do sqlite: login com `VICTOR@X.COM` **entra** no MySQL
  (`utf8mb4_unicode_ci`) e `unique(email)` é case-insensitive. O cadastro exige minúsculas antes
  disso, então é coerente. A suíte nunca vai perceber regressão aqui.

## ✅ Verificado e correto

### Suíte no MySQL (3 rodadas)
| Rodada | Resultado |
|---|---|
| 1 | 19 falhas (7 de M-3 + 12 flaky), 131 s |
| 2 e 3 | **7 falhas, só M-3** / 1.145 passaram, ~135 s |

### Concorrência real — processos PHP em paralelo contra o MySQL, 5 rodadas por cenário, **0 falhas, 0 deadlock, 0 HTTP 500**
| Cenário | Resultado |
|---|---|
| 10 despesas de R$ 100 simultâneas, conta com R$ 500, sem cheque | 5 gravadas, 5 recusadas, `available` = 0 |
| 10 despesas com resgate, 100 disponível + 1.000 aplicados | resgates = gasto − 100, aplicado ≥ 0, 0 órfãos |
| 2 pagamentos da mesma fatura (mesma e contas diferentes) | 1 quitação, saiu R$ 300 uma vez |
| 16 processos misturando aportes em meta/investimento, despesas e resgates na mesma conta | identidade contábil fechou nas 5; sem deadlock (ordem conta → pai consistente) |
| Mesmo `client_uuid` em 2 processos: lançamento, aporte, 12x, recorrência | 1 linha / 1 aporte / 12 parcelas (não 24) / 1 sucessora |

### Colunas e modo estrito
- Todas as colunas com cast `encrypted` são `text` (7 colunas conferidas por `SHOW CREATE`).
- `varchar` no limite gravam sem estouro onde há validação `max:` (descrição 255, motivo 500 etc.).
- `sql_mode` estrito com `ONLY_FULL_GROUP_BY`; todas as queries com `GROUP BY` passaram. Nenhum
  `whereDate`, `MONTH()` nem `NOW()` em SQL no `app/`.
- `DB_TIMEZONE +00:00`: `paid_at` às 23:30 de Brasília aparece em 04/09 nas telas.

### Migrations (MySQL 8.0 e sqlite) — **nenhum defeito**
| Etapa | Resultado |
|---|---|
| `migrate:fresh` | 7,9 s, 50 migrations, 0 warnings |
| `rollback --step=1` × 50 | todas OK; pós-reset só a tabela `migrations` |
| `migrate` de novo | schema **idêntico** ao fresh (`mysqldump --no-data` + diff) |
| Comparação passo a passo R[i] × U[i] | mesmo conjunto de linhas em todos os 50 pontos (só ordem de coluna muda no meio) |
| `fresh --seed` + demo + `rollback --step=3` (setembro) + `migrate` | 7 tabelas de dinheiro com checksum idêntico; nenhuma linha apagada |
| sqlite ↔ MySQL | 197 colunas, 48 índices e 25 FKs idênticos (tipos divergem só nas famílias esperadas) |
| Índices/FKs do modelo de dinheiro | todos presentes com a regra certa; FKs "de propósito ausentes" confirmadas ausentes |
| `route:cache`, `config:cache`, `schedule:list` | OK (`sessoes:limpar` 03:10, `lembretes:vencimentos` 08:00) |

Perda por desenho ao reverter setembro: pontas de transferência viram receita/despesa comuns,
`client_uuid` das contribuições some, `reminder_emails` volta ao default. Nenhuma linha apagada.
