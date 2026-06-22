# Stabil Money — Guia do Projeto

App **financeiro pessoal multiusuário**: receitas, despesas, contas/cartões, categorias,
saldo e dashboard. **Monolito Laravel 12** (Blade server-rendered), web + celular do mesmo
código (PWA na próxima fase). UI 100% em **português do Brasil**, moeda **R$** (formato pt-BR).

> Este arquivo é o contexto que o Claude Code carrega a cada sessão. **Mantenha-o atualizado**
> quando decisões de stack, arquitetura ou convenções mudarem.
>
> **Idioma — responda SEMPRE em português do Brasil**: explicações ao usuário, mensagens de
> commit, descrições de PR e comentários de código. (Preferência do Victor.)

---

## 🧭 Estado atual (leia primeiro numa sessão nova)

**Última grande entrega (jun/2026):** **design v2** implementado (handoff novo do Claude
Design) — escopo desta rodada foi **só visual/shell/auth**, sem features financeiras novas:

- **Shell v2:** sidebar com menu reorganizado (grupos "Menu" e "Preferências"), **popover de
  perfil** (Meu perfil/Configurações/Sair — Configurações saiu do menu lateral), card
  **"Patrimônio total" com dados reais** (saldo, spark 7 dias em SVG server-rendered, variação
  30 dias via `SidebarService` + View Composer), card **Dependentes** (estado vazio → rota
  placeholder), topbar com botão "Lançar", bottom-nav v2, **logo/favicon reais** (`public/assets/`).
  Em 18/06/2026 entrou também o **fundo animado "falling"** atrás de todo o app pós-login
  (estrias de luz verdes caindo, CSS puro em `.sm-falling` no `design-system.css`, incluído no
  `layouts/app.blade.php`, nos dois temas e respeitando `prefers-reduced-motion`).
- **Auth v2:** login e cadastro em layout split com **vídeo de fundo** (`layouts/auth.blade.php`
  + `auth.css` escopado, sempre claro). Cadastro **sem confirmação de senha** e com **aceite de
  termos obrigatório** (validado no servidor).
- **Telas:** categorias em 2 colunas com **drag & drop para trocar o tipo** (PATCH AJAX);
  `/accounts` agora se apresenta como **"Métodos de Pagamento"**; rotas `relatorios`/`ajuda`
  removidas; rota `dependentes` adicionada (coming-soon).

O app segue **funcional de ponta a ponta** em dev: login/registro → dashboard com dados
reais → CRUD de transações/contas(=métodos de pagamento)/categorias.

| O quê | Estado |
|---|---|
| Núcleo (CRUD + dashboard + design system) | ✅ Pronto e testado |
| Login multiusuário (Breeze customizado) | ✅ Pronto (isolamento testado) |
| Design v2 (shell, popover, patrimônio, auth com vídeo) | ✅ Pronto |
| Suíte de testes | ✅ 184 testes / 610 asserções verdes |
| Features financeiras v2 (metas, investimentos, faturas/despesas, cartão c/ ciclo/limite) | ✅ **Implementadas** (jun/2026) |
| PWA (manifest + SW + lançamento offline com fila e Background Sync) | ✅ Instalável + offline (Fases 1-2) |
| Deploy (VPS) / domínio | ⬜ Futuro (ver "Visão de infraestrutura") |

**Para subir o ambiente:** seção "Fluxo de trabalho" abaixo. **Login de dev:** o usuário do
seeder vem das variáveis `SEED_USER_*` no `.env` (e-mail `victor.rosa.system@gmail.com`;
a senha está **só no `.env`**, nunca no código/git).

**Repositório:** `github.com/Victordemelo/StabilMoney` — **privado**. O `gh` CLI está
instalado e autenticado na máquina do Victor (conta `Victordemelo`).

---

## 📜 Decisões históricas (por quê as coisas são assim)

- **Nome:** era "MoneyLife"; renomeado para **Stabil Money** em jun/2026 (moneylife.com.br
  estava ocupado). Domínios `stabilmoney.com.br` e `.com` estavam **livres** em 09/06/2026 —
  plano: registrar o `.com.br` no registro.br como principal.
- **Laravel + PWA, não Supabase/Flutter:** avaliados em jun/2026; manter Laravel aproveita o
  que já existia e evita aprender duas stacks novas de uma vez. App nativo fica para a Fase 2
  via **TWA** (empacota a PWA sem reescrever). Supabase só entraria se um dia o app fosse
  nativo puro.
