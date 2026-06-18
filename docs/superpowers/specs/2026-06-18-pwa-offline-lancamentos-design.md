# Spec — PWA instalável + fila de lançamentos offline

**Data:** 2026-06-18
**Status:** Design aprovado (brainstorm). Fase 1 (PWA) aprovada para implementação imediata;
Fase 2 (fila offline) documentada aqui para revisão enquanto a Fase 1 é construída.

## Contexto

O StabilMoney é um monolito Laravel 12 (Blade server-rendered), mobile-first, multiusuário
(conta-família). A Fase 1 do roadmap previa PWA (`manifest` + service worker) ainda pendente.
Esta rodada torna o app **instalável** ("Adicionar à tela inicial" / botão Instalar) usando a
logo atual e, a pedido do usuário, adiciona **lançamentos offline com sincronização automática**.

A logo é servida de `public/assets/` (`stabilmoney-mark.png`, 256×256, verde sobre transparente).
Os três layouts (`app`, `auth`, `guest`) têm `<head>` próprio, sem partial compartilhado; já
trazem `theme-color`, `favicon.png` e `apple-touch-icon`.

## Decisões fechadas (brainstorm)

- **Escopo offline:** completo e seguro para app financeiro. Inclui fila de lançamentos offline
  com sync (escolha do usuário), **exceto** cachear HTML/dados financeiros logados para leitura
  (saldo nunca pode desatualizar; dado de um usuário nunca fica guardado para outro ver).
- **Arquitetura:** `manifest` e service worker servidos por **rota Laravel** (`PwaController`),
  não arquivos estáticos nem plugin (Workbox / `vite-plugin-pwa`). Motivos: testáveis com TDD
  (`$this->get('/sw.js')`), zero dependência nova de framework, alinhado à regra "vanilla, sem
  frameworks" do projeto.
- **Rotas públicas:** `manifest`, `sw.js` e `/offline` ficam **fora** do `middleware('auth')`.
  Justificativa: o navegador precisa ler o manifest para oferecer "Instalar" inclusive na tela
  de login (pública), e registra/atualiza o SW por conta própria, às vezes em segundo plano sem
  sessão ativa. Nenhum desses arquivos expõe dado do usuário (manifest = nome+cores+ícones;
  SW = código genérico de cache; `/offline` = aviso "sem conexão"). O **app** segue 100% atrás
  de login.
- **Ícones:** gerados da logo transparente (`stabilmoney-mark.png`). O JPG de 1024px
  (`stailmoney_faviicon.jpg`) tem xadrez de transparência queimado no fundo → descartado.
  Estilo: **símbolo branco sobre fundo verde da marca** (`#0C3D2B`, mesmo `theme-color`).
- **Fila offline (Fase 2):** sincronização **dirigida pela página** (não pelo service worker),
  para manter o CSRF ligado — ver "Decisões difíceis" abaixo.

## Fase 1 — PWA instalável (aprovada para implementação)

### 1.1 Rotas públicas + `PwaController`
Adicionar **antes** do grupo `auth` em `routes/web.php`:
- `GET /site.webmanifest` → `PwaController@manifest`: JSON do manifest,
  `Content-Type: application/manifest+json`.
- `GET /sw.js` → `PwaController@serviceWorker`: JS do service worker,
  `Content-Type: application/javascript`. Servido na **raiz** → escopo `/` (controla o app
  inteiro) sem header extra.
- `GET /offline` → `PwaController@offline` (ou `Route::view`): página da marca "Você está offline".

> Nenhuma colisão: `/sw.js`, `/site.webmanifest`, `/offline` não existem hoje (verificado).

### 1.2 Manifest (conteúdo)
```jsonc
{
  "name": "Stabil Money",
  "short_name": "StabilMoney",
  "description": "Controle financeiro pessoal e da família.",
  "lang": "pt-BR",
  "dir": "ltr",
  "start_url": "/",
  "scope": "/",
  "display": "standalone",
  "orientation": "portrait",
  "background_color": "#FFFFFF",
  "theme_color": "#0C3D2B",
  "icons": [
    { "src": "/assets/icons/icon-192.png", "sizes": "192x192", "type": "image/png", "purpose": "any" },
    { "src": "/assets/icons/icon-512.png", "sizes": "512x512", "type": "image/png", "purpose": "any" },
    { "src": "/assets/icons/icon-maskable-512.png", "sizes": "512x512", "type": "image/png", "purpose": "maskable" }
  ]
}
```

