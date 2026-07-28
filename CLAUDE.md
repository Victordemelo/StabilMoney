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

**Última grande entrega (27/07/2026): modelo de dinheiro v3** — cheque especial, separação
real entre saldo e investido, escolha da fonte quando o saldo acaba, e contas fixas mensais.
Antes disso o app **não tinha trava de gasto nenhuma**: uma conta com R$ 100 aceitava despesa
de R$ 99.999,99. Leia a seção **"💰 Modelo de dinheiro"** antes de mexer em saldo, fatura ou
lançamento. Spec completa (com a auditoria que originou tudo) em
`docs/superpowers/specs/2026-07-27-cheque-especial-segregacao-e-contas-fixas.md`.

**Entrega anterior (jun/2026):** **design v2** implementado (handoff novo do Claude
Design) — escopo daquela rodada foi **só visual/shell/auth**, sem features financeiras novas:

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
| Suíte de testes | ✅ **290 testes / 969 asserções** verdes |
| Features financeiras v2 (metas, investimentos, faturas/despesas, cartão c/ ciclo/limite) | ✅ **Implementadas** (jun/2026) |
| **Modelo de dinheiro v3** (cheque especial, saldo × investido, escolha de fonte, contas fixas) | ✅ **Implementado** (27/07/2026) |
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
  validado no servidor com mensagem PT-BR). As páginas de Termos/Privacidade **já existem**
  (rotas públicas `/termos` e `/privacidade` → views em `resources/views/legal/`, cobertas por
  `LegalPagesTest`); o cadastro linka para elas. **Conteúdo v2 (27/07/2026):** documentos
  **completos** e específicos ao app — controlador = **Victor de Melo da Rosa** (pessoa física,
  sem CPF publicado), contato/DPO = **victor.rosa.faculdade@gmail.com**, app **gratuito** com
  cláusula de planos futuros, tom mantido em "fase de testes". Termos cobrem disclaimers-chave
  (não é instituição financeira / não movimenta dinheiro / não é aconselhamento financeiro /
  nunca pedimos senha de banco), conta-família, PWA offline, limitação de responsabilidade e
  foro do consumidor. Privacidade traz tabelas de transparência (dado → finalidade → base legal
  da LGPD; cookies com os **nomes reais**: `stabilmoney_session`, `XSRF-TOKEN`, `sm-theme`,
  `sm-collapsed`, `sm-cookie-consent`, `sm-form-user`, IndexedDB da fila offline), operadores
  (hospedagem + Google Fonts como transferência internacional), retenção (logs 6 meses — Marco
  Civil) e os direitos do art. 18. **Ao mexer no que o app coleta/compartilha, atualizar essas
  tabelas** — elas descrevem o código real, não texto genérico. Estilos `.legal-table`/`.legal-toc`/
  `h3` vivem no `<style>` do `layouts/legal.blade.php`. Ainda **falta revisão jurídica** antes do
  lançamento público amplo. Os fluxos de **redefinir senha** e **alterar senha no perfil**
  continuam exigindo confirmação.
- **`config/legal.php` é a fonte única** de `version` / `updated_at` / `controller` /
  `contact_email` — as views legais exibem esses valores e o cadastro grava `legal.version` em
  `users.terms_version`. **Ao alterar o texto dos documentos, suba a `version`** (senão o registro
  do aceite passa a apontar para um texto que mudou por baixo).
