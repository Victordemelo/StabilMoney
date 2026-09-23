# shellcheck shell=bash
#
# Stabil Money — funções do scripts/deploy.sh que decidem se é seguro publicar.
#
# Este arquivo NÃO é executado: o deploy.sh o carrega com `.`, depois do
# backup-comum.sh (de onde vêm sm_ler_env e as funções de saída). Tudo aqui é
# PURO — texto entra, texto sai, sem Docker nem rede —, de propósito: é o pedaço
# que diz "não suba isto", e precisa ser testado em qualquer máquina
# (tests/scripts/deploy.test.sh roda estas funções no CI).
#
# Compatível com o bash 3.2 do macOS e com as ferramentas BSD (Mac) e GNU (VPS).

# ------------------------------------------------------------- o .env de produção

# Minúsculas, sem depender do ${var,,} do bash 4.
sm_minusculas() { printf '%s' "$1" | tr '[:upper:]' '[:lower:]'; }

# O item <ip> está na lista <lista> (vírgulas e/ou espaços, como no TRUSTED_PROXIES)?
# Aceita o IP puro ou escrito como /32.
sm_lista_contem() { # <lista> <ip>
  local item
  for item in $(printf '%s' "$1" | tr ',' ' '); do
    if [ "$item" = "$2" ] || [ "$item" = "$2/32" ]; then return 0; fi
  done
  return 1
}

