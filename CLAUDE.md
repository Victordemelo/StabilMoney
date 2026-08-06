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
| Suíte de testes | ✅ **755 testes / 2.965 asserções** verdes |
| Features financeiras v2 (metas, investimentos, faturas/despesas, cartão c/ ciclo/limite) | ✅ **Implementadas** (jun/2026) |
| **Modelo de dinheiro v3** (cheque especial, saldo × investido, escolha de fonte, contas fixas) | ✅ **Implementado** (27/07/2026) |
| **2FA (verificação em duas etapas por app autenticador)** | ✅ **Implementado** (05/08/2026) — **opcional**, ver seção própria |
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
| JS | **Vanilla** em `resources/js/sm/` (padrão atual) | Módulos em `resources/js/sm/` (ver mapa de pastas). **Frameworks/bibliotecas JS são liberados** quando a feature se beneficiar (decisão do Victor, jun/2026) — escolher a ferramenta certa caso a caso; "vanilla" deixou de ser obrigatório. |
| Auth | **Laravel Breeze 2.4** (blade) | **Todas** as telas de auth no layout split v2 com vídeo (`layouts/auth.blade.php` — o `layouts/guest.blade.php` foi removido em 06/08/2026). Tudo PT-BR. Hash de senha em **argon2id** (`config/hashing.php`). **2FA opcional** por app autenticador (TOTP) — seção própria abaixo. |
| QR do 2FA | **bacon/bacon-qr-code 3.x** | Única dependência de produção fora do Laravel. Só desenha o QR (SVG puro, sem imagick/GD); o algoritmo TOTP é nosso (`App\Support\Totp`). |
| i18n | **laravel-lang/common** | `lang/pt_BR` completo (validation, auth, passwords). `APP_LOCALE=pt_BR`; `Carbon::setLocale` no `AppServiceProvider`. |
| Fontes | Google Fonts | Sora (títulos/números) + Plus Jakarta Sans (corpo) — link nos layouts. |
| Mobile | **PWA** (Fase 1, pendente) | Web instalável; sem Android Studio por enquanto. |

---

## Estrutura do monolito (mapa de pastas)

```
app/
├── Exceptions/             # RequiresFundingChoice (vira HTTP 409 com as opções de fonte)
├── Http/
│   ├── Controllers/        # Dashboard, Transaction, Account, Category, Fatura, FixedBill, Goal, Investment, Profile, Settings, Security, Dependent,
│   │                       # TwoFactor (2FA nas Configurações) + Auth/ (Breeze + TwoFactorChallengeController = 2ª etapa do login)
│   └── Requests/           # Form Requests com mensagens/attributes PT-BR (Store/Update por recurso) + PayInvoiceRequest, PayFixedBillRequest
├── Models/                 # User (bolsos de auth: two_factor_*), Account (bolsos: balance/reserved/available/spendable), Category, Transaction, Goal, Investment, FixedBill
├── Policies/               # Account/Category/Transaction/FixedBillPolicy (update+delete = família); descoberta automática
├── Services/               # DashboardService, SidebarService, FaturaService,
│                           # SpendingGuard (calcula os bolsos e decide), FundingService (grava sob lock),
│                           # FixedBillService (projeta as competências das contas fixas),
│                           # TwoFactorService (liga/confirma/desliga o 2FA, valida sob lock e gera o QR)
├── Support/                # DefaultCategories, BrowserSessions, Brl (formato R$ pt-BR), FundingSource (constantes),
│                           # Totp (RFC 6238, sem biblioteca), RecoveryCodes (códigos de emergência), Mailer, ImageMetadata
├── Listeners/              # SeedDefaultCategoriesForNewUser (evento Registered, auto-descoberto)
└── Providers/              # AppServiceProvider (Carbon::setLocale, directive @brl, View Composers, rate limits)

resources/
├── css/
│   ├── app.css             # Orquestra: @import tailwindcss + design-system + forms + auth (+ @source)
│   ├── design-system.css   # Design system completo portado do protótipo v2 + seção "Extensões"
│   ├── forms.css           # Formulários, pickers, filtros, paginação, flash de erro, chips de categoria
│   └── auth.css            # Telas de auth split com vídeo — TUDO escopado sob .auth (sempre claro)
├── js/sm/                  # theme, shell (popover do perfil), charts, dashboard, auth, categories (drag),
│                           # security, launch (modal global), funding (modal "de onde sai o dinheiro" — consome o 409),
│                           # money (máscara BRL), nav (pjax), offline-queue, pwa
└── views/
    ├── layouts/            # app.blade.php (shell), auth.blade.php (login/cadastro com vídeo), guest.blade.php (demais telas de auth)
    ├── partials/           # sidebar (patrimônio + cheque especial), topbar (sino, também no mobile),
    │                       # bottom-nav, flash, launch-modal, funding-modal
    ├── dashboard.blade.php
    ├── transactions|accounts|categories/   # index/create/edit + _form por recurso
    ├── auth/               # telas Breeze reescritas (login, register, etc.) + two-factor-challenge (2ª etapa do login)
    ├── settings/           # index + partials/security e partials/two-factor (card de 2FA, três estados)
    ├── profile/            # edit + partials (perfil, senha, excluir conta com modal)
    └── coming-soon.blade.php   # placeholder das seções futuras

public/assets/              # stabilmoney-mark.png (logo), favicon.png, video_login.mp4 (login), icons/ (ícones do PWA)
design/                     # Handoff do Claude Design v2 (fonte da verdade visual — NÃO editar)
lang/pt_BR(+.json)          # Traduções PT-BR (laravel-lang)
routes/web.php              # Rotas do app | routes/auth.php (Breeze)
database/
├── migrations/             # users/cache/jobs + accounts/categories/transactions + goals/investments
│                           # + 2026_07_28_*: cheque especial, funding_source, fixed_bills
│                           # + 2026_08_05_000000: two_factor_* (2FA — colunas `text`, ver a seção do 2FA)
├── factories/              # User, Account (states creditCard/overdraft/debitCard), Category, Transaction
└── seeders/                # DatabaseSeeder (só roda em APP_ENV=local; credenciais via .env)
tests/Unit/                 # TotpTest — o algoritmo do 2FA contra os vetores oficiais da RFC 6238
tests/Feature/              # 755 testes: auth, dashboard, CRUD, validação, isolamento multiusuário,
                            # ModeloDeDinheiroTest (cheque especial/fonte/limite), FixedBillTest e DoisFatoresTest
```

---

## Autenticação (multiusuário — FEITO)

- Login obrigatório: **todas** as rotas do app ficam sob `middleware('auth')`.
- **REGRA: NUNCA usar `Auth::id() ?? 1`** (fallback antigo, já removido). Use
  `auth()->id()` / `$request->user()->id` e escope **toda** query pelo dono.
