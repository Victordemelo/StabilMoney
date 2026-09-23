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

### ~~5. Mailer de verdade~~ — ✅ **Feito** (06/08/2026)

```env
MAIL_MAILER=smtp
MAIL_SCHEME=smtps                                    # 465 = TLS implícito
MAIL_HOST=victor.jwsolucoesdigitais.com.br
MAIL_PORT=465
MAIL_USERNAME=victor_teste@victor.jwsolucoesdigitais.com.br
MAIL_PASSWORD=<a senha da conta de e-mail>           # ← única linha pendente
MAIL_FROM_ADDRESS="victor_teste@victor.jwsolucoesdigitais.com.br"
MAIL_TIMEOUT=10                                      # desiste em 10 s, não 60
```

**Por quê:** com `log`, **nenhum e-mail sai**. Consequência prática: quem esquecer a senha
**perde a conta**, porque o link de redefinição só vai para o arquivo de log. É também
pré-requisito do item 13.

**Estado:** configurado e **autenticando de verdade** — o servidor aceita e entrega. Os três
fluxos foram verificados de ponta a ponta: cadastro envia o link de verificação (e o
dashboard fica bloqueado até o clique), a troca de e-mail manda a confirmação **para o
endereço novo**, e "esqueci a senha" entrega o link. Além deles, os sete alertas de segurança
(ver CLAUDE.md, "📧 E-mail") passaram a sair.

⚠️ Ao publicar, esta caixa é de **teste** (`victor_teste@`). Vale criar uma caixa dedicada e
sem pessoa por trás (`nao-responda@`) antes de abrir para desconhecidos: e-mail transacional
saindo de um endereço pessoal mistura resposta de usuário com a caixa de quem administra.

> ⚠️ **A porta decide o `MAIL_SCHEME`**: `465 → smtps` (TLS implícito), `587 → smtp`
> (STARTTLS). Trocados, o cliente fala texto puro com um servidor que só entende TLS e a
> conexão morre **sem mensagem de erro útil** — parece "servidor fora do ar".

> ⚠️ `MAIL_FROM_ADDRESS` precisa ser **o mesmo** do `MAIL_USERNAME` nesta hospedagem:
> servidor compartilhado recusa remetente diferente do autenticado.

