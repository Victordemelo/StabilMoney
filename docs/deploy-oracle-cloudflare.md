# Publicar o Stabil Money na VPS (Oracle Cloud + Cloudflare)

Passo a passo para colocar o app em **https://stabilmoney.victordemelo.com.br**, na VPS que já
serve o portfólio. Cada passo diz **o que fazer** e **por quê** — o porquê é o que evita repetir um
erro na próxima vez. O [`docs/checklist-de-publicacao.md`](checklist-de-publicacao.md) continua
sendo a lista do que não pode faltar; este guia é o COMO, nesta infraestrutura.

> Os comandos com `sudo` rodam como você mesmo (o usuário de SSH da VPS). O `scripts/deploy.sh`
> roda **sem** `sudo`, como o dono da pasta do projeto.

---

## 0. O que já existe na VPS (e não muda)

| Peça | Como está |
|---|---|
| Sistema | Ubuntu 24.04, processador Ampere (ARM64 / aarch64) |
| Firewall | Só 22, 80 e 443 abertas (Security List da Oracle + iptables) |
| DNS e proxy | Cloudflare (plano Free), SSL/TLS em **Full (strict)** |
| HTTPS | **Exclusivo do nginx do host + Certbot** (Let's Encrypt, renovação automática) |
| IP real | O nginx já usa o `CF-Connecting-IP` para o portfólio |
| Apps | Cada um em `/opt/apps/<nome>`, em container próprio, publicado **só em 127.0.0.1** |
| Portfólio | `/opt/apps/victordemelo`, em `127.0.0.1:8080` |

O Stabil Money entra do mesmo jeito:

```
visitante → Cloudflare → nginx do host (:443, Let's Encrypt)
          → 127.0.0.1:8081 → container `app` (Apache + PHP) → container `db` (MySQL)
```

- **Porta 8081**, porque a 8080 é do portfólio. Nada novo no firewall: tudo passa pelo nginx.
- **Pasta `/opt/apps/stabilmoney`.**
- **O mesmo comando de sempre** para subir (`docker compose up -d --build`) — mas, para publicar
  uma versão nova, use o `scripts/deploy.sh` (passo 7 explica a diferença).

---

## 1. Cloudflare — o endereço

No painel da Cloudflare, zona **victordemelo.com.br**:

1. **DNS › Records › Add record**
   - Type **A**, Name **`stabilmoney`**, IPv4 = o IP público da VPS (o mesmo do registro do
     portfólio), **Proxy status: Proxied** (nuvem laranja).
   - Se a VPS tiver IPv6 e o portfólio tiver um registro AAAA, crie o AAAA do `stabilmoney` também.
2. **SSL/TLS** — já está em **Full (strict)** para a zona inteira: a Cloudflare só aceita falar
   com a VPS se o certificado dela for válido. É por isso que o certificado (passo 4) vem antes de abrir o site.
3. **Desligue o que reescreve a página** (a CSP do app bloqueia o que for injetado, e a tela
   quebra em silêncio — só o console do navegador acusa):
   - **Speed › Optimization › Content Optimization › Rocket Loader: Off.** Ele reescreve as
     tags `<script>`; os scripts do app levam um *nonce* que a reescrita descarta.
   - **Security › Settings (ou Scrape Shield) › Email Address Obfuscation: Off.** Injeta um
     script que a CSP bloqueia — e os e-mails nas páginas legais viram texto quebrado.
   - **Bot Fight Mode**: se estiver ligado para o portfólio, pode desafiar o *service worker* e
     a sincronização offline do app (que não têm como resolver um desafio de navegador). Se o
     app mostrar lançamentos presos na fila, é o primeiro suspeito.
4. **Cache** — nada a fazer. A Cloudflare não guarda HTML por padrão, o app responde
   `Cache-Control: no-cache, private` no `/sw.js` e nas páginas, e os arquivos de `/build/` têm
   o hash da versão no nome (pode guardar à vontade).

> A propagação do registro novo costuma levar minutos. Confira com
> `dig +short stabilmoney.victordemelo.com.br` (deve responder IPs da Cloudflare, não o da VPS —
> é o proxy funcionando).

---

## 2. O código na VPS

### 2.1 Deploy Key (acesso SÓ de leitura ao repositório privado)

Na VPS, como o seu usuário:

```bash
ssh-keygen -t ed25519 -C "vps-stabilmoney" -f ~/.ssh/deploy_stabilmoney -N ""
cat ~/.ssh/deploy_stabilmoney.pub
```

No GitHub: **Victordemelo/StabilMoney › Settings › Deploy keys › Add deploy key** — cole a chave
pública, **deixe "Allow write access" desmarcado**. (O GitHub não deixa usar a mesma deploy key
em dois repositórios; por isso uma chave própria, separada da do portfólio.)

Ensine o SSH a usar essa chave só para este repositório — acrescente ao `~/.ssh/config`:

```
Host github-stabilmoney
    HostName github.com
    User git
    IdentityFile ~/.ssh/deploy_stabilmoney
    IdentitiesOnly yes
```

### 2.2 Clonar

```bash
sudo mkdir -p /opt/apps/stabilmoney
sudo chown "$USER": /opt/apps/stabilmoney
git clone git@github-stabilmoney:Victordemelo/StabilMoney.git /opt/apps/stabilmoney
cd /opt/apps/stabilmoney
```

Seu usuário precisa estar no grupo `docker` (o do portfólio já está, se você sobe o portfólio
sem `sudo`). Confira com `groups | grep docker`.

---

## 3. O `.env` de produção

```bash
cd /opt/apps/stabilmoney
cp .env.example .env
chmod 600 .env
nano .env
```

Troque ou acrescente **estas** chaves (o resto do `.env.example` fica como está):

```ini
APP_NAME=StabilMoney
APP_ENV=production
APP_DEBUG=false
APP_URL=https://stabilmoney.victordemelo.com.br
# gerada abaixo, no servidor — NUNCA copie a do seu computador
APP_KEY=

LOG_STACK=daily
LOG_DAILY_DAYS=180

DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=stabilmoney
DB_USERNAME=stabilmoney
# as duas geradas abaixo, DIFERENTES entre si
DB_PASSWORD=
DB_ROOT_PASSWORD=

SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=null

TRUSTED_PROXIES=172.16.80.1
COMPOSE_FILE=docker-compose.prod.yml
HTTP_PORT=8081

ADMIN_PANEL_ENABLED=false
```

Gere a chave e as senhas **no servidor** e cole os valores no `.env`:

```bash
echo "APP_KEY=base64:$(openssl rand -base64 32)"
openssl rand -hex 32     # DB_PASSWORD
openssl rand -hex 32     # DB_ROOT_PASSWORD
```

**Por que cada uma importa:**

- **`APP_DEBUG=false`** — com `true`, qualquer erro mostra na tela as senhas do `.env`.
- **`APP_URL`** — é o **único endereço que o app aceita** em produção (qualquer outro Host recebe
  400) e a raiz de **todo link que ele gera**, inclusive o do e-mail de "esqueci a senha". Sem isso,
  alguém pediria a redefinição da senha de outra pessoa com um endereço falso, e a vítima receberia
  um link para o site do atacante com o código dentro.
- **`APP_KEY`** — cifra as sessões, o 2FA e a prova do aceite dos termos. Gerada no servidor e
  guardada: **trocar depois tranca todo mundo que tem 2FA** (só os códigos de recuperação salvam).
- **Senhas só com letras e números** (`openssl rand -hex 32`): o `docker compose` lê este mesmo
  `.env` e troca `$ALGO` pelo valor de outra variável — uma senha com `$` chegaria cortada ao
  MySQL, inteira ao Laravel, e o app não conectaria.
- **`TRUSTED_PROXIES=172.16.80.1`** — o gateway fixo da rede do `docker-compose.prod.yml`, por
  onde o nginx chega ao container. Sem ele, o app enxerga todo mundo com o MESMO IP: os limites de
  tentativa por IP (login, cadastro, "esqueci a senha") virariam um limite para o site inteiro, e
  o sexto cadastro do minuto, de qualquer pessoa, levaria "muitas tentativas". Não use `*` (o app
  recusa: deixaria qualquer um escolher o próprio IP).
- **`COMPOSE_FILE=docker-compose.prod.yml`** — faz o `docker compose` desta pasta usar o compose
  de PRODUÇÃO sem você digitar `-f`. O de desenvolvimento (`docker-compose.yml`) publica a 8001
  aberta para a rede (de propósito, para testar pelo celular no Wi-Fi) e sobe uma caixa de e-mail
  falsa — nunca pode subir aqui.
- **`HTTP_PORT=8081`** — a porta do app, só em 127.0.0.1. Mudou? Mude o `proxy_pass` do nginx junto.
- **E-mail** — ver o passo 9 (ainda falta escolher o provedor).

O `scripts/deploy.sh` confere tudo isso antes de publicar e se recusa a continuar se algo estiver
errado — a lista inteira de conferências está em `scripts/lib/deploy-comum.sh`.

---

## 4. Certificado (Let's Encrypt, como o do portfólio)

O arquivo do site aponta para o certificado em `/etc/letsencrypt/live/stabilmoney.victordemelo.com.br/`.
Na primeira vez ele **ainda não existe** — e com um certificado que não existe o `sudo nginx -t`
recusa a configuração INTEIRA, derrubando o portfólio junto no próximo reload. Por isso a primeira
emissão é em dois tempos: primeiro um arquivo só com a porta 80, depois o de verdade.

```bash
cd /opt/apps/stabilmoney

# 1) o arquivo temporário, só porta 80
sudo cp deploy/nginx/stabilmoney-emitir-certificado.conf /etc/nginx/sites-available/stabilmoney.victordemelo.com.br
sudo ln -sf /etc/nginx/sites-available/stabilmoney.victordemelo.com.br /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

# 2) emitir — `certonly`: o Certbot só obtém o certificado e NÃO edita o arquivo do site
sudo certbot certonly --nginx -d stabilmoney.victordemelo.com.br \
     --deploy-hook "systemctl reload nginx"
```

- **`certonly`, e não `certbot --nginx`:** o modo sem `certonly` reescreve o arquivo do site e
  acrescenta a configuração SSL dele, que duplicaria `ssl_protocols`/`ssl_session_cache` do nosso
  — e o `nginx -t` passaria a recusar. O nosso arquivo é a verdade; o Certbot só entrega o certificado.
- **`--deploy-hook`**: a cada renovação automática o nginx é recarregado e passa a usar o
  certificado novo. Sem isso ele continuaria com o velho até vencer.
- **Se a validação falhar** (a Cloudflare na frente às vezes atrapalha o desafio HTTP-01): mude o
  registro `stabilmoney` para **DNS only** (nuvem cinza), rode o `certbot certonly` de novo e volte
  para **Proxied**. É provavelmente o que foi feito para o portfólio.

Confira a renovação: `sudo certbot renew --dry-run`.

---

## 5. nginx

### 5.1 O IP real do visitante e "só pela Cloudflare"

```bash
cd /opt/apps/stabilmoney
sudo nginx -T 2>/dev/null | grep -n real_ip          # o que o portfólio já tem
sudo cp deploy/nginx/cloudflare-ip-real.conf /etc/nginx/snippets/cloudflare-ip-real.conf
sudo cp deploy/nginx/cloudflare-origem.conf  /etc/nginx/conf.d/cloudflare-origem.conf
```

- `snippets/cloudflare-ip-real.conf` é incluído DENTRO do server do Stabil Money. No contexto do
  server ele nunca conflita com o real_ip que o portfólio já tenha em outro lugar.
- `conf.d/cloudflare-origem.conf` define `$stabilmoney_via_cloudflare`, que o site usa para
  **fechar a conexão de quem fala direto com o IP da VPS**, pulando a Cloudflare. Precisa ficar em
  `conf.d/` (contexto `http`) — dentro de um `server` o nginx recusa o `geo`.

### 5.2 O servidor padrão (Host desconhecido não recebe nada)

```bash
sudo nginx -T 2>/dev/null | grep -n default_server
```

- **Só pode existir UM `default_server` por porta.** Se aparecer o `default` do Ubuntu (a página
  "Welcome to nginx!"), remova o link dele: `sudo rm /etc/nginx/sites-enabled/default`. Se for o
  do portfólio, tire o `default_server` de lá (ele tem o próprio `server_name` e não precisa ser o
  padrão).
- Depois:

```bash
sudo cp deploy/nginx/00-host-desconhecido.conf /etc/nginx/sites-available/00-host-desconhecido
sudo ln -sf /etc/nginx/sites-available/00-host-desconhecido /etc/nginx/sites-enabled/
```

Sem um padrão explícito, o nginx entregaria um Host desconhecido ao PRIMEIRO site da porta — em
ordem alfabética, `stabilmoney…` vem antes de `victordemelo…`. Com ele, quem chega pelo IP ou com
um Host falso leva a conexão fechada (444), e o TLS nem começa.

### 5.3 O site de verdade

```bash
sudo cp deploy/nginx/stabilmoney.victordemelo.com.br.conf /etc/nginx/sites-available/stabilmoney.victordemelo.com.br
sudo nginx -t && sudo systemctl reload nginx
```

(O link em `sites-enabled` já existe desde o passo 4.) Mudou algo no arquivo? Mude no
repositório, copie de novo e repita o `nginx -t` + `reload` — o arquivo da VPS nunca é editado à mão.

### 5.4 As faixas da Cloudflare, atualizadas sozinhas

As faixas de IP da Cloudflare mudam raramente, mas mudam; uma que faltar vira visitante barrado.

```bash
sudo install -m 0755 deploy/nginx/atualizar-ips-cloudflare.sh /usr/local/sbin/stabilmoney-atualizar-ips-cloudflare
sudo /usr/local/sbin/stabilmoney-atualizar-ips-cloudflare      # confere agora
sudo crontab -e
```

Na crontab do root, uma vez por mês:

```cron
17 4 1 * * /usr/local/sbin/stabilmoney-atualizar-ips-cloudflare >> /var/log/stabilmoney-ips-cloudflare.log 2>&1
```

O script só troca os arquivos se a lista baixada for válida, e desfaz a troca se o `nginx -t` reprovar.

---

## 6. Primeiro deploy

```bash
cd /opt/apps/stabilmoney
bash scripts/deploy.sh --primeiro-deploy
```

Ele confere o `.env` e as portas, sobe o banco e o app (`docker compose up -d --build` — a
primeira vez demora: a imagem é construída no ARM), instala as dependências, ajusta as
permissões de `storage/` sem `chmod 777`, cria as tabelas, gera os caches, constrói o CSS/JS num
container Node (a VPS não precisa de Node) e confere o `/up` no fim. O `--primeiro-deploy` só
pula o backup — não há banco para salvar ainda.

**Confira:**

```bash
curl -sI https://stabilmoney.victordemelo.com.br/up        # 200, com Strict-Transport-Security
curl -s  https://stabilmoney.victordemelo.com.br/robots.txt # aponta o sitemap
docker compose ps                                           # app, agendador e db de pé
```

E no navegador: crie sua conta, entre, e abra **Configurações › Segurança › Sessões** — o IP
mostrado tem de ser o SEU (se aparecer `172.16.80.1`, o `TRUSTED_PROXIES` está errado).

**Painel administrativo** (desligado por padrão): crie o admin quando precisar e ligue com
`ADMIN_PANEL_ENABLED=true` + `bash scripts/deploy.sh --sem-git`. Perdeu o celular e os códigos? `admin:zerar-2fa`.

```bash
docker compose exec -u www-data app php artisan admin:criar
```

> **Todo `php artisan` na VPS leva `-u www-data`** (o usuário do Apache). Rodado como root, um
> comando pode criar o log do dia como root — e aí o site passa a dar erro 500 ao tentar escrever
> nele. O `scripts/deploy.sh` já faz assim.

---

## 7. Os próximos deploys

```bash
cd /opt/apps/stabilmoney && bash scripts/deploy.sh
```

**Por que não só `git pull && docker compose up -d --build`, como no portfólio?** O portfólio é
um site estático: o build acontece dentro da imagem. Aqui a pasta do projeto é montada no
container — o `git pull` põe o código novo no ar NA HORA, mas nada do resto acompanha:

| Sem o script | O que acontece |
|---|---|
| `composer.lock` mudou e ninguém rodou o `composer install` | "Class not found" |
| migration nova e ninguém rodou o `migrate` | erro de SQL nas telas novas |
| caches do deploy anterior | rota nova dá 404; config nova não vale |
| assets sem rebuild | tela nova sem estilo, ou erro 500 por arquivo que falta no manifest |

O script faz esses passos na ordem certa, com **backup do banco antes** e o **app em manutenção
durante** (o `/up` responde 503 "manutencao"). Rodar o comando genérico antes não estraga nada —
o script só não terá o que puxar.

- Mexeu só no `.env`? `bash scripts/deploy.sh --sem-git` (o `config:cache` precisa ser refeito).
- Voltar uma versão: `git checkout <commit>` e `bash scripts/deploy.sh --sem-git`.
- Se algo falhar no meio, o app **fica em manutenção** (o lado seguro) e a mensagem final diz o
  que fazer. Rodar de novo é seguro.

---

## 8. Backups

O backup diário é o item 11 do checklist. Na VPS, na crontab do SEU usuário (`crontab -e`):

```cron
PATH=/usr/local/bin:/usr/bin:/bin
0 3 * * * cd /opt/apps/stabilmoney && ./scripts/backup-db.sh >> "$HOME/stabilmoney-backup.log" 2>&1
```

Os scripts usam o compose de produção sozinhos (pelo `COMPOSE_FILE` do `.env`). E **backup que só
existe na VPS não é backup**: o checklist (item 11) mostra como puxar uma cópia para fora dela e
como provar que um backup restaura (`./scripts/restore-db.sh --ensaio`).

O agendador do Laravel (limpeza das sessões, lembretes de vencimento) **não precisa de cron**: é
o serviço `agendador` do compose, que sobe junto com o app.

---

## 9. E-mail (falta escolher o provedor)

O app manda e-mail sozinho: confirmação de cadastro, "esqueci a senha", alertas de segurança e
lembretes de vencimento. Hoje sai de uma caixa de TESTE. Em produção, o remetente precisa ser do
seu domínio (ex.: `nao-responda@victordemelo.com.br`), senão Gmail e Outlook mandam para o spam.

- **A Cloudflare não envia e-mail** (o Email Routing dela só recebe e encaminha). É preciso um
  serviço de envio, que te dá os registros **SPF, DKIM e DMARC** para criar no DNS da Cloudflare —
  eles provam que aquele serviço pode enviar em nome do domínio.
- Depois, no `.env`: `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME` (**465 → `smtps`**, **587 → `smtp`**
  — trocados, a conexão morre sem mensagem útil), `MAIL_USERNAME`, `MAIL_PASSWORD`,
  `MAIL_FROM_ADDRESS`, e `bash scripts/deploy.sh --sem-git`.

---

## 10. Google (Search Console)

Com o site no ar:

1. [search.google.com/search-console](https://search.google.com/search-console) › **Adicionar
   propriedade › Domínio** › `victordemelo.com.br` (cobre o portfólio e o app de uma vez).
2. O Google mostra um registro **TXT**: crie-o no DNS da Cloudflare (Type TXT, Name `@`).
3. Verificado, em **Sitemaps** envie `https://stabilmoney.victordemelo.com.br/sitemap.xml`.

Só o login, o cadastro, os Termos e a Privacidade vão para o Google; todo o resto do app sai com
`noindex` (ver "🔎 SEO" no CLAUDE.md).

---

## 11. Quando algo dá errado

| Sintoma | Causa provável |
|---|---|
| **400 Bad Request** em tudo | `APP_URL` diferente do endereço aberto (o app só aceita o Host dele) |
| Todo mundo com o **mesmo IP** na tela de sessões; "muitas tentativas" para quem nem tentou | `TRUSTED_PROXIES` ausente ou errado |
| Conexão **fechada sem resposta** | acesso direto ao IP da VPS (ou registro em "DNS only") — de propósito |
| **502 Bad Gateway** | o app não está de pé: `docker compose ps` e `docker compose logs app` |
| **526** (página da Cloudflare) | certificado da VPS inválido ou vencido: `sudo certbot certificates` |
| Site **sem CSS** | sobrou um `public/hot` ou o build falhou — rode `bash scripts/deploy.sh --sem-git` |
| App não conecta no banco depois de trocar senha | `$` na senha (ver passo 3), ou o MySQL só cria o usuário no PRIMEIRO boot: trocar depois exige `ALTER USER` (checklist, item 6) |

---

## 12. O que ainda não é código (antes de abrir para o público)

- **Política de Privacidade:** ela lista os operadores e a transferência internacional de dados.
  A Oracle Cloud (com a região da VPS) e a Cloudflare precisam entrar — e o `legal.version` sobe
  junto (`config/legal.php`).
- **Revisão jurídica** dos Termos e da Política (checklist, item 17): com o cadastro aberto ao
  público desde o primeiro dia, é um bloqueador.