- **Verificação em duas etapas (2FA): OPCIONAL, desligada por padrão.** Cada pessoa liga a
  sua em Configurações › Segurança — inclusive dependentes, porque o 2FA protege o LOGIN
  (individual), ao contrário do dinheiro (da família). ⚠️ Quem ligou **não entra só com a
  senha**: `LoginRequest::authenticate()` confere a credencial sem abrir sessão e o
  controller manda para o desafio. Ver a seção "🔐 Verificação em duas etapas".
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
- **Verificação de e-mail LIGADA** (06/08/2026): `User` implementa `MustVerifyEmail` e as
  rotas do app estão sob `['auth', 'verified']`. Ver "📧 E-mail" abaixo — inclusive por que
  isso **não tranca ninguém** quando o app não consegue enviar e-mail.
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
| `/accounts` (resource, sem `show`) | `accounts/*` | **"Métodos de Pagamento"**. 5 tipos (Conta Corrente/Poupança, Cartão de Débito/Crédito, **Pix**) + **banco** com logo (imagem `public/assets/banks/`, preview no form). Form com campos condicionais por tipo (JS): conta = saldo inicial; **corrente = + limite do cheque especial**; crédito = limite + fechamento/vencimento; débito = vincula corrente/poupança que ele espelha. **Sem picker de ícone/cor.** O card mostra a imagem do banco e o **"Saldo em conta" = `available`** (vermelho quando negativo), com barra de uso do cheque especial; débito mostra corrente/poupança separados + total. |
| `/categories` (resource, sem `show`) | `categories/*` | Duas colunas Despesas/Receitas com chips emoji+nome; **drag & drop entre colunas troca o tipo** (PATCH AJAX em `categories.js`, rollback se falhar); botões editar/excluir por chip; form com type-toggle e pickers. |
| `GET/PATCH/DELETE /meu-perfil` (`profile.*`) | `profile/edit` | Dados pessoais em **dois cards lado a lado** (mesmo grid das Configurações, largura cheia): "Quem é você" (foto com preview, nome, **data de nascimento**, **sexo**) e "Como falamos com você" (e-mail, telefone). Nascimento e sexo são **opcionais** — minimização de dados; `User::GENEROS` traz "Prefiro não informar". O campo de **senha atual** só aparece quando o e-mail muda (mesma regra do `ProfileUpdateRequest`). **Acesso pelo popover do perfil** (sidebar). |
| `GET /configuracoes/{tab?}` (`settings`) + `DELETE /configuracoes/sessoes` (`settings.sessions.destroy` → `SecurityController`) | `settings/index` (+ `settings/partials/security|two-factor|conta`) | **Três subabas-pílula** (Segurança · **2FA** · Conta) numa coluna de 1120px, com o corpo em grid de 12 colunas — cada aba tem DOIS cards lado a lado (`span6`/`span7`+`span5`), de altura igual, e cabe sem rolar. **Segurança** = visão geral (e-mail + idade da senha via `password_changed_at`), card de senha com **medidor de força**/mostrar-ocultar/requisitos ao vivo, **sessões/dispositivos ativos** (lista via `BrowserSessions`) + **encerrar outras sessões** (confirma senha → `Auth::logoutOtherDevices` + apaga as outras linhas de `sessions`), (lista com **teto de 4 itens** e rolagem interna — sem isso o card esticava além do de Senha e a página voltava a rolar). **2FA** = card de ação + card "Como funciona" ao lado. **O SWITCH é o controle**: a linha inteira é um `<summary>` (`.tfa-toggle`) que abre a confirmação por senha DENTRO do card — ligar e desligar seguem exigindo senha, sem botão-gatilho separado empurrando o conteúdo. No rodapé, **"Autenticadores da família"** lista quem já protegeu o próprio login (nome, papel, desde quando) — só status, nunca segredo: `two_factor_secret` é `encrypted` e não chega à view. **Conta** = resumo real da conta (e-mail, desde quando, dependentes, estado do 2FA) + zona de perigo. |
| `POST/DELETE /configuracoes/2fa` (`settings.2fa.ativar` / `.desativar`), `POST /configuracoes/2fa/confirmar` (`.confirmar`), `POST /configuracoes/2fa/codigos` (`.codigos`) → `TwoFactorController` | bloco em `settings/partials/two-factor` | **2FA (opcional).** Ligar/desligar/trocar códigos exigem a **senha atual** (`throttle:senha`); confirmar o setup exige o **código** (`throttle:dois-fatores`). Sem rota de listagem — tudo acontece no card da aba Segurança. |
| `GET/POST /verificacao-em-duas-etapas` (`two-factor.login`) + `POST /verificacao-em-duas-etapas/cancelar` (`two-factor.cancel`) → `Auth\TwoFactorChallengeController` | `auth/two-factor-challenge` | **Segunda etapa do login.** Grupo `guest`: quem está aqui ainda NÃO tem sessão. Aceita o código do autenticador ou um **código de recuperação** (`?recuperacao=1`, sem depender de JS). |
| `/dependentes` (`DependentController`: index/store/update/destroy) | `dependents/index` | **Conta-família (implementado).** Titular cria/edita/remove dependentes (modais **fora da `.card`** — ela tem `overflow:hidden`+animação `transform`, que prendia o `position:fixed`). Cada card mostra **foto** (avatar), nome/e-mail e **quanto gastou no mês** (`Σ` despesas do mês corrente com `made_by_user_id` da pessoa; titular incluso), com botões **editar** e **excluir**. No cadastro/edição define-se nome, e-mail, **foto** (avatar central clicável — a bolinha É o botão de upload, classe `.avatar-pick`), **parentesco** (select `User::RELATIONSHIPS`) e senha (Store/UpdateDependentRequest; senha opcional na edição). **Lançar em nome de um dependente** é feito no formulário de transação, pelo seletor "quem fez a compra" (suporta deep-link `transactions.create?autor=ID`). Só titular acessa (403 p/ dependente). |
| `/metas` (`GoalController` index/store/update/destroy + aportes/resgates) | `metas/index` | **Metas (implementado).** Objetivos de poupança modelo "cofrinho": aporte reserva, resgate devolve à conta. Compartilhadas na família (`ownerId`). |
| `/investimentos` (`InvestmentController` index/store/update/destroy + aportes/resgates) | `investimentos/index` | **Investimentos (implementado).** Cofrinho + metadados/projeções (indexador CDI/Selic/IPCA+/Prefixado, % do indexador, prévia de IR/IOF). Compartilhados na família. |
| `/faturas` (**"Pagar despesas"**: `FaturaController` index + `faturas.lancar` + `faturas.compra.destroy` + **`faturas.fatura.pagar`** + **`faturas.fatura.estornar`** + `faturas.recorrente.pagar`) | `faturas/index` | **Pagar despesas (implementado).** Três blocos: **contas fixas do mês** (topo — competências projetadas, badge de vencida, botão Pagar e "+ Nova conta fixa"), faturas por cartão (parcelas/recorrência, ciclo, limite) e despesas avulsas. **Marcar fatura como paga** usa `PayInvoiceRequest` (com **data do pagamento** informável) e passa pelo `FundingService` — respeita saldo e pergunta a fonte. `Account::openInvoiceDue` = fatura do ciclo aberto; **`closedInvoiceDue`/`overdueInvoice`** = a do ciclo fechado e vencida. A recorrência de cartão agora tem **botão "Pagar"** (a rota existia sem UI, então nunca avançava de mês). |
| `/contas-fixas` (`FixedBillController` store/update/destroy + **`contas-fixas.pagar/{competencia}`**) | bloco em `faturas/index` | **Contas fixas mensais (implementado).** Condomínio, aluguel, parcela do carro. **Sem rota de listagem** — aparecem em `/faturas`. `due_day` aceita **1..31**. Pagar recebe o valor REAL (editável, vem preenchido com o previsto) e a data; idempotente pelo `unique(fixed_bill_id, competence)`. |
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

**Notificações (topbar):** o sino mostra **vencidas primeiro**, depois o que vence nos próximos
7 dias, de três fontes: faturas de cartão (do ciclo aberto **e do fechado não pago**), **contas
fixas** e recorrências legadas — `FaturaService::upcomingDue` num View Composer de
`partials.topbar`. Badge fica **vermelho** (`.notif-badge.late`) quando há atraso. **O sino também
existe no mobile** (a `.topbar` some em ≤920px; sem ele o celular não recebia aviso nenhum num app
PWA-first). **Saldo negativo:** a sidebar mostra "Disponível para gastar" em vermelho e uma linha
de cheque especial usado.

**⚠️ `animation-fill-mode` é `backwards`, NUNCA `both`, em `.card` e `.view`.** Com `both` a
propriedade continua "animada" depois de terminar, e um `transform: none` animado computa como
**matriz identidade** — sem efeito visual, mas o elemento vira o **bloco de contenção** de qualquer
`position: fixed` descendente. Era isso que espremia o modal de excluir conta para ~100px
(min-content) e o recortava. Todo modal continua nascendo **fora da `.card`**, e usa os primitivos
`.modal-scrim`/`.modal` — não utilitários Tailwind arbitrários: `w-full max-w-[440px]` num grid
`place-items-center` faz a largura percentual ficar cíclica e o painel colapsar para min-content.

**Modal "Lançar" — FECHA ao salvar.** O recarregamento pós-sucesso é por pjax, que troca só o
`#content`; o modal vive no shell e **sobrevive**. Sem chamar `close()` explicitamente ele ficava
aberto por cima do resultado, escondendo o lançamento que acabou de entrar.

**`[data-launch-open]` usa DELEGAÇÃO no document.** O `initLaunch` roda uma vez (o modal vive no
shell), mas há gatilhos DENTRO do `#content` — o "Nova transação" do Histórico — e o pjax troca o
`#content` inteiro. Com bind elemento a elemento o botão funcionava no primeiro carregamento e
virava link comum depois de qualquer navegação.

