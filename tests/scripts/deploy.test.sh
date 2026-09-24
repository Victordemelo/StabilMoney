#!/usr/bin/env bash
#
# Testes do scripts/deploy.sh (o deploy na VPS). Rodam em qualquer máquina — Mac
# (bash 3.2, ferramentas BSD) ou Linux (bash 5, GNU) — SEM Docker e SEM servidor:
#
#   bash tests/scripts/deploy.test.sh
#
# Três camadas:
#
#   1. UNIDADE — as funções de scripts/lib/deploy-comum.sh: a leitura do
#      `docker compose config` (porta fora de 127.0.0.1, network_mode: host, o
#      gateway da rede) e a conferência do .env de produção.
#
#   2. CONTRATOS — os arquivos do pacote de deploy concordam entre si: o gateway do
#      docker-compose.prod.yml é o TRUSTED_PROXIES documentado, a porta do compose é
#      a do proxy_pass do nginx, o limite de corpo do nginx é o post_max_size do PHP.
#      Com um docker de verdade no PATH (o CI tem), também renderiza os dois compose.
#
#   3. PONTA A PONTA — o deploy.sh DE VERDADE, com git de verdade (um "GitHub" local)
#      e `docker`/`curl` falsos na frente do PATH (tests/scripts/docker-falso-deploy.sh
#      e curl-falso.sh), que registram cada operação. É isso que prova a ORDEM — backup
#      antes da manutenção, código novo só depois dela, view:cache antes do build — e
#      que nada muda quando uma conferência falha.
#
# SM_SCRIPTS_SOB_TESTE=<dir> roda as camadas 1 e 3 contra OUTRA cópia de scripts/
# (com lib/), para provar que os testes reprovam uma versão quebrada.

set -u

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
SOB_TESTE="$(cd "${SM_SCRIPTS_SOB_TESTE:-$REPO/scripts}" && pwd)"
T="$(mktemp -d "${TMPDIR:-/tmp}/sm-teste-deploy.XXXXXX")"
trap 'rm -rf "$T"' EXIT

# O docker de verdade (se houver) — só a camada 2 o usa, e só para RENDERIZAR arquivos
# (`docker compose config` não cria nada). A camada 3 nunca o alcança.
DOCKER_DE_VERDADE="$(command -v docker 2> /dev/null || true)"

PASSOU=0
FALHOU=0
LISTA_FALHAS=""

ok() {
  PASSOU=$((PASSOU + 1))
  printf 'ok      %s\n' "$1"
}
falhou() {
  FALHOU=$((FALHOU + 1))
  LISTA_FALHAS="$LISTA_FALHAS
  - $1"
  printf 'FALHOU  %s\n' "$1"
  if [ -n "${2-}" ]; then printf '%s\n' "$2" | sed -n '1,14p' | sed 's/^/          | /'; fi
}
afirmar() {
  local descricao="$1"
  shift
  if "$@"; then ok "$descricao"; else falhou "$descricao" "${DETALHE-}"; fi
  DETALHE=""
}
DETALHE=""

contem() { case "$1" in *"$2"*) return 0 ;; esac; return 1; }

# ======================================================================
# 1. UNIDADE — scripts/lib/deploy-comum.sh
# ======================================================================

echo "# unidade: $SOB_TESTE/lib/deploy-comum.sh"
# shellcheck source=../../scripts/lib/backup-comum.sh
. "$SOB_TESTE/lib/backup-comum.sh"
# shellcheck source=../../scripts/lib/deploy-comum.sh
. "$SOB_TESTE/lib/deploy-comum.sh"

# O que o `docker compose config` imprime para o docker-compose.prod.yml (capturado do
# Compose v2 em 23/09/2026, encurtado), com as seções x-* que ele também repete no fim.
CONFIG_PROD='name: stabilmoney
services:
  agendador:
    command:
      - php
      - artisan
      - schedule:work
    image: stabilmoney-app:producao
    user: www-data
    volumes:
      - type: bind
        source: /opt/apps/stabilmoney
        target: /var/www/html
        bind: {}
  app:
    image: stabilmoney-app:producao
    networks:
      default: null
    ports:
      - mode: ingress
        host_ip: 127.0.0.1
        target: 80
        published: "8081"
        protocol: tcp
    restart: unless-stopped
  db:
    healthcheck:
      test:
        - CMD
        - mysqladmin
    image: mysql:8.0
networks:
  default:
    name: stabilmoney_default
    ipam:
      config:
        - subnet: 172.16.80.0/24
          gateway: 172.16.80.1
volumes:
  db_data:
    name: stabilmoney_db_data
x-app:
  image: stabilmoney-app:producao
  ports:
    - mode: ingress
      target: 9999
      published: "9999"'

# O do docker-compose.yml de DESENVOLVIMENTO: a 8001 em todas as interfaces (de
# propósito, para o celular no Wi-Fi) — exatamente o que não pode subir na VPS.
CONFIG_DEV='name: stabilmoney
services:
  app:
    container_name: stabilmoney_app
    ports:
      - mode: ingress
        target: 80
        published: "8001"
        protocol: tcp
  db:
    ports:
      - mode: ingress
        host_ip: 127.0.0.1
        target: 3306
        published: "3307"
        protocol: tcp
  mailpit:
    ports:
      - mode: ingress
        host_ip: 127.0.0.1
        target: 8025
        published: "8026"
        protocol: tcp
networks:
  default:
    name: stabilmoney_default'

