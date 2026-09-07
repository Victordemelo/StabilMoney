# Cobertura das auditorias — o que ainda NÃO foi verificado (07/09/2026)

Pergunta que originou este documento: "todos os pontos do sistema foram verificados?"

**Resposta: não. 71%.** Seis agentes inventariaram **532 itens** do sistema (rotas, controllers,
services, models, policies, form requests, middlewares, comandos, mailables, 19 arquivos JS,
64 views, 13 configs, 50 migrations, testes e infraestrutura); três extraíram o que os sete
documentos de auditoria e a suíte de 1.152 testes **provam** ter exercitado; e o cruzamento foi
feito item a item, com a regra de que ler um arquivo não conta como verificá-lo. Depois, quatro
críticos independentes procuraram o que ninguém pensou em olhar.

| Fatia | Coberto | Cobertura |
|---|---|---|
| Rotas e controllers | 222/247 | 90% |
| Config, banco, testes e infraestrutura | 71/110 | 65% |
| Views Blade | 36/64 | 56% |
| Form Requests, middlewares, comandos, mails, providers | 22/40 | 55% |
| Services, models, policies e support | 20/37 | 54% |
| **JavaScript e service worker** | **5/34** | **15%** |
| **Total** | **376/532** | **71%** |

**120 itens nunca verificados** (27 de risco alto), **119 parcialmente**, e **46 lacunas novas**
dos críticos (16 de risco alto). Nada disso foi corrigido — é registro.

---

## O buraco estrutural: o JavaScript não tem verificação nenhuma

15% de cobertura, e o motivo é simples: **não existe runner de teste JS no projeto**. O
`package.json` tem só `@playwright/test`, sem vitest, jest ou jsdom. E o `ci.yml` roda apenas
`php artisan test` e `view:cache` — **nenhum passo de e2e e nenhum `npm run build`**. São 19
arquivos JS (~180 kB) e 13 blocos inline em Blade sem nenhuma rede de segurança automatizada.

Pior: **os dois specs e2e que cobririam essa fatia estão vermelhos** desde a auditoria de 05/09
(ambos começam por `page.goto('/register')`, que responde 500 com SMTP real).

O que isso deixa sem prova, em ordem de gravidade:

- **`nav.js::descartarScriptsSemNonce()`** — a única defesa que impede o pjax de virar bypass da
  CSP. Sem ela, qualquer `<script>` vindo no corpo de uma resposta seria recriado com o nonce
  vivo, entregando exatamente o que a CSP existe para negar. E o outro lado da mesma guarda
  falha por excesso: estrita demais, apaga os scripts legítimos e a tela renderiza inerte.
- **`charts.js`** — a regressão do XSS armazenado da legenda do donut, que foi o achado crítico
  do pentest de julho. A auditoria de 28/07 diz textualmente que a correção está "verificada
  APENAS por leitura". Continua assim.
- **`launch.js`, ramo do 419** — o `ModalLancarTrata419Test` faz `file_get_contents` do arquivo e
  procura strings. Isso prova que o código está escrito, não que funciona.
- **`money.js`** — toda entrada de dinheiro do app passa por aqui. O contrato dos centavos
  (`1 → 0,01`, `130000 → 1.300,00`) e o teto de 14 dígitos nunca foram exercitados.
- **`funding.js::enviarComFonte()`** e **`faturas.js [data-pay-ciclo]`** — o segundo copia o ciclo
  do botão clicado para o hidden do modal compartilhado; se quebrar, clicar em "pagar a vencida"
  paga a aberta.

## Risco alto entre os 120 nunca verificados

- **`admin:criar`** — a única porta de entrada do painel. Nenhum teste o chama; o `AdminFactory`
  monta o Admin na mão e passa ao largo de `Admin::provisionar`. E ele usa `Password::defaults()`
  com `uncompromised()`, que é desligado na suíte mas **não** no comando: na VPS ele faz uma
  chamada de rede ao Have I Been Pwned, e sem internet pode falhar justamente ao criar o primeiro
  admin.
- **`BrowserSessions::forUser()`** — o corpo inteiro nunca executa: o método aborta na primeira
  linha porque o `phpunit.xml` fixa `SESSION_DRIVER=array`. A tela de dispositivos ativos
  renderiza sempre o estado vazio nos testes.
- **`AdminAudit::avisar()`** — a suíte prova que a linha de log é gravada, mas nenhum teste faz
  `Mail::assertSent(AlertaDoPainel)`.
- **`AdminPanelService::familias()`** — a busca por nome/e-mail nunca recebe parâmetro em teste
  algum, incluindo o escape de `%` e `_` que vai para um LIKE.
- **A ordem dos stats do dashboard** — o CLAUDE.md marca como contrato load-bearing (o JS casa
  card e valor por índice), e nenhum teste assere as chaves.
- **`/painel_admin/historico`** — a única tela que torna o painel auditável não é tocada por
  nenhum dos três testes do painel, nem pela varredura de "não vê valores".
- **`config/session.php`** — `encrypt`, `http_only`, `same_site`, `secure`: grep em `tests/`
  devolve zero. O cookie nunca é emitido com esses atributos durante a suíte.
- **`APP_PREVIOUS_KEYS`** — a única rede de segurança para rotacionar a `APP_KEY` sem trancar
  fora quem tem 2FA. Marcada como crítica em duas seções do CLAUDE.md, nunca exercitada.

---

## As 46 lacunas dos críticos (o que ninguém pensou em olhar)