- **Docker:** a máquina do Victor não tem PHP/Composer — só Node. Tudo PHP roda no container.
- **Auth:** até a Fase 1 havia um fallback `Auth::id() ?? 1` (single-user). Foi **removido**
  quando o Breeze entrou — hoje login é obrigatório em tudo.
- **Perfil do dono (Victor):** nível inicial/intermediário em Laravel; nunca publicou na Play
  Store; prefere explicações didáticas em PT-BR. Ele desenha o visual no Claude Design e o
  Claude Code implementa (ver processo abaixo).

---

## 🎨 Visão e processo de design

O visual do app vem de um **handoff do Claude Design** (claude.ai/design), versionado em
`design/` — atualmente o **bundle v2**:

- `design/project/StabilMoney Dashboard.html` — protótipo completo (app shell v2 + todas as views).
- `design/project/StabilMoney Login.html` / `StabilMoney Cadastro.html` + `auth.css` — telas
  de auth split com vídeo de fundo.
- `design/project/styles.css` — design system v2 (tokens, temas claro/escuro, componentes,
  popover, modais, cards da sidebar, responsivo).
- `design/project/app.js` — interações do protótipo (tema, drawer, gráficos SVG, contadores).
- `design/project/finance.js` — **protótipo das features financeiras** (lançamentos com parcelas,
  faturas por cartão, métodos de pagamento, dependentes, investimentos, metas) — referência
  visual; as features **já estão implementadas** (jun/2026).
- `design/project/assets/` — logo (`stabilmoney-mark.png`), `favicon.png` e vídeo
  (`auth-bg.mp4`); **copiados para `public/assets/`** — o vídeo é servido lá como
  `video_login.mp4` (é de `public/assets/` que o app serve).
- `design/README.md` + `design/chats/chat1.md` — intenção do usuário.

**Processo:** o usuário desenha no Claude Design → exporta o bundle → o Claude Code implementa
**pixel-fiel** no Laravel. O protótipo é uma SPA fake (troca de views via JS); o app real é
**server-routed**: cada item de menu é um link para uma rota Laravel, estado ativo via
`request()->routeIs(...)`. **Nunca** copie a lógica de troca de views do protótipo.
Para novas telas, consulte sempre o HTML/CSS do protótipo como fonte da verdade visual.

**Recebendo um handoff novo:** chega como URL `https://api.anthropic.com/v1/design/h/...` —
é um `.tar.gz`: baixar com curl, extrair, ler README + chats + arquivos do projeto, e
atualizar a pasta `design/` do repo com o bundle novo antes de implementar.

**Telas das features financeiras** (metas, faturas/despesas, investimentos) e **dependentes**
**já estão implementadas** (jun/2026) — não usam mais o `coming-soon.blade.php` (que segue
disponível como padrão "em breve" para telas futuras). Os estilos exclusivos dessas telas
(`.fatura-*`, `.pm-*`, `.alloc-*`, `.meta-*`, `.dep-grid`, modal de lançamento `.modal-lg`,
toast `.sm-toast`) **já foram portados** do `styles.css` v2 para o `design-system.css`/`forms.css`
conforme cada feature entrou. Formulários/elementos sem protótipo seguem os tokens do design
system (`design-system.css` + `forms.css`) — nunca inventar visual do zero.

---

## Stack (implementada)