abertas_de() { printf '%s\n' "$1" | sm_portas_fora_do_loopback; }

prod_sem_porta_aberta() {
  local r
  r="$(abertas_de "$CONFIG_PROD")"
  [ -z "$r" ] || { DETALHE="$r"; return 1; }
}
afirmar "compose de produção: nenhuma porta fora de 127.0.0.1 (e as seções x-* são ignoradas)" prod_sem_porta_aberta

dev_acusado() {
  local r
  r="$(abertas_de "$CONFIG_DEV")"
  [ "$r" = "app 0.0.0.0:8001 -> 80" ] || { DETALHE="acusou: '$r'"; return 1; }
}
afirmar "compose de dev: acusa a 8001 do app em todas as interfaces (e só ela)" dev_acusado

acusa() { # <descrição do caso> <config> <linha esperada>
  local r
  r="$(abertas_de "$2")"
  [ "$r" = "$3" ] || { DETALHE="$1 — esperado '$3', veio '$r'"; return 1; }
}
CONFIG_IP_ZERO="$(printf '%s\n' "$CONFIG_PROD" | sed 's/host_ip: 127.0.0.1/host_ip: 0.0.0.0/')"
afirmar "porta com host_ip 0.0.0.0 explícito é acusada" acusa "0.0.0.0" "$CONFIG_IP_ZERO" "app 0.0.0.0:8081 -> 80"
CONFIG_IPV6="$(printf '%s\n' "$CONFIG_PROD" | sed 's/host_ip: 127.0.0.1/host_ip: "::1"/')"
afirmar "o loopback IPv6 (::1) é aceito" acusa "::1" "$CONFIG_IPV6" ""
CONFIG_ALEATORIA="$(printf '%s\n' "$CONFIG_PROD" | sed -e '/host_ip: 127.0.0.1/d' -e '/published: "8081"/d')"
afirmar "porta sem 'published' (porta aleatória em todas as interfaces) é acusada" \
  acusa "aleatória" "$CONFIG_ALEATORIA" "app 0.0.0.0:(aleatória) -> 80"
CONFIG_HOST="$(printf '%s\n' "$CONFIG_PROD" | awk '{ print } /^    user: www-data$/ { print "    network_mode: host" }')"
afirmar "serviço em network_mode: host é acusado" acusa "network_mode" "$CONFIG_HOST" "agendador network_mode: host"

gateway_e() { # <config> <esperado>
  local r
  r="$(printf '%s\n' "$1" | sm_gateway_da_rede_padrao)"
  [ "$r" = "$2" ] || { DETALHE="gateway: '$r' (esperado '$2')"; return 1; }
}
afirmar "gateway da rede lido do compose de produção (172.16.80.1)" gateway_e "$CONFIG_PROD" "172.16.80.1"
afirmar "sem ipam, não há gateway" gateway_e "$CONFIG_DEV" ""
porta_e() {
  local r
  r="$(printf '%s\n' "$CONFIG_PROD" | sm_porta_publicada app)"
  [ "$r" = "8081" ] || { DETALHE="porta: '$r'"; return 1; }
}
afirmar "porta publicada do app lida do compose (8081)" porta_e

lista_ok() {
  sm_lista_contem "172.16.80.1" "172.16.80.1" || { DETALHE="valor puro"; return 1; }
  sm_lista_contem "10.0.0.1, 172.16.80.1" "172.16.80.1" || { DETALHE="na lista"; return 1; }
  sm_lista_contem "172.16.80.1/32" "172.16.80.1" || { DETALHE="como /32"; return 1; }
  ! sm_lista_contem "172.16.80.10" "172.16.80.1" || { DETALHE="casou prefixo de texto"; return 1; }
  ! sm_lista_contem "172.16.80.0/24" "172.16.80.1" || { DETALHE="aceitou a sub-rede inteira"; return 1; }
  ! sm_lista_contem "" "172.16.80.1" || { DETALHE="lista vazia"; return 1; }
}
afirmar "TRUSTED_PROXIES tem de conter o gateway exato (ou /32), não a sub-rede inteira" lista_ok

# --- a conferência do .env de produção

ENV_BOM='APP_NAME="Stabil Money"
APP_ENV=production
APP_KEY=base64:c3RhYmlsbW9uZXktY2hhdmUtZGUtdGVzdGUtMzJieXRlcw==
APP_DEBUG=false
APP_URL=https://stabilmoney.victordemelo.com.br
LOG_STACK=daily
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=stabilmoney
DB_USERNAME=stabil_app
DB_PASSWORD=5d2c8e0f4b7a9d1c3e6f8a0b2d4c6e8f
DB_ROOT_PASSWORD=9f1e3d5c7b9a1f3e5d7c9b1a3f5e7d9c
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=null
TRUSTED_PROXIES=172.16.80.1
COMPOSE_FILE=docker-compose.prod.yml
HTTP_PORT=8081
MAIL_MAILER=smtp
MAIL_HOST=smtp.exemplo.com.br'

U="$T/unidade"
mkdir -p "$U"
conferir() { # <conteúdo do .env>
  printf '%s\n' "$1" > "$U/env"
  sm_conferir_env_de_producao "$U/env"
}
com() { # <chave> <valor> — o ENV_BOM com uma chave trocada (valor vazio = linha removida)
  printf '%s\n' "$ENV_BOM" | awk -v k="$1" -v v="$2" -F= '
    $1 == k { if (v != "") print k "=" v; next } { print }'
}

