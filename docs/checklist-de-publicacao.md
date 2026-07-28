# Checklist de publicação — Stabil Money

Levantado no pentest de **27/07/2026**. Nada aqui é bug no código atual: é o que precisa
mudar **quando o app sair do `localhost` para a VPS**. O risco número um da lista é
simplesmente **copiar o `.env` de desenvolvimento para o servidor**.

Marque conforme for fazendo. A ordem importa: os itens de 🔴 travam o lançamento.

---

## 🔴 Bloqueadores — sem isto, não publique

### 1. `APP_DEBUG=false`

```env
APP_DEBUG=false
```

**Por quê:** com `true`, qualquer erro devolve a tela do Ignition com *stack trace*,
trechos de código e **os valores das variáveis de ambiente** — incluindo usuário e senha
do banco e a `APP_KEY`. Basta um visitante forçar um erro. É o item mais grave da lista.

### 2. `APP_ENV=production`

```env
APP_ENV=production
```

**Por quê:** além de desligar comportamentos de desenvolvimento, é o que impede o
`DatabaseSeeder` de rodar (ele aborta fora do ambiente `local`) e o que faz a política de
senha aplicar a checagem de vazamento.

### 3. `APP_KEY` nova, gerada no servidor

```bash
php artisan key:generate
```

**Por quê:** a chave de dev já circulou em backups, prints e no seu histórico de shell.
Ela criptografa cookies e assina URLs — reutilizá-la é entregar a capacidade de forjar
qualquer uma dessas coisas. **Gere no servidor e nunca versione.**

> ⚠️ Trocar a `APP_KEY` invalida sessões e qualquer dado criptografado existente. Faça
> antes de ter usuários reais.

### 4. Cookie de sessão só em HTTPS

```env
SESSION_SECURE_COOKIE=true
```

**Por quê:** sem isso o cookie de sessão é enviado também em `http://`. Quem estiver na
mesma rede intercepta e entra na conta sem senha.

### 5. Mailer de verdade

```env
MAIL_MAILER=smtp   # e as credenciais do provedor
```

**Por quê:** hoje é `log` — **nenhum e-mail sai**. Consequência prática: quem esquecer a
senha **perde a conta**, porque o link de redefinição só vai para o arquivo de log. É
também pré-requisito dos itens 12 e 13.

### 6. MySQL sem porta publicada

No `docker-compose.yml`, **remova o bloco `ports:` do serviço `db`** na VPS. O app fala
com `db:3306` pela rede interna do compose; a porta no host existe apenas para conectar
DBeaver/TablePlus em desenvolvimento.

E troque as senhas — os defaults (`root` / `user` / `password`) são de dev:

```env
DB_USERNAME=stabil_app
DB_PASSWORD=<senha longa e aleatória>
DB_ROOT_PASSWORD=<outra senha longa>
```

> ⚠️ O MySQL só cria o usuário no **primeiro boot** do volume. Num banco já existente,
> trocar a senha no `.env` não basta: rode `ALTER USER` dentro do container ou comece com
> o volume novo.

### 7. Permissões de arquivo — nada de `chmod 777`

O passo de desenvolvimento (`chmod -R 777 storage bootstrap/cache`) **não vai para a
VPS**. Lá:

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

`777` deixa qualquer usuário do sistema escrever nos diretórios de onde o PHP lê e
escreve — inclusive views compiladas, que são executadas.

---

## 🟠 Importante — faça na mesma janela de publicação

### 8. HTTPS obrigatório + HSTS no proxy

No Caddy (que já faz o certificado automático), garanta o redirecionamento de `http` para
`https`. O app já envia `Strict-Transport-Security` quando a requisição é segura
(`SecurityHeaders`), mas o redirect é do proxy.

### 9. `SESSION_DOMAIN` continua `null`

```env
SESSION_DOMAIN=null
```

**Por quê:** o plano é `app.stabilmoney.com.br`, `n8n.` e a landing no domínio raiz.
Definir `.stabilmoney.com.br` faria o cookie de sessão do app ser enviado **para o n8n
também**. Deixe `null` para o cookie ficar preso ao host que o emitiu.