| Camada | Escolha | Observações |
|--------|---------|-------------|
| Backend | **Laravel 12** (PHP 8.4) | Monolito, resource controllers + Form Requests + Policies + Services. |
| Banco | **MySQL 8.0** (Docker) | Container `db`; porta **3307 no host → 3306 no container** (db `stabilmoney`, user/password no `.env`). A 3307 no host só serve p/ ferramenta externa (DBeaver/TablePlus); o app fala com `db:3306` pela rede interna, então `DB_PORT=3306` no `.env`. |
| Runtime | **Docker** (php:8.4-apache) | Container `app`, site em **http://localhost:8001** (porta do host → 80 no container). Host não precisa de PHP. |
| Frontend | **Blade + design system próprio** | `resources/css/design-system.css` (portado de `design/project/styles.css` v2) + `forms.css` + `auth.css` (telas de auth, escopado sob `.auth`). Tailwind 4 carregado como base utilitária via Vite 7. |
| JS | **Vanilla** em `resources/js/sm/` (padrão atual) | Módulos: `theme.js`, `shell.js`, `charts.js`, `dashboard.js`, `auth.js`, `categories.js`. **Frameworks/bibliotecas JS são liberados** quando a feature se beneficiar (decisão do Victor, jun/2026) — escolher a ferramenta certa caso a caso; "vanilla" deixou de ser obrigatório. |
| Auth | **Laravel Breeze 2.4** (blade) | Login/cadastro no layout split v2 com vídeo (`layouts/auth.blade.php`); demais telas no `layouts/guest.blade.php`. Tudo PT-BR. Hash de senha em **argon2id** (`config/hashing.php`). |
| i18n | **laravel-lang/common** | `lang/pt_BR` completo (validation, auth, passwords). `APP_LOCALE=pt_BR`; `Carbon::setLocale` no `AppServiceProvider`. |
| Fontes | Google Fonts | Sora (títulos/números) + Plus Jakarta Sans (corpo) — link nos layouts. |
| Mobile | **PWA** (Fase 1, pendente) | Web instalável; sem Android Studio por enquanto. |

---

## Estrutura do monolito (mapa de pastas)

```
app/
├── Http/
│   ├── Controllers/        # Dashboard, Transaction, Account, Category, Profile, Settings, Security, Dependent + Auth/ (Breeze)
│   └── Requests/           # Form Requests com mensagens/attributes PT-BR (Store/Update por recurso)
├── Models/                 # User, Account (accessor balance), Category, Transaction
├── Policies/               # Account/Category/TransactionPolicy (update+delete = dono); descoberta automática
├── Services/               # DashboardService (agregação SQL do dashboard) + SidebarService (card patrimônio)
├── Support/                # DefaultCategories (categorias padrão; seedFor() idempotente) + BrowserSessions (sessões ativas via tabela `sessions`, parse de user-agent sem dependência)
├── Listeners/              # SeedDefaultCategoriesForNewUser (evento Registered, auto-descoberto)
└── Providers/              # AppServiceProvider (Carbon::setLocale + View Composer da sidebar)

resources/
├── css/
│   ├── app.css             # Orquestra: @import tailwindcss + design-system + forms + auth (+ @source)
│   ├── design-system.css   # Design system completo portado do protótipo v2 + seção "Extensões"
│   ├── forms.css           # Formulários, pickers, filtros, paginação, flash de erro, chips de categoria
│   └── auth.css            # Telas de auth split com vídeo — TUDO escopado sob .auth (sempre claro)
├── js/sm/                  # theme, shell (popover do perfil), charts, dashboard, auth, categories (drag), security (medidor de força + mostrar/ocultar senha + revelar "encerrar sessões")
└── views/
    ├── layouts/            # app.blade.php (shell), auth.blade.php (login/cadastro com vídeo), guest.blade.php (demais telas de auth)
    ├── partials/           # sidebar (popover, patrimônio, dependentes), topbar, bottom-nav, flash
    ├── dashboard.blade.php
    ├── transactions|accounts|categories/   # index/create/edit + _form por recurso
    ├── auth/               # 6 telas Breeze reescritas (login, register, etc.)
    ├── profile/            # edit + partials (perfil, senha, excluir conta com modal)
    └── coming-soon.blade.php   # placeholder das seções futuras

public/assets/              # stabilmoney-mark.png (logo), favicon.png, video_login.mp4 (login), icons/ (ícones do PWA)
design/                     # Handoff do Claude Design v2 (fonte da verdade visual — NÃO editar)
lang/pt_BR(+.json)          # Traduções PT-BR (laravel-lang)
routes/web.php              # Rotas do app | routes/auth.php (Breeze)
database/
├── migrations/             # users/cache/jobs + accounts/categories/transactions
├── factories/              # User, Account, Category (states income/expense), Transaction
└── seeders/                # DatabaseSeeder (só roda em APP_ENV=local; credenciais via .env)
tests/Feature/              # 87 testes: auth, dashboard, CRUD, validação, isolamento multiusuário
```

---

## Autenticação (multiusuário — FEITO)

- Login obrigatório: **todas** as rotas do app ficam sob `middleware('auth')`.
- **REGRA: NUNCA usar `Auth::id() ?? 1`** (fallback antigo, já removido). Use
  `auth()->id()` / `$request->user()->id` e escope **toda** query pelo dono.