env_bom_passa() {
  local r
  r="$(conferir "$ENV_BOM")"
  [ -z "$r" ] || { DETALHE="$r"; return 1; }
}
afirmar ".env de produção completo: nenhuma queixa" env_bom_passa

recusa_env() { # <descrição> <conteúdo> <trecho esperado numa linha "erro:">
  local r
  r="$(conferir "$2")"
  printf '%s\n' "$r" | grep '^erro: ' | grep -qF "$3" || { DETALHE="$1 — veio: $r"; return 1; }
}
afirmar ".env: APP_DEBUG=true é recusado" recusa_env debug "$(com APP_DEBUG true)" "APP_DEBUG=true"
afirmar ".env: APP_ENV=local é recusado" recusa_env env "$(com APP_ENV local)" "APP_ENV=local"
afirmar ".env: APP_KEY vazia é recusada" recusa_env key "$(com APP_KEY '')" "APP_KEY"
afirmar ".env: APP_URL em http é recusado" recusa_env url-http "$(com APP_URL http://stabilmoney.victordemelo.com.br)" "APP_URL"
afirmar ".env: APP_URL de dev (localhost) é recusado" recusa_env url-dev "$(com APP_URL https://localhost)" "desenvolvimento"
afirmar ".env: SESSION_SECURE_COOKIE ausente é recusado" recusa_env cookie "$(com SESSION_SECURE_COOKIE '')" "SESSION_SECURE_COOKIE"
afirmar ".env: SESSION_DOMAIN com domínio é recusado (o cookie iria para o portfólio)" \
  recusa_env domain "$(com SESSION_DOMAIN .victordemelo.com.br)" "portfólio"
afirmar ".env: TRUSTED_PROXIES vazio é recusado" recusa_env proxies "$(com TRUSTED_PROXIES '')" "TRUSTED_PROXIES vazio"
afirmar ".env: TRUSTED_PROXIES com curinga é recusado" recusa_env curinga "$(com TRUSTED_PROXIES '*')" "curinga"
afirmar ".env: sem COMPOSE_FILE (o compose de DEV subiria) é recusado" recusa_env compose "$(com COMPOSE_FILE '')" "COMPOSE_FILE"
afirmar ".env: DB_HOST errado é recusado" recusa_env host "$(com DB_HOST 127.0.0.1)" "DB_HOST"
afirmar ".env: senha de exemplo do banco é recusada" recusa_env senha "$(com DB_PASSWORD password)" "DB_PASSWORD"
afirmar ".env: senha de root vazia é recusada" recusa_env root "$(com DB_ROOT_PASSWORD '')" "DB_ROOT_PASSWORD"
afirmar ".env: '\$' na senha do banco é recusado (o Compose interpolaria)" \
  recusa_env dolar "$(com DB_PASSWORD 'abc$def')" "tem '\$'"
afirmar ".env: HTTP_PORT que não é número é recusada" recusa_env porta "$(com HTTP_PORT 127.0.0.1:8081)" "HTTP_PORT"
# O .env.example traz o SMTP de desenvolvimento (o Mailpit do docker-compose.yml). Na VPS ele
# não existe: nada sai, e com `smtp` o app acha que sai — promete o link do "Esqueci a senha".
afirmar ".env: MAIL_HOST=mailpit (o do .env.example, de desenvolvimento) é recusado" \
  recusa_env mailpit "$(com MAIL_HOST mailpit)" "MAIL_HOST=mailpit"
afirmar ".env: MAIL_MAILER=smtp sem MAIL_HOST (vale 127.0.0.1, sem servidor de e-mail) é recusado" \
  recusa_env mail-sem-host "$(com MAIL_HOST '')" "MAIL_HOST=(vazio)"

avisa_env() { # <descrição> <conteúdo> <trecho esperado numa linha "aviso:">
  local r
  r="$(conferir "$2")"
  printf '%s\n' "$r" | grep -q '^erro: ' && { DETALHE="$1 — virou erro: $r"; return 1; }
  printf '%s\n' "$r" | grep '^aviso: ' | grep -qF "$3" || { DETALHE="$1 — veio: $r"; return 1; }
}
afirmar ".env: MAIL_MAILER=log só avisa (os e-mails não saem)" avisa_env mail "$(com MAIL_MAILER log)" "MAIL_MAILER=log"
afirmar ".env: MAIL_MAILER=log com o MAIL_HOST do Mailpit só avisa (o app diz que não envia)" \
  avisa_env mail-log-mailpit "$(com MAIL_MAILER log | awk -F= '$1 == "MAIL_HOST" { print "MAIL_HOST=mailpit"; next } { print }')" "MAIL_MAILER=log"
afirmar ".env: HTTP_PORT ausente só avisa (vale o 8081 do compose)" avisa_env porta "$(com HTTP_PORT '')" "HTTP_PORT"

css() {
  mkdir -p "$U/build/assets"
  head -c 1000 /dev/zero > "$U/build/assets/a.css"
  head -c 500 /dev/zero > "$U/build/assets/b.css"
  head -c 999 /dev/zero > "$U/build/assets/c.js"
  [ "$(sm_bytes_de_css "$U/build")" = "1500" ] || { DETALHE="somou $(sm_bytes_de_css "$U/build")"; return 1; }
  [ "$(sm_bytes_de_css "$U/nao-existe")" = "0" ] || { DETALHE="sem build: $(sm_bytes_de_css "$U/nao-existe")"; return 1; }
  sm_css_encolheu 130000 110000 || { DETALHE="130k → 110k não foi acusado"; return 1; }
  ! sm_css_encolheu 130000 125000 || { DETALHE="130k → 125k (−4%) foi acusado"; return 1; }
  ! sm_css_encolheu 0 110000 || { DETALHE="sem medida anterior foi acusado"; return 1; }
}
afirmar "tamanho do CSS: soma só .css e acusa queda de mais de 10%" css

