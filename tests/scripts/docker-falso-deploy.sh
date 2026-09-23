#!/usr/bin/env bash
#
# Stabil Money — `docker` FALSO para os testes do scripts/deploy.sh.
#
# O teste (tests/scripts/deploy.test.sh) copia este arquivo como `docker` para um
# diretório que vai na FRENTE do PATH. Ele finge ser o `docker compose` do projeto de
# produção — o app, o banco, o agendador e o build dos assets —, mas só registra o que
# pediram e deixa no projeto os arquivos que o comando de verdade deixaria (vendor/,
# as views compiladas, o public/build). Nunca chama o docker de verdade.
#
# Cada operação vira uma linha em $SM_FALSO_DIR/registro:
#
#   CONFIG <arquivo>              docker compose config
#   UP <argumentos>               docker compose up
#   EXEC <usuário> <serviço> <comando...>
#   ASSETS                        docker compose run --rm assets
#   ASSETS SEM VIEWS              ...rodado ANTES do view:cache (o defeito do checklist, item 10)
#
# O `docker compose config` monta o YAML normalizado do Compose v2 a partir de
# MARCAS no arquivo de compose, para o teste descrever o cenário no próprio arquivo:
#
#   # falso: porta-aberta          o app publica a 8081 em todas as interfaces
#   # falso: gateway=<ip>          o gateway da rede (padrão: 172.16.80.1)
#   # falso: sem-gateway           a rede não fixa sub-rede nem gateway
#   # falso: network-host          o agendador em network_mode: host
#   # falso: config-quebrado       o `config` falha
#
# Botões:
#   SM_FALSO_FALHAR=<o quê>   falha nesse ponto: up, composer, migrate (ou outro
#                             subcomando do artisan), assets
#   SM_FALSO_CSS_BYTES=<n>    tamanho do CSS que o build gera (padrão 130000)

set -u

D="${SM_FALSO_DIR:?SM_FALSO_DIR não definido}"
mkdir -p "$D"
touch "$D/rodando"

registrar() { printf '%s\n' "$*" >> "$D/registro"; }

# Em que commit o código da pasta estava quando o comando rodou — é o que prova que a
# manutenção foi ligada ANTES de o código novo chegar, e o composer rodou DEPOIS.
versao() { git rev-parse --short HEAD 2> /dev/null || echo "-"; }

falhar_se() { # <ponto>
  if [ "${SM_FALSO_FALHAR-}" = "$1" ]; then
    echo "docker-falso: falha simulada em '$1'" >&2
    exit 1
  fi
}

case "${1-}" in
  compose) shift ;;
  *)
    echo "docker-falso: não sei fazer: docker $*" >&2
    exit 99
    ;;
esac

arquivo=""
while [ $# -gt 0 ]; do
  case "$1" in
    -f) arquivo="$2"; shift 2 ;;
    --project-directory) shift 2 ;;
    *) break ;;
  esac
done
if [ -z "$arquivo" ]; then
  arquivo="$(sed -n 's/^COMPOSE_FILE=//p' .env 2> /dev/null | tail -n 1)"
  arquivo="${arquivo:-docker-compose.yml}"
fi

tem_marca() { grep -q "^# falso: $1" "$arquivo" 2> /dev/null; }

emitir_config() {
  local host_ip="        host_ip: 127.0.0.1" gateway="172.16.80.1"
  if tem_marca porta-aberta; then host_ip=""; fi
  if tem_marca 'gateway='; then gateway="$(sed -n 's/^# falso: gateway=//p' "$arquivo" | head -n 1)"; fi
  printf '%s\n' "name: stabilmoney" "services:" "  agendador:" "    command:" "      - php"
  if tem_marca network-host; then printf '%s\n' "    network_mode: host"; fi
  printf '%s\n' "    user: www-data" "  app:" "    image: stabilmoney-app:producao" "    ports:" "      - mode: ingress"
  if [ -n "$host_ip" ]; then printf '%s\n' "$host_ip"; fi
  printf '%s\n' "        target: 80" "        published: \"8081\"" "        protocol: tcp" \
    "    restart: unless-stopped" "  db:" "    image: mysql:8.0" "networks:" "  default:" "    name: stabilmoney_default"
  if ! tem_marca sem-gateway; then
    printf '%s\n' "    ipam:" "      config:" "        - subnet: 172.16.80.0/24" "          gateway: $gateway"
  fi
  printf '%s\n' "volumes:" "  db_data:" "    name: stabilmoney_db_data"
}

comando="${1-}"
shift || true

case "$comando" in
  version)
    echo "Docker Compose version v2 (falso)"
    ;;
  config)
    registrar "CONFIG $arquivo"
    if tem_marca config-quebrado; then
      echo "service \"app\" refers to undefined volume x: invalid compose project" >&2
      exit 15
    fi
    emitir_config
    ;;
  ps)
    if [ "${1-}" = "--status" ]; then cat "$D/rodando"; else printf '%s\n' app db agendador; fi
    ;;
  up)
    registrar "UP $* @$(versao)"
    falhar_se up
    servicos=""
    for a in "$@"; do
      case "$a" in -*) ;; *) servicos="$servicos $a" ;; esac
    done
    [ -n "$servicos" ] || servicos=" app db agendador"
    for s in $servicos; do
      grep -qx "$s" "$D/rodando" || echo "$s" >> "$D/rodando"
    done
    ;;
  run)
    [ "${1-}" = "--rm" ] && shift
    [ "${1-}" = "assets" ] || { echo "docker-falso: run de quê? $*" >&2; exit 99; }
    if ls storage/framework/views/*.php > /dev/null 2>&1; then registrar "ASSETS"; else registrar "ASSETS SEM VIEWS"; fi
    falhar_se assets
    mkdir -p public/build/assets
    printf '{"resources/css/app.css":{"file":"assets/app.css"}}\n' > public/build/manifest.json
    head -c "${SM_FALSO_CSS_BYTES:-130000}" /dev/zero | tr '\0' 'a' > public/build/assets/app.css
    ;;
  exec)
    usuario="root"
    while [ $# -gt 0 ]; do
      case "$1" in
        -T) shift ;;
        -u) usuario="$2"; shift 2 ;;
        -e) shift 2 ;;
        *) break ;;
      esac
    done
    servico="$1"
    shift
    if ! grep -qx "$servico" "$D/rodando"; then
      echo "service \"$servico\" is not running" >&2
      exit 1
    fi
    case "$*" in
      "sh -c"*) registrar "EXEC $usuario $servico sh (permissões) @$(versao)" ;;
      *) registrar "EXEC $usuario $servico $* @$(versao)" ;;
    esac
    case "${1-} ${2-}" in
      "composer install")
        falhar_se composer
        mkdir -p vendor
        : > vendor/autoload.php
        ;;
      "php artisan")
        sub="${3-}"
        falhar_se "$sub"
        case "$sub" in
          down) : > "$D/manutencao" ;;
          up) rm -f "$D/manutencao" ;;
          view:cache)
            mkdir -p storage/framework/views
            : > storage/framework/views/compilada.php
            ;;
        esac
        ;;
    esac
    ;;
  *)
    echo "docker-falso: não sei fazer: docker compose $comando $*" >&2
    exit 99
    ;;
esac
exit 0
