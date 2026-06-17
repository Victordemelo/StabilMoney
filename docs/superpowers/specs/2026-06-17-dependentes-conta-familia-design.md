# Spec — Subprojeto 1: Conta-família (Dependentes núcleo)

**Data:** 2026-06-17
**Status:** Design aprovado (brainstorm + análise de design); aguardando revisão da spec antes do plano.

## Contexto

O StabilMoney é multiusuário e isolado por dono (testado: 16 testes de isolamento).
Hoje cada usuário é uma ilha. Este subprojeto introduz **conta-família**: quem se cadastra
é o **titular**, que pode criar **dependentes** (sub-usuários com login próprio) que
**compartilham a mesma visão financeira do titular**.

Esta spec cobre **apenas o núcleo**. Permissões granulares, Meu perfil e Configurações são
subprojetos separados (2, 3 e 4).

## Decisões fechadas (brainstorm)

- **Escopo:** núcleo da conta-família **+** atribuição "quem fez a compra".
- **Criação de dependente:** o titular define nome + e-mail + senha (sem e-mail/convite —
  não há mailer). O dependente entra com essas credenciais e pode trocar a senha depois.
- **Permissões (round 1):** dependente tem **acesso total às finanças** (criar/editar/excluir
  contas, categorias, transações — mesma visão do titular). **Só o titular** gerencia
  dependentes e exclui a conta da família. Permissões granulares ficam pro Subprojeto 4.
- **Modelo de dados:** `users.account_owner_id` auto-referenciado (null = titular). Todo dado
  da família continua com `user_id = id do titular`. `transactions.made_by_user_id` guarda
  quem lançou.

## Modelo de dados

### Migration 1 — `users.account_owner_id`
- `account_owner_id` — `unsignedBigInteger` nullable, FK → `users.id`, **cascadeOnDelete**.
- `null` = titular; preenchido = dependente apontando pro titular.
- Excluir o titular dissolve a família (apaga dependentes via cascade; dados da família já
  caem pelo FK `user_id` existente — **confirmar no plano** que accounts/categories/transactions
  são `cascadeOnDelete` no `user_id`).

### Migration 2 — `transactions.made_by_user_id`
- `made_by_user_id` — nullable, FK → `users.id`, **nullOnDelete** (autor removido → transação
  fica, autor vira null = "Removido").
- **Backfill:** `UPDATE transactions SET made_by_user_id = user_id` para as linhas existentes.

> Nenhuma outra tabela muda. O isolamento atual é preservado: dados continuam com
> `user_id = titular`.

## Domínio (Model `User`)

```php
public function ownerId(): int      { return $this->account_owner_id ?? $this->id; }
public function isTitular(): bool    { return $this->account_owner_id === null; }
public function dependents(): HasMany { return $this->hasMany(User::class, 'account_owner_id'); }
public function titular(): BelongsTo  { return $this->belongsTo(User::class, 'account_owner_id'); }
```
- `account_owner_id` entra no `$fillable`.

## Escopo por família (a mudança sensível)

Trocar o escopo de "meu id" por **`->ownerId()`** nos pontos mapeados:

| Arquivo | Mudança |
|---|---|
| `AccountController` | `where('user_id', $request->user()->ownerId())`; no store `$data['user_id'] = ownerId` |
| `CategoryController` | idem |
| `TransactionController` | `$userId = $request->user()->ownerId()`; store grava `user_id = ownerId` **e** `made_by_user_id` (ver abaixo) |
| `DashboardController` | `$dashboard->build($request->user()->ownerId())` |
| `DashboardService` / `SidebarService` | sem mudança interna (já recebem o id por parâmetro) |
| `StoreTransactionRequest` / `UpdateTransactionRequest` | `Rule::exists(...)->where('user_id', $this->user()->ownerId())` + validar `made_by_user_id` |
| `AccountPolicy` / `CategoryPolicy` / `TransactionPolicy` | `$model->user_id === $user->ownerId()` |

> Round 1 = acesso total na família. As policies checam só pertencimento à família (não a
> ação) — a checagem por ação entra no Subprojeto 4.

## Gerenciar dependentes (titular)

