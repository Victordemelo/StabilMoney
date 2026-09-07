# Auditoria: volume/desempenho e backup/restauração — 06/09/2026

Duas frentes executadas em bancos MySQL separados. **Nada corrigido** — só registro.

> ⚠️ **Incidente da rodada:** o auditor de volume zerou o banco de dev `stabilmoney` por engano
> (`migrate:fresh` rodou no banco do `.env` porque as flags `-e` montadas numa variável do zsh não
> sofrem word-splitting). O dump completo feito pela auditoria de backup naquela manhã
> (19 tabelas, `-- Dump completed`, checksums iguais ao banco vivo, zero requisições no Apache
> entre o dump e o incidente) foi usado para restaurar — **restaurado com sucesso** no mesmo dia
> (3 users / 148 transactions / 5 accounts, checksums conferidos). O motor Docker (OrbStack)
> travou durante a rodada e precisou ser parado e reiniciado; os volumes persistiram. Lição: **nunca montar flags `-e` via
> variável em zsh**; comando destrutivo em container só com flag literal e guard de
> `getenv("DB_DATABASE")` antes.

---

## Parte 1 — Volume e desempenho

Seeder de **36 meses pelos caminhos reais** (faturas pagas via controller, contas fixas,
transferências, resgates via `FundingService`): 25.516 transações em 10,6 s, 300 chamadas HTTP,
**0 erros** (0×409, 0×422) — o modelo de dinheiro segurou. Depois o dobro (50.323, 72 meses).
Medição em processo (kernel HTTP), mediana de 3, MySQL 8.0 no Docker.

### 🔴 Problema estrutural

- **V-1 · Toda tela cresce linearmente com o histórico, inclusive as que não mostram dinheiro.**
  Dobrar as transações dobrou o tempo de todas: `/meu-perfil` 156→302 ms, `/configuracoes/2fa`
  157→302 ms, dashboard 321→581 ms. Contagem de queries estável (+2): não é N+1 por linha, são
  **somas sobre o histórico inteiro em cada request** pelos View Composers do shell
  (`AppServiceProvider.php` 51/59/70): `SidebarService::build` (23 q, 76→147 ms; `signedSumUntil`
  2× a 35 ms varrendo 25k linhas; laço do cheque especial em `SidebarService.php:102-103` sem
  `preloadMoney` — 4× `sum(amount)` de 16 ms em 100% das telas), `Account::paymentOptions` (5 q,
  38→76 ms; dois `GROUP BY account_id` `type: index` sobre a tabela inteira, para o `data-saldo`
  de um modal que a maioria das páginas nem abre) e `FaturaService::upcomingDue` (10 q, 39→74 ms).
  Só esses três custam ~140 ms e ~38 queries em qualquer página, ~300 ms no dobro. ~+6 ms por
  1.000 transações. Com 100k linhas uma tela em branco fica em ~600 ms e o dashboard ~1,1 s.
  **Correção de maior alavancagem não é índice:** saldo materializado por conta atualizado no
  `FundingService`, ou ao menos uma agregação única por request compartilhada entre sidebar, sino
  e modal.

### 🟠 Pontos específicos

- **V-2 · Query da recorrência do sino é a mais lenta do app e roda em toda página.**
  `FaturaService.php:158-165` (`recurring=1 and paid_at is null and not exists(credit_card)`):
  27→55 ms, `EXPLAIN` pelo índice errado (`user_id_client_uuid_unique`), 25k rows, `filtered
  0.33%`. Um índice `(user_id, recurring, paid_at)` a torna trivial.
- **V-3 · Dashboard: 103-108 queries, 321→581 ms.** `$deltas` com `Using temporary`,
  `signedSumUntil` 6× (~220 ms no dobro), `creditCardsPanel`/`obrigacoesEmAberto` sem
  `preloadMoney` (8 SUMs por cartão + `vencimentoMaisAntigoEmAberto` carregando todas as linhas
  em aberto), `featureResumos` (`DashboardService.php:310-328`) chamando `saved`/`aplicado`
  dentro de `sum/sortBy/map` (2 queries por meta/investimento, repetidas).
- **V-4 · `/faturas`: 83-87 queries, 320→534 ms, pico de 7,1 MB** (7× as outras): 2× os GROUP BY
  de 35 ms (`preloadMoney` dos cartões E `paymentOptions`), 6× `select categories where id in`
  repetido por card, e `recorrenciasParaAvancar` (`FaturaService.php:325-340`) faz `get()` de
  todas as recorrentes do cartão desde sempre só para pegar a mais recente de cada grupo.
- **V-5 · `/metas` e `/investimentos` (69-73 q):** as views imprimem `$account->available` por
  conta de caixa e os controllers não chamam `preloadMoney` → 6 queries por conta, cada SUM
  varrendo a conta (16→33 ms).
- **V-6 · `FixedBillService::occurrences` carrega todos os pagamentos de contas fixas** sem
  janela de datas (144→288 linhas), e entra em `upcomingDue` (toda página) e no dashboard.

### ✅ O que está bem