**Modal "Lançar" (global):** o botão da topbar, o FAB e o "Nova transação" do Histórico
(`data-launch-open`) abrem um modal de
**nova transação** (`partials/launch-modal.blade.php`, dados via View Composer em `AppServiceProvider`
= contas/categorias/família da família), em vez de navegar para `transactions.create` (que segue
de fallback no `href` e como página cheia). Abre/fecha com a animação do `.modal-scrim`; envia por
AJAX (`sm/launch.js` → `transactions.store` com `Accept: json`), spinner no "Salvar", erro treme +
banner, sucesso recarrega via `smPjaxReload`. Gera **`client_uuid`** por abertura (idempotência —
antes o caminho mais usado do app não tinha) e trata **409** abrindo o modal de escolha de fonte.
**Trata 419 também** (06/08/2026): token vencido busca um fresco (`refreshCsrfToken`, exportado do
`offline-queue.js`), reescreve o `_token` do payload e refaz UMA vez; se a sessão morreu de vez, o
lançamento vai para a FILA em vez de sumir. Antes o 419 caía no texto de validação ("confira os
campos", numa tela sem campo errado) e o lançamento não era gravado nem enfileirado. Não é defeito
só de PWA: trocar a senha derruba as outras sessões e toda aba aberta fica com token morto.
**Offline (02/08/2026):** o modal AGORA passa pela fila (`enfileirarLancamento`) — sem rede ele nem
tenta o POST, enfileira, e o toast diz "na fila", nunca "salvo". Antes usava `fetch` direto, então o
caminho mais usado do app perdia o lançamento feito sem internet.

**Modal "De onde sai esse dinheiro?" (global):** `partials/funding-modal.blade.php` + `sm/funding.js`,
no shell. Consome o **409** e devolve a escolha para quem chamou — serve o modal de lançar, o
formulário cheio e a tela de faturas sem duplicar regra. Ver "💰 Modelo de dinheiro".

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
      // ⚠️ "saldo" é o DISPONÍVEL (saldo cru − metas − investimentos), não o bruto.
      "stats":  { "saldo": 0.0, "receitas": 0.0, "despesas": 0.0, "economia": 0.0 },
      "trends": { "saldo": -4.2, ... }  // % vs período anterior; null = sem base ("—" neutro)
    }
  },
  "sparks": { "saldo": [...], "receitas": [...], "despesas": [...], "economia": [...] }, // últimos 7 dias; [] sem dados
  "cats": [ { "name": "Alimentação", "value": 0.0, "color": "#0F6B47" } ], // despesas do mês, top 5 + "Outros"
  "hasData": true                    // usuário tem transações?
}
```

> ⚠️ **Contrato é ADITIVO e a ordem dos stats é FIXA.** `dashboard.js` casa card ↔ valor **por
> ÍNDICE** (`STAT_ORDER[i]`), então acrescentar um 5º stat card quebra o casamento — antes disso é
> preciso migrar para `data-stat="chave"`. Trate como sub-passo explícito, nunca como efeito colateral.

Convenções do front: valores do **mês** são server-rendered (acessível sem JS); o JS anima
contadores e troca período sem reload. Trend de **despesas** invertida (cair = verde/`up`).
Saldo total = atual de todas as contas, independe do período.

---

## Modelo de dados

- **users** — `name`, `email`, `password`, `phone`, `avatar_path` (foto, disco `public`),
  `birth_date` (date nullable, cast `date:Y-m-d` — o cast padrão gravaria "Y-m-d H:i:s" e o
  `input[type=date]` abriria vazio) e `gender` (string 20 nullable, valores em `User::GENEROS`;
  string e não enum, que diverge entre MySQL e sqlite): os dois OPCIONAIS,
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
  `savings`=Conta Poupança, `debit_card`=Cartão de Débito, `credit_card`=Cartão de Crédito,
  **`pix`**=Pix),
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
  A coluna temporária `legacy_account_id` foi **REMOVIDA** em 05/08/2026 (migration
  `2026_08_05_000100_drop_legacy_account_id`), das três tabelas em que existia. Estava vazia em
  todas, então a reversibilidade que comprava já era zero; o `down()` de `2026_07_28_000000` vira
  no-op limpo (ele já se protegia com `hasColumn`). A cobertura do bug C-5 continua em
  `MigracoesReversiveisTest`, que agora RECRIA as colunas para montar o cenário histórico.
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
4. Front **reenvia o mesmo payload** + `funding_source` (+ `funding_investment_id`
   + **`funding_max_amount`**), com o **mesmo `client_uuid`** — por isso não duplica.
5. `resgate_investimento` resgata só o **FALTANTE**, não o total, e a despesa + o resgate nascem
   na mesma transação de banco.

**🚨 `funding_max_amount` = teto do que o usuário aprovou** (06/08/2026). O valor do resgate
**não viaja no payload**: o `faltante` é recalculado no servidor, sob lock, na hora de gravar.
Entre aprovar e gravar o disponível pode despencar — e um lançamento que dormiu na fila offline
resgatava MUITO mais do que o número que o modal prometeu ("Vamos resgatar R$ 100,00" → saíam
R$ 700). Agora `spend()` recebe `maxFonte` e, se o faltante estourar o teto, devolve **409 com as
opções recalculadas** em vez de sacar mais. Null = sem teto (não veio do modal). Quem preenche:
`funding.js` (`faltanteAprovado`, capturado na abertura do modal) e o `funding-modal.blade.php`
por hidden, no caminho sem JS. Coberto por `TetoDoResgateAprovadoTest`.

**Duas contas diferentes, de propósito** (02/08/2026 — não unifique):

| Método | Fórmula | Para quê |
|---|---|---|
| `SpendingGuard::faltante()` | `amount − disponível` (**sem clamp**) | o que o **resgate** traz para a conta terminar em zero — inclui o vermelho que ela já tinha. Com o clamp antigo, resgatar numa conta em −100 deixava ela em −100, enquanto o modal prometia que o saldo não ficaria negativo. |
| `SpendingGuard::chequeNecessario()` | `amount − max(0, disponível)` (**com clamp**) | o cheque especial **adicional** que a despesa consome, e o que vai para `funding_amount`. Aqui o clamp é obrigatório: o vermelho atual já saiu do `overdraftAvailable`, e contá-lo de novo recusaria despesa que cabe. |

**`cobre` da opção de resgate olha o MAIOR investimento, nunca a soma** — `comResgate()` aceita
**um** `funding_investment_id`. Medindo pela soma, a opção dizia "cobre", o usuário escolhia e o
servidor recusava: beco sem saída. Os itens que não cobrem sozinhos vêm desabilitados no modal,
com o `motivo` PT-BR apontando a saída real (resgatar de mais de um em Investimentos e lançar
depois). Consequência aceita: quando só a soma cobriria e não há cheque especial, o veredito é
`ESTOURA_LIMITE` (recusa explicada) em vez de um 409 sem saída.

Consumidores do 409: `sm/launch.js` (modal global), `sm/offline-queue.js` (form cheio) e, sem
JS, o Blade via `session('fonteNecessaria')`.

**🚨 A fila offline NUNCA escolhe a fonte** (06/08/2026 — mudou; não regrida). Antes, o replay da
página e o service worker reenviavam sozinhos com `cheque_especial`, no argumento de que "a compra
já aconteceu no mundo real". O argumento vale para **registrar** a despesa, não para escolher a
fonte: cheque especial cobra juros de verdade e o sucesso do drain é **silencioso** — o usuário só
descobria pelo extrato. Isso furava o invariante "o app NUNCA usa o cheque especial sozinho".

Hoje, no 409 os dois caminhos marcam o item **`needsFunding`**, guardam nele o payload `fonte` que
veio do servidor, e **param** (o laço pula item retido nos dois lados). A página então mostra o
banner de revisão (`renderRevisao` → `resolverFonte` → `pedirFonte`), o usuário escolhe, e só aí
o lançamento é gravado. Se a escolha chegar tarde demais e o servidor responder 409 de novo, as
opções são **atualizadas** e ele pergunta outra vez — nunca grava por conta própria.
O mesmo banner é a tela dos itens **`failed`** (422), que antes contavam no selo, eram pulados
pelo drain e não tinham onde ser vistos: agora dão "Tentar de novo" e "Descartar" (com confirmação).
**Nada é apagado sozinho.** Coberto por `FilaNaoEscolheFonteTest`.

### Cartão de crédito

- `committed` = **tudo que não foi pago** (`paid_at` null), sem filtro de data. O limite volta
  **ao pagar**, não com a passagem do tempo. (Antes era por data: quem nunca pagava recebia
  limite de volta quando o ciclo virava, e quem pagava não recebia nada.)
- `availableLimit` devolve o valor **real** (pode ser negativo, se estourado);
  `availableLimitDisplay` é o clampado em 0 — use este na view.
- `dueDateForCycle($cycleEnd)`: vencimento **derivado do ciclo**. Se `due_day > closing_day`,
  cai no mês do fechamento; senão, no seguinte.
- `closedCycle()` / `closedInvoiceDue` / `closedInvoice` / `overdueInvoice`: **tudo que já fechou
  e continua em aberto** — não um único mês. A janela é `(início dos tempos, início do ciclo
  aberto]`, porque no cartão real o saldo não pago **rola** para a fatura seguinte. Olhando um
  ciclo só, a dívida de dois meses atrás não estava em `openInvoiceDue` nem em `closedInvoiceDue`:
  sumia da tela e do sino e comia o limite para sempre. O **vencimento exibido** é o da fatura
  fechada mais antiga ainda não paga (`vencimentoMaisAntigoEmAberto()`) — derivar do fim da janela
  faria uma dívida de junho aparecer "em dia" em setembro.

### Contas fixas — as competências NÃO são materializadas

`FixedBillService::occurrences()` **projeta** os meses de `starts_on` até hoje. Só existe linha
em `transactions` quando a conta é **paga** (com `fixed_bill_id` + `competence`).

**Por que isso importa:** uma transação em aberto já reduz o saldo hoje (`Account::balance` soma
tudo, sem olhar `paid_at` nem data). Materializar 12 meses derrubaria o saldo em 12 aluguéis de
uma vez e sabotaria o limite de gasto, que depende de um saldo confiável.
**Corolário: não é preciso agendador** — a competência do mês existe sempre porque é calculada.
Um comando agendado só entraria depois, para NOTIFICAR, nunca para criar dado.

### Pix (05/08/2026) — `PixComoMetodoTest`

**Pix é método ESPELHO, na mesma classe do cartão de débito.** Do ponto de vista de quem PAGA —
que é o que este app modela — os dois são idênticos: o dinheiro sai da conta na hora, não há
fatura nem limite, e o cheque especial daquela conta entra sozinho se o saldo acabar. As
diferenças que a literatura cita (liquidação instantânea × D+1, rede de cartão × transferência do
BC, tarifa) são do **lojista que recebe**, não de quem controla o próprio dinheiro.

- **Uma chave Pix vive em UMA conta** (ao contrário do débito, que saca da corrente E da poupança).
  O formulário tem **um** select (`pix_account_id`) e o `prepareForValidation` o devolve para
  `checking_account_id` ou `savings_account_id` conforme o TIPO da conta escolhida — assim o Pix
  reaproveita o par de colunas do débito, sem migration, e `paymentOptions()` (que já resolvia com
  `checking_account_id ?? savings_account_id`) funcionou sem mudança. `withValidator` recusa as duas.
- **`Account::espelhaConta()`** (= débito **ou** Pix) é a pergunta que o cálculo de dinheiro faz.
  Todo lugar que somava saldo, montava o select ou excluía do patrimônio usava `isDebit()`;
  manter isso com o Pix contaria **a mesma conta duas vezes** no patrimônio. Use `espelhaConta()`,
  não `isDebit()`, sempre que a questão for "tem saldo próprio?".
- `classeDoTipo('pix') === 'debito'`: converter débito ↔ Pix é livre (nenhum dos dois tem saldo
  próprio, não há dinheiro para sumir), enquanto virar caixa/crédito continua travado.
- **Requests que aceitam conta de lançamento usam lista de NEGAÇÃO** (`whereNotIn ['debit_card','pix']`)
  — ao criar um método espelho novo, os 4 precisam ser atualizados. Já `PayInvoiceRequest` e o
  `cashAccounts` do `FaturaService` usam lista de PERMISSÃO (`whereIn ['checking','savings']`) e
  ficaram corretos sem tocar. Prefira a lista de permissão em código novo.
- ⚠️ **`@php($x = $obj->metodo())` quebrou o Blade** em `accounts/index`: compilou como
  `<?php($x = ...)` sem fechar a tag, e o `@if` seguinte virou erro de sintaxe **em tempo de
  execução** — `view:cache` passou, porque ele só compila, não executa. Use a forma em bloco
  `@php ... @endphp`.

### Cartão de débito e Pix NÃO são contas de lançamento

Nenhum dos dois tem saldo próprio (espelham corrente/poupança). Os Form Requests recusam
`debit_card` e `pix` em `account_id`, e os selects usam **`Account::paymentOptions($ownerId)`**:
o método aparece com o rótulo dele, mas o `id` submetido é o da conta que ele espelha.

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
- A §14 da spec foi **zerada em 02/08/2026**: estorno de fatura (01/08), guard de parcela isolada
  no Histórico e bloqueio de excluir investimento/meta com a conta negativa (02/08, abaixo).

### Estorno e idempotência (01/08/2026) — `EstornoEIdempotenciaTest`

Quatro buracos do mesmo tema: **escrever dinheiro era fácil, desescrever não existia.**

- **`FundingService::estornarFonte(iterable $ids)`** desfaz o `resgate` que financiou uma
  despesa apagada (ligado por `investment_contributions.transaction_id`). Antes, apagar a
  despesa devolvia o saldo mas deixava o resgate de pé: o **aplicado do investimento encolhia
  para sempre**, sem contrapartida. **Chame-o em todo caminho que apaga transação** —
  explicitamente, nunca por hook de model: `FaturaController::destroy` apaga as parcelas com
  `->delete()` no builder, e **delete em massa não dispara evento Eloquent**. Precisa rodar na
  mesma `DB::transaction` do delete.
- **`transactions.settled_by_id`** (migration `2026_08_01_000400`) liga cada COMPRA à quitação
  que a pagou — sem FK de propósito (cascade apagaria o histórico de compras). `payInvoice`
  agora cria a quitação **antes** de marcar as compras, para ter o id.
- **`faturas.fatura.estornar`** (`DELETE /faturas/quitacao/{transaction}`) desfaz o pagamento:
  compras voltam a `paid_at = null` (voltam para a fatura e voltam a consumir limite), o resgate
  volta atrás e a saída de caixa é apagada. Botão "Estornar pagamento" no card do cartão
  (`FaturaService` expõe `settlement` = a **última** quitação daquele cartão). Quitações antigas,
  sem `settled_by_id`, caem no fallback `(settles_account_id, paid_at)` — o lote inteiro
  compartilha o mesmo instante de pagamento.
- **`client_uuid` em `faturas.lancar`** (era o único caminho de escrita de despesa sem dedupe):
  duplo clique num parcelado em 12x criava **24 linhas**. O uuid identifica a COMPRA e vive **só
  na primeira parcela** — o índice é único em `(user_id, client_uuid)`. `UniqueConstraintViolationException`
  é capturada e respondida como duplicata (corrida entre dois POSTs simultâneos).
- **`gerarProximaOcorrencia` decide sob lock**: a checagem "já existe" mora **dentro** do `write`
  do `FundingService` (que roda com a linha da conta travada) + fast-path fora, para um clique
  repetido em cartão sem folga sair em silêncio em vez de erro de limite.
- **`FundingService::spend` devolve `?Transaction`**: o `write` pode devolver `null` quando, já
  sob lock, descobre que não há o que gravar. Antes esse caminho de corrida era TypeError.

### Rodada de pré-lançamento (02/08/2026) — não regredir

Testes: `VerificacaoDeEmailTest`, `LimpezaDeSessoesTest`, `FilaOfflineNaTrocaDeUsuarioTest`,
`DesignV2SecundariasTest`, `SinalDeDinheiroNaTelaTest`, `AporteResgateSemDataFuturaTest`.

- **`MustVerifyEmail` está LIGADO, e é seguro porque ninguém nasce trancado.** Sem mailer
  (`Mailer::entrega()` falso) o cadastro grava `email_verified_at` — **antes** do
  `event(new Registered)`, senão o listener do framework dispara um link inútil. **Dependente
  nasce verificado SEMPRE** (não passa pelo `/register`, ninguém lhe envia link). Uma migration
  fez o backfill de quem já existia. ⚠️ **Desatualizado desde 06/08/2026:** o middleware
  `verified` passou a valer nas rotas do app — ver "📧 E-mail".
- **`terms_accepted_ip` tem cast `encrypted`** e a coluna virou `text`: o cifrado tem 200–256
  caracteres e ela era `varchar(45)`. Em MySQL isso é erro 1406 no `/register` — e como a suíte
  roda em **sqlite, que não aplica o limite**, ficaria verde escondendo o defeito. ⚠️ Rotacionar
  `APP_KEY` sem `APP_PREVIOUS_KEYS` torna a prova do aceite ilegível.
- **`SESSION_ENCRYPT=true`** (o default de `config/session.php` já era `true`; quem desligava era
  o `.env`). Ligar/desligar invalida as sessões existentes.
- **`php artisan sessoes:limpar`**, agendado 03:10. `session:prune` **não existe no Laravel 12** —
  a limpeza nativa é o `gc()` por loteria (2% das requisições), e a tabela guarda IP/user-agent.
  ⚠️ O agendador só roda com uma entrada de cron chamando `schedule:run`.
- **Fila offline: item de outro dono é SEGURADO, nunca apagado.** Existia uma
  `purgeQueueFromOtherUsers()` que, ao alguém logar, apagava em silêncio os lançamentos offline
  dos outros — resolvia o vazamento destruindo dinheiro que nunca chegou ao servidor. Agora o
  lançamento fica, o **CSRF é apagado** (é isso que impede o service worker de reenviar: ele
  filtra por `i.csrf`), e um banner avisa, com descarte protegido por confirmação. **Não use bump
  de versão do IndexedDB nem registro-marcador** — os dois quebram `tests/e2e/offline-lancamento.spec.js`.
- **Aporte/resgate não aceitam data futura.** `Account::reserved` soma tudo sem olhar data (igual
  ao `balance` — decisão D-4), então aporte futuro derrubava o disponível de HOJE e resgate futuro
  o levantava. Barrar a entrada é a saída coerente: tornar só o `reserved` sensível à data o faria
  discordar do `balance`.
  **São CINCO portas, não quatro** (06/08/2026): além dos 4 Form Requests de aporte/resgate, o
  **`StoreInvestmentRequest`** grava o aporte inicial na mesma tabela e ficou aceitando +10 anos por
  uma rodada inteira. Ao criar caminho novo que escreva em `goal_contributions`/
  `investment_contributions`, a regra `before_or_equal:now()` vai junto. Os **5 inputs de data**
  (`inv-c-date`, `inv-aporte-date`, `inv-resgate-date`, `meta-aporte-date`, `meta-resgate-date`)
  levam `max` — tela que oferece o que o servidor recusa é o mesmo defeito das contas fixas.
  Coberto por `AporteInicialSemDataFuturaTest`.
- **Sinal do dinheiro na tela:** `−R$ 150,00`, traço U+2212 **antes** do símbolo. Nos 4 stat cards
  e no card Patrimônio o valor é montado à mão (o design separa "R$" e centavos em `<span>`), então
  o sinal vive num `<span class="sign">` próprio e o `.num` anima o **valor absoluto** — senão o
  formato "pulava" no primeiro clique do segmented, que é quando o `dashboard.js` reescreve o número.
- **Telas secundárias de auth** (esqueci/redefinir/confirmar senha, verificar e-mail) usam
  `layouts/auth.blade.php`; **`layouts/guest.blade.php` foi REMOVIDO** em 05/08/2026 (sem uso).
  O `layouts/auth` compõe o `<title>` a partir de `@section('title')`, como o `layouts/app` —
  antes era fixo e as cinco telas ficavam idênticas na aba do navegador.
- **Bottom-nav tem teto de 4 destinos + FAB**: com 5 rótulos a barra passa de ~388px e quebra num
  aparelho de 360px. Hoje: Início · Extrato · [FAB] · Pagar · Metas.
- **CSP com nonce: FEITO em 05/08/2026** — ver a seção própria abaixo.
- **`same_site=strict` foi DESCARTADO**: o cookie não viaja em navegação vinda de fora, e é
  exatamente isso que um link de confirmação de e-mail é. `lax` já barra requisição de estado cross-site.

### CSP com nonce (05/08/2026) — `CspComNonceTest`

`script-src` deixou de aceitar `'unsafe-inline'`. Só executa script que carregue o nonce sorteado
por requisição (`Vite::useCspNonce()`, 40 caracteres). Um XSS armazenado — o achado do pentest de
jul/2026 — vira texto inerte. **Verificado no navegador:** script sem nonce, com nonce chutado e
injetado por `innerHTML` foram os três bloqueados.

- **🚨 Todo `<script>` inline novo precisa de `nonce="{{ Vite::cspNonce() }}"`.** São 12 hoje, e o
  `partials/cookie-consent` está em 100% das páginas. Sem o carimbo a tela morre **em silêncio**
  (o navegador bloqueia e só o console avisa) — por isso o `CspComNonceTest` varre o HTML servido
  de 17 telas e falha se algum ficar sem. As tags do `@vite` o Laravel carimba sozinho.
- **O pjax valida no documento INERTE, antes do `innerHTML`.** Aqui está a armadilha que custou
  uma rodada: assim que o script entra num documento VIVO o navegador **esvazia o atributo**
  `nonce` (guarda o valor num slot interno, exposto só por `.nonce`), para um XSS não raspá-lo do
  DOM. Medido no Chrome:

  | | `getAttribute('nonce')` | `.nonce` |
  |---|---|---|
  | documento inerte (`DOMParser`) | `"ABC123"` | `"ABC123"` |
  | documento vivo | `""` | `"ABC123"` |

  Validar depois do `innerHTML` nunca casa, e a guarda apaga justamente os scripts legítimos —
  a tela renderiza e não faz nada. Por isso `nav.js::descartarScriptsSemNonce()` roda em cima do
  `DOMParser`, e só depois o `runScripts()` recria os sobreviventes.
- **O nonce da resposta viaja no header `X-Csp-Nonce`**, não no corpo: header não é forjável por
  conteúdo armazenado. É com ele que o `nav.js` distingue script legítimo de injetado. Sem essa
  checagem o pjax carimbaria o nonce válido em QUALQUER script vindo do corpo — entregando de
  graça o que a CSP existe para negar.
- No elemento recriado, o nonce vai por **propriedade** (`s.nonce = ...`); `setAttribute` não
  alimenta o slot interno.
- **🚨 O Vite dev precisa de host IPv4 fixo** (`server.host: '127.0.0.1'` no `vite.config.js`).
  Sem isso ele pode subir em IPv6 (`http://[::1]:5173`) — e a gramática de `host-source` da CSP
  **não aceita literal IPv6**: o navegador marca a fonte como inválida, ignora, e bloqueia a folha
  de estilo e o websocket do HMR. O app abre **sem CSS nenhum** com `npm run dev`, e o único aviso
  é no console. A liberação do dev é LIDA de `public/hot` (com `localhost`/`127.0.0.1` de rede de
  segurança) e descarta endereço IPv6 em vez de emitir fonte inválida.
