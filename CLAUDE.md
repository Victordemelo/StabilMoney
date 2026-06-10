# Stabil Money — Guia do Projeto

App **financeiro pessoal multiusuário**: receitas, despesas, contas/cartões, categorias,
saldo e dashboard. **Monolito Laravel 12** (Blade server-rendered), web + celular do mesmo
código (PWA na próxima fase). UI 100% em **português do Brasil**, moeda **R$** (formato pt-BR).

> Este arquivo é o contexto que o Claude Code carrega a cada sessão. **Mantenha-o atualizado**
> quando decisões de stack, arquitetura ou convenções mudarem.

---

## Visão e processo de design

O visual do app vem de um **handoff do Claude Design** (claude.ai/design), versionado em `design/`:

- `design/project/StabilMoney Dashboard.html` — protótipo completo (app shell + todas as views).
- `design/project/styles.css` — design system (tokens, temas claro/escuro, componentes, responsivo).
- `design/project/app.js` — interações do protótipo (tema, drawer, gráficos SVG, contadores).
- `design/README.md` + `design/chats/chat1.md` — intenção do usuário.

**Processo:** o usuário desenha no Claude Design → exporta o bundle → o Claude Code implementa
**pixel-fiel** no Laravel. O protótipo é uma SPA fake (troca de views via JS); o app real é
**server-routed**: cada item de menu é um link para uma rota Laravel, estado ativo via
`request()->routeIs(...)`. **Nunca** copie a lógica de troca de views do protótipo.
Para novas telas, consulte sempre o HTML/CSS do protótipo como fonte da verdade visual.

---

## Stack (implementada)

| Camada | Escolha | Observações |
|--------|---------|-------------|
| Backend | **Laravel 12** (PHP 8.4) | Monolito, resource controllers + Form Requests + Policies + Services. |
| Banco | **MySQL 8.0** (Docker) | Container `db`, porta 3306. |
| Runtime | **Docker** (php:8.4-apache) | Container `app`, site em http://localhost:8000. Host não precisa de PHP. |
| Frontend | **Blade + design system próprio** | `resources/css/design-system.css` (portado de `design/project/styles.css`) + `forms.css`. Tailwind 4 carregado como base utilitária via Vite 7. |
| JS | **Vanilla** em `resources/js/sm/` | SEM Alpine, SEM frameworks. Módulos: `theme.js`, `shell.js`, `charts.js`, `dashboard.js`. |
| Auth | **Laravel Breeze 2.4** (blade) | Telas reescritas no design system, em PT-BR. |
| i18n | **laravel-lang/common** | `lang/pt_BR` completo (validation, auth, passwords). `APP_LOCALE=pt_BR`; `Carbon::setLocale` no `AppServiceProvider`. |
| Mobile | **PWA** (Fase 1, pendente) | Web instalável; sem Android Studio por enquanto. |

---

## Estrutura do monolito (mapa de pastas)

```
app/
├── Http/
│   ├── Controllers/        # Dashboard, Transaction, Account, Category, Profile + Auth/ (Breeze)
│   └── Requests/           # Form Requests com mensagens/attributes PT-BR (Store/Update por recurso)
├── Models/                 # User, Account (accessor balance), Category, Transaction
├── Policies/               # Account/Category/TransactionPolicy (update+delete = dono); descoberta automática
├── Services/               # DashboardService (toda a agregação SQL do dashboard, sem N+1)
├── Support/                # DefaultCategories (categorias padrão; seedFor() idempotente)
├── Listeners/              # SeedDefaultCategoriesForNewUser (evento Registered, auto-descoberto)
└── Providers/              # AppServiceProvider (Carbon::setLocale)

resources/
├── css/
│   ├── app.css             # Orquestra: @import tailwindcss + design-system + forms (+ @source)
│   ├── design-system.css   # Design system completo portado do protótipo + seção "Extensões"
│   └── forms.css           # Formulários, pickers, filtros, paginação, flash de erro
├── js/sm/                  # theme.js, shell.js, charts.js, dashboard.js (vanilla, orientados a dados)
└── views/
    ├── layouts/            # app.blade.php (shell: sidebar/topbar/bottom-nav) e guest.blade.php (auth)
    ├── partials/           # sidebar, topbar, bottom-nav, flash
    ├── dashboard.blade.php
    ├── transactions|accounts|categories/   # index/create/edit + _form por recurso
    ├── auth/               # 6 telas Breeze reescritas (login, register, etc.)
    ├── profile/            # edit + partials (perfil, senha, excluir conta com modal)
    └── coming-soon.blade.php   # placeholder das seções futuras

design/                     # Handoff do Claude Design (fonte da verdade visual — NÃO editar)
lang/pt_BR(+.json)          # Traduções PT-BR (laravel-lang)
routes/web.php              # Rotas do app | routes/auth.php (Breeze)
database/migrations/        # users/cache/jobs + accounts/categories/transactions
```

