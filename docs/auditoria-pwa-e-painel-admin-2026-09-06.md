# Auditoria: PWA / service worker e painel administrativo — 06/09/2026

Duas frentes em Chromium real (Playwright), com contextos persistentes para o service worker e
o painel ligado só durante a rodada (`.env` restaurado, admin e usuário-alvo apagados, dados de
teste removidos). **Nada corrigido** — só registro. Capturas e scripts no scratchpad da sessão.

## 🔴 Corrigir antes do deploy

- **P-1 · Com o service worker ativo, as fontes do design system NUNCA carregam.** O
  `SecurityHeaders` carimba a CSP também em `/sw.js`; um service worker herda a CSP do próprio
  script e o `fetch()` dele obedece a `connect-src 'self'`. O SW intercepta
  `fonts.googleapis.com`/`fonts.gstatic.com` e o fetch é bloqueado → a página recebe erro de rede
  e cai na fonte de sistema. Vale para **todo o app pós-login em qualquer navegador com SW**
  (Chrome desktop, Android, iOS PWA); só a tela de login mostra Sora. Passa despercebido porque a
  pilha de fallback é boa. Correção: não emitir CSP na rota do SW (ou liberar os dois hosts em
  `connect-src`), ou o SW não interceptar fontes.
- **P-2 · `Clear-Site-Data: "cache"` no logout NÃO limpa o Cache Storage do SW.** `"cache"` é
  só o cache HTTP; `caches.*` só cai com `"storage"`, que o app evita por causa do IndexedDB.
  Provado com stub sem JS na tela de login: `/transactions/create` (HTML autenticado com
  contas/categorias/família) continua no cache após o logout. Quem apaga de verdade é o JS da
  tela de login (`purgeCachedFormIfUserChanged`) — proteção que depende de o próximo carregamento
  executar o bundle. Sugestão: apagar no SW ao ver navegação para `/login`, ou `postMessage` no
  logout. A premissa documentada no controller e no CLAUDE.md está errada; a suíte só testa a
  presença do header.
- **A-1 · Painel DESLIGADO vaza que as rotas existem.** `PainelAdminLigado` é middleware de rota,
  então rota só-POST recebendo GET dá **405** no router e POST sem token dá **419** no
  `VerifyCsrfToken` do grupo `web`, ambos antes do 404 que ele quer produzir. Controle:
  `/nao_existe` dá 404 em todos os verbos. Um scanner distingue o prefixo. Correção: mover a
  checagem para antes do router (middleware global ou `prepend` no grupo) ou registrar as rotas
  só quando ligado.

## 🟠 Lógica quebrada

- **P-3 · Banner "precisa de você" mostra R$ 0,00 para todo item.** `offline-queue.js::brl()`
  faz `Number(payload.amount)` sobre `"31.000,00"` → `NaN`. Num aviso de dinheiro retido (409/422),
  o valor errado é o pior lugar para errar.
- **P-4 · Sino não atualiza depois de navegar por pjax.** O `.notif-badge` vive no shell e só
  muda em reload completo; criar uma conta fixa vencendo hoje e navegar não muda o número.
- **P-5 · Barra de cookies por cima do modal "Lançar"** na primeira visita (mesmo achado A-9 da
  auditoria de 05/09, agora também no mobile com teclado).
- **P-6 · Deploy com assets novos: sem aviso de nova versão e o cache de `/build` cresce para
  sempre.** Trocar o hash do `manifest.json` não muda o SW (`CACHE = 'sm-cache-v2'` fixo, sem
  `updatefound`/toast); a aba aberta e o pjax seguem com CSS velho até um reload completo, que num
  app instalado pode demorar dias; o cache guarda os dois CSS.
- **A-2 · Primeiro acesso do admin (setup do 2FA) não registra `LOGIN`, não envia alerta e não
  grava `last_login`.** `TwoFactorController::confirmar` (~52-72) só põe a flag na sessão; o
  `AdminAudit::registrar(LOGIN)` vive só em `verificar()`. Código errado no setup também não gera
  `TOTP_FALHOU`.
- **A-3 · Parte texto dos alertas do painel sai com entidades HTML** (`&lt;` em vez de `<`):
  `AlertaDoPainel::paragrafos` já aplica `e()`, `strip_tags` preserva, e `layout-texto.blade.php:16`
  escapa de novo. Provável mesmo duplo escape no `AlertaDeSeguranca` do app.