# ======================================================================
# 2. CONTRATOS — os arquivos do pacote de deploy concordam entre si
# ======================================================================

echo "# contratos: $REPO"
COMPOSE_PROD="$REPO/docker-compose.prod.yml"
NGINX_SITE="$REPO/deploy/nginx/stabilmoney.victordemelo.com.br.conf"
GUIA="$REPO/docs/deploy-oracle-cloudflare.md"

GATEWAY_DO_COMPOSE="$(sed -n 's/^ *gateway: *\([0-9.]*\) *$/\1/p' "$COMPOSE_PROD" | head -n 1)"
PORTA_DO_COMPOSE="$(sed -n 's/.*"127\.0\.0\.1:\${HTTP_PORT:-\([0-9]*\)}:80".*/\1/p' "$COMPOSE_PROD" | head -n 1)"

gateway_documentado() {
  [ "$GATEWAY_DO_COMPOSE" = "172.16.80.1" ] || { DETALHE="gateway no compose: '$GATEWAY_DO_COMPOSE'"; return 1; }
  grep -q "^# *TRUSTED_PROXIES=$GATEWAY_DO_COMPOSE\$" "$REPO/.env.example" \
    || { DETALHE=".env.example não sugere TRUSTED_PROXIES=$GATEWAY_DO_COMPOSE"; return 1; }
  grep -q "TRUSTED_PROXIES=$GATEWAY_DO_COMPOSE" "$REPO/config/trustedproxy.php" \
    || { DETALHE="config/trustedproxy.php cita outro valor"; return 1; }
  grep -q "TRUSTED_PROXIES=$GATEWAY_DO_COMPOSE" "$GUIA" \
    || { DETALHE="o guia não usa TRUSTED_PROXIES=$GATEWAY_DO_COMPOSE"; return 1; }
}
afirmar "o gateway fixo do compose é o TRUSTED_PROXIES do .env.example, do config e do guia" gateway_documentado

porta_combina() {
  local nginx
  nginx="$(sed -n 's/.*proxy_pass http:\/\/127\.0\.0\.1:\([0-9]*\);.*/\1/p' "$NGINX_SITE" | head -n 1)"
  [ -n "$PORTA_DO_COMPOSE" ] || { DETALHE="não achei \"127.0.0.1:\${HTTP_PORT:-N}:80\" no compose"; return 1; }
  [ "$nginx" = "$PORTA_DO_COMPOSE" ] || { DETALHE="nginx: $nginx | compose: $PORTA_DO_COMPOSE"; return 1; }
  grep -q "^# *HTTP_PORT=$PORTA_DO_COMPOSE\$" "$REPO/.env.example" \
    || { DETALHE=".env.example não sugere HTTP_PORT=$PORTA_DO_COMPOSE"; return 1; }
  grep -q "HTTP_PORT=$PORTA_DO_COMPOSE" "$GUIA" || { DETALHE="o guia não usa HTTP_PORT=$PORTA_DO_COMPOSE"; return 1; }
}
afirmar "a porta do app no compose é a do proxy_pass do nginx (e a do .env.example e do guia)" porta_combina

corpo_combina() {
  local nginx php
  nginx="$(sed -n 's/^ *client_max_body_size *\([0-9]*\)[mM];.*/\1/p' "$NGINX_SITE" | head -n 1)"
  php="$(sed -n "s/.*'post_max_size = \([0-9]*\)M'.*/\1/p" "$REPO/Dockerfile" | head -n 1)"
  [ -n "$nginx" ] && [ "$nginx" = "$php" ] || { DETALHE="nginx: ${nginx:-?}m | PHP post_max_size: ${php:-?}M"; return 1; }
}
afirmar "o limite de corpo do nginx é o post_max_size do PHP (o nginx não barra upload que o app aceita)" corpo_combina

# O guia manda deixar "o resto do .env.example como está" até escolher o provedor de e-mail
# (passo 9). Então o e-mail do .env.example chega à VPS: a conferência tem de DIZER que nada vai
# sair — aviso (MAIL_MAILER=log: o app sabe que não envia) ou erro (SMTP que não existe lá, com
# o app achando que envia). Passar calado é o defeito.
mail_do_exemplo_acusado() {
  local r
  r="$(conferir "$(printf '%s\n' "$ENV_BOM" | grep -v '^MAIL_'; grep '^MAIL_' "$REPO/.env.example")")"
  printf '%s\n' "$r" | grep -Eq '^(aviso: MAIL_MAILER=|erro: MAIL_HOST=)' || { DETALHE="passou calado: '$r'"; return 1; }
}
afirmar "o e-mail do .env.example, deixado como está na VPS, não passa calado pela conferência" mail_do_exemplo_acusado

so_127_no_arquivo() {
  local portas
  # Toda linha de porta publicada do compose de produção começa em 127.0.0.1.
  portas="$(grep -E '^ *- "?[0-9$]' "$COMPOSE_PROD" | grep -v '127\.0\.0\.1:' || true)"
  [ -z "$portas" ] || { DETALHE="$portas"; return 1; }
}
afirmar "o docker-compose.prod.yml só publica em 127.0.0.1" so_127_no_arquivo