### 1.3 Ícones (`public/assets/icons/`)
Gerados por script Node `scripts/generate-pwa-icons.mjs` usando **`sharp`** (devDependency).
PNGs finais **commitados** — produção não precisa do sharp. O script compõe o símbolo branco
(via alpha do PNG) centralizado sobre fundo verde `#0C3D2B`:
- `icon-192.png` (192, purpose any) — símbolo ~70% do canvas.
- `icon-512.png` (512, purpose any).
- `icon-maskable-512.png` (512, maskable) — símbolo ~60% (dentro da safe-zone de 80%).
- `apple-touch-icon.png` (180, opaco — iOS ignora transparência).

### 1.4 Service worker (Fase 1 — conservador)
- **Versão no nome do cache** (ex.: `sm-cache-v1`); `activate` apaga caches de versões antigas.
- **Precache** no `install`: `/offline` + os 3 ícones.
- **`fetch` handler — só intercepta GET.** POST/PATCH/DELETE (forms com CSRF) **passam direto**
  (`return;` sem `respondWith`). É a salvaguarda que evita quebrar os forms do Laravel.
  - **Assets estáticos same-origin** (`/build/*`, `/assets/*`) e **Google Fonts**: cache-first.
  - **Navegações** (`request.mode === 'navigate'`): network-first → falha de rede cai em `/offline`.
  - **Nunca** grava HTML autenticado no cache (exceto o form de lançamento na Fase 2).
- `skipWaiting()` + `clients.claim()` para a atualização valer rápido.

### 1.5 Cabeçalho + registro
- Novo partial `resources/views/partials/pwa-head.blade.php` incluído nos **3 layouts**:
  `<link rel="manifest" href="/site.webmanifest">` + metas iOS
  (`apple-mobile-web-app-capable`, `apple-mobile-web-app-status-bar-style=black-translucent`,
  `apple-mobile-web-app-title=StabilMoney`) + `apple-touch-icon` apontando para o ícone opaco.
  **Aditivo** — não remove favicon/theme-color existentes.
- Novo módulo `resources/js/sm/pwa.js`: registra `/sw.js` com `'serviceWorker' in navigator`
  + `try/catch`. Chamado no `init()` do `app.js`. Falhou → não quebra a página.

## Fase 2 — Fila de lançamentos offline (para revisão)

### Objetivo
Lançamento feito offline é **salvo no celular** e **reenviado sozinho** quando a internet volta,
sem duplicar e sem perder dado.

### Decisões difíceis (as 4 dúvidas do usuário, resolvidas)

1. **Token CSRF que expira →** a sincronização é **dirigida pela página**, não pelo service
   worker. O SW não consegue ler cookies/token; replay no SW obrigaria a desligar o CSRF do
   endpoint. Em vez disso, a página lê o token fresco de `<meta name="csrf-token">` e faz o POST
   normal. **CSRF continua ligado, sem endpoint isento.** Gatilhos do replay: evento `online`
   e carregamento da página. Trade-off: sincroniza quando o app está aberto e recupera conexão
   (cobre o caso real "lancei e meu sinal estava ruim"); sync com app fechado (SyncManager) fica
   como evolução futura.
2. **Não duplicar →** cada item da fila ganha um **`client_uuid`** (UUID gerado no cliente).
   Coluna `transactions.client_uuid` única. No `store`, se já existir transação com aquele uuid
   na família, o servidor **retorna a existente** em vez de criar. Protege contra replay repetido,
   toque duplo e múltiplas abas.
3. **Sessão válida na hora de sincronizar →** como o replay é na página, o usuário está presente.
   Se a sessão expirou, o POST volta 401/redirect → o item **fica na fila** e mostramos "faça
   login para sincronizar". Nada é perdido silenciosamente.
4. **Conflitos (conta/categoria apagada) →** o `store` revalida posse de conta/categoria (regras
   já existentes). 422 → o item é marcado **"falhou"** com a mensagem do servidor, exibida ao
   usuário para corrigir/reenviar. Sem descarte silencioso.