- **`style-src` mantém `'unsafe-inline'` de propósito:** são 71 atributos `style="..."` e **nonce
  não existe para atributo de estilo**, só para `<style>`/`<link>`. Style inline não executa código.

### Auditoria de integridade (02/08/2026) — os 7 críticos, não regredir

Testes: `GuardsDeEdicaoNoHistoricoTest`, `ExclusaoComDividaTest`, `FaturaAtrasadaTest`,
`ContasFixasEndsOnTest`, `EscolhaDeFonteCobreOBuracoTest`, `EstornoNoCartaoNoDashboardTest`,
`ValidacaoDeValoresTest`.

- **🚨 `transactions.update` tem guardas, e elas ficam no TOPO do método** — antes do ramo
  `type !== 'expense'`, que grava com `$transaction->update()` cru, fora do `FundingService`.
  Guarda que more dentro do ramo de despesa é contornável virando a linha em receita.
  Recusam: quitação de fatura, parcela isolada, compra de cartão já paga, pagamento de conta fixa
  mudando de tipo, e **mover linha já paga para um cartão** (`committed` ignora `paid_at`, então
  o cartão nunca cobraria e o dinheiro voltaria para a conta).
- **Editar despesa financiada por resgate RECONCILIA**, não recusa: `estornarFonte()` + `spend()`
  recalcula do zero, com a **conta travada antes do estorno** (ordem conta → pai). Zera também a
  auditoria de cheque especial — senão uma despesa de R$ 100 ficava marcada "cheque especial R$ 300".