if [ -n "$DOCKER_DE_VERDADE" ] && "$DOCKER_DE_VERDADE" compose version > /dev/null 2>&1; then
  printf '%s\n' "DB_DATABASE=stabilmoney" "DB_USERNAME=stabil_app" "DB_PASSWORD=x" "DB_ROOT_PASSWORD=y" "HTTP_PORT=8081" > "$T/env-render"
  renderizar() { # <arquivo de compose>
    "$DOCKER_DE_VERDADE" compose --env-file "$T/env-render" -f "$1" --project-directory "$REPO" config 2>&1
  }
  render_prod() {
    local cfg abertas
    cfg="$(renderizar "$COMPOSE_PROD")" || { DETALHE="$cfg"; return 1; }
    abertas="$(printf '%s\n' "$cfg" | sm_portas_fora_do_loopback)"
    [ -z "$abertas" ] || { DETALHE="$abertas"; return 1; }
    [ "$(printf '%s\n' "$cfg" | sm_gateway_da_rede_padrao)" = "172.16.80.1" ] || { DETALHE="gateway errado"; return 1; }
    [ "$(printf '%s\n' "$cfg" | sm_porta_publicada app)" = "8081" ] || { DETALHE="porta errada"; return 1; }
  }
  afirmar "docker compose config (de verdade) do compose de produção passa na conferência do deploy" render_prod
  render_dev() {
    local cfg
    cfg="$(renderizar "$REPO/docker-compose.yml")" || { DETALHE="$cfg"; return 1; }
    contem "$(printf '%s\n' "$cfg" | sm_portas_fora_do_loopback)" "app 0.0.0.0:8001" || { DETALHE="não acusou"; return 1; }
  }
  afirmar "docker compose config (de verdade) do compose de DEV é recusado pela conferência" render_dev
else
  echo "# (sem docker compose de verdade no PATH: a renderização real dos dois compose foi pulada)"
fi

# ======================================================================
# 3. PONTA A PONTA — o deploy.sh de verdade, com git de verdade e docker/curl falsos
# ======================================================================

echo "# ponta a ponta: $SOB_TESTE/deploy.sh"

mkdir -p "$T/bin"
cp "$REPO/tests/scripts/docker-falso-deploy.sh" "$T/bin/docker"
cp "$REPO/tests/scripts/curl-falso.sh" "$T/bin/curl"
cat > "$T/bin/backup-falso" << 'FIM'
#!/usr/bin/env bash
echo "BACKUP" >> "$SM_FALSO_DIR/registro"
if [ "${SM_FALSO_FALHAR-}" = backup ]; then
  echo "ERRO: o dump saiu inválido: o arquivo não tem nenhum CREATE TABLE — isso não é um backup." >&2
  exit 1
fi
if [ -n "${SM_ARQUIVO_RESULTADO-}" ]; then echo "/opt/apps/stabilmoney/storage/backups/stabilmoney-20260923-120000.sql.gz" > "$SM_ARQUIVO_RESULTADO"; fi
exit 0
FIM
# `id` falso: diz "root" só quando pedido; no resto, o de verdade.
ID_DE_VERDADE="$(command -v id)"
cat > "$T/bin/id" << FIM
#!/usr/bin/env bash
if [ -n "\${SM_FALSO_ROOT-}" ] && [ "\${1-}" = "-u" ]; then echo 0; exit 0; fi
exec "$ID_DE_VERDADE" "\$@"
FIM
chmod +x "$T/bin/"*
PATH_TESTE="$T/bin:$PATH"

if [ "$(PATH="$PATH_TESTE" command -v docker)" != "$T/bin/docker" ] || [ "$(PATH="$PATH_TESTE" command -v curl)" != "$T/bin/curl" ]; then
  echo "ABORTADO: o docker/curl falso não ficou na frente do PATH" >&2
  exit 2
fi

# git sem a configuração de quem roda o teste (hooks, assinatura, editor).
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1
export GIT_AUTHOR_NAME=teste GIT_AUTHOR_EMAIL=teste@example.com
export GIT_COMMITTER_NAME=teste GIT_COMMITTER_EMAIL=teste@example.com

HOST="stabilmoney.victordemelo.com.br"