# Confere o .env contra os bloqueadores do docs/checklist-de-publicacao.md e imprime
# uma linha por problema, prefixada com "erro: " (impede o deploy) ou "aviso: "
# (segue, mas mostra). Não imprime nada quando está tudo certo.
#
# Não executa o .env (lê com sm_ler_env): um `source .env` rodaria qualquer comando
# que estivesse lá dentro.
sm_conferir_env_de_producao() { # <arquivo .env>
  local env="$1" v host chave

  v="$(sm_ler_env "$env" APP_ENV)"
  [ "$v" = "production" ] || echo "erro: APP_ENV=${v:-(vazio)} — precisa ser production (checklist, item 2)"

  v="$(sm_minusculas "$(sm_ler_env "$env" APP_DEBUG)")"
  case "$v" in
    false | '(false)') ;;
    *) echo "erro: APP_DEBUG=${v:-(vazio)} — precisa ser false; com true, qualquer erro mostra as senhas do .env na tela (checklist, item 1)" ;;
  esac

  v="$(sm_ler_env "$env" APP_KEY)"
  case "$v" in
    base64:?????????????????????*) ;;
    *) echo "erro: APP_KEY vazia ou fora do formato base64:... — gere NO servidor: echo \"APP_KEY=base64:\$(openssl rand -base64 32)\" (checklist, item 3)" ;;
  esac

  v="$(sm_ler_env "$env" APP_URL)"
  host="$(printf '%s' "$v" | sed -n 's#^https://\([^/:]*\).*#\1#p')"
  if [ -z "$host" ]; then
    echo "erro: APP_URL=${v:-(vazio)} — precisa ser o endereço público em https (ex.: https://stabilmoney.victordemelo.com.br). É dele que saem os links dos e-mails e o único Host que o app aceita"
  else
    case "$(sm_minusculas "$host")" in
      localhost | 127.0.0.1 | *.test | *.local)
        echo "erro: APP_URL=$v — é endereço de desenvolvimento; em produção o app recusaria todo visitante (400)" ;;
    esac
  fi

  v="$(sm_minusculas "$(sm_ler_env "$env" SESSION_SECURE_COOKIE)")"
  [ "$v" = "true" ] || echo "erro: SESSION_SECURE_COOKIE=${v:-(vazio)} — precisa ser true (checklist, item 4)"

  v="$(sm_minusculas "$(sm_ler_env "$env" SESSION_DOMAIN)")"
  case "$v" in
    '' | null) ;;
    *) echo "erro: SESSION_DOMAIN=$v — deixe null: com um domínio, o cookie de sessão iria também para o portfólio e para qualquer outro subdomínio (checklist, item 9)" ;;
  esac

  v="$(sm_ler_env "$env" TRUSTED_PROXIES)"
  if [ -z "$v" ]; then
    echo "erro: TRUSTED_PROXIES vazio — atrás do nginx, todo visitante apareceria com o IP do gateway e os limites por IP travariam o site inteiro (use o gateway da rede do docker-compose.prod.yml: 172.16.80.1)"
  else
    case "$v" in
      *'*'*) echo "erro: TRUSTED_PROXIES=$v — o curinga confiaria em qualquer um; use o IP do gateway (172.16.80.1)" ;;
    esac
  fi

  v="$(sm_ler_env "$env" COMPOSE_FILE)"
  [ "$v" = "docker-compose.prod.yml" ] || echo "erro: COMPOSE_FILE=${v:-(vazio)} — precisa ser docker-compose.prod.yml; sem isso o 'docker compose' da pasta sobe o compose de DESENVOLVIMENTO (porta 8001 aberta para a rede e o Mailpit)"

  v="$(sm_ler_env "$env" DB_CONNECTION)"
  [ "$v" = "mysql" ] || echo "erro: DB_CONNECTION=${v:-(vazio)} — precisa ser mysql"
  v="$(sm_ler_env "$env" DB_HOST)"
  [ "$v" = "db" ] || echo "erro: DB_HOST=${v:-(vazio)} — precisa ser db (o nome do serviço do banco no docker-compose.prod.yml)"

  v="$(sm_ler_env "$env" DB_PASSWORD)"
  case "$v" in
    '' | password | secret | root) echo "erro: DB_PASSWORD vazia ou de exemplo — gere com: openssl rand -hex 32 (checklist, item 6)" ;;
  esac
  v="$(sm_ler_env "$env" DB_ROOT_PASSWORD)"
  case "$v" in
    '' | password | secret | root) echo "erro: DB_ROOT_PASSWORD vazia ou de exemplo — gere com: openssl rand -hex 32 (checklist, item 6)" ;;
  esac

  # O Compose lê este mesmo .env e SUBSTITUI "$ALGO" e "${ALGO}" dentro dos valores
  # (o Laravel não). Uma senha "abc$def" chegaria ao MySQL como "abc" e ao Laravel
  # inteira — o banco nasce com uma senha e o app tenta outra.
  for chave in DB_DATABASE DB_USERNAME DB_PASSWORD DB_ROOT_PASSWORD HTTP_PORT; do
    case "$(sm_ler_env "$env" "$chave")" in
      *'$'*) echo "erro: $chave tem '\$' — o Compose trocaria o trecho por outra variável (vazia); gere senhas só com letras e números (openssl rand -hex 32)" ;;
    esac
  done

  v="$(sm_ler_env "$env" HTTP_PORT)"
  case "$v" in
    '') echo "aviso: HTTP_PORT não definido — vale o padrão 8081 do docker-compose.prod.yml; deixe explícito no .env" ;;
    *[!0-9]*) echo "erro: HTTP_PORT=$v — precisa ser só o número da porta (ex.: 8081)" ;;
  esac

  v="$(sm_ler_env "$env" LOG_STACK daily)"
  [ "$v" = "daily" ] || echo "aviso: LOG_STACK=$v — em produção use daily (checklist, item 10.3)"

  v="$(sm_ler_env "$env" MAIL_MAILER log)"
  case "$v" in
    log | null | array) echo "aviso: MAIL_MAILER=$v — nenhum e-mail sai: 'Esqueci a senha', confirmação de cadastro e alertas ficam desligados (checklist, item 5)" ;;
  esac

  v="$(sm_minusculas "$(sm_ler_env "$env" ADMIN_PANEL_ENABLED false)")"
  [ "$v" != "true" ] || echo "aviso: ADMIN_PANEL_ENABLED=true — o painel administrativo está ligado; desligue quando não estiver usando"
}

# ------------------------------------------------ a saída do `docker compose config`
#
# As três funções abaixo leem, no STDIN, o YAML que o `docker compose config`
# imprime. É o arquivo JÁ RESOLVIDO — com o COMPOSE_FILE, as variáveis do .env e os
# padrões aplicados —, ou seja, exatamente o que o `up` vai subir. O formato é o
# normalizado do Compose v2: chaves com 2 espaços por nível e cada porta como um item
# com `host_ip`, `target` e `published` (sem `host_ip` = todas as interfaces).