**Em desenvolvimento** não se usa este servidor: o `docker-compose` sobe um **Mailpit**
(SMTP de mentira, caixa em http://localhost:8026). Para voltar a ele, basta
`MAIL_HOST=mailpit`, `MAIL_PORT=1025`, `MAIL_SCHEME=smtp` e usuário/senha vazios.

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

> ⚠️ **O erro de permissão agora é MUDO.** A imagem passou a ativar o `php.ini-production`
> (ver o `Dockerfile`), e com ele vem `display_errors=Off`. Antes **nenhum php.ini estava
> carregado** — `php --ini` respondia `Loaded Configuration File: (none)` e valia o padrão
> compilado, `display_errors=On`. Erro que acontece **antes** de o Laravel instalar o próprio
> tratador (o caso clássico é justamente este: `bootstrap/cache` sem permissão de escrita)
> era impresso direto na resposta. Agora a mesma falha devolve **500 em branco** e a
> mensagem vai só para o log: `docker compose logs app`. É mais seguro — erro anterior ao boot do Laravel não vaza mais
> caminho de arquivo para o visitante — e é o primeiro lugar para olhar quando a tela vier
> vazia.

**O que mais mudou junto com o php.ini** (cada valor está comentado no `Dockerfile`, com a
regra do código que o justifica):

| Valor | Por quê |
|---|---|
| `upload_max_filesize = 8M` · `post_max_size = 12M` | O avatar é limitado a 2 MB pelo Laravel (`max:2048`), e o padrão do PHP era **também** 2M — empatado, sem folga para o overhead do multipart. Um arquivo no limite era recusado pelo PHP antes do Laravel, com `$_FILES` vazio, e a pessoa lia *"O campo foto é obrigatório"*. Agora quem recusa é sempre o Laravel, com a mensagem certa. |
| `expose_php = Off` | Tira o `X-Powered-By: PHP/8.4.x` de toda resposta. O `php.ini-production` deixa isso **ligado**. |
| `date.timezone` | `America/Sao_Paulo`, igual ao padrão de `config/app.php`. Vale para o que roda antes do Laravel subir; o fuso da **conexão** com o banco continua sendo o `DB_TIMEZONE`. |
| `variables_order = "EGPCS"` | O `php.ini-production` usa `GPCS`, que deixa `$_ENV` vazio. Hoje é inofensivo (o Dotenv escreve em `$_ENV` **e** `$_SERVER`), mas quebraria no dia em que a VPS injetar segredo pelo `environment:` do compose. |

**Confira depois do deploy:** `docker compose exec app php --ini`. Se a linha
`Loaded Configuration File` ainda disser `(none)`, a imagem em execução é a antiga — falta
um `docker compose build` (o `Dockerfile` mudou, e o bind mount do código não reconstrói a
imagem sozinho).

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

### 10. Migrations e caches de produção — **a ordem importa**

```bash
php artisan migrate --force   # ⚠️ ANTES dos caches — e depois do backup (item 11)
php artisan config:cache
php artisan route:cache
php artisan view:cache        # ⚠️ ANTES do build, sempre
npm run build
```

Rodando em Docker na VPS, cada linha de artisan vira
`docker compose exec -T app php artisan ...`.

`route:cache` falha se houver rota apontando para classe inexistente — é um bom teste de
sanidade antes de subir.

**Por que o `migrate` vem primeiro.** No instante em que o `config:cache` grava
`bootstrap/cache/config.php`, o Laravel **deixa de ler o `.env`**: dali em diante vale o
retrato que aquele comando tirou. E esse arquivo **fica no servidor entre deploys** — do
segundo deploy em diante você começa a janela com um config cache velho no disco. Se você
acabou de corrigir a senha do banco (item 6) e roda o `migrate` depois do `config:cache`,
ele vai para a configuração antiga: na melhor das hipóteses falha na autenticação; na pior,
funciona — no banco errado. Rodando antes de qualquer cache, o `migrate` lê o `.env` de
verdade, que é o único arquivo que você editou à mão.

O `--force` não é enfeite: fora do ambiente `local` o Artisan pede confirmação interativa, e
num script de deploy não há ninguém para responder. Sem ele o comando aborta sem migrar
nada — e o deploy continua, aparentemente bem-sucedido, com o código novo sobre o banco
velho.

> ⚠️ **Backup antes do migrate**, sempre (item 11). Migration não é reversível na prática: o
> `down()` desfaz a estrutura, nunca o dado que a estrutura levou junto. Use
> `php artisan migrate:status` para ver o que vai rodar **antes** de rodar.

**Por que `view:cache` vem antes do `npm run build`** (medido em 02/08/2026): o
`resources/css/app.css` declara

```css
@source '../../storage/framework/views/*.php';
```

ou seja, o Tailwind varre as views **já compiladas**, não os `.blade.php`. Num deploy
limpo esse diretório está vazio — e o Tailwind não tem como saber que faltou alguma coisa.
O build termina **verde**, com **~20 kB de CSS a menos** (110 kB em vez de 129 kB), e o
site sobe **sem parte dos estilos**. Falha silenciosa: nenhum erro, nenhum aviso.

Vale para toda máquina nova e para qualquer deploy que rode `view:clear` antes. Se
desconfiar que aconteceu, compare o tamanho do CSS gerado em `public/build/assets/`.

> O número saudável **cresce junto com o app** (06/08/2026: ~134 kB, depois do CSS do 2FA),
> então não trate "129 kB" como meta — compare com o último deploy que você sabe que ficou
> bom. O sinal de alarme é uma queda brusca, não o valor absoluto.

### 10.1. Apagar o `public/hot` — senão o app sobe sem CSS e sem JS

```bash
rm -f public/hot
```

**Por quê:** `public/hot` é um arquivo que o **Vite cria ao iniciar o `npm run dev`** e
apaga ao encerrar — com o endereço do servidor de desenvolvimento dentro. É por ele que a
directive `@vite` decide de onde puxar os assets, e a decisão é literalmente esta:

```php
// Illuminate\Foundation\Vite
public function isRunningHot()
{
    return is_file($this->hotFile());   // hotFile() = public_path('/hot')
}
```

**Não há checagem de ambiente.** Com `APP_ENV=production`, `APP_DEBUG=false` e o
`public/build` no lugar, basta o arquivo existir para todas as páginas apontarem `<script>`
e `<link>` para `http://127.0.0.1:5173` — que não existe na VPS. O resultado é o app inteiro
**sem estilo e sem JavaScript**, com erro só no console do navegador. E como o `public/hot`
está no `.gitignore`, ele não chega pelo `git pull`: ele aparece quando alguém roda
`npm run dev` **na própria VPS** para "testar rapidinho" e mata o processo com Ctrl+C num
momento em que o Vite não conseguiu limpar (ou fecha o terminal).

Por isso o `rm -f` entra no script de deploy, depois do `npm run build`: é barato, é
idempotente e cobre o caso de alguém ter mexido no servidor entre um deploy e outro. O
`.dockerignore` também lista o arquivo, para ele nunca entrar numa imagem.

> Sintoma para reconhecer sem investigar: o HTML servido tem `src="http://127.0.0.1:5173/..."`
> ou `/@vite/client`. Confira com `curl -s https://app.stabilmoney.com.br | grep 5173`.

### 10.2. Monitor no `/up` e manutenção durante o deploy

Aponte o monitor de disponibilidade (UptimeRobot, Better Stack, o que for) para:

```text
GET https://app.stabilmoney.com.br/up   → espera 200 com o corpo "ok"
```

**Por quê:** o `/up` é do app (`SaudeController`, 22/09/2026), não o do framework. Ele confere o
banco (`select 1`) e se a pasta do log aceita escrita, e responde em texto: `ok` (200),
`manutencao` (503) ou `indisponivel` (503) — o motivo da falha vai para o log, nunca para a
resposta. O do framework respondia 200 com o banco fora do ar, e carregava um script de CDN de
terceiro no domínio do app. Fica fora do grupo `web`: cada batida do monitor não abre sessão.

Durante o deploy, ponha o app em manutenção com prazo de volta:

```bash
php artisan down --retry=60   # a página 503 diz "Tente de novo em 1 minuto"
# ... migrate, caches, build (item 10) ...
php artisan up
```

> ⚠️ `--render=errors::503` sai **sem os cabeçalhos de segurança**: quem serve essa página é o
> `public/index.php`, antes de o framework subir. Use só se o próprio deploy puder quebrar o
> framework no meio; no caso comum, o `down` simples já mostra a página de manutenção do app.

### 10.3. Log diário, 180 dias

```env
LOG_STACK=daily
LOG_DAILY_DAYS=180
```

**Por quê:** com `single` (o padrão antigo) o `laravel.log` nunca gira e cresce até encher o
disco da VPS — e guarda para sempre dados pessoais que a Política de Privacidade promete manter
por "até 6 meses". Com `daily`, nasce um arquivo por dia e os que passaram de 180 dias são
apagados sozinhos. `storage/logs` precisa aceitar escrita do `www-data` (item 7): se não aceitar,
o `/up` responde 503 (item 10.2).

> ⚠️ Os logs do **Docker** (o de acesso do Apache vai para a saída do container) também não giram
> no driver padrão `json-file`. Configure `max-size`/`max-file` no `docker-compose` da VPS ou no
> `daemon.json` — senão o disco enche do mesmo jeito, por outro caminho.

### 11. Backup do banco — automático, testado e fora da VPS

Existem dois scripts prontos no repositório (feitos em 02/08/2026, depois do incidente de
perda de dados de 28/07 — ver `storage/app/backup-incidente-2026-07-28/`):

| Script | O que faz |
|---|---|
| `scripts/backup-db.sh` | `mysqldump` do container `db` → `.sql.gz` datado, com rotação. **Confere o dump depois de gravar** e descarta o arquivo se não achar nenhum `CREATE TABLE`. |
| `scripts/restore-db.sh` | Restaura um backup. Valida o arquivo **antes** de apagar qualquer coisa, exige que você digite o nome do banco, e tira um backup de segurança do estado atual antes de sobrescrever. |

```bash
./scripts/backup-db.sh                    # backup em storage/backups/, guarda os 14 últimos
./scripts/backup-db.sh --manter 30        # muda a retenção
./scripts/backup-db.sh --saida /mnt/hd    # grava noutro lugar (disco externo, volume montado)

./scripts/restore-db.sh                            # restaura o backup mais recente
./scripts/restore-db.sh caminho/do/arquivo.sql.gz  # restaura um específico
./scripts/restore-db.sh arq.sql.gz --banco stabil_ensaio   # ENSAIO: restaura noutro banco
```

**A senha do banco nunca vai para a linha de comando** (`ps` do host expõe a linha de
comando de qualquer processo, e é por isso que `mysqldump -p$SENHA` está errado). Os
scripts leem o `.env` e escrevem um `my.cnf` temporário, via STDIN, com `umask 077` dentro
do container — apagado no final até se o script morrer no meio.

**Cron na VPS** (backup diário às 3h, log próprio):

```cron
0 3 * * * cd /caminho/do/projeto && ./scripts/backup-db.sh >> /var/log/stabilmoney-backup.log 2>&1
```

**Periodicidade sugerida:** diária enquanto for só o Victor; de hora em hora quando houver
usuários reais (um lançamento perdido é um lançamento que ninguém lembra de refazer).
Retenção de 14 dias é o padrão do script — suficiente para perceber um erro que só
aparece dias depois.

**Onde guardar — fora da VPS, obrigatoriamente.** Backup que mora no mesmo servidor não
protege contra o que mais acontece: o servidor sumir. Depois de gerar, copie para fora.
Qualquer uma destas serve:

```bash
# 1. Puxar do seu computador (mais simples, nada a configurar no servidor)
rsync -az usuario@vps:/caminho/do/projeto/storage/backups/ ~/Backups/stabilmoney/

# 2. Empurrar para um bucket S3/R2/Backblaze com o rclone
rclone copy storage/backups/ remoto:stabilmoney-backups --max-age 25h
```

> ⚠️ **Teste a restauração antes de precisar dela.** Rode
> `./scripts/restore-db.sh <arquivo> --banco stabil_ensaio` uma vez: restaura num banco de
> lado, sem tocar no de produção, e prova que o arquivo presta. Backup nunca testado é
> só um arquivo grande.

> ⚠️ O diretório `storage/backups/` contém **dados pessoais de todos os usuários**, em
> texto legível depois de descompactar. Os arquivos nascem com permissão `600` e o
> diretório com `700`, e o script grava um `.gitignore` com `*` lá dentro na primeira
> execução — um `git add .` distraído não alcança os dumps. Ainda assim, acrescente
> **`/storage/backups`** ao `.gitignore` da raiz: é a linha que documenta a intenção para
> quem chegar depois.

### 12. Cron do agendador (`schedule:run`)

O `routes/console.php` tem tarefas agendadas — a limpeza diária das sessões expiradas
(`sessoes:limpar`, item da tabela verde abaixo) e os **lembretes de vencimento por e-mail**
(`lembretes:vencimentos`, 08:00 — sem o cron ninguém recebe aviso de fatura). **Nada disso roda sozinho.** O scheduler
do Laravel depende de uma única entrada de cron que acorda o artisan a cada minuto:

```cron
* * * * * cd /caminho/do/projeto && php artisan schedule:run >> /dev/null 2>&1
```

Rodando em Docker na VPS, o `cd` é no host e o comando vira
`docker compose exec -T app php artisan schedule:run`.

Confira o que está agendado com `php artisan schedule:list` — se a saída vier vazia depois
de um deploy, provavelmente sobrou um `config:cache` velho.

---

## 🟡 Antes de abrir o cadastro para desconhecidos

Eram os itens da "onda 3" do pentest: dependiam de infraestrutura ou de decisão de produto,
não de correção pontual. **Quatro dos cinco já foram implementados** (14, 15 e 16 em
02/08/2026; 13 em 06/08/2026) e ficam aqui como registro do que foi decidido — o único que
sobra é o **17**, que espera advogado.

### ~~13. Verificação de e-mail~~ — ✅ **Feito** (06/08/2026)

`routes/web.php` usa `['auth', 'verified']`: quem não confirmou o e-mail é mandado para a
tela de confirmação e não entra no app. Isso fecha o buraco de se cadastrar com o e-mail de
**outra pessoa** — como o "esqueci a senha" manda o link para aquele endereço, o dono do
e-mail "recuperava" a conta e via os lançamentos de quem a criou.

**Por que isto não tranca ninguém, mesmo com o item 5 pendente:** quem cria usuário só deixa
`email_verified_at` nulo quando o app CONSEGUE enviar o link. Sem entrega, o cadastro grava a
data no ato, o dependente nasce verificado e a troca de e-mail no perfil também. O middleware
fica **inerte** enquanto não houver mailer e passa a valer sozinho no dia em que houver.

Duas coisas entraram junto, e sem elas isto seria uma armadilha:

- **`ProfileController` corrigido.** Ele zerava `email_verified_at` ao trocar o e-mail sem
  mailer — criando uma conta que **nenhum link destrava**. Inofensivo enquanto `verified` não
  existia; conta perdida no dia seguinte. Agora o endereço novo nasce verificado nesse
  cenário, como no cadastro.
- **Migration `2026_08_06_000000_backfill_email_verified_at_antes_do_middleware`**, que roda
  no mesmo deploy e preenche quem tiver ficado com a coluna nula pelo defeito acima.

> ⚠️ Daqui em diante, `email_verified_at` nulo é estado **legítimo** (usuário novo que ainda
> não clicou no link), e não mais acidente para consertar em massa. Não repita o backfill.

> ⚠️ Rota que precise funcionar ANTES da confirmação (reenviar o link, sair da conta) vai em
> `routes/auth.php`, fora do grupo protegido — senão a tela que destrava a conta fica ela
> própria trancada.

### ~~14. Confirmar a troca de e-mail~~ — ✅ **Feito** (02/08/2026)

Trocar o e-mail no perfil exige a senha atual (onda 2) **e** confirmação no endereço novo: o
`ProfileController` guarda o valor em `users.pending_email` e só promove a `email` quando o
link assinado enviado **àquele** endereço for clicado (rota `profile.email.confirm`, sob
`signed`). Coberto por `TrocaDeEmailConfirmadaTest`.

> Enquanto `MAIL_MAILER=log`, o link não sai — nesse cenário a troca é aplicada direto (e
> `email_verified_at` **volta a nulo**), para não deixar o usuário sem poder corrigir o
> próprio e-mail. Com o item 5 resolvido, a confirmação em duas etapas passa a valer
> sozinha, sem mudar código: o `ProfileController` decide pelo `Mailer::entrega()`.

### ~~15. Avatares fora do disco público~~ — ✅ **Feito** (02/08/2026)

As fotos gravam no disco **`local` (privado)** — `User::AVATAR_DISK` — e são servidas pela
rota autenticada `GET /avatar/{user}` (`AvatarController`), que valida se quem pede é da
**mesma família** do dono. `User::avatarUrl()` aponta para a rota, não para o arquivo. A
migration `2026_08_02_000000_move_avatars_to_private_disk` moveu os arquivos que já existiam.
Coberto por `AvatarPrivadoTest`.

### ~~16. Limpar a fila offline na troca de usuário~~ — ✅ **Feito** (02/08/2026)

Resolvido, mas **não** como este item propunha. Apagar o object store no logout destruiria
lançamentos que nunca chegaram ao servidor — resolveria o vazamento destruindo dinheiro do
usuário. O que ficou:

- o item de outro dono é **desarmado, não apagado**: o `csrf` dele é removido, e é justamente
  por `i.csrf` que o service worker filtra o que pode reenviar;
- o lançamento continua na fila, com um banner avisando, e o descarte exige confirmação.

Coberto por `FilaOfflineNaTrocaDeUsuarioTest`.

> ⚠️ Não use bump de versão do IndexedDB nem registro-marcador para isso: os dois quebram
> `tests/e2e/offline-lancamento.spec.js`.

> O logout manda `Clear-Site-Data: "cache"`, mas isso limpa só o **cache HTTP** — ⚠️ **não** o
> Cache Storage do service worker, onde fica o `/transactions/create` com contas, categorias e
> família. Quem apaga essa página é o **próprio service worker** (`HTML_AUTENTICADO` em
> `resources/views/pwa/service-worker.blade.php`), ao ver o `POST /logout` passar ou o `/login`
> responder 200 (P-2, 16/09/2026). Não incluímos `"storage"` de propósito: apagaria a fila
> offline, destruiria lançamentos não sincronizados e desregistraria o service worker.
>
> **Confira depois do deploy, no Chrome (DevTools › Application):** entre, abra "Nova
> transação", clique em Sair e atualize a lista do Cache Storage `sm-cache-v2` — a entrada
> `/transactions/create` tem de ter sumido, e o IndexedDB `sm-offline` tem de continuar lá.

### 17. Revisão jurídica dos Termos e da Política

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
| ~~2FA~~ | ~~tela de Segurança~~ | ✅ **Feito** (05/08/2026): verificação em duas etapas por app autenticador (TOTP), **opcional**, ligada pelo próprio usuário em Configurações › Segurança. Ver a seção "🔐 Verificação em duas etapas" no CLAUDE.md. ⚠️ Depende da `APP_KEY`: rotacioná-la sem `APP_PREVIOUS_KEYS` deixa o segredo ilegível e tranca fora quem tiver 2FA ligado. |
| `is_admin`/`account_owner_id` fora de `$fillable` | `app/Models/User.php` | Não é explorável hoje (nenhum `update($request->all())`), mas é armadilha para o futuro: um único uso descuidado viraria escalada de privilégio. |
| Retenção/criptografia de IP | `sessions`, `terms_accepted_ip` | Se quiser proteger IPs em repouso, use o cast `encrypted` (reversível). **Não** use hash — quebraria a tela de dispositivos e a prova do aceite. |
| ~~Rotina de limpeza de sessões~~ | ~~agendador~~ | ✅ **Feito** (02/08/2026): `php artisan sessoes:limpar`, agendado às 3h10 em `routes/console.php`. Só falta o cron do item 12. Cuidado: `session:prune` **não existe** no Laravel 12 — o framework limpa por loteria (2% das requisições), o que num app de pouco tráfego deixa IP e user-agent parados na tabela por meses. |

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
