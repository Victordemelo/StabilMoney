<p align="center">
  <img src="public/assets/stabilmoney-mark.png" width="72" alt="Stabil Money" />
</p>

<h1 align="center">Stabil Money</h1>

<p align="center">
  App de finanças pessoais multiusuário — receitas, despesas, contas, cartões,
  metas e investimentos, com conta-família.<br>
  Monolito <strong>Laravel 12</strong> server-rendered, interface 100% em português do Brasil.
</p>

---

## O que ele faz

Controla o dinheiro de uma família num lugar só: lançamentos, faturas de cartão com ciclo e
limite, contas fixas mensais, metas de poupança e investimentos com projeção de IR. O titular
cria dependentes com login próprio, e todos compartilham a mesma visão.

A parte que dá o nome ao projeto é o **modelo de dinheiro**: o app separa o que está na conta
do que já está comprometido, e não deixa gastar o que não existe.

| Bolso | O que é |
|---|---|
| **Saldo bruto** | `saldo inicial + receitas − despesas` |
| **Reservado** | o que está guardado em metas e investimentos — está na conta, mas não é para gastar |
| **Disponível** | `bruto − reservado`. **É este número que a interface chama de "saldo em conta"** |
| **Cheque especial** | limite por conta corrente; o piso do disponível é `−limite` |

Quando o disponível não cobre uma despesa, o servidor responde **409** e pergunta de onde sai o
dinheiro (cheque especial ou resgate de investimento). O app **nunca** usa o cheque especial por
conta própria. Obrigação já vencida — fatura, conta fixa — é a exceção: passa e a conta fica
negativa, porque não se recusa um boleto que já existe.

## Stack

| Camada | Escolha |
|---|---|
| Backend | Laravel 12 · PHP 8.4 |
| Banco | MySQL 8 (Docker) · testes em SQLite `:memory:` |
| Frontend | Blade + design system próprio (portado do handoff do Claude Design) · Tailwind 4 como utilitário |
| JS | Vanilla em `resources/js/sm/` · navegação pjax sem reload |
| Auth | Laravel Breeze customizado · senhas em **argon2id** |
| Mobile | PWA instalável, com lançamento offline (fila + Background Sync) |

## Rodando

Pré-requisitos: **Docker Desktop** e **Node** no host. O PHP roda só no container.

```bash
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan storage:link
```

Assets (rodam no host):

```bash
npm install
npm run dev
```

App em **http://localhost:8001**. As portas são 8001 (app), 3307 (MySQL no host) e 5173 (Vite).

> ⚠️ **No build de produção, `php artisan view:cache` vem ANTES de `npm run build`.** O Tailwind
> escaneia as views compiladas; com o cache vazio o CSS sai ~20 kB menor e o site perde estilos
> sem avisar.

## Testes

```bash
docker compose exec app php artisan test
```

**700 testes / 2.744 asserções.** Rodam em SQLite em memória, sem precisar de build de assets.
O CI (`.github/workflows/ci.yml`) roda a suíte a cada push e PR.

Toda feature nova sai com teste no mesmo commit — vários buracos de dinheiro foram pegos assim
antes de chegar ao usuário.

## Backup

```bash
./scripts/backup-db.sh
```

```bash
./scripts/restore-db.sh ARQUIVO
```

O backup gera um dump datado em `storage/backups/` com rotação; o restore valida o arquivo
antes de destruir qualquer coisa e guarda o estado atual por segurança.

## Documentação

| Onde | O quê |
|---|---|
| [`CLAUDE.md`](CLAUDE.md) | **Comece por aqui.** Arquitetura, modelo de dinheiro, convenções e o histórico de por que as coisas são como são |
| [`docs/checklist-de-publicacao.md`](docs/checklist-de-publicacao.md) | 17 itens de deploy, priorizados, com o porquê de cada um |
| [`docs/superpowers/specs/`](docs/superpowers/specs/) | Specs das entregas grandes, com as decisões e as auditorias que as originaram |
| [`design/`](design/) | Handoff do Claude Design — fonte da verdade visual (não editar) |

## Estado

Funcional de ponta a ponta em desenvolvimento. **Ainda não publicado**: faltam credenciais de
e-mail (recuperação de senha e verificação dependem delas), revisão jurídica dos documentos
legais e a infraestrutura de produção. O checklist de publicação tem a lista completa.