- **Prova do aceite (LGPD art. 8º, §1º):** o `RegisteredUserController` grava
  `terms_accepted_at` + `terms_version` + `terms_accepted_ip` no cadastro — validar o checkbox sem
  gravar não comprova consentimento. Fica **nulo para dependentes** (criados pelo titular, não
  passam pelo `/register`): se um dia dependente precisar aceitar no 1º login, é aqui que entra.
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
| `GET /` (`dashboard`) | `dashboard.blade.php` | Stats com sparklines, segmented semana/mês/ano, fluxo de caixa, donut por categoria, transações recentes, "Meu cartão" (rótulo "Limite disponível" p/ crédito) + contas, e cards **com dados reais** de Metas / Contas a pagar (faturas de cartão em aberto) / Investimentos — resumos via `DashboardService::featureResumos`. |
| `/transactions` (resource, sem `show`) | `transactions/*` | Lista com filtros GET (tipo/conta), paginação; form com type-toggle, valor com vírgula, conta, categoria filtrada por tipo. |
| `/accounts` (resource, sem `show`) | `accounts/*` | **"Métodos de Pagamento"**. 4 tipos (Conta Corrente/Poupança, Cartão de Débito/Crédito) + **banco** com logo (imagem `public/assets/banks/`, preview no form). Form com campos condicionais por tipo (JS): conta = saldo inicial; crédito = limite + fechamento/vencimento; débito = vincula corrente/poupança que ele espelha. **Sem picker de ícone/cor.** O card mostra a imagem do banco; débito mostra corrente/poupança separados + total. |
| `/categories` (resource, sem `show`) | `categories/*` | Duas colunas Despesas/Receitas com chips emoji+nome; **drag & drop entre colunas troca o tipo** (PATCH AJAX em `categories.js`, rollback se falhar); botões editar/excluir por chip; form com type-toggle e pickers. |
| `GET/PATCH/DELETE /meu-perfil` (`profile.*`) | `profile/edit` | Dados pessoais: nome, e-mail, telefone, foto (preview antes de salvar). **Acesso pelo popover do perfil** (sidebar). |
| `GET /configuracoes/{tab?}` (`settings`) + `DELETE /configuracoes/sessoes` (`settings.sessions.destroy` → `SecurityController`) | `settings/index` (+ `settings/partials/security`) | Subabas-pílula numa coluna centrada (680px). **Segurança** = visão geral (e-mail + idade da senha via `password_changed_at`), card de senha com **medidor de força**/mostrar-ocultar/requisitos ao vivo, **sessões/dispositivos ativos** (lista via `BrowserSessions`) + **encerrar outras sessões** (confirma senha → `Auth::logoutOtherDevices` + apaga as outras linhas de `sessions`), e **2FA "em breve"**. **Conta** = excluir conta (modal). |
| `/dependentes` (`DependentController`: index/store/update/destroy) | `dependents/index` | **Conta-família (implementado).** Titular cria/edita/remove dependentes (modais **fora da `.card`** — ela tem `overflow:hidden`+animação `transform`, que prendia o `position:fixed`). Cada card mostra **foto** (avatar), nome/e-mail e **quanto gastou no mês** (`Σ` despesas do mês corrente com `made_by_user_id` da pessoa; titular incluso), com botões **editar** e **excluir**. No cadastro/edição define-se nome, e-mail, **foto** (avatar central clicável — a bolinha É o botão de upload, classe `.avatar-pick`), **parentesco** (select `User::RELATIONSHIPS`) e senha (Store/UpdateDependentRequest; senha opcional na edição). **Lançar em nome de um dependente** é feito no formulário de transação, pelo seletor "quem fez a compra" (suporta deep-link `transactions.create?autor=ID`). Só titular acessa (403 p/ dependente). |
| `/metas` (`GoalController` index/store/update/destroy + aportes/resgates) | `metas/index` | **Metas (implementado).** Objetivos de poupança modelo "cofrinho": aporte reserva, resgate devolve à conta. Compartilhadas na família (`ownerId`). |
| `/investimentos` (`InvestmentController` index/store/update/destroy + aportes/resgates) | `investimentos/index` | **Investimentos (implementado).** Cofrinho + metadados/projeções (indexador CDI/Selic/IPCA+/Prefixado, % do indexador, prévia de IR/IOF). Compartilhados na família. |
| `/faturas` (**"Pagar despesas"**: `FaturaController` index + `faturas.lancar` + `faturas.compra.destroy` + **`faturas.fatura.pagar`** + `faturas.recorrente.pagar`) | `faturas/index` | **Pagar despesas (implementado).** Faturas por cartão (parcelas/recorrência, ciclo, limite) via `FaturaService` + despesas avulsas. **Marcar fatura como paga** (só cartão de crédito): `payInvoice` marca as despesas EM ABERTO do ciclo (`paid_at`) e cria a saída no **caixa escolhido** (corrente/poupança) — é o que **desconta do saldo**; débito/Pix/conta já descontam no ato. `Account::openInvoiceDue` = fatura não paga do ciclo; `FaturaService` expõe `isPaid`/`canPay`/`invoiceDue` por cartão. |
| `routes/auth.php` | `auth/*` | Breeze: login, registro, esqueci/redefinir senha, confirmar senha, verificar e-mail. |

