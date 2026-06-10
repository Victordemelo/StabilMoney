# Stabil Money — Guia do Projeto

App financeiro pessoal. Objetivo: gerenciar **receitas, despesas, contas/carteiras e categorias**,
com **saldo e dashboard**. Funciona como **web + celular** a partir de um único código (PWA).

> Este arquivo é o contexto que o Claude Code carrega a cada sessão. Mantenha-o atualizado
> quando decisões de stack ou arquitetura mudarem.

---

## Stack (decidido)

| Camada | Escolha | Motivo |
|--------|---------|--------|
| Backend / Framework | **Laravel 12** (PHP 8.4) | Já iniciado; o dev conhece. |
| Banco de dados | **MySQL 8.0** (no Docker) | Já provisionado no `docker-compose`. Bom para multiusuário futuro. |
| Frontend | **Blade + Tailwind 4 + Vite** | Renderização server-side, simples e mobile-first. |
| Empacotamento | **Docker** (PHP/Apache + MySQL) | Dependências isoladas; a máquina host não precisa de PHP. |
| Mobile | **PWA** (web instalável) | Web e celular do mesmo código. Sem Android Studio por enquanto. |

### Por que não outra stack?
- **Supabase / Flutter**: avaliados, mas pivotariam o projeto e exigiriam aprender 2 coisas novas.
  O Laravel já está montado e o dev já mexeu nele. Supabase fica como opção de banco/auth no futuro.
- **App nativo (Play Store)**: futuro (Fase 2). A PWA pode ser empacotada em app Android (TWA) sem reescrever.

---

## Roadmap em fases

- **Fase 0 — Núcleo (em andamento):** modelo de dados (Contas, Categorias, Transações),
  CRUD, dashboard com saldo/resumo do mês, layout mobile-first. Banco MySQL no Docker.
- **Fase 1 — PWA + Login:** `manifest.json` + service worker (instalável na tela inicial),
  autenticação multiusuário (Laravel Breeze). Cada usuário com seus próprios dados.
- **Fase 2 — Play Store:** empacotar a PWA como app Android (TWA) **ou** app Flutter consumindo a API.

---

## Modelo de dados (Fase 0)

- **users** — usuário (já existe; `is_admin` booleano).
- **accounts** — carteira/banco/cartão. Campos: `user_id`, `name`, `type`
  (`wallet|bank|credit_card|savings|investment|other`), `initial_balance`, `color`, `icon`.
  Saldo atual = `initial_balance` + soma das transações.
- **categories** — `user_id`, `name`, `type` (`income|expense`), `color`, `icon`.
- **transactions** — `user_id`, `account_id`, `category_id` (nullable), `type` (`income|expense`),
  `amount` (decimal 15,2, sempre positivo), `description`, `date`.

> **Valores monetários** usam `decimal(15,2)`. O sinal (entra/sai) vem do `type`, não do valor.

### Autenticação temporária
Ainda **não há login** (Fase 1). Enquanto isso, os controllers usam o usuário atual via
`Auth::id() ?? 1` — existe um usuário "seed" com id 1. Ao adicionar o Breeze na Fase 1,
basta o login funcionar que o `Auth::id()` passa a valer automaticamente.

---

## Fluxo de trabalho com Docker

> Pré-requisito: **Docker Desktop** instalado (com WSL2 no Windows).
> Instalar: `winget install Docker.DockerDesktop` (pode pedir reinício).

```powershell
# 1. Subir os containers (app + MySQL)
docker compose up -d --build

# 2. Instalar dependências PHP dentro do container (gera vendor/)
docker compose exec app composer install

# 2b. Garantir permissão de escrita em storage/ e cache (evita erro 500)
docker compose exec app chmod -R 777 storage bootstrap/cache

# 3. Preparar o .env e a chave da aplicação
#    (.env já vem configurado para MySQL; só gerar a APP_KEY)
docker compose exec app php artisan key:generate

# 4. Rodar as migrations + seed (cria tabelas, usuário e categorias padrão)
docker compose exec app php artisan migrate --seed

# App disponível em: http://localhost:8000
```

### Assets (Tailwind/Vite) — rodam no HOST (Node já instalado)
```powershell
npm install
npm run dev      # desenvolvimento com hot reload (Vite em http://localhost:5173)
# ou
npm run build    # build de produção (gera public/build)
```

### Comandos úteis
```powershell
docker compose exec app php artisan migrate:fresh --seed   # recria o banco do zero
docker compose exec app php artisan tinker                 # console interativo
docker compose exec app bash                               # shell dentro do container
docker compose logs -f app                                 # logs do Apache/PHP
docker compose down                                        # derruba os containers
```

### Testar no celular (mesma rede Wi-Fi)
Descubra o IP da máquina (`ipconfig`) e acesse `http://SEU_IP:8000` no navegador do celular.
Quando a PWA estiver pronta (Fase 1), use "Adicionar à tela inicial".

---

## Convenções

- Controllers como **resource controllers** (`index/create/store/show/edit/update/destroy`).
- Validação dentro dos controllers (ou Form Requests quando crescer).
- Views Blade em `resources/views/<recurso>/`, mobile-first com Tailwind.
- Migrations sempre reversíveis (`down()`), banco agnóstico quando possível.
- Mensagens de commit: prefixos `Feat:`, `Fix:`, `style:` (padrão já usado no repo).