# Um cenário = um "GitHub" (repositório nu), um clone de desenvolvimento (DEV) e o
# clone do servidor (P), com o .env de produção e o app já no ar.
novo_cenario() { # <nome>
  C="$T/cenarios/$1"
  P="$C/servidor"
  DEV="$C/dev"
  E="$C/estado"
  mkdir -p "$C" "$E"
  git init -q --bare "$C/github.git"
  git -C "$C/github.git" symbolic-ref HEAD refs/heads/main
  git clone -q "$C/github.git" "$DEV" 2> /dev/null
  # Clone de repositório vazio: o nome do branch local depende da versão do git.
  git -C "$DEV" symbolic-ref HEAD refs/heads/main
  mkdir -p "$DEV/scripts/lib" "$DEV/public" "$DEV/storage/framework/views"
  cp "$SOB_TESTE/deploy.sh" "$DEV/scripts/"
  cp "$SOB_TESTE/lib/backup-comum.sh" "$SOB_TESTE/lib/deploy-comum.sh" "$DEV/scripts/lib/"
  printf '%s\n' '/vendor' '/node_modules' '/public/build' '/public/hot' '/.env' '/storage/framework/views/*.php' > "$DEV/.gitignore"
  printf '%s\n' '# compose de produção (de mentira: o docker falso lê as marcas "# falso:")' > "$DEV/docker-compose.prod.yml"
  : > "$DEV/storage/framework/views/.gitkeep"
  : > "$DEV/public/index.php"
  echo "v1" > "$DEV/app.txt"
  git -C "$DEV" add -A
  git -C "$DEV" commit -q -m "versão 1"
  git -C "$DEV" push -q origin main 2> /dev/null
  git clone -q "$C/github.git" "$P" 2> /dev/null
  printf '%s\n' "$ENV_BOM" > "$P/.env"
  # O app já no ar: containers de pé e vendor/ instalado (o primeiro deploy é outro cenário).
  printf '%s\n' app db agendador > "$E/rodando"
  mkdir -p "$P/vendor"
  : > "$P/vendor/autoload.php"
  : > "$E/registro"
  VERSAO_1="$(git -C "$P" rev-parse --short HEAD)"
}
# Um commit novo no "GitHub" (feito no clone de desenvolvimento).
commit_novo() { # [conteúdo extra do compose]
  echo "v2" > "$DEV/app.txt"
  if [ -n "${1-}" ]; then printf '%s\n' "$1" >> "$DEV/docker-compose.prod.yml"; fi
  git -C "$DEV" commit -q -am "versão 2"
  git -C "$DEV" push -q origin main 2> /dev/null
  VERSAO_2="$(git -C "$DEV" rev-parse --short HEAD)"
}
rodar() { # [args do deploy.sh]
  (cd "$P" && env PATH="$PATH_TESTE" SM_PATH_EXTRA="" SM_FALSO_DIR="$E" SM_DEPLOY_ESPERA=0 \
    SM_DEPLOY_BACKUP="$T/bin/backup-falso" bash "$P/scripts/deploy.sh" "$@") > "$C/saida" 2>&1 < /dev/null
  CODIGO=$?
  SAIDA="$(cat "$C/saida")"
}

# --- asserções sobre o registro

registrou() { grep -qF -- "$1" "$E/registro"; }
nao_registrou() { # <trecho>...
  local t
  for t in "$@"; do
    if registrou "$t"; then
      DETALHE="registrou '$t': $(grep -F -- "$t" "$E/registro" | head -n 2 | tr '\n' ';')"
      return 1
    fi
  done
}
# em_ordem <trecho>... — cada um aparece no registro, depois do anterior.
em_ordem() {
  local linha=0 t achada
  for t in "$@"; do
    achada="$(grep -nF -- "$t" "$E/registro" | awk -F: -v min="$linha" '$1 > min { print $1; exit }')"
    if [ -z "$achada" ]; then
      DETALHE="'$t' não aparece depois da linha $linha. Registro:
$(cat "$E/registro")"
      return 1
    fi
    linha="$achada"
  done
}
saiu_ok() { [ "$CODIGO" -eq 0 ] || { DETALHE="código $CODIGO: $SAIDA"; return 1; }; }
falhou_dizendo() { # <trecho da mensagem>...
  local t
  [ "$CODIGO" -ne 0 ] || { DETALHE="saiu com 0: $SAIDA"; return 1; }
  for t in "$@"; do
    contem "$SAIDA" "$t" || { DETALHE="faltou '$t' na saída: $SAIDA"; return 1; }
  done
}
nada_mudou() { # o app não saiu do ar, o código não andou, nenhum container foi mexido
  nao_registrou "BACKUP" "EXEC" "UP " "ASSETS" || return 1
  [ "$(git -C "$P" rev-parse --short HEAD)" = "$VERSAO_1" ] || { DETALHE="o código andou para $(git -C "$P" rev-parse --short HEAD)"; return 1; }
}
sem_trava() { [ ! -e "$P/.git/stabilmoney-deploy.lock" ] || { DETALHE="a trava ficou"; return 1; }; }

# --- o deploy de sempre ---------------------------------------------------

novo_cenario normal
commit_novo
: > "$P/public/hot"
rodar
afirmar "deploy normal: termina bem" saiu_ok
afirmar "deploy normal: a ordem do checklist — backup, manutenção, código, containers, composer, permissões, migrate, caches, view:cache, build, no ar" \
  em_ordem "CONFIG" "BACKUP" \
  "EXEC root app sh (permissões) @$VERSAO_1" \
  "EXEC www-data app php artisan down --retry=60 @$VERSAO_1" \
  "CONFIG" "UP -d --build --wait db app @$VERSAO_2" \
  "EXEC root app composer install --no-dev" \
  "EXEC root app sh (permissões) @$VERSAO_2" \
  "php artisan config:clear" "php artisan migrate --force" \
  "php artisan config:cache" "php artisan route:cache" "php artisan event:cache" "php artisan view:cache" \
  "ASSETS" "php artisan up" "UP -d --remove-orphans" \
  "CURL http://127.0.0.1:8081/up host=$HOST" "CURL http://127.0.0.1:8081/login host=$HOST" "CURL https://$HOST/up"