- **Registro (design v2):** **sem campo de confirmação de senha** (decisão do design) e com
  **aceite de Termos de Uso/Política de Privacidade obrigatório** (`terms => required|accepted`,
  validado no servidor com mensagem PT-BR). Os links dos termos ainda são placeholders (`#`) —
  criar as páginas reais antes do deploy público. Os fluxos de **redefinir senha** e **alterar
  senha no perfil** continuam exigindo confirmação.
- Login/cadastro usam o `layouts/auth.blade.php` (split com vídeo, **sempre claro** — tokens
  fixos no escopo `.auth`); as demais telas de auth (esqueci/redefinir/confirmar senha,
  verificar e-mail) seguem no `layouts/guest.blade.php` com suporte a tema (inconsistência
  visual aceita até ganharem design v2).
- Registro dispara o listener `SeedDefaultCategoriesForNewUser` → cria as categorias padrão
  (9 despesas + 5 receitas, cores da paleta) via `App\Support\DefaultCategories::seedFor()`.
- `DatabaseSeeder` roda **só em ambiente `local`** e lê as credenciais do `.env`:
  `SEED_USER_NAME`, `SEED_USER_EMAIL`, `SEED_USER_PASSWORD` (usa `updateOrCreate`, então
  rodar o seed de novo re-sincroniza nome/senha com o `.env`). **Senha real jamais vai
  para o código/git** — fica só no `.env` (gitignorado).
- `User` **não** implementa `MustVerifyEmail` (fluxo de verificação pronto em PT-BR,
  desativado de propósito — não há mailer configurado; `MAIL_MAILER=log` em dev).
- **Hashing de senha: `argon2id`** (`config/hashing.php`, `driver => 'argon2id'`) — vale para
  o app inteiro (registro, troca de senha e seeder). O `Hash::check` detecta o algoritmo pelo
  prefixo do hash, então qualquer hash antigo em bcrypt continua validando. Lembrete conceitual:
  senha de usuário é **hash** (mão única), nunca "criptografia" reversível; só o hash vai pro
  banco, o texto puro existe apenas no `.env` local (gitignorado) para o seeder gerar o hash.

---

## Mapa de rotas / telas

| Rota (name) | View | O que mostra |
|---|---|---|
| `GET /` (`dashboard`) | `dashboard.blade.php` | Stats com sparklines, segmented semana/mês/ano, fluxo de caixa, donut por categoria, transações recentes, "Meu cartão" + contas, cards "Em breve". |
| `/transactions` (resource, sem `show`) | `transactions/*` | Lista com filtros GET (tipo/conta), paginação; form com type-toggle, valor com vírgula, conta, categoria filtrada por tipo. |
| `/accounts` (resource, sem `show`) | `accounts/*` | **"Métodos de Pagamento"** no menu/título (versão inicial — modelo ainda é `accounts`). Cards `.cc` com saldo (accessor `balance`), gradiente pela cor; form com icon/color picker. |
| `/categories` (resource, sem `show`) | `categories/*` | Duas colunas Despesas/Receitas com chips emoji+nome; **drag & drop entre colunas troca o tipo** (PATCH AJAX em `categories.js`, rollback se falhar); botões editar/excluir por chip; form com type-toggle e pickers. |
| `GET/PATCH/DELETE /meu-perfil` (`profile.*`) | `profile/edit` | Dados pessoais: nome, e-mail, telefone, foto (preview antes de salvar). **Acesso pelo popover do perfil** (sidebar). |
| `GET /configuracoes/{tab?}` (`settings`) + `DELETE /configuracoes/sessoes` (`settings.sessions.destroy` → `SecurityController`) | `settings/index` (+ `settings/partials/security`) | Subabas-pílula numa coluna centrada (680px). **Segurança** = visão geral (e-mail + idade da senha via `password_changed_at`), card de senha com **medidor de força**/mostrar-ocultar/requisitos ao vivo, **sessões/dispositivos ativos** (lista via `BrowserSessions`) + **encerrar outras sessões** (confirma senha → `Auth::logoutOtherDevices` + apaga as outras linhas de `sessions`), e **2FA "em breve"**. **Conta** = excluir conta (modal). |
| `/dependentes` (`DependentController`: index/store/update/destroy) | `dependents/index` | **Conta-família (implementado).** Titular cria/edita/remove dependentes (modais); cada card tem **foto** (avatar), botão **editar** e o **saldo para gastar** (limite + restante com barra). No cadastro/edição define-se **foto** e **`spending_limit`** (Store/UpdateDependentRequest; senha opcional na edição). Só titular acessa (403 p/ dependente). Card "Dependentes" da sidebar escondido p/ dependente. |
| `/metas` (`GoalController` index/store/update/destroy + aportes/resgates) | `metas/index` | **Metas (implementado).** Objetivos de poupança modelo "cofrinho": aporte reserva, resgate devolve à conta. Compartilhadas na família (`ownerId`). |
| `/investimentos` (`InvestmentController` index/store/update/destroy + aportes/resgates) | `investimentos/index` | **Investimentos (implementado).** Cofrinho + metadados/projeções (indexador CDI/Selic/IPCA+/Prefixado, % do indexador, prévia de IR/IOF). Compartilhados na família. |
| `/faturas` ("Faturas / Despesas": `FaturaController` index + `faturas.lancar` + `faturas.compra.destroy`) | `faturas/index` | **Faturas/Despesas (implementado).** Faturas por cartão (parcelas/recorrência, ciclo fechamento/vencimento, limite) via `FaturaService` + despesas avulsas em conta. Rotas `relatorios` e `ajuda` foram **removidas** no design v2. |
| `routes/auth.php` | `auth/*` | Breeze: login, registro, esqueci/redefinir senha, confirmar senha, verificar e-mail. |