- **Excluir meta/investimento com a conta no vermelho é bloqueado.** Não porque crie dinheiro
  (excluir e resgatar por inteiro têm efeito IDÊNTICO no disponível), mas porque quita o cheque
  especial **sem registro** de que a poupança cobriu. Excluir o **perfil** NÃO é bloqueado: seria
  brigar com o direito de eliminação da LGPD que a própria Política promete — em vez disso, as
  pendências são listadas e há aceite explícito.
- **Conta fixa: `ends_on` e a janela de 12 meses valem no PAGAMENTO**, não só na projeção. A regra
  é copiada de `FixedBillService::occurrences` (fronteira pelo MÊS, inclusive) — divergir faz a
  tela oferecer "Pagar" num botão que o servidor recusa.
- **Estorno lançado em cartão ABATE a despesa do período**, não vira receita, nas quatro fontes do
  dashboard (dailySums semana/mês, query do ano, totals, sparks). Piso 0.
- **Todo campo de dinheiro usa `NormalizesMoneyInput::regrasDeDinheiro()`** (`decimal:0,2` +
  `TETO_MONETARIO`). `numeric` sozinho aceita **`1e12`**. E o teto antigo (`9999999999999.99`) era
  pior que inútil: com `precision=14` do PHP ele vira `"10000000000000"` no bind e o MySQL responde
  **erro 500**, não erro de validação. ⚠️ Ponto + 3 dígitos é separador de MILHAR: `800.123` é
  oitocentos mil; a terceira casa decimal se escreve `800,123`.