**Menu da sidebar (v2):** grupo **Menu** = Visão geral → `dashboard`, **Histórico** →
`transactions.index`, Pagar despesas → `faturas`, Metas → `metas`, Investimentos →
`investimentos`; grupo **Preferências** = Métodos de Pagamento → `accounts.index`,
Categorias → `categories.index`. Sem Relatórios, sem Ajuda, sem Configurações no menu
(Configurações vive no popover do perfil) e sem card de upsell. A sidebar ainda tem o card
**Patrimônio total** (dados reais via `SidebarService`/View Composer) e o card **Dependentes**
(estado vazio → rota `dependentes`).

**Navegação por AJAX (pjax):** os itens de menu (sidebar + bottom-nav, marcados `data-pjax`)
navegam **sem reload** via `resources/js/sm/nav.js` — troca só o `#content` (shell persiste),
re-executa scripts inline, reinicia os módulos de conteúdo (`initContent` no `app.js`), atualiza
título/histórico/estado-ativo. Fallback para navegação normal em qualquer erro. `window.smPjaxReload()`
recarrega a página atual sem reload (usado após salvar no modal de lançar).

**Notificações (topbar):** o sino mostra as **contas a vencer nos próximos 7 dias** (faturas de
cartão em aberto + recorrências não pagas), via `FaturaService::upcomingDue` num View Composer de
`partials.topbar`; badge com a contagem no sino. **Saldo negativo:** o card "Patrimônio total" da
sidebar fica **vermelho** (`.sb-value.neg`) quando `saldoTotal < 0`.

**Modal "Lançar" (global):** o botão da topbar e o FAB (`data-launch-open`) abrem um modal de
**nova transação** (`partials/launch-modal.blade.php`, dados via View Composer em `AppServiceProvider`
= contas/categorias/família da família), em vez de navegar para `transactions.create` (que segue
de fallback no `href` e como página cheia). Abre/fecha com a animação do `.modal-scrim`; envia por
AJAX (`sm/launch.js` → `transactions.store` com `Accept: json`), spinner no "Salvar", erro treme +
banner, sucesso recarrega via `smPjaxReload`. **Nuance:** o modal usa `fetch` direto (não passa pela
fila offline do `offline-queue.js`); lançar **offline** ainda funciona pela página cheia `transactions.create`.

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
  titular. Helpers no model: `ownerId()` (= `account_owner_id ?? id`), `isTitular()`,
  `dependents()`, `titular()`, `madeTransactions()` (despesas lançadas pela pessoa, base do
  "quanto gastou no mês" no card de dependentes), `avatarUrl()`. `relationship` (string nullable):
  parentesco do dependente — valores em `User::RELATIONSHIPS` (conjuge/filho/pai_mae/irmao/outro);
  `relationshipLabel()` devolve o rótulo PT-BR. `terms_accepted_at` (datetime nullable) +
  `terms_version` (string 20) + `terms_accepted_ip` (string 45, cabe IPv6): **prova do aceite** dos
  documentos legais, gravada só no `/register` (nulo em dependentes) — ver seção de Autenticação.
  **Hook `deleting` (em `User::booted`)** limpa o que o cascade do banco não alcança ao excluir a
  conta: o **arquivo da foto** no disco `public` (`purgeStoredAvatar()`) e as linhas de `sessions`
  (`BrowserSessions::purgeForUser()` — IP/user-agent; a tabela não tem FK com cascade). Os
  **dependentes são apagados um a um pelo Eloquent** de propósito: o `cascadeOnDelete` de
  `account_owner_id` roda no banco e **não dispara eventos**, então as fotos deles ficariam órfãs
  (e servidas pelo symlink de `storage/`). Coberto por `AccountDeletionPurgeTest`. **Regra geral:
  ao excluir algo que tenha arquivo em disco, o cascade da FK não basta.**