**Menu da sidebar (v2):** grupo **Menu** = Visão geral → `dashboard`, Transações →
`transactions.index`, Faturas / Despesas → `faturas`, Metas → `metas`, Investimentos →
`investimentos`; grupo **Preferências** = Métodos de Pagamento → `accounts.index`,
Categorias → `categories.index`. Sem Relatórios, sem Ajuda, sem Configurações no menu
(Configurações vive no popover do perfil) e sem card de upsell. A sidebar ainda tem o card
**Patrimônio total** (dados reais via `SidebarService`/View Composer) e o card **Dependentes**
(estado vazio → rota `dependentes`).

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

- **users** — `name`, `email`, `password`, `phone`, `avatar_path` (foto, disco `public`),
  `is_admin` (boolean: titular=true, dependente=false), `account_owner_id` (nullable, auto-ref →
  `users`, **cascadeOnDelete**): **null = titular; preenchido = dependente** apontando para o
  titular. `spending_limit` (decimal nullable): **saldo/limite de gasto do dependente** — as
  despesas que ele lança (`made_by_user_id`) descontam desse valor; o card de dependentes mostra
  o restante (`spending_limit − Σ despesas dele`). Helpers no model: `ownerId()`
  (= `account_owner_id ?? id`), `isTitular()`, `dependents()`, `titular()`, `madeTransactions()`,
  `avatarUrl()`.
- **accounts** — `user_id`, `name`, `type` (`wallet|bank|credit_card|savings|investment|other`),
  `initial_balance`, `color`, `icon`. Saldo = `initial_balance` + receitas − despesas
  (accessor `balance` no model; o dashboard calcula via SQL agregado).
- **categories** — `user_id`, `name`, `type` (`income|expense`), `color`, `icon`.
- **transactions** — `user_id` (dono = **titular** da família), `made_by_user_id` (nullable,
  FK `nullOnDelete` — **quem lançou**, p/ "quem fez a compra"), `account_id` (FK `cascadeOnDelete`),
  `category_id` (nullable, FK `nullOnDelete`), `type` (`income|expense`), `amount` (decimal 15,2
  **sempre positivo**), `description` (nullable), `date`.

> **Dinheiro**: `decimal(15,2)`; o sinal vem do `type`, nunca do valor.
> Excluir **conta** com transações é bloqueado (apagaria histórico); excluir **categoria** é
> permitido (transações viram "Sem categoria").

> **Conta-família (escopo por família):** todo dado (accounts/categories/transactions) tem
> `user_id = id do titular`. As queries e policies escopam por **`$user->ownerId()`** (NÃO por
> `auth()->id()`) — assim titular e dependentes compartilham a mesma visão. Dependente é criado
> pelo titular (tela `/dependentes`, titular-only) **sem** disparar `Registered` (usa as
> categorias da família). Round atual = acesso total na família; permissões granulares por
> módulo são um subprojeto futuro (ver `docs/superpowers/specs/`).

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
- Migrations sempre reversíveis (`down()`); SQL compatível com MySQL **e** sqlite
  (testes rodam em sqlite `:memory:` — cuidado com funções tipo `MONTH()`, ver
  `DashboardService` para o padrão por driver).