- **Reduzir `overdraft_limit` abaixo do que já está EM USO é recusado** — deixaria a conta abaixo
  do piso que o modelo promete (`−overdraft_limit`).

### Regras que a auditoria de 28/07 fixou (01/08/2026) — não regredir

Relatório: `docs/auditoria-completa-2026-07-28.md`. Testes: `AuditoriaCorrecoesTest`,
`AuditoriaContasTest`, `AuditoriaMetasInvestimentosTest`, `ContasFixasCorrecoesTest`,
`CorrecoesFaturaDashboardTest`, `EscolhaDeFonteNaTelaTest`, `MigracoesReversiveisTest`.

- **Resgate só devolve o que a conta aportou** (`Goal/Investment::reservedFromAccount`),
  validado no Form Request E no recheque sob lock. Sem isso, resgatar para outra conta criava
  `reserved` negativo = dinheiro do nada.
- **Ordem de lock: conta → pai, SEMPRE**, nos dois caminhos, com `attempts: 3`. (Antes o trait
  fazia o contrário do `FundingService` — deadlock ABBA.)
- **`settles_account_id`**: marca a saída de caixa que QUITA fatura. Ela conta no **saldo** e no
  extrato, mas **não** nas somas de despesa (senão pagar o cartão dobra o gasto do período).
  Corolário: `dailySums(..., incluirQuitacoes: true)` para séries de SALDO.
- **Soma de fatura é COM SINAL**: estorno lançado no cartão abate. `committed`,
  `currentInvoice` e as duas faturas usam `SUM(CASE WHEN type='expense' …)` com piso 0.
- **Conta não muda de CLASSE** (caixa ↔ cartão) com histórico — trocar o tipo zerava
  `initial_balance` e sumia com o patrimônio. Corrente ↔ poupança segue livre.
- **`Account::preloadMoney()`** antes de iterar contas (3 contas: 33 → 19 queries; 30: 195 → 19).
  Nunca ler `$conta->balance`/`reserved` dentro de laço.
- **Toda despesa passa pelo `FundingService`** — inclusive a próxima ocorrência de recorrência,
  que era o único `Transaction::create` cru fora da trava.
- **Fatura de ciclo fechado é pagável** (`ciclo=fechado` no `payInvoice`); antes ficava
  impagável, comendo o limite para sempre.
- **O 409 de escolha de fonte tem consumidor** em `/faturas` e contas fixas: AJAX
  (`funding.js::enviarComFonte`) + fallback server-rendered no `partials/funding-modal`
  (que precisa do `_method` quando o verbo original não é POST).
- **`whereDate()` está proibido** em coluna que já é `DATE` — anula os índices
  `(account_id, date)` e `(account_id, paid_at, date)`. Use `where()` com `->toDateString()`.
- **Contas fixas:** `decimal:0,2` nos valores (é o que barra `1e12`), teto de 3× o previsto,
  `obrigacao: true` só quando a competência JÁ venceu, e competência descartada quando o
  vencimento é anterior ao `starts_on`.
- **IR/IOF:** tabela regressiva por prazo (12 meses = 17,5%, não 15%); IOF não é modelado e o
  rótulo não promete que seja. A projeção é rotulada como estimativa na tela.
- **`DB_TIMEZONE`** (default `+00:00`) fixa o fuso da conexão: sem isso, publicar na VPS
  deslocaria em 3h todo `paid_at`/`created_at` já gravado.

---

## 🔒 Segurança (pentest de 27/07/2026 — ondas 1, 2 e 3 aplicadas)

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
  **só sobre HTTPS**. A CSP entrega `connect-src`/`img-src` na própria origem,
  `frame-ancestors`/`object-src`/`base-uri`/`form-action` travados e **`script-src` com nonce**
  (ver abaixo). **Libera `localhost:5173` só em ambiente local** — senão `npm run dev` quebra.
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
- **`docs/checklist-de-publicacao.md`** — 17 itens de deploy priorizados, com o "por quê" e o
  valor de config de cada um. **Consulte antes de publicar.**

**Pendências (não são código — infra ou decisão):** **senha do SMTP** (host/porta/usuário já
no `.env`, ver "📧 E-mail"; o resto do e-mail está pronto e testado);
revisão jurídica dos documentos legais. Detalhes no checklist.

---

## 📧 E-mail (SMTP — 06/08/2026)

Testes: `VerificacaoDeEmailTest`, `TrocaDeEmailConfirmadaTest`.

### `App\Support\Mailer::entrega()` é a chave de tudo

Três fluxos dependem de e-mail: **recuperar senha**, **verificar o e-mail do cadastro** e
**confirmar a troca de e-mail**. Nenhum deles tem `if` de ambiente espalhado pelo código —
todos perguntam a este helper, que só olha `config('mail.default')`. Transporte que não
entrega (`log`, `null`) ⇒ o app **se adapta em vez de mentir**; transporte de verdade ⇒ os
três passam a valer sozinhos, **sem mudar uma linha**.

### Desenvolvimento: Mailpit, não `log`

O `docker-compose.yml` sobe um container **`mailpit`** — um SMTP de mentira que aceita tudo,
não entrega a ninguém e mostra as mensagens em **http://localhost:8026**.

**Por que não `log`:** com `log`, `Mailer::entrega()` é falso e o app desvia para os caminhos
de "fase de testes". São caminhos legítimos, mas **não são os que vão rodar em produção** — e
com `log` eles seriam os únicos jamais exercitados em dev. Com Mailpit, o dev roda o mesmo
código da produção e dá para ler o e-mail.

Porta **8026** e não a 8025 padrão: outro projeto da máquina (`umadflor`) já ocupa a 8025 —
mesma história do `megatruck` com 8000/3306/5173. A porta SMTP (1025) não é publicada; o app
fala por `mailpit:1025` na rede interna do compose.

### Produção

Configuração no `.env` (receita completa e comentada no `.env.example`). O que **não** pode
errar:

> ⚠️ **A porta decide o `MAIL_SCHEME`**: `465 → smtps` (TLS implícito), `587 → smtp`
> (STARTTLS). Trocar os dois faz o cliente falar texto puro com um servidor que só entende
> TLS — a conexão morre **sem mensagem de erro útil**.

> ⚠️ `MAIL_FROM_ADDRESS` tem de ser endereço de um domínio que você controla, e em
> hospedagem compartilhada normalmente **o mesmo** do `MAIL_USERNAME`: o servidor recusa
> remetente diferente do autenticado.

### O middleware `verified` está APLICADO — e não tranca ninguém

`routes/web.php` usa `['auth', 'verified']`. Fecha um buraco concreto: sem ele, dá para se
cadastrar com o e-mail de outra pessoa e, como o "esqueci a senha" manda o link para aquele
endereço, o dono do e-mail "recupera" a conta e vê os lançamentos de quem a criou.

**O invariante que torna isso seguro: enquanto o app não consegue enviar e-mail, ninguém
fica pendente de confirmação.** Quem cria usuário decide o `email_verified_at`:

| Caminho | Sem mailer | Com mailer |
|---|---|---|
| `/register` | grava a data no ato | nasce nulo + recebe o link |
| Dependente (`DependentController`) | grava sempre | grava sempre (ninguém lhe manda link) |
| Trocar e-mail no perfil | grava a data no ato | fica em `pending_email`, `email` não muda |