afirmar "deploy normal: o build dos assets nunca roda antes do view:cache" nao_registrou "ASSETS SEM VIEWS"
nenhum_artisan_como_root() { ! grep -q '^EXEC root app php artisan' "$E/registro" || { DETALHE="$(grep '^EXEC root app php artisan' "$E/registro")"; return 1; }; }
afirmar "deploy normal: todo artisan roda como www-data (log do dia gravável pelo site)" nenhum_artisan_como_root
codigo_novo() { [ "$(git -C "$P" rev-parse --short HEAD)" = "$VERSAO_2" ] || { DETALHE="HEAD: $(git -C "$P" rev-parse --short HEAD)"; return 1; }; }
afirmar "deploy normal: o servidor fica no commit do GitHub" codigo_novo
hot_apagado() {
  [ ! -e "$P/public/hot" ] || { DETALHE="public/hot continua lá"; return 1; }
  contem "$SAIDA" "public/hot existia e foi apagado" || { DETALHE="não avisou: $SAIDA"; return 1; }
}
afirmar "deploy normal: o public/hot é apagado (checklist, item 10.1)" hot_apagado
no_ar() { [ ! -e "$E/manutencao" ] || { DETALHE="terminou em manutenção"; return 1; }; }
afirmar "deploy normal: o app termina fora da manutenção" no_ar
afirmar "deploy normal: a trava de deploy é removida" sem_trava
css_guardado() { [ "$(cat "$P/.git/stabilmoney-deploy/css-bytes" 2> /dev/null)" = "130000" ] || { DETALHE="$(cat "$P/.git/stabilmoney-deploy/css-bytes" 2>&1)"; return 1; }; }
afirmar "deploy normal: o tamanho do CSS fica guardado para o próximo deploy comparar" css_guardado

# Quem rodou o "git pull && docker compose up -d --build" genérico antes: nada a puxar,
# e mesmo assim tudo o que falta acontece.
novo_cenario sem-novidade
rodar
afirmar "sem commit novo: termina bem, dizendo que republica" saiu_ok
afirmar "sem commit novo: backup, migrate, caches e build acontecem igual" \
  em_ordem "BACKUP" "php artisan down" "php artisan migrate --force" "php artisan view:cache" "ASSETS" "php artisan up"
diz_republicando() { contem "$SAIDA" "Nenhum commit novo" || { DETALHE="$SAIDA"; return 1; }; }
afirmar "sem commit novo: a saída diz que está republicando" diz_republicando

# --- conferências: nada muda se falharem ------------------------------------

novo_cenario env-inseguro
commit_novo
printf '%s\n' "$ENV_BOM" | sed -e 's/^APP_DEBUG=false/APP_DEBUG=true/' -e 's#^APP_URL=.*#APP_URL=http://localhost#' -e '/^COMPOSE_FILE=/d' > "$P/.env"
rodar
afirmar ".env inseguro: recusa listando os problemas" falhou_dizendo "APP_DEBUG=true" "APP_URL=http://localhost" "COMPOSE_FILE" "Nada foi mudado"
afirmar ".env inseguro: nada muda (sem backup, sem manutenção, código parado)" nada_mudou

novo_cenario porta-aberta
printf '%s\n' "# falso: porta-aberta" >> "$DEV/docker-compose.prod.yml"
git -C "$DEV" commit -q -am "compose com porta aberta"
git -C "$DEV" push -q origin main 2> /dev/null
git -C "$P" pull -q 2> /dev/null
VERSAO_1="$(git -C "$P" rev-parse --short HEAD)"
rodar
afirmar "porta fora de 127.0.0.1 no compose: recusa" falhou_dizendo "FORA de 127.0.0.1" "app 0.0.0.0:8081 -> 80"
afirmar "porta fora de 127.0.0.1 no compose: nada muda" nada_mudou

novo_cenario porta-aberta-na-versao-nova
commit_novo "# falso: porta-aberta"
rodar
afirmar "porta aberta na versão que VAI chegar: recusa antes de tirar o app do ar" falhou_dizendo "da versão nova" "app 0.0.0.0:8081"
afirmar "porta aberta na versão que vai chegar: nada muda (nem o código)" nada_mudou

novo_cenario gateway-diferente
printf '%s\n' "$ENV_BOM" | sed 's/^TRUSTED_PROXIES=.*/TRUSTED_PROXIES=10.9.9.1/' > "$P/.env"
rodar
afirmar "TRUSTED_PROXIES diferente do gateway da rede: recusa" falhou_dizendo "TRUSTED_PROXIES=10.9.9.1" "172.16.80.1"
afirmar "TRUSTED_PROXIES diferente do gateway: nada muda" nada_mudou

novo_cenario network-host
printf '%s\n' "# falso: network-host" >> "$DEV/docker-compose.prod.yml"
git -C "$DEV" commit -q -am "agendador na rede do host"
git -C "$DEV" push -q origin main 2> /dev/null
git -C "$P" pull -q 2> /dev/null
VERSAO_1="$(git -C "$P" rev-parse --short HEAD)"
rodar
afirmar "serviço em network_mode: host: recusa" falhou_dizendo "agendador network_mode: host"
afirmar "network_mode: host: nada muda" nada_mudou

novo_cenario pasta-suja
commit_novo
echo "mexido no servidor" >> "$P/app.txt"
rodar
afirmar "pasta do servidor com mudança à mão: recusa" falhou_dizendo "não está limpa" "app.txt"
nada_mudou_suja() { nao_registrou "BACKUP" "EXEC" "UP "; }
afirmar "pasta suja: nada muda" nada_mudou_suja

novo_cenario commit-local
(cd "$P" && echo "local" > local.txt && git add local.txt && git commit -q -m "feito no servidor")
VERSAO_1="$(git -C "$P" rev-parse --short HEAD)"
rodar
afirmar "servidor com commit que o GitHub não tem: recusa" falhou_dizendo "que o GitHub não tem"
afirmar "commit local: nada muda" nada_mudou