### 10. Caches de produção

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
npm run build
```

`route:cache` falha se houver rota apontando para classe inexistente — é um bom teste de
sanidade antes de subir.

### 11. Backup do banco antes de qualquer coisa

Não existe rotina de backup hoje, e a Política de Privacidade é honesta sobre isso. Antes
de ter usuários reais, configure pelo menos um `mysqldump` diário com retenção — de
preferência **fora** da mesma VPS.

---

## 🟡 Antes de abrir o cadastro para desconhecidos

Estes são os itens da "onda 3" do pentest que dependem de infraestrutura ou de decisão de
produto, não de correção pontual.

### 12. Verificação de e-mail

Depende do item 5 (mailer). Hoje `User` **não** implementa `MustVerifyEmail`, de propósito
— ativar sem mailer trancaria todos os usuários fora do app.

Para ativar quando houver mailer:

1. `class User extends Authenticatable implements MustVerifyEmail`
2. adicionar o middleware `verified` no grupo de rotas do app em `routes/web.php`
3. testar o fluxo (as telas em PT-BR já existem, prontas)

**Por que importa:** sem verificação, dá para cadastrar com o e-mail de outra pessoa — e
como o "esqueci a senha" manda o link para aquele endereço, **o dono do e-mail pode
"recuperar" a conta e ver os lançamentos de quem a criou**.

### 13. Confirmar a troca de e-mail

Também depende do mailer. Hoje trocar o e-mail no perfil exige a senha atual (feito na
onda 2), mas o novo endereço não é confirmado — dá para apontar a conta para um e-mail que
não se controla e perder o acesso. O padrão é guardar em `pending_email` e só efetivar
quando o link enviado ao **novo** endereço for clicado.

### 14. Avatares fora do disco público

Hoje as fotos ficam em `storage/app/public/avatars` e são servidas **sem autenticação**.
O nome é aleatório (40 caracteres), então não é enumerável, e o EXIF/GPS já é removido no
upload (onda 2) — mas quem tiver a URL vê a foto para sempre, sem login.

Para fechar: gravar no disco `local` (privado) e servir por uma rota sob `auth` que
valide a família do dono, ajustando `User::avatarUrl()`. Requer **migrar os arquivos
existentes** de `app/public/avatars` para `app/private/avatars`.

### 15. Limpar a fila offline na troca de usuário

O IndexedDB (`sm-offline`) guarda valor, descrição e o token CSRF dos lançamentos
pendentes, e nada o limpa no logout — num aparelho compartilhado, o próximo usuário lê
pelo DevTools. O envio em background já filtra por usuário na página, mas o service worker
itera a fila inteira e depende só do CSRF para não cruzar contas.

A fazer: limpar o object store em `purgeCachedFormIfUserChanged()`, filtrar por `userId`
também no `flushLancamentos()` do service worker, descartar itens `failed` antigos e
buscar um token fresco em `/csrf-token` em vez de persistir o token.

> O logout já manda `Clear-Site-Data: "cache"`, que resolve o **cache de páginas**. Não
> incluímos `"storage"` de propósito: apagaria a fila offline e destruiria lançamentos não
> sincronizados.

### 16. Revisão jurídica dos Termos e da Política

Os documentos são completos e específicos ao app, mas não passaram por advogado. Antes de
cadastro aberto ao público, vale a revisão — é barato comparado ao risco de tratar dado
financeiro de terceiros.

---

## 🟢 Endurecimento adicional (opcional)

| Item | Onde | Observação |
|---|---|---|
| `SESSION_ENCRYPT=true` | `.env` | Defesa em profundidade: protege o payload da sessão se alguém ler a tabela `sessions` sem a `APP_KEY`. |
| `same_site=strict` | `config/session.php` | O app não recebe navegação cross-site legítima; `lax` já está coberto pelo CSRF. |
| CSP com nonce | `SecurityHeaders` | Hoje usa `'unsafe-inline'` em `script-src` por causa dos scripts inline e do `nav.js`, que recria `<script>` no pjax. Trocar por nonce exige refatorar os três. |
| 2FA | tela de Segurança | Já está na interface como "em breve". |
| `is_admin`/`account_owner_id` fora de `$fillable` | `app/Models/User.php` | Não é explorável hoje (nenhum `update($request->all())`), mas é armadilha para o futuro: um único uso descuidado viraria escalada de privilégio. |
| Retenção/criptografia de IP | `sessions`, `terms_accepted_ip` | Se quiser proteger IPs em repouso, use o cast `encrypted` (reversível). **Não** use hash — quebraria a tela de dispositivos e a prova do aceite. |
| Rotina de limpeza de sessões | agendador | `php artisan session:prune` (driver `database` não limpa sozinho as expiradas). |

---

## Já resolvido (não precisa fazer de novo)

Corrigido nas ondas 1 e 2 do pentest — ver `CLAUDE.md`, seção 🔒 Segurança:

- XSS armazenado via nome de categoria (`charts.js` usava `innerHTML`)
- JSON do dashboard sem `JSON_HEX_TAG`
- endpoints que validam senha sem *rate limit*
- enumeração de usuário no "esqueci a senha"
- troca de senha que não derrubava as outras sessões
- MySQL publicado em `0.0.0.0` em desenvolvimento
- política de senha valendo só `min(8)` sem checagem de vazamento
- ausência de CSP e demais cabeçalhos de segurança
- troca de e-mail sem confirmação de senha
- EXIF/GPS preservado nas fotos de perfil
- foto e sessões sobrevivendo à exclusão da conta

**Sem achados** em: injeção SQL (nenhum SQL cru com entrada do usuário) e isolamento entre
usuários/famílias (IDOR).