### 🔴 Cadeia de suprimentos e ambiente

- **18 advisories de segurança em 3 pacotes PHP** — confirmado agora: `guzzlehttp/guzzle` (7,
  uma HIGH), `guzzlehttp/psr7` (1) e `league/commonmark` (10, 8 HIGH). **Nenhuma auditoria de
  dependência jamais rodou**, e o CI não tem passo de `composer audit`. (`npm audit` de produção
  está limpo.)
- **O container roda sem `php.ini` nenhum** — confirmado: `Loaded Configuration File: (none)`. O
  Dockerfile não copia o `php.ini-production` da imagem base. Valem os padrões compilados:
  `upload_max_filesize=2M`, `post_max_size=8M`, `memory_limit=128M`. O upload de avatar valida
  `max:2048` (2 MB), exatamente no limite do PHP — um arquivo no limite mais o overhead do
  multipart é recusado pelo PHP antes de o Laravel ver, com mensagem confusa.
- **`/up` é a única rota fora do grupo `web`** — sem CSP, sem `nosniff`, sem `X-Frame-Options`, e
  a view do framework **carrega um script de CDN de terceiro na origem do app**. Além disso o
  health check é cego: não toca no banco e responde 200 até em modo manutenção, porque o app não
  registra nenhum listener de `DiagnosingHealth`. O smoke que varre todas as rotas a exclui
  explicitamente.
- **Capacidade nunca dimensionada** — Apache em prefork com `MaxRequestWorkers 150` de fábrica e
  `memory_limit` de 128 MB: teto teórico de 150 processos PHP numa VPS que não tem essa memória.
- Imagens sem digest (`php:8.4-apache`, `composer:latest`, `mysql:8.0`, `mailpit:latest`) — o
  stack está congelado por acidente, não por decisão.

### 🔴 Jornada real de uso

- **Recorrência de cartão não recupera meses pulados** — uma ocorrência por clique, sem
  agendador. Quem não abre o app por dois meses simplesmente não tem a despesa lançada.
- **Conta que já financiou meta ou investimento nunca mais pode ser excluída** — a guarda testa
  `exists()` sobre qualquer contribuição, sem filtrar o tipo, e resgatar não apaga o aporte.
- **Excluir a conta do titular apaga o login de todos os dependentes sem avisar nenhum deles** —
  a tela de pendências não menciona isso.
- **Trocar a senha de um dependente não derruba as sessões dele nem o avisa** — é o único caminho
  de troca de senha do app que não faz nenhuma das duas coisas.
- **Editar uma conta fixa reescreve o passado** — o valor previsto de hoje vale para todas as
  competências em aberto, inclusive as de meses anteriores. Reajustar o aluguel em julho move
  retroativamente o que ainda não foi pago.

### 🔴 Modos de falha

- **Excluir conta roda fora de qualquer transação** — nos dois caminhos (perfil e painel).
  `DB::transaction` aparece em 20 lugares do código, nenhum deles aqui. Uma falha no meio deixa
  arquivo apagado e linha viva, ou o contrário.
- **O guard de replay do 2FA tranca todo mundo fora se o relógio do servidor adiantar** — o passo
  gravado não tem teto nem checagem de sanidade.
- **O checklist de publicação não tem `migrate`** — grep no arquivo inteiro devolve zero. E
  `npm run build` sem `view:cache` antes esvazia o CSS, armadilha já documentada.
- **Nenhuma página de erro foi escrita** — não existe `resources/views/errors/`, e o `withExceptions`
  está vazio. 404, 419, 429, 500 e 503 caem na página mínima do framework, e o 419 destrói o
  formulário preenchido.
- **`public/hot` em produção deixa o app sem CSS e sem JS** — o Laravel decide por pura existência
  do arquivo, sem olhar o ambiente. O checklist não menciona apagá-lo.
- **SMTP com `timeout => null`** — um servidor que descarta pacotes em vez de recusar trava a
  requisição.

### 🔴 Artefatos

- **Não existe medição de cobertura de código** — 1.152 testes e nenhuma capacidade de dizer
  quais linhas eles executam. O `phpunit.xml` tem `<source>` mas nenhum bloco `<coverage>`.
- **Não existe `.dockerignore` e o Dockerfile nunca copia a aplicação** — não há imagem de
  produção, só bind mount de desenvolvimento.
- **39 seletores mortos** no CSS, herdados do protótipo.

---

## Leitura honesta

O que foi auditado, foi auditado a fundo: lógica financeira, autenticação, isolamento entre
famílias, concorrência real em MySQL, migrations, PWA, painel, volume, backup, e-mail, LGPD e
acessibilidade. As rotas e controllers estão em 90%.

O que ficou de fora tem um padrão claro: **tudo que a suíte PHP não alcança**. O JavaScript
(sem runner), o ambiente do container (sem php.ini, sem imagem de produção), as dependências
(sem `composer audit`), e o comportamento ao longo do tempo (recorrência pulada, conta fixa
reajustada, meses acumulados).

**Os três primeiros passos que eu daria**, por relação entre risco e esforço:

1. `composer audit` no CI e atualizar os 3 pacotes com advisories. É uma tarde.
2. `php.ini-production` no Dockerfile, `.dockerignore`, e `migrate --force` no checklist.
3. Um runner de teste JS (vitest) cobrindo `money.js`, `nav.js` e o ramo 419 do `launch.js`, e os
   dois specs e2e voltando ao verde e entrando no CI.