novo_cenario trava
commit_novo
mkdir "$P/.git/stabilmoney-deploy.lock"
rodar
afirmar "outro deploy rodando (trava presente): recusa" falhou_dizendo "já há um deploy rodando"
afirmar "trava presente: nada muda" nada_mudou
trava_intacta() { [ -d "$P/.git/stabilmoney-deploy.lock" ] || { DETALHE="apagou a trava do outro deploy"; return 1; }; }
afirmar "trava presente: a trava do outro deploy continua lá" trava_intacta

novo_cenario root
SM_FALSO_ROOT=1 rodar
afirmar "rodando como root: recusa" falhou_dizendo "não rode com sudo"
afirmar "root: nada muda" nada_mudou

# --- falhas no caminho ---------------------------------------------------------

novo_cenario backup-falha
commit_novo
SM_FALSO_FALHAR=backup rodar
afirmar "backup falhou: recusa sugerindo --primeiro-deploy (banco vazio)" falhou_dizendo "o backup falhou" "--primeiro-deploy" "continua no ar"
backup_sem_mudanca() { nao_registrou "php artisan down" "UP " "composer" || return 1; [ "$(git -C "$P" rev-parse --short HEAD)" = "$VERSAO_1" ] || { DETALHE="o código andou"; return 1; }; }
afirmar "backup falhou: o app não sai do ar e o código não anda" backup_sem_mudanca

novo_cenario migrate-falha
commit_novo
SM_FALSO_FALHAR=migrate rodar
afirmar "migrate falhou: para e diz que o app CONTINUA EM MANUTENÇÃO, com o caminho de volta" \
  falhou_dizendo "migrate --force falhou" "CONTINUA EM MANUTENÇÃO" "git reset --hard $VERSAO_1" "stabilmoney-20260923-120000.sql.gz"
afirmar "migrate falhou: nada depois dele roda (caches, build, fim da manutenção)" \
  nao_registrou "config:cache" "ASSETS" "php artisan up"
ainda_em_manutencao() { [ -e "$E/manutencao" ] || { DETALHE="saiu da manutenção"; return 1; }; }
afirmar "migrate falhou: o app fica em manutenção (o lado seguro)" ainda_em_manutencao
afirmar "migrate falhou: a trava é removida (dá para rodar de novo)" sem_trava

novo_cenario up-falha-depois
commit_novo
SM_FALSO_UP=indisponivel rodar
afirmar "/up final não responde ok: falha dizendo o que olhar" falhou_dizendo "o /up respondeu 'indisponivel'" "docker compose logs"
nao_diz_manutencao() { ! contem "$SAIDA" "CONTINUA EM MANUTENÇÃO" || { DETALHE="disse manutenção com o app já no ar"; return 1; }; }
afirmar "/up final: o app já saiu da manutenção, e a mensagem não diz o contrário" nao_diz_manutencao

novo_cenario vite-de-dev
SM_FALSO_HTML_HOT=1 rodar
afirmar "HTML apontando para o Vite de dev (5173): falha" falhou_dizendo "5173"

novo_cenario css-encolheu
rodar
SM_FALSO_CSS_BYTES=100000 rodar
avisou_css() {
  saiu_ok || return 1
  contem "$SAIDA" "o CSS encolheu de 130000 para 100000" || { DETALHE="$SAIDA"; return 1; }
}
afirmar "CSS mais de 10% menor que no deploy anterior: avisa (view:cache fora de ordem?)" avisou_css

novo_cenario publico-fora
SM_FALSO_PUBLICO=erro rodar
avisou_publico() {
  saiu_ok || return 1
  contem "$SAIDA" "https://$HOST/up não respondeu" || { DETALHE="$SAIDA"; return 1; }
}
afirmar "o endereço público não responde (nginx/Cloudflare ainda não prontos): só avisa" avisou_publico

# --- primeiro deploy e --sem-git ---------------------------------------------

novo_cenario primeiro
: > "$E/rodando"
rm -rf "$P/vendor"
rodar --primeiro-deploy
afirmar "primeiro deploy: termina bem" saiu_ok
afirmar "primeiro deploy: sem backup (banco vazio) e sem manutenção (não há app no ar)" nao_registrou "BACKUP" "php artisan down"
afirmar "primeiro deploy: sobe banco e app, instala, migra, faz caches e build" \
  em_ordem "UP -d --build --wait db app" "composer install --no-dev" "sh (permissões)" "php artisan migrate --force" \
  "php artisan view:cache" "ASSETS" "php artisan up" "UP -d --remove-orphans"

novo_cenario sem-git
commit_novo
rodar --sem-git
afirmar "--sem-git: termina bem" saiu_ok
parado_no_commit() { [ "$(git -C "$P" rev-parse --short HEAD)" = "$VERSAO_1" ] || { DETALHE="andou para $(git -C "$P" rev-parse --short HEAD)"; return 1; }; }
afirmar "--sem-git: não puxa o commit novo" parado_no_commit
afirmar "--sem-git: republica o que está na pasta (backup, caches, build)" em_ordem "BACKUP" "php artisan down" "php artisan config:cache" "ASSETS" "php artisan up"

# ---------------------------------------------------------------- resumo

echo ""
echo "$PASSOU passaram, $FALHOU falharam"
if [ "$FALHOU" -gt 0 ]; then
  printf 'Falharam:%s\n' "$LISTA_FALHAS"
  exit 1
fi