**🚨 Nunca deixe `email_verified_at` nulo num caminho que não envie link.** Foi exatamente
o defeito corrigido em 06/08: o `ProfileController` zerava a coluna quando não havia mailer,
criando uma conta que **nenhum link destrava** — inerte enquanto `verified` não existia, e
conta perdida no dia em que ele entrou. A migration
`2026_08_06_000000_backfill_email_verified_at_antes_do_middleware` limpou o rastro.

⚠️ Rota que precise funcionar ANTES da confirmação (reenviar link, sair da conta) vai em
`routes/auth.php`, fora do grupo protegido — senão a tela que destrava a conta fica ela
própria trancada.

### Alertas de segurança (06/08/2026) — `AlertasDeSegurancaTest`

**O buraco que fecham:** o app já derruba as outras sessões quando a senha muda e já exige
a senha atual para desligar o 2FA — isso protege contra quem **não** tem a credencial. O
caso oposto ficava descoberto: quem **já entrou** troca a senha, desliga o 2FA, e o dono não
fica sabendo de nada — descobre semanas depois, quando já não consegue entrar. **O e-mail é
o único canal que o invasor não controla.**

| Ação | Alerta |
|---|---|
| Trocar senha (Configurações) | `senhaAlterada` |
| Redefinir senha pelo link | `senhaRedefinida` |
| Ativar / desativar 2FA | `doisFatoresAtivado` / `doisFatoresDesativado` |
| Encerrar outras sessões | `sessoesEncerradas` (com quantos aparelhos caíram) |
| Excluir conta | `contaExcluida` — enviado **antes** do delete |
| Criar dependente | `BemVindoDependente` (para o dependente) |

- **🚨 Todo alerta sai por `App\Support\Notificador::avisar()`, nunca por `Mail::` direto.**
  Ele engole a exceção e registra no log. O motivo é a razão de a classe existir: trocar a
  senha é o que a pessoa faz **justamente ao desconfiar de invasão** — se o SMTP estiver
  fora do ar e a exceção subir, ela vê um 500 e conclui que a troca falhou, quando já foi
  gravada. **O aviso é acessório; a ação é o que importa.**
- **`AlertaDeSeguranca` é um Mailable com construtores nomeados**, não seis classes: o
  formato é o mesmo e o que não pode divergir é justamente a instrução do "não foi você".
- **A senha do dependente NUNCA vai no e-mail** — o caminho oferecido é o "Esqueci a senha",
  que além de seguro é o único que lhe dá uma senha que o titular não conhece.
- **Alarme falso é proibido:** cancelar um setup de 2FA pendente não dispara nada (não
  desligou proteção nenhuma). Alerta que grita à toa é alerta que ninguém lê no dia certo.
- Envio **síncrono** (~1 s). `QUEUE_CONNECTION=database` mas não há worker rodando; se um
  dia houver `queue:work`, basta os Mailables implementarem `ShouldQueue`.

### Layout dos e-mails — o design system traduzido

`resources/views/emails/layout.blade.php` (HTML) + `layout-texto.blade.php` (texto puro),
os dois usados por **todos** os Mailables, que só passam dados.

**Não dá para reusar o CSS do app**, e cada motivo já mordeu alguém: `var(--brand-600)` não
existe em Outlook nem no app do Gmail; folha externa (`@vite`) nunca chega ao cliente;
flex/grid são irregulares — daí o layout em `<table>`; e web font é bloqueada, então a pilha
cai em fonte de sistema. Os tokens viram **hex literal**, copiados do `:root` do
`design-system.css`. Tema **sempre claro**, como as telas de auth.

⚠️ Toda mensagem sai em **duas partes** (HTML + texto). Não é capricho: filtro de spam
desconfia de mensagem só-HTML, e um HTML que não renderize deixa o aviso ilegível sem ela.
⚠️ Parágrafo é impresso com `{!! !!}` (para permitir `<strong>`), então **dado do usuário
interpolado ali passa por `e()`** — senão é injeção de HTML no e-mail.

---

## 🔐 Verificação em duas etapas (2FA — 05/08/2026)

Testes: `tests/Unit/TotpTest.php` (algoritmo) e `tests/Feature/DoisFatoresTest.php` (fluxo).

**É OPCIONAL, e opcional de verdade.** Nasce desligada, nenhum caminho a liga sozinha
(nem cadastro, nem dependente, nem padrão), e quem não ligar não vê diferença nenhuma no
login. Conta com as quatro colunas `users.two_factor_*` nulas = 2FA desligado.

### As três peças

| Arquivo | Papel |
|---|---|
| `App\Support\Totp` | O algoritmo (RFC 6238/4226): base32, HMAC-SHA1, truncamento, janela. **Sem biblioteca** — cabe em 40 linhas e permite devolver **qual passo casou**, que é o que torna possível barrar replay. |
| `App\Support\RecoveryCodes` | Os códigos de emergência: alfabeto **sem 0/O, 1/I/L e U** (são anotados no papel), uso único, comparação em tempo constante. |
| `App\Services\TwoFactorService` | Liga/confirma/desliga e valida, com `lockForUpdate` no que é de uso único. Gera o QR (`bacon/bacon-qr-code` → SVG inline). |

### Ligar tem DUAS etapas, e o motivo é não trancar ninguém fora

`two_factor_secret` preenchido **não** liga a exigência no login — quem liga é
**`two_factor_confirmed_at`**. Entre "gerei o QR" e "digitei o primeiro código" existe uma
janela em que o autenticador talvez nem tenha sido escaneado; cobrar o código ali trancaria
a pessoa fora da própria conta com um QR que ela não chegou a ler. Fechar a aba no meio não
tem consequência alguma. **Ao mexer aqui, pergunte sempre por `temDoisFatores()`, nunca por
`two_factor_secret !== null`.**

Pelo mesmo motivo `ativar` **recusa** regerar o segredo de quem já está protegido: `iniciar()`
limpa o `confirmed_at`, e quem desistisse no meio ficaria SEM 2FA sem ter pedido para desligar.
Trocar de aparelho passa por desligar e ligar de novo, que é explícito.

### O login não usa mais `Auth::attempt()`

`LoginRequest::authenticate()` chama **`Auth::guard('web')->validate()`** e só depois decide:
sem 2FA, `login()`; com 2FA, **não abre sessão nenhuma** e o controller manda para o desafio.
A alternativa óbvia (logar e deslogar) tem um efeito colateral traiçoeiro:
`SessionGuard::logout()` **recicla o remember token**, o que derrubaria o "Lembrar de mim" de
todos os outros aparelhos daquele usuário a cada login — e só em quem ligasse o 2FA.

O login pendente vive na sessão (`login.id` / `login.remember` / `login.at`) e **expira em 5
minutos**: sem prazo, um computador compartilhado ficaria com a porta encostada, já com a
senha vencida. A sessão autenticada só nasce em `TwoFactorChallengeController::store`.

**O login por AJAX (`sm/auth.js`) não precisou de uma linha nova**: ele já navega para o
`redirect` que vier no JSON, e o desafio é só outro destino.

### Uso único dos dois lados

- **TOTP:** `two_factor_last_step` guarda o último passo de 30 s gasto; `Totp::verificar`
  recusa passos `<=` ele. Sem isso, quem espia a tela por cima do ombro tem 30 segundos para
  reusar o mesmo número, e a segunda etapa deixa de ser "algo que você tem".
- **Recuperação:** o código sai da lista ao ser usado.
- Ambos gravam sob `lockForUpdate` — verificar e gravar em passos separados é uma janela de
  corrida, e aqui a corrida vale o login.

### Limite de tentativas: minuto **e** hora

Limitador `dois-fatores` (`AppServiceProvider`), chave = conta + IP: `Limit::perMinute(5)`
**somado a** `Limit::perHour(20)`. São 6 dígitos e a janela aceita 3 códigos por vez, então
5/min sustentados dariam ~2% de chance por dia; o teto por hora derruba para ~0,1%. O contador
**não é zerado no acerto**, de propósito — zerar daria ao atacante como renovar a cota.

### Códigos de recuperação são a ÚNICA porta de volta

Se o app não estiver entregando e-mail, nem "esqueci a senha" funciona (`App\Support\Mailer`), então
**não existe recuperação por e-mail**. A tela avisa isso **antes** de ligar e mostra a lista
logo depois de confirmar (flash, uma vez). Ficam **cifrados** e não com hash porque o segredo
TOTP ao lado é obrigatoriamente reversível — hash nos códigos não fecharia buraco nenhum e
impediria reexibi-los.

### Cuidados que já custaram caro