- **A-4 · Avatar `.ab` sem tamanho nas telas de pessoas do painel** (`design-system.css:504` só
  define `.acct .ab`; `admin/pessoas/index.blade.php:27` e `show.blade.php:11,71` usam solto).
- **A-5 · Páginas de erro fora do padrão:** 429 dos throttles é a genérica do Laravel, sem
  instrução de espera; o 405 sai **sem CSP, sem `X-Frame-Options`, sem `nosniff`** e com 5 scripts
  sem nonce (exceção lançada antes do grupo `web`).
- **A-6 · Ficha da pessoa não mostra se a conta tem 2FA** (`AdminPanelService::COLUNAS` não
  inclui `two_factor_confirmed_at`).

## 🔵 Observações

- `funding_max_amount` só é enviado para `resgate_investimento`; com `cheque_especial` o item
  enfileirado vai sem teto (o piso `overdraft_limit` é a trava). Conferir com a intenção.
- Tema do sistema (`prefers-color-scheme: dark`) é ignorado sem preferência salva: o app abre
  claro no celular escuro.
- `/sw.js` está no grupo `web` e devolve `Set-Cookie` a cada checagem do SW → uma linha em
  `sessions` por aparelho/dia.
- `storage/app/private/avatars` tem 8 `.jpg` órfãos, anteriores a esta rodada.
- Nenhum dos cenários 409/422/419/troca de usuário/SW-sync da fila tem teste automatizado; o
  e2e existente cobre só o caminho feliz. Os scripts do scratchpad podem virar specs.
- O painel não tem filtro titular/dependente (dependentes aparecem na linha da família).

## ✅ Verificado e correto

**PWA:** manifest válido e servido certo (ícones 192/512 `any` + `maskable` opacos, apple-touch
180), `beforeinstallprompt` dispara em Chromium headed, `--app` abre em standalone; SW registra com
escopo `/`, precache de `/offline` + ícones, `/build` cache-first, `/assets` SWR, navegação
network-first com página offline, `/transactions/create` abre offline com CSS. **Fila offline
completa:** básico; fechar aba e reabrir; Background Sync (handler provado por CDP: o SW envia
sem página aberta); 409 → `needsFunding` retido com banner e modal de fonte, nada gravado até a
escolha; 422 → `failed` com "Tentar de novo"/"Descartar" com `confirm`; 419 com sessão viva →
token novo e reenvio; 419 com sessão morta → fila com aviso e sincroniza após login; troca de
usuário → item segurado (`csrf` nulo, banner, nunca enviado nem apagado) e re-armado ao voltar;
duplo clique → 1 linha; transferência offline → 2 pontas; escolha de fonte que cai offline no
confirmar → enfileira com a fonte. **Mobile:** bottom-nav com `safe-area`, FAB, modal cabendo com
teclado simulado, histórico do pjax com back/forward e reload, anti-flash do tema escuro antes do
1º frame, layout íntegro sem Google Fonts, `theme-color` acompanhando o tema.

**Painel:** desligado → 404 em `/painel_admin` e `/inicio` logado e deslogado; credencial do app
recusada; throttle de login 3/min (4ª = 429) e teto por hora; alerta `LOGIN` com HTML + texto,
PT-BR, aparelho e IP; setup do 2FA obrigatório em toda rota, QR em SVG sem violação de CSP,
`otpauth://` fora do HTML, chave manual bate com o banco, 8 códigos exibidos uma vez, throttle
TOTP; desafio nas visitas seguintes com replay recusado, recuperação queimada; sessões do app e
do painel isoladas nos dois sentidos; 18 telas × 2 viewports com CSP/nonce, sem JS error, sem
inglês, sem rolagem lateral, **nenhum valor em dinheiro** (ficha do Victor com 148 lançamentos
mostra só contagens); banir derruba titular e dependente, apaga remember-me, mensagem sem o
motivo, filtro, alerta e histórico; desbanir; throttle de ação 5/min; excluir some com
dependente, fotos e sessões, alerta com descrição preservada e histórico mantido; logout só por
POST; SMTP fora → ação completa em 100 ms, aviso no log, sem 500.