---

## Autenticação (multiusuário — FEITO)

- Login obrigatório: **todas** as rotas do app ficam sob `middleware('auth')`.
- **REGRA: NUNCA usar `Auth::id() ?? 1`** (fallback antigo, já removido). Use
  `auth()->id()` / `$request->user()->id` e escope **toda** query pelo dono.
- Registro dispara o listener `SeedDefaultCategoriesForNewUser` → cria as categorias padrão
  (9 despesas + 5 receitas, cores da paleta) via `App\Support\DefaultCategories::seedFor()`.
- `DatabaseSeeder` roda **só em ambiente `local`**: usuário demo `victor@stabilmoney.test`
  (senha de `SEED_USER_PASSWORD`, fallback `password`; `is_admin`) + categorias padrão + conta Carteira.
- `User` **não** implementa `MustVerifyEmail` (fluxo de verificação pronto em PT-BR, desativado de propósito).

---

## Mapa de rotas / telas

| Rota (name) | View | O que mostra |
|---|---|---|
| `GET /` (`dashboard`) | `dashboard.blade.php` | Stats com sparklines, segmented semana/mês/ano, fluxo de caixa, donut por categoria, transações recentes, "Meu cartão" + contas, cards "Em breve". |
| `/transactions` (resource, sem `show`) | `transactions/*` | Lista com filtros GET (tipo/conta), paginação; form com type-toggle, valor com vírgula, conta, categoria filtrada por tipo. |
| `/accounts` (resource, sem `show`) | `accounts/*` | **"Cartões"** no menu. Cards `.cc` com saldo (accessor `balance`), gradiente pela cor; form com icon/color picker. |
| `/categories` (resource, sem `show`) | `categories/*` | Cards Receitas/Despesas com linhas emoji+nome; form com type-toggle e pickers. |
| `GET/PATCH/DELETE /configuracoes` (`profile.*`) | `profile/edit` | Perfil, senha e exclusão de conta (modal de confirmação com senha). |
| `/investimentos`, `/metas`, `/faturas`, `/relatorios`, `/ajuda` | `coming-soon` | Placeholders "Em breve" com `$title`/`$description`/`$page`. |
| `routes/auth.php` | `auth/*` | Breeze: login, registro, esqueci/redefinir senha, confirmar senha, verificar e-mail. |

---

## Contrato `#sm-dashboard-data`

A view do dashboard embute um `<script type="application/json" id="sm-dashboard-data">` gerado
pelo `DashboardService` (consumido por `resources/js/sm/dashboard.js` — sem esse id o módulo não roda):

```jsonc
{
  "periods": {                       // "semana" | "mes" | "ano"
    "mes": {
      "sub": "Junho de 2026",        // legenda do período (#periodSub)
      "labels": ["Sem 1", ...],      // buckets do fluxo de caixa (semana: Seg..Dom; ano: Jan..Dez)
      "receitas": [0.0, ...],        // série por bucket
      "despesas": [0.0, ...],
      "stats":  { "saldo": 0.0, "receitas": 0.0, "despesas": 0.0, "economia": 0.0 },
      "trends": { "saldo": -4.2, ... }  // % vs período anterior; null = sem base ("—" neutro)
    }
  },
  "sparks": { "saldo": [...], "receitas": [...], "despesas": [...], "economia": [...] }, // últimos 7 dias; [] sem dados
  "cats": [ { "name": "Alimentação", "value": 0.0, "color": "#0F6B47" } ], // despesas do mês, top 5 + "Outros"
  "hasData": true                    // usuário tem transações?
}
```

Convenções do front: valores do **mês** são server-rendered (acessível sem JS); o JS anima
contadores e troca período sem reload. Trend de **despesas** invertida (cair = verde/`up`).
Saldo total = atual de todas as contas, independe do período.

---

## Modelo de dados

- **users** — `name`, `email`, `password`, `is_admin` (boolean).
- **accounts** — `user_id`, `name`, `type` (`wallet|bank|credit_card|savings|investment|other`),
  `initial_balance`, `color`, `icon`. Saldo = `initial_balance` + receitas − despesas
  (accessor `balance` no model; o dashboard calcula via SQL agregado).