Nenhum N+1 por linha de listagem (`/transactions` custa o mesmo na página 1, 50, com filtro e
período). Escritas rápidas: despesa e transferência 19-31 ms / 15-16 q; pagar fatura com 278
linhas 82-100 ms; estornar 71-103 ms. Painel admin ≤ 12 ms (withCount com índice de cobertura).
Sem `type: ALL`, sem `filesort`/`temporary` em `transactions` fora do `$deltas`. `preloadMoney`
zera o crescimento onde é usado (accounts, cards de fatura, paymentOptions). Memória por request
0,5-1,1 MB. Banco com 50k transações: 6,8 MB de dados + **22 MB de índices** (10 índices; o
índice pesa 3× o dado); `mysqldump` em 0,16 s / 8,8 MB.

| Tela | queries base→dobro | ms base→dobro |
|---|---|---|
| `/` dashboard | 103→108 | 321→581 |
| `/transactions` (p1, p50, filtro, 1 ano) | 51→53 | 164-177→311-339 |
| `/faturas` | 83→87 | 320→534 |
| `/accounts` | 50→52 | 201→384 |
| `/metas` · `/investimentos` | 69-71→71-73 | 178-182→340-342 |
| `/meu-perfil` · `/configuracoes/*` | 43-53→45-55 | 156-203→302-391 |
| `POST /transactions` · transferir | 15-16 | 19→31 |
| Pagar fatura · estornar | 27 · 9 | 82→100 · 71→103 |
| Admin home · pessoas · pessoa | 8 · 4 · 3 | 1-12 |

**Resposta à pergunta:** hoje, sim — 3 anos com uso intenso fica em 150-320 ms por tela. O sinal
ruim é a curva: nada é cacheado ou materializado, então 6 anos = 2× e 12 anos = 4×, em todas as
páginas, por causa do shell.

---

## Parte 2 — Backup e restauração (`scripts/backup-db.sh` / `restore-db.sh`)

### 🔴 Defeitos nos scripts

- **B-1 · Restauração de arquivo corrompido apaga o banco e pode declarar "OK".** A validação
  (`restore-db.sh` ~119) só conta `CREATE TABLE ≥ 1`; `gunzip 2>/dev/null` engole o erro e
  ninguém confere `-- Dump completed`. Provado 3×: gzip truncado → passa → DROP de tudo → ensaio
  fica com 17 tabelas sem `users` nem `transactions`; SQL cortado num INSERT → 18 tabelas,
  `transactions` vazia; SQL cortado em fim de linha sem a tabela `users` → restaurou e imprimiu
  **"OK — banco restaurado com 18 tabelas"** (a conferência final usa o próprio dump como régua).
  O backup de segurança salvou em todos os casos, mas a promessa "valida antes de destruir" é
  falsa para arquivo parcial. Correção: exigir `gzip -t` **e** o rodapé `-- Dump completed`, e
  comparar o número de tabelas do dump com o do destino antes de apagar.
- **B-2 · O ensaio prometido no checklist não funciona com as credenciais do app.** O usuário
  MySQL só tem grant em `stabilmoney.*`; `CREATE DATABASE stabil_ensaio` dá `1044 Access denied`
  (`restore-db.sh` ~181). Na VPS será igual. Decisão: grant permanente para o ensaio ou o script
  usar `DB_ROOT_PASSWORD`.
- **B-3 · "Mais recente" é ordem alfabética, não cronológica, e ignora o nome do banco**
  (`restore-db.sh` ~107: `sort -r | head -1`). Os backups de segurança do restore vão para o mesmo
  diretório; com outro prefixo de nome, `./scripts/restore-db.sh` sem argumento restauraria o
  dump do ensaio por cima da produção.

### 🟠 Menores

- **B-4 · O backup de segurança do restore rotaciona com `--manter 14` fixo** (~172): quem roda o
  cron com `--manter 30` perde os backups 15-30 no primeiro restore.
- **B-5 · Com o PATH mínimo do cron o script diz "não achei o Docker Compose"** quando o problema
  é PATH. Cores ANSI vão para o log; resolução de segundo no nome (duas execuções no mesmo segundo
  se sobrescrevem); sem caminho de `.env` alternativo.
- Sem recuperação ponto-a-ponto: entre dois backups diários, o que se perde se perde. O binlog do
  incidente de julho está guardado, mas nada nos scripts o usa.

### ✅ O que funcionou

Backup do dev em 0,94 s (19 tabelas, 56 kB, `SET NAMES utf8mb4`, `-- Dump completed`), `600`
no arquivo e `700` no diretório, `.gitignore` criado, rotação `--manter 2` correta, **senha nunca
aparece em `ps` nem em `/proc/*/cmdline`** (só `--defaults-extra-file`), `.cnf` some no fim, falha
do `mysqldump` não deixa `.parcial`. Restore em 1,8 s para o ensaio: **contagens e `CHECKSUM
TABLE` idênticos nas 19 tabelas**, `migrate:status` 50/50, dashboard renderizado nos dois bancos
com JSON byte a byte igual, cifrados (`two_factor_secret`, `terms_accepted_ip`) decifram com o
mesmo `APP_KEY`, ponto-no-tempo no ensaio voltou a transação apagada. Confirmação exige digitar o
nome do banco; stdin fechado cancela; backup de segurança antes de sobrescrever; linha do cron
funciona sem TTY; `rsync -az` preserva `600`/`700`.

**Só a VPS prova:** `docker` no PATH do cron, log gravável, tempo com volume real, grant do
ensaio.