- **Rota:** `dependentes` (GET) deixa de ser `coming-soon` e passa a `DependentController@index`;
  adicionar `dependentes` (POST store) e `dependentes/{user}` (DELETE destroy).
- **Gating titular-only:** `abort_unless($request->user()->isTitular(), 403)` (ou middleware
  dedicado). Dependente recebe 403 e não vê o card "Dependentes" na sidebar.
- **index:** lista `User::where('account_owner_id', $titular->id)` + contagem de lançamentos
  de cada um (via `made_by_user_id`).
- **store:** valida `name`, `email` (único), `password` (`Rules\Password::defaults()`); cria
  `User` com `account_owner_id = titular->id`, `is_admin = false`, senha com `Hash::make`.
  **NÃO** dispara `Registered` (dependente usa as categorias da família, não cria as próprias).
- **destroy:** apaga o dependente **se** `account_owner_id === titular->id` (senão 403).

## "Quem fez a compra"

- **Form de lançamento:** select "Quem fez a compra?" listando os membros da família
  (titular + dependentes), **default = usuário atual**. Só renderiza se a família tem +1 membro.
- **Store/Update:** valida que `made_by_user_id` pertence à família; grava. Ausente → usuário atual.
- **Exibição:** mostrar o autor na lista de transações e nas "recentes" do dashboard
  (iniciais/nome). Autor null → "Removido".

## UI (portar do protótipo v2)

- **Tela Dependentes** (`view-dependentes` do protótipo): `.section-head` com CTA
  **"Adicionar dependente"** + grid `.dep-grid` de cards `.dep-person` (avatar `.ab` com
  iniciais, nome, badge `Titular`/`Dependente`, e-mail, contador de lançamentos, excluir nos
  dependentes). Último item do grid: card tracejado "+" (`.pm-add`).
- **Adicionar:** modal `.modal-lg` com `.field`/`.input` (Nome, E-mail, Senha com toggle);
  toast de sucesso.
- **Estado vazio** (`.dep-empty`): ícone + "Você ainda não tem dependentes" + CTA.
- **Portar do `styles.css` v2:** `.dep-grid`, `.dep-person`, `.dep-empty`, `.dep-add-btn`,
  `.modal-lg`. Dentro do modal, usar `.field`/`.input` do `forms.css`.
- **Simplificação:** os stats "Limite concedido / Gasto pelos dependentes" do protótipo
  pressupõem limites de gasto (outra feature) — **fora** do round 1. Mostrar no máximo
  "Dependentes ativos".
- **Card "Dependentes" da sidebar:** mostra a contagem real; **escondido pro dependente**.

## Casos de borda

- Remover dependente: dados da família ficam (são do titular); `made_by_user_id` das
  transações dele vira null → "Removido".
- Criação de dependente **não** semeia categorias padrão (compartilha as da família).
- Auto-cadastro continua criando **titular** (`is_admin = true`, já implementado).
- Excluir a própria conta: titular → cascade dissolve a família; dependente → remove só a si.

## Testes

- **Ajustar `MultiUserIsolationTest`:** isolamento agora é **por família** (os testes atuais
  usam 2 usuários = 2 famílias, continuam válidos como isolamento entre famílias).
- **Novos testes:**
  - Dependente da família A enxerga os dados do titular A (dashboard, transações, contas, categorias).
  - Dependente de A **não** enxerga dados da família B.
  - Dependente recebe 403 ao acessar/gerenciar dependentes; titular consegue criar/remover.
  - `made_by_user_id` é gravado corretamente (titular e dependente).
  - Criar dependente **não** cria categorias para ele.
  - Excluir titular remove os dependentes (cascade).

## Fora de escopo (outros subprojetos)

- Permissões granulares por módulo/ação + módulos à vista (**Subprojeto 4**).
- Meu perfil (telefone, foto, e-mail) (**Subprojeto 2**).
- Configurações com subabas / corrigir layout (**Subprojeto 3**).
- Limites de gasto por dependente (feature futura de orçamento).
- Botões CTA em Investimentos/Metas/Faturas (vêm quando cada feature existir).

## Capacidade

100 usuários simultâneos é dimensionamento de infra (requests stateless + sessão no banco).
O escopo por família não adiciona custo por request. Sem mudança de código necessária.