- ⚠️ **`text`, nunca `varchar`, para campo com cast `encrypted`.** Medido em MySQL: o segredo
  cifrado tem **256 caracteres** e a lista de códigos, **400**. Um `varchar(255)` seria erro
  1406 em produção **passando verde na suíte**, que roda em sqlite (sem limite de tamanho).
  Mesma armadilha do `terms_accepted_ip`.
- ⚠️ **Rotacionar `APP_KEY` sem `APP_PREVIOUS_KEYS`** torna o segredo ilegível e tranca fora
  todo mundo que tiver 2FA ligado — sobram só os códigos de recuperação.
- ⚠️ **Nunca escreva a tag de fechamento do PHP dentro de um comentário `//`** (ex.: citando
  uma declaração XML): o interpretador encerra o bloco ali e o arquivo deixa de compilar.
  Custou um `ParseError` no `TwoFactorService`.
- **"Lembrar de mim" pula o desafio nas visitas seguintes** — é a semântica padrão de
  "dispositivo confiável" (Fortify e a maioria dos sites fazem igual), mas note que a tela de
  login deste app deixa a caixa **marcada por padrão**. O 2FA continua protegendo qualquer
  login em aparelho novo, que é o ataque real.
- **O `switch` do card é decorativo** (`aria-hidden`). Quem liga/desliga são os formulários —
  um interruptor de um clique não teria onde pedir a senha nem mostrar o QR.

---

## Convenções

- **Validação em Form Requests** com `messages()`/`attributes()` PT-BR. Valores aceitam vírgula
  pt-BR ("1.234,56") normalizada em `prepareForValidation` (trait `NormalizesMoneyInput`).
- **Dinheiro nunca é negativo.** O sinal vem do `type`, então todo campo de valor valida `min:0`
  (ou `min:0.01` para os obrigatórios > 0); `initial_balance` é `min:0`. No cliente, `sm/money.js`
  formata todo `input[inputmode="decimal"]` para BRL **A CADA TECLA** (06/08/2026) e **descarta o
  sinal de menos**. Os dígitos entram pelos **centavos** (`1` → `0,01`, `130000` → `1.300,00`),
  como nos apps de banco: assim o campo nunca é ambíguo — no modo antigo, formatado só no blur,
  "1300" tanto podia ser mil e trezentos quanto treze reais, e o valor "pulava" depois de digitado.
  Consequência aceita: vírgula e ponto digitados são ignorados (a casa decimal é fixa); colar
  "1.234,56" ou "1234.56" dá o mesmo resultado. Ao adicionar um novo campo de
  valor, use `inputmode="decimal"` para herdar esse comportamento. Campos que NÃO são moeda
  (ex.: taxa em %) marcam `data-no-money` para o `money.js` ignorá-los.
- **Exibir dinheiro: `@brl($valor)`** (ou `App\Support\Brl::format()`), nunca `number_format` cru —
  o negativo precisa sair como `−R$ 1.234,56`, com o sinal antes do símbolo.
- **🚨 NUNCA grave uma despesa com `Transaction::create()` direto.** Todo caminho de gasto passa
  por `FundingService::spend()`, que checa o saldo sob lock e pergunta a fonte quando falta. Isso
  vale para os 6 caminhos: `transactions.store`/`update`, `faturas.lancar`, `faturas.fatura.pagar`,
  `faturas.recorrente.pagar` e `contas-fixas.pagar`. Um caminho novo que escape do guard reabre o
  buraco que a v3 fechou. Ver "💰 Modelo de dinheiro".
- **🚨 Apagar despesa = `FundingService::estornarFonte()` ANTES do delete**, na mesma
  `DB::transaction`. Senão o resgate que financiou a despesa sobrevive e o investido encolhe
  sozinho. Vale inclusive (principalmente) nos deletes em massa, que não disparam eventos.
- **Toda escrita de despesa disparada por clique leva `client_uuid`** (`nullable|uuid` no Form
  Request + dedupe antes do guard). O índice único é `(user_id, client_uuid)`, então num
  lançamento de N linhas o uuid fica **só na primeira**.
- **Saldo exibido = `Account::available`**, não `balance`. O bruto é detalhe interno.
- **Cartão de débito não é conta de lançamento:** os selects usam `Account::paymentOptions()`, que
  devolve **Fluent** — nas views, `$conta->isCard` (propriedade), nunca `$conta->isCard()`.
- **Ownership sempre**: queries escopadas por `auth()->id()`; `account_id`/`category_id` validados
  com `Rule::exists()->where('user_id', ...)`; categoria deve casar com o `type` da transação.
- **🚨 Coluna com cast `encrypted` é `text`, nunca `varchar`.** O cifrado do Laravel tem 200–400
  caracteres; `varchar(255)` estoura em MySQL (erro 1406) e **passa verde na suíte**, que roda em
  sqlite — sem limite de tamanho. Já mordeu duas vezes: `terms_accepted_ip` e `two_factor_secret`.
- **2FA ligado se pergunta por `$user->temDoisFatores()`**, nunca por `two_factor_secret !== null`:
  entre gerar o QR e confirmar o primeiro código o segredo já existe, mas cobrar o código ali
  trancaria a pessoa fora da conta. Rota nova que valide código de 2FA leva `throttle:dois-fatores`.
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
- **Pint:** `pint.json` exclui `lang/` (gerado pelo laravel-lang, ninguém edita à mão). O job no
  CI existe **comentado**: o baseline ainda reprova 85 arquivos, e ligar antes de um commit só de
  formatação deixaria o CI vermelho permanente — o que treina todo mundo a ignorá-lo. A rodada de
  formatação precisa de um momento em que ninguém mais esteja com trabalho pendente no repo.
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

> ⚠️ **`view:cache` ANTES de `npm run build`, sempre.** O `app.css` tem
> `@source '../../storage/framework/views/*.php'`: o Tailwind escaneia as views **compiladas**,
> não só os `.blade.php`. Com `storage/framework/views/` vazio (máquina nova, logo após um
> `view:clear`, ou um deploy limpo) o build sai com **~20 kB de CSS a menos** — 110 kB em vez de
> 129 kB — e não avisa nada: o site simplesmente perde estilos. Medido em 02/08/2026.
> Ordem correta no deploy: `php artisan view:cache` → `npm run build`.

### Portas (host) e troubleshooting
- **App:** http://localhost:8001 · **MySQL (host):** 3307 · **Vite:** 5173 ·
  **Mailpit (caixa de e-mail de dev):** http://localhost:8026.
- Portas movidas de **8000→8001** (app) e **3306→3307** (MySQL, só no host) para **não
  conflitar com o projeto `megatruck`** na mesma máquina (ele ocupa 8000/3306/5173). A porta do
  **container** do MySQL segue 3306 — por isso `DB_PORT=3306` no `.env` (rede interna do Docker).
  O **Mailpit** foi para **8026** pelo mesmo motivo: o projeto `umadflor` já roda um na 8025.
  A porta SMTP dele (1025) não é publicada — o app fala por `mailpit:1025` na rede do compose.
- ⚠️ **Vite/5173 ainda colide com o megatruck:** não rodar os dois `npm run dev` ao mesmo tempo
  (ou mudar a porta do Vite no `vite.config.js` quando precisar dos dois no ar).
- **Erro `SQLSTATE[HY000] [2002] ... getaddrinfo for db failed`** (o app não resolve o host
  `db`): glitch do Docker Desktop/WSL2 em que o container do banco "solta" da rede (aparece sem
  rede em `docker inspect`). **Fix:** `docker compose down; docker compose up -d` (recria os
  containers na mesma rede; o volume `db_data` é preservado, nada se perde).

### Comandos úteis
```powershell
docker compose exec app php artisan test                       # suíte completa (850 testes)
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
- **Modelo de dinheiro v3: ✅ CONCLUÍDA (27/07/2026)** — cheque especial por conta corrente,
  "saldo em conta" = disponível (investido nunca é consumido em silêncio), escolha da fonte via
  409, contas fixas mensais com competências projetadas, fatura vencida que não some, e o limite
  do cartão voltando ao pagar. Nasceu de uma auditoria que provou 12 defeitos rodando código.
  Ver a seção "💰 Modelo de dinheiro" e a spec de 27/07.
- **Fase 2 — Futuro:** empacotar a PWA como app Android (**TWA**) para a Play Store;
  **bot WhatsApp** para consultar/lançar transações por mensagem (ver infra abaixo).

---

## 📜 Rodada de features financeiras (jun/2026 — ENTREGUE, mantido como histórico)

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