- **accounts** — `user_id`, `name`, `type` (`Account::TYPES`: `checking`=Conta Corrente,
  `savings`=Conta Poupança, `debit_card`=Cartão de Débito, `credit_card`=Cartão de Crédito),
  `bank` (`Account::BANKS`: banco_do_brasil/bradesco/caixa/inter/itau/mercado_pago/nubank/santander
  — imagem em `public/assets/banks/{bank}.png`), `initial_balance` (**nullable**: só corrente/poupança
  têm; cartões = null), **`overdraft_limit`** (decimal 15,2 **NOT NULL default 0** — cheque
  especial; só faz sentido em `checking`, zerado nos outros tipos pelo `prepareForValidation`),
  campos de cartão de crédito (`credit_limit`/`closing_day`/`due_day`),
  `checking_account_id`/`savings_account_id` (FKs `nullOnDelete` — o **cartão de débito** espelha
  estas contas), `color`/`icon` (legado, sem picker no form). Saldo: corrente/poupança =
  `initial_balance` + receitas − despesas; **débito = saldo da corrente + poupança vinculadas**
  (mostradas separadas + total; `checkingBalance`/`savingsBalance`); crédito não é caixa. Débito e
  crédito ficam **fora do patrimônio** (Sidebar/DashboardService) p/ não duplicar. Helpers:
  `isCard()`/`isDebit()`/**`isCash()`**, `typeLabel()`, `bankLabel()`, `bankImageUrl()`,
  `linkedChecking()`/`linkedSavings()`, **`paymentOptions()`** (estático — ver "Modelo de dinheiro").
- **categories** — `user_id`, `name`, `type` (`income|expense`), `color`, `icon`.
- **transactions** — `user_id` (dono = **titular** da família), `made_by_user_id` (nullable,
  FK `nullOnDelete` — **quem lançou**, p/ "quem fez a compra"), `account_id` (FK `cascadeOnDelete`),
  `category_id` (nullable, FK `nullOnDelete`), `type` (`income|expense`), `amount` (decimal 15,2
  **sempre positivo**), `description` (nullable), `date`, `paid_at`, campos de parcela
  (`group_id`/`installment_no`/`installments`/`recurring`).
  **Auditoria da fonte:** `funding_source` (string 24 nullable — `cheque_especial` |
  `resgate_investimento` | null; valores em `App\Support\FundingSource`, **não** enum de banco,
  que diverge entre MySQL e sqlite) e `funding_amount` (quanto veio da fonte; pode ser < `amount`).
  **Conta fixa:** `fixed_bill_id` (nullable, **sem FK** — `dropForeign` em sqlite exige recriar a
  tabela) + `competence` (date, sempre dia 01), com **`unique(fixed_bill_id, competence)`** como
  trava de idempotência. Nos dois drivers o índice único ignora linhas com NULL, então as
  transações comuns não colidem.
  Coluna temporária `legacy_account_id` (nullable): guarda de onde veio o movimento que a migration
  `2026_07_28_000000` moveu de cartão de débito para a conta vinculada — existe só para o `down()`
  ser real; uma migration de faxina pode removê-la.
- **fixed_bills** (contas fixas mensais) — `user_id` (titular), `made_by_user_id`, `name`,
  `amount` (valor **esperado**; o real vai na transação do pagamento, porque conta de luz varia),
  `due_day` (**1..31** — o clamp de mês curto é feito em PHP, ao contrário do 1..28 dos cartões),
  `account_id`/`category_id` (nullable, padrão de pagamento), `starts_on`, `ends_on` (nullable =
  sem fim), `active`. **As competências mensais NÃO são materializadas** — ver "Modelo de dinheiro".
- **goal_contributions / investment_contributions** — ganharam `transaction_id` (nullable, **sem
  FK**, indexado): liga o resgate à despesa que ele cobriu quando o usuário escolheu "tirar do
  investimento". Null nos aportes/resgates feitos direto na tela.

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

## 💰 Modelo de dinheiro (v3 — 27/07/2026) — LEIA ANTES DE MEXER EM SALDO

Spec completa: `docs/superpowers/specs/2026-07-27-cheque-especial-segregacao-e-contas-fixas.md`

### Os quatro bolsos

```
┌──────────────────────────────────────────────────────────────────┐
│  SALDO BRUTO   Account::balance                                  │
│  = initial_balance + receitas − despesas                         │
│                                                                  │
│  ├── RESERVADO   Account::reserved                               │
│  │   = Σ aportes − Σ resgates (metas + investimentos) DESTA conta│
│  │   → dinheiro carimbado: está na conta, mas não é para gastar  │
│  │                                                               │
│  └── DISPONÍVEL  Account::available = balance − reserved         │
│      → É ISTO que a UI chama de "Saldo em conta".                │
│        É este número que fica VERMELHO e pode ir a negativo.     │
└──────────────────────────────────────────────────────────────────┘
                              +
   CHEQUE ESPECIAL  accounts.overdraft_limit  (só `checking`)

   Account::spendable = max(0, disponível) + cheque especial livre
   PISO do disponível = − overdraft_limit
```

**Regra de ouro da UI:** onde antes se mostrava `balance`, hoje se mostra **`available`**.
O `balance` é detalhe interno. Se você for exibir saldo numa tela nova, use `available`.

### Quem decide se um gasto pode acontecer

Nunca escreva uma despesa direto com `Transaction::create()`. Todo caminho de gasto passa por:

| Classe | Papel |
|---|---|
| `App\Services\SpendingGuard` | **Calcula** os bolsos e devolve o veredito: `ok` / `precisa_fonte` / `estoura_limite`. Também monta o payload de opções e as mensagens PT-BR. |
| `App\Services\FundingService::spend()` | **Grava**, dentro de UMA `DB::transaction` com `lockForUpdate`. É a palavra final (o Form Request é time-of-check; aqui é time-of-use). |
| `App\Exceptions\RequiresFundingChoice` | Vira **HTTP 409** (não 422) com as opções, ou redirect com `session('fonteNecessaria')` sem JS. |

**Ordem de lock: conta → pai (Goal/Investment), SEMPRE.** `HandlesContributions` usa a mesma
ordem; inverter em um dos caminhos causa deadlock.

### Gasto novo × obrigação vencida — regras DIFERENTES

| Situação | Sem fonte que cubra |
|---|---|
| **Gasto novo** (compra, despesa avulsa) | **Recusa** (422). Mensagem sugere lançar um recebimento. |
| **Obrigação vencida** (fatura de cartão, conta fixa) | **Passa e a conta fica negativa.** A dívida já existe no mundo real; não se recusa um boleto. O flash avisa o novo saldo. |

Quem paga obrigação chama `spend(..., obrigacao: true)`.

**O app NUNCA usa o cheque especial sozinho.** Uma conta que vence não é paga automaticamente:
fica **marcada como vencida** e espera. No pagamento, havendo cheque especial e/ou investimento,
o servidor responde **409** e o usuário escolhe. Enquanto não escolher, nada é pago.

### Escolha da fonte (fluxo do 409)

1. Front envia a despesa normalmente.
2. Servidor responde **409** com `{precisa_fonte: true, fonte: {...}}`.
3. `resources/js/sm/funding.js` → `pedirFonte(payload)` abre o modal
   (`partials/funding-modal.blade.php`, no shell) e resolve com a escolha.
4. Front **reenvia o mesmo payload** + `funding_source` (+ `funding_investment_id`), com o
   **mesmo `client_uuid`** — por isso não duplica.
5. `resgate_investimento` resgata só o **FALTANTE** (`amount − max(0, disponível)`), não o total,
   e a despesa + o resgate nascem na mesma transação de banco.

Consumidores do 409: `sm/launch.js` (modal global), `sm/offline-queue.js` (form cheio) e, sem
JS, o Blade via `session('fonteNecessaria')`. **Fila offline e service worker**: no 409
reenviam **uma vez** com `cheque_especial` (a compra já aconteceu no mundo real); resgate de
investimento nunca é automático. Se ainda falhar, marcam `failed` com a mensagem real do servidor.

### Cartão de crédito

- `committed` = **tudo que não foi pago** (`paid_at` null), sem filtro de data. O limite volta
  **ao pagar**, não com a passagem do tempo. (Antes era por data: quem nunca pagava recebia
  limite de volta quando o ciclo virava, e quem pagava não recebia nada.)
- `availableLimit` devolve o valor **real** (pode ser negativo, se estourado);
  `availableLimitDisplay` é o clampado em 0 — use este na view.
- `dueDateForCycle($cycleEnd)`: vencimento **derivado do ciclo**. Se `due_day > closing_day`,
  cai no mês do fechamento; senão, no seguinte.
- `closedCycle()` / `closedInvoiceDue` / `overdueInvoice`: a fatura do ciclo **já fechado** e não
  paga. Sem isso ela sumia da tela no dia em que o ciclo virava.

### Contas fixas — as competências NÃO são materializadas

`FixedBillService::occurrences()` **projeta** os meses de `starts_on` até hoje. Só existe linha
em `transactions` quando a conta é **paga** (com `fixed_bill_id` + `competence`).

**Por que isso importa:** uma transação em aberto já reduz o saldo hoje (`Account::balance` soma
tudo, sem olhar `paid_at` nem data). Materializar 12 meses derrubaria o saldo em 12 aluguéis de
uma vez e sabotaria o limite de gasto, que depende de um saldo confiável.
**Corolário: não é preciso agendador** — a competência do mês existe sempre porque é calculada.
Um comando agendado só entraria depois, para NOTIFICAR, nunca para criar dado.

### Cartão de débito NÃO é conta de lançamento

Ele não tem saldo próprio (espelha corrente/poupança). Os Form Requests recusam `debit_card` em
`account_id`, e os selects usam **`Account::paymentOptions($ownerId)`**: o cartão aparece com o
rótulo dele, mas o `id` submetido é o da conta que ele espelha.

⚠️ `paymentOptions()` devolve **`Fluent`**, não `Account`. Nas views use `$conta->isCard`
(propriedade), **nunca** `$conta->isCard()` — o `__call` do Fluent devolveria `$this` (truthy) e
marcaria toda conta como cartão.

### Formatação

`App\Support\Brl::format()` e a directive **`@brl($valor)`**: negativo sai como **`−R$ 1.234,56`**
(sinal antes do símbolo, traço U+2212), não `R$ -1.234,56`. Entrada (`sm/money.js`) não muda:
continua descartando o menos, porque valor digitado nunca é negativo.

### Comportamentos conhecidos e aceitos (não são bugs novos)

- Despesa com **data futura** e recorrência **não paga** já entram no saldo (`Account::balance`
  não olha `date` nem `paid_at`). Mudar isso era a proposta "R-SALDO", **descartada** quando as
  contas fixas passaram a ser calculadas — ver §8.2 e decisão D-4 da spec.
- Falta implementar (§14 da spec): estorno de pagamento de fatura, guard de parcela isolada no
  Histórico, bloqueio de excluir conta/investimento com saldo negativo.

---

## 🔒 Segurança (pentest de 27/07/2026 — onda 1 aplicada)

Auditoria completa em jul/2026 (SQL injection, IDOR, auth/sessão, XSS/PWA). **Limpo em
SQL injection e isolamento entre famílias** — o padrão "escopo por `ownerId()` + policy no
binding + `Rule::exists` escopado em todo FK" está consistente; não há SQL cru com input do
usuário (a única interpolação, `DashboardService` `$monthExpr`, é ternário entre constantes).

Corrigido nesta rodada — **não regredir**:

- **XSS armazenado (era CRÍTICO):** `charts.js` montava a legenda do donut com
  `innerHTML` interpolando o **nome da categoria**. Como categorias são compartilhadas na
  família, um dependente executava script no dashboard do titular. Agora a legenda é
  `createElement` + `textContent`. **REGRA: nunca interpolar dado do usuário em `innerHTML`** —
  no JS do projeto, `textContent` sempre (nome de categoria/meta/conta/dependente, descrição
  de transação).
- **JSON dentro de `<script>`:** `DashboardService` usa `JSON_HEX_TAG|HEX_AMP|HEX_APOS|HEX_QUOT`
  (as mesmas flags do `@json`). Sem elas, categoria chamada `<!--<script>` engolia a página.
  `</script>` já era coberto pelo escape de `/` do `json_encode`.
- **Throttle:** limiters nomeados em `AppServiceProvider::configurarLimitesDeTaxa()` —
  `senha` (6/min por usuário) em **todo endpoint que valida senha** (confirmar senha, trocar
  senha, excluir conta, encerrar sessões); `credencial` (5/min por IP) em register/forgot/reset;
  `login-ip` (20/min por IP) somado ao throttle por e-mail+IP do `LoginRequest` (aquele protege
  uma conta, este barra *password spraying*). **Ao criar rota que pede senha, aplique
  `throttle:senha`** — sem limite é oráculo de força bruta e amplificação de DoS (cada tentativa
  custa um argon2id de 64 MiB).
- **Enumeração de usuário:** `PasswordResetLinkController` responde igual para e-mail
  inexistente (`INVALID_USER` → mensagem de sucesso).
- **Trocar senha derruba as outras sessões** (`PasswordController` → `logoutOtherDevices` +
  `BrowserSessions::purgeForUser`). `AuthenticateSession` NÃO está habilitado, então é a purga
  das linhas de `sessions` que efetivamente desconecta.
- **MySQL:** porta publicada em `127.0.0.1:3307` (antes `3307:3306` = bind em 0.0.0.0, banco
  exposto a toda a rede Wi-Fi) e credenciais via `.env` com defaults. Trocar a senha exige
  recriar o volume ou `ALTER USER` — o MySQL só cria o usuário no 1º boot.

Coberto por `tests/Feature/SecurityHardeningTest.php`. **argon2id está adequado** (64 MiB, t=4 —
acima do mínimo OWASP); não hashear IP com argon2id (hash é irreversível e quebraria a tela de
dispositivos e a prova do aceite — para IP em repouso o certo é cast `encrypted`).

**Onda 2** (`tests/Feature/SecurityHardeningWave2Test.php`):

- **Política de senha:** `Password::defaults()` agora é configurado em
  `AppServiceProvider::configurarPoliticaDeSenha()` → `min(8)->uncompromised()` (antes valia o
  default do framework: só `min(8)`, aceitava "12345678"). Segue o **NIST SP 800-63B**:
  comprimento + checagem de vazamento, **sem** regra de composição — exigir maiúscula/símbolo
  empurra para "Senha@123", que passa em tudo e está em qualquer lista de ataque.
  `uncompromised()` usa k-anonimato (envia 5 caracteres do SHA-1, nunca a senha) e é
  **desligado em teste** (`runningUnitTests`) p/ a suíte não depender de rede.
- **Headers de segurança:** `App\Http\Middleware\SecurityHeaders` (append no grupo `web`) —
  CSP, `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`, e HSTS
  **só sobre HTTPS**. A CSP usa `'unsafe-inline'` em `script-src` de propósito (scripts inline
  + `nav.js` recriando `<script>` no pjax); o que ela entrega é `connect-src`/`img-src` na
  própria origem e `frame-ancestors`/`object-src`/`base-uri`/`form-action` travados. **Libera
  `localhost:5173` só em ambiente local** — senão `npm run dev` quebra.
- **Trocar e-mail exige a senha atual** (`ProfileUpdateRequest` → `current_password` requerido
  **só quando o e-mail muda**). O e-mail é o que recupera a conta: sem isso, sessão sequestrada
  → troca e-mail → "esqueci a senha" → conta tomada. Nome/telefone/foto seguem sem atrito.
- **Metadados das fotos:** `App\Support\ImageMetadata::strip()` remove segmentos APPn/COM do
  JPEG e chunks de texto do PNG **no nível dos bytes** (sem re-encode, então não perde
  qualidade). Foi preciso assim porque **o GD do container está compilado SEM suporte a JPEG**
  (`imagejpeg` não existe) — não tente `imagejpeg()` aqui. Consolidado em
  `User::storeAvatar()`, que substituiu a duplicação em perfil + criar/editar dependente.

**Onda 3:**

- **Logout manda `Clear-Site-Data: "cache"`** (`AuthenticatedSessionController::destroy`): o SW
  cacheia `/transactions/create` (HTML autenticado com contas/categorias/família) e o cache
  sobrevivia ao logout. **Só `"cache"`, NUNCA `"storage"`** — `storage` apagaria o IndexedDB da
  fila offline e destruiria lançamentos não sincronizados. Há teste garantindo a ausência de
  `storage` no header.
- **`config/filesystems.php`:** `'serve' => false` no disco `local` (o default `true` registra
  `GET|PUT /storage/{path}` fora de auth; não é explorável, mas é superfície morta).
- **`docs/checklist-de-publicacao.md`** — 16 itens de deploy priorizados, com o "por quê" e o
  valor de config de cada um. **Consulte antes de publicar.**

**Pendências (não são código — infra ou decisão):** avatares ainda no disco `public` sem auth
(o EXIF já sai; falta rota autenticada + migrar arquivos); IndexedDB não é limpo na troca de
usuário no cliente; **verificação de e-mail bloqueada por `MAIL_MAILER=log`** — ativar
`MustVerifyEmail` sem mailer trancaria todos fora do app, e é o mesmo bloqueio que faz a
**recuperação de senha não funcionar hoje**; `is_admin`/`account_owner_id` em `$fillable` (sem
sink hoje); revisão jurídica dos documentos legais. Detalhes e passo a passo no checklist.

---

## Convenções

- **Validação em Form Requests** com `messages()`/`attributes()` PT-BR. Valores aceitam vírgula
  pt-BR ("1.234,56") normalizada em `prepareForValidation` (trait `NormalizesMoneyInput`).
- **Dinheiro nunca é negativo.** O sinal vem do `type`, então todo campo de valor valida `min:0`
  (ou `min:0.01` para os obrigatórios > 0); `initial_balance` é `min:0`. No cliente, `sm/money.js`
  formata todo `input[inputmode="decimal"]` para BRL ("1.234,56") **ao sair do campo (blur)** e
  **descarta o sinal de menos** — entrada negativa vira positiva. Ao adicionar um novo campo de
  valor, use `inputmode="decimal"` para herdar esse comportamento. Campos que NÃO são moeda
  (ex.: taxa em %) marcam `data-no-money` para o `money.js` ignorá-los.
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
- **🔒 REGRA DE OURO — cada agente cuida SÓ dos seus arquivos (jul/2026).** Quando houver mais
  de uma sessão/agente trabalhando no repo ao mesmo tempo, **ninguém edita arquivo que outro
  agente está mexendo**. Motivo: em 27/07/2026 duas sessões implementaram a MESMA feature
  ("saldo disponível") em paralelo, uma deixou o `DashboardService` com `use` duplicado (app
  inteiro em erro fatal) e o `AppServiceProvider` ficou com as duas frentes misturadas, sem
  como separar por arquivo na hora de commitar.
  - **Antes de começar:** rode `git status` e veja o que já está modificado. Arquivo que
    aparece como `M` e você não mexeu é de outra pessoa — **não toque**.
  - **Se precisar de um arquivo que já está sendo mexido:** pare e avise o Victor, em vez de
    editar por cima. Ele decide quem segue.
  - **Se encontrar o app quebrado por edição alheia:** conserte só o necessário para destravar
    (ex.: um `use` duplicado), avise no relatório, e **não continue** implementando por cima.
  - **Antes de commitar:** confira que o `git status` só tem os SEUS arquivos. Nunca faça
    `git add .` com trabalho de outro agente pendente — isso engole a feature dele numa
    mensagem de commit que não é a dele.

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

# 5. Symlink p/ servir uploads (fotos de perfil/dependentes via disco public).
#    Recriar após migrate:fresh / ambiente novo — sem ele as fotos não aparecem.
docker compose exec app php artisan storage:link

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
foram portadas). **Feito:** o card patrimônio já separa "Disponível / Guardado em metas /
Investido" (`SidebarService`); e o rótulo "Saldo disponível" do card "Meu cartão" do dashboard
já vira "Limite disponível" quando a primeira conta é cartão de crédito.

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