### Servidor
- **Migration:** `transactions.client_uuid` — `char(36)` nullable, **unique**. Reversível.
  Linhas existentes ficam null (lançamentos feitos pela web normal não usam uuid).
- **`StoreTransactionRequest`:** aceita `client_uuid` opcional (`nullable|uuid`).
- **`TransactionController@store`:** se `client_uuid` presente e já existir transação com ele na
  família → não cria de novo (idempotente). **Negociação de conteúdo:** requisição com
  `Accept: application/json` (o replay) recebe **JSON** (`201` criado / `200` já existia /
  `422` validação); o form web normal continua com **redirect** (comportamento atual intacto).

### Cliente
- **Alcançar o form offline:** o SW faz **stale-while-revalidate** de `/transactions/create`
  (exceção controlada: é HTML autenticado, cacheado só para o dono do dispositivo). Os selects
  de conta/categoria vêm do HTML cacheado (aceita leve desatualização offline).
- **`resources/js/sm/offline-queue.js`:**
  - Intercepta o submit do form de lançamento. Se `!navigator.onLine` (ou o fetch falhar):
    gera `client_uuid`, guarda o payload no **IndexedDB**, mostra toast "Lançamento salvo —
    será enviado quando você voltar a ficar online", e libera o usuário.
  - Nos gatilhos (`online`, load): drena a fila — POST de cada item para `/transactions` com
    `Accept: application/json` e o `_token` do meta. Sucesso → remove da fila + atualiza UI;
    422 → marca "falhou"; 401/419 → mantém + pede login.
  - **Indicador** discreto "N lançamento(s) pendente(s)"; itens pendentes aparecem na lista com
    chip "pendente".
- **Logout:** limpa o cache do form autenticado e a fila do IndexedDB (evita um segundo usuário
  no mesmo aparelho reenviar lançamentos do primeiro). Se houver itens pendentes ao sair, avisar.

## Não vai quebrar (tudo aditivo + handler conservador)

- Rotas novas (sem colisão), partial/JS/ícones novos, 1 devDependency dev-only.
- SW ignora POST/PATCH/DELETE → forms e CSRF intactos.
- Migration da Fase 2 só adiciona coluna nullable.
- Nenhuma rota/middleware/teste existente é alterado.
- Rodar a suíte completa (87 testes) + os novos no fim de cada fase.

## Testes (TDD — testar antes de codar)

### Fase 1 — `tests/Feature/PwaTest.php` (PHPUnit, totalmente testável)
1. `GET /site.webmanifest` → 200, `application/manifest+json`, JSON válido com `name`,
   `short_name`, `start_url`, `display: standalone`, ícones 192 + 512 + maskable.
2. `GET /sw.js` → 200, content-type JS, contém a versão de cache e referência a `/offline`.
3. `GET /offline` → 200, contém "Você está offline".
4. As 3 rotas acessíveis **sem login** (guest).
5. Layout app (autenticado), login e guest contêm `<link rel="manifest">`.
6. Arquivos de ícone existem em `public/assets/icons/`.

### Fase 2 — `tests/Feature/OfflineSyncTest.php` (servidor; TDD)
1. POST com `client_uuid` cria a transação (1ª vez) e responde JSON quando `Accept: json`.
2. POST repetido com o **mesmo** `client_uuid` **não** duplica (dedupe) e responde a existente.
3. POST com conta/categoria inválida → 422 JSON com a mensagem.
4. Form web normal (sem `Accept: json`) continua redirecionando com flash de sucesso.
5. `client_uuid` é escopado por família (uuid de outra família não vaza).

> **Limite honesto do TDD:** o comportamento de IndexedDB / service worker / replay no `online`
> é de navegador — não dá para cobrir em PHPUnit. Servidor (rotas, migration, dedupe, JSON) é
> 100% TDD; o lado cliente é **verificado rodando o app** (skill `verify`/`run`) com DevTools
> (Application → Manifest/Service Workers, throttling Offline).

## Fora de escopo (futuro)

- **Background Sync com app fechado** (SyncManager) — Fase 2 sincroniza com app aberto/online.
- Cache de leitura de dados financeiros offline (saldo/listas) — excluído de propósito.
- Edição/exclusão de lançamento offline — só **criação** entra nesta rodada.
- Notificação "nova versão disponível" com botão recarregar — `skipWaiting` cobre o básico.
- Push notifications.