# Imprime uma linha por porta publicada FORA do loopback — "<serviço> <ip>:<porta> -> <porta do
# container>" — e por serviço em `network_mode: host` (que expõe o que escutar, sem
# passar por publicação nenhuma). Nada impresso = tudo em 127.0.0.1.
sm_portas_fora_do_loopback() {
  LC_ALL=C awk '
    function valor(l) { sub(/^[^:]*: */, "", l); gsub(/"/, "", l); return l }
    function fechar() {
      if (item) {
        if (host_ip != "127.0.0.1" && host_ip != "::1")
          printf "%s %s:%s -> %s\n", servico, (host_ip == "" ? "0.0.0.0" : host_ip), (publicada == "" ? "(aleatória)" : publicada), alvo
        item = 0
      }
    }
    /^[^ ]/ { fechar(); secao = $0; sub(/:.*/, "", secao); servico = ""; em_ports = 0; next }
    secao != "services" { next }
    /^  [^ ]/ { fechar(); servico = $0; sub(/^  /, "", servico); sub(/:.*/, "", servico); em_ports = 0; next }
    /^    [^ ]/ {
      fechar()
      em_ports = ($0 ~ /^    ports:/)
      if ($0 ~ /^    network_mode: *"?host"?[ \t]*$/) printf "%s network_mode: host\n", servico
      next
    }
    em_ports && /^      - / {
      fechar(); item = 1; host_ip = ""; publicada = ""; alvo = ""
      linha = $0; sub(/^      - /, "", linha)
    }
    em_ports && /^        [^ ]/ { linha = $0; sub(/^        /, "", linha) }
    em_ports && linha != "" {
      if (linha ~ /^host_ip:/) host_ip = valor(linha)
      else if (linha ~ /^published:/) publicada = valor(linha)
      else if (linha ~ /^target:/) alvo = valor(linha)
      linha = ""
    }
    END { fechar() }
  '
}

# O gateway fixado em networks.default.ipam.config — é por ele que o nginx do host chega
# ao container. Vazio se a rede não fixar gateway.
sm_gateway_da_rede_padrao() {
  LC_ALL=C awk '
    /^[^ ]/ { secao = $0; sub(/:.*/, "", secao); rede = ""; next }
    secao != "networks" { next }
    /^  [^ ]/ { rede = $0; sub(/^  /, "", rede); sub(/:.*/, "", rede); next }
    !achou && rede == "default" && /gateway:/ { l = $0; sub(/^[^:]*: */, "", l); gsub(/"/, "", l); print l; achou = 1 }
  '
}

# A porta do HOST publicada para <serviço> (a primeira) — a que o nginx usa no proxy_pass.
sm_porta_publicada() { # <serviço>
  LC_ALL=C awk -v alvo_servico="$1" '
    /^[^ ]/ { secao = $0; sub(/:.*/, "", secao); servico = ""; em_ports = 0; next }
    secao != "services" { next }
    /^  [^ ]/ { servico = $0; sub(/^  /, "", servico); sub(/:.*/, "", servico); em_ports = 0; next }
    /^    [^ ]/ { em_ports = ($0 ~ /^    ports:/); next }
    !achou && em_ports && servico == alvo_servico && /published:/ { l = $0; sub(/^[^:]*: */, "", l); gsub(/"/, "", l); print l; achou = 1 }
  '
}

# ------------------------------------------------------------- o CSS construído

# Soma, em bytes, os .css do build (public/build/assets). É a régua do checklist
# (item 10): o `view:cache` fora de ordem faz o Tailwind gerar ~15% MENOS CSS, e o
# build termina verde. 0 se não houver CSS nenhum.
sm_bytes_de_css() { # <dir do build>
  local total=0 f n
  for f in "$1"/assets/*.css; do
    [ -f "$f" ] || continue
    n="$(wc -c < "$f" | tr -d ' ')"
    total=$((total + n))
  done
  printf '%s' "$total"
}

# 0 (verdadeiro) se <atual> caiu mais de 10% em relação a <anterior>.
sm_css_encolheu() { # <anterior> <atual>
  [ "$1" -gt 0 ] && [ $(($2 * 100)) -lt $(($1 * 90)) ]
}