- **categories** — `user_id`, `name`, `type` (`income|expense`), `color`, `icon`.
- **transactions** — `user_id`, `account_id` (FK `cascadeOnDelete`), `category_id`
  (nullable, FK `nullOnDelete`), `type` (`income|expense`), `amount` (decimal 15,2 **sempre
  positivo**), `description` (nullable), `date`.

> **Dinheiro**: `decimal(15,2)`; o sinal vem do `type`, nunca do valor.
> Excluir **conta** com transações é bloqueado (apagaria histórico); excluir **categoria** é
> permitido (transações viram "Sem categoria").

---

## Convenções

- **Validação em Form Requests** com `messages()`/`attributes()` PT-BR. Valores aceitam vírgula
  pt-BR ("1.234,56") normalizada em `prepareForValidation`.
- **Ownership sempre**: queries escopadas por `auth()->id()`; `account_id`/`category_id` validados
  com `Rule::exists()->where('user_id', ...)`; categoria deve casar com o `type` da transação.
- **Policies** para `update`/`delete` (dono); controllers usam `$this->authorize()`
  (trait `AuthorizesRequests` adicionado localmente — o `Controller` base do Laravel 12 é vazio).
- Controllers como **resource controllers**; lógica pesada em **Services** (ex.: `DashboardService`).
- Views Blade com `@extends('layouts.app')` + `@section('content')` e `@section('title')`;
  **mobile-first**, classes do design system (Tailwind só como utilitário pontual com tokens `var(--...)`).
- **Dark mode**: atributo `data-theme` no `<html>` + `localStorage` chave `sm-theme`
  (anti-flash inline no head dos layouts). Collapse da sidebar em `sm-collapsed`.
- Flash de sucesso: `session('status')` ou `session('success')` (partial `partials/flash`);
  erros via `$errors` / banner `.flash-error`.
- Strings de UI e comentários de código em **PT-BR**.
- Migrations sempre reversíveis (`down()`).
- Commits: prefixos `Feat:`, `Fix:`, `style:`.

---

## Fluxo de trabalho

> Pré-requisito: **Docker Desktop** (WSL2 no Windows) e **Node no host**.

```powershell
# 1. Subir containers (app + MySQL)
docker compose up -d --build

# 2. Dependências PHP (dentro do container)
docker compose exec app composer install
docker compose exec app chmod -R 777 storage bootstrap/cache   # evita erro 500

# 3. .env + chave (já configurado para MySQL)
docker compose exec app php artisan key:generate

# 4. Tabelas + dados demo (demo só em APP_ENV=local)
docker compose exec app php artisan migrate --seed

# App: http://localhost:8000  (login demo: victor@stabilmoney.test / password)
```

### Assets (Vite/Tailwind) — rodam no HOST
```powershell
npm install
npm run dev      # hot reload (Vite em http://localhost:5173)
npm run build    # produção (gera public/build — necessário p/ páginas sem `npm run dev`)
```

### Comandos úteis
```powershell
docker compose exec app php artisan test                       # suíte de testes (Feature: auth + profile)
docker compose exec app php artisan migrate:fresh --seed       # recria o banco do zero
docker compose exec app php artisan tinker                     # console interativo
docker compose exec app php artisan view:cache                 # valida sintaxe de TODAS as views
docker compose exec app bash                                   # shell no container
docker compose logs -f app                                     # logs Apache/PHP
docker compose down                                            # derruba containers
```

> Os testes usam `withoutVite()` no `TestCase` base — rodam sem build de assets.

### Testar no celular (mesma rede Wi-Fi)
`ipconfig` para descobrir o IP → `http://SEU_IP:8000` no navegador do celular.
Com a PWA pronta (Fase 1), "Adicionar à tela inicial".

---

## Roadmap

- **Fase 0 — Núcleo: ✅ CONCLUÍDA.** Modelo de dados, CRUD completo (transações/contas/categorias),
  dashboard com dados reais (períodos, trends, gráficos SVG), layout mobile-first pixel-fiel ao design,
  tema claro/escuro.
- **Fase 1 — PWA + Login: 🔶 PARCIAL.**
  - ✅ Autenticação multiusuário (Breeze, telas no design system, PT-BR, categorias padrão no registro).
  - ⬜ PWA: `manifest.json` + service worker (instalável na tela inicial).
- **Fase 2 — Futuro:** empacotar a PWA como app Android (TWA) para a Play Store **ou** Flutter
  consumindo API; **bot WhatsApp** para lançar transações por mensagem + **deploy em VPS** como
  visão de infraestrutura.