- Commits: prefixos `Feat:`, `Fix:`, `style:`.
- **Fluxo git — modelo principal/secundário (jun/2026):** há um **agente principal** (o que
  conversa com o Victor) e **agentes secundários** (subagentes despachados para implementar
  partes em paralelo). **SOMENTE o agente principal commita e dá `push`.** Agentes secundários
  **NUNCA** commitam nem dão push — eles implementam, validam o que conseguem no próprio escopo
  e reportam; o principal **integra, verifica e commita**. Sempre commitar **direto na `main`**
  (sem PR/branch de feature) e **dar `push` para `origin/main`** — preferência do Victor.
  Se você é um subagente lendo isto: não rode `git commit` nem `git push`.

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

# 4. Tabelas + usuário de dev (seeder só roda em APP_ENV=local; credenciais nas SEED_USER_* do .env)
docker compose exec app php artisan migrate --seed

# App: http://localhost:8001
```

### Assets (Vite/Tailwind) — rodam no HOST
```powershell
npm install
npm run dev      # hot reload (Vite em http://localhost:5173)
npm run build    # produção (gera public/build — necessário p/ páginas sem `npm run dev`)
```

### Portas (host) e troubleshooting
- **App:** http://localhost:8001 · **MySQL (host):** 3307 · **Vite:** 5173.
- Portas movidas de **8000→8001** (app) e **3306→3307** (MySQL, só no host) para **não
  conflitar com o projeto `megatruck`** na mesma máquina (ele ocupa 8000/3306/5173). A porta do
  **container** do MySQL segue 3306 — por isso `DB_PORT=3306` no `.env` (rede interna do Docker).
- ⚠️ **Vite/5173 ainda colide com o megatruck:** não rodar os dois `npm run dev` ao mesmo tempo
  (ou mudar a porta do Vite no `vite.config.js` quando precisar dos dois no ar).
- **Erro `SQLSTATE[HY000] [2002] ... getaddrinfo for db failed`** (o app não resolve o host
  `db`): glitch do Docker Desktop/WSL2 em que o container do banco "solta" da rede (aparece sem
  rede em `docker inspect`). **Fix:** `docker compose down; docker compose up -d` (recria os
  containers na mesma rede; o volume `db_data` é preservado, nada se perde).

### Comandos úteis
```powershell
docker compose exec app php artisan test                       # suíte completa (87 testes)
docker compose exec app php artisan migrate:fresh --seed       # recria o banco do zero
docker compose exec app php artisan tinker                     # console interativo
docker compose exec app php artisan view:cache                 # valida sintaxe de TODAS as views
docker compose exec app bash                                   # shell no container
docker compose logs -f app                                     # logs Apache/PHP
docker compose down                                            # derruba containers
```

> Os testes usam `withoutVite()` no `TestCase` base — rodam sem build de assets.

### Testar no celular (mesma rede Wi-Fi)
`ipconfig` para descobrir o IP → `http://SEU_IP:8001` no navegador do celular.
Com a PWA pronta (Fase 1), "Adicionar à tela inicial".
**PWA exige HTTPS** — para testar instalação no celular antes de ter servidor, usar
**Cloudflare Tunnel** (`cloudflared tunnel --url http://localhost:8001` dá URL https grátis).

---

## Roadmap

- **Fase 0 — Núcleo: ✅ CONCLUÍDA.** Modelo de dados, CRUD completo (transações/contas/categorias),
  dashboard com dados reais (períodos, trends, gráficos SVG), layout mobile-first pixel-fiel ao design,
  tema claro/escuro.
- **Design v2 (visual/shell/auth): ✅ CONCLUÍDA (jun/2026).** Shell v2 (sidebar com grupos,
  popover de perfil, card patrimônio real, card dependentes, logo/favicon reais), login/cadastro
  split com vídeo, categorias com drag & drop, accounts rotulado "Métodos de Pagamento".
- **Fase 1 — PWA + Login: ✅ CONCLUÍDA.**
  - ✅ Autenticação multiusuário (Breeze, telas no design system, PT-BR, categorias padrão no registro).
  - ✅ PWA: manifest + service worker (instalável na tela inicial) + **lançamento offline com fila,
    sincronização dirigida pela página e Background Sync** (reenvia até com o app fechado).
- **Features financeiras do design v2: ✅ CONCLUÍDA (jun/2026)** — Metas, Investimentos,
  Faturas/Despesas (cartão com ciclo/limite), seletor "quem fez a compra" e conta-família com
  dependentes. Decisões e referência do protótipo na seção "Features financeiras" abaixo.
- **Fase 2 — Futuro:** empacotar a PWA como app Android (**TWA**) para a Play Store;
  **bot WhatsApp** para consultar/lançar transações por mensagem (ver infra abaixo).

---

## 💰 Próxima rodada (features financeiras — decisões já tomadas com o usuário)

Implementar o que o protótipo `design/project/finance.js` + as views do Dashboard.html v2
demonstram. **Decisões fechadas com o usuário em jun/2026** (não rediscutir do zero):

- **Métodos de pagamento:** cartão de crédito, cartão de débito, contas e Pix — evolução da
  tela atual de `accounts` (que já se apresenta como "Métodos de Pagamento").
- **Lançamentos → faturas:** lançamentos geram faturas **parceladas/recorrentes POR CARTÃO**,
  com **dia de fechamento e dia de vencimento configuráveis por cartão** (decisão do usuário).
- **Dependentes:** sub-usuários vinculados ao titular, **com login próprio**, que **veem TUDO
  da família (mesma visão do titular)** — decisão do usuário; não é visão restrita.
- **Seletor "quem fez a compra"** (titular/dependente) nos lançamentos.
- **Investimentos:** com indexadores (CDI, Selic, IPCA+, Prefixado), percentual do indexador
  e prévia de IR/IOF.
- **Metas:** objetivos com aportes.

**Modelo de dados novo necessário:** `payment_methods`, `invoices`/`installments`,
sub-usuários vinculados ao titular (dependentes), `goals`, `investments`.

**Apoios já deixados prontos nesta rodada:** estilos de drag de categorias/emoji picker no
`design-system.css`; estilos exclusivos das telas futuras (`.fatura-*`, `.pm-*`, `.alloc-*`,
`.meta-*`, `.dep-grid`, `.modal-lg`, `.sm-toast`) ficam no `styles.css` v2 para portar na hora;
primitivos de modal (`.modal-scrim`/`.modal`) já portados — **dentro de modais, usar o
`.field`/`.input` do `forms.css`** (as regras `.field` do modal do protótipo conflitam e não
foram portadas). O card patrimônio mostra "Em conta" = saldo total por enquanto; quando
investimentos existirem, separar as parcelas no `SidebarService`. O rótulo "Saldo disponível"
do card "Meu cartão" do dashboard vira "Limite disponível" quando houver limite de cartão.

---

## 🏗️ Visão de infraestrutura (decidida em jun/2026, ainda não executada)

- **Hoje (dev):** tudo local (Docker + `npm run dev`). Para demo/PWA no celular: Cloudflare Tunnel.
- **Domínio:** registrar `stabilmoney.com.br` no **registro.br** (principal; ~R$40/ano) e,
  se quiser proteger a marca, `stabilmoney.com` no Cloudflare Registrar. Não usar domínio
  "grátis" de plano de hospedagem (prende o domínio ao provedor).
- **Servidor (quando publicar):** **VPS** — provável **HostGator "VPS n8n"** (mesmo preço da
  VPS comum, AlmaLinux, acesso root, já vem com Docker + n8n; infra Oracle Cloud no Brasil).
  Hospedagem compartilhada foi descartada (não roda Docker/n8n/workers).
- **Arquitetura na VPS:** proxy reverso **Caddy** (HTTPS automático) na frente, com subdomínios:
  `stabilmoney.com.br` (landing), `app.` (Laravel), `n8n.` (automações). O mesmo
  `docker-compose` do dev sobe lá.
- **Bot WhatsApp (visão):** gateway (Cloud API oficial para valer; Evolution API para hobby)
  → n8n ou controller Laravel → API do app + **Claude API** para linguagem natural
  ("quanto gastei esse mês?") → resposta no WhatsApp. Tudo na mesma VPS.
- **Play Store (Fase 2):** TWA exige domínio com HTTPS + `/.well-known/assetlinks.json`
  servido pelo site; conta de desenvolvedor Google (US$ 25 única).
