#!/usr/bin/env bash
#
# Testes da configuração do nginx do host (deploy/nginx/) contra um nginx DE VERDADE —
# o 1.24, a versão do Ubuntu 24.04 da VPS — num container:
#
#   bash tests/scripts/nginx.test.sh
#
# Ao contrário dos outros testes de tests/scripts/, este PRECISA de Docker (é o nginx
# de verdade que decide o que passa). Sem Docker, ele diz que foi pulado e sai com 0.
# SM_NGINX_IMAGEM troca a imagem (padrão: nginx:1.24-alpine).
#
# O que ele prova, com os arquivos do repositório sem mudar uma linha:
#   - o `nginx -t` aceita a combinação (site + servidor padrão + snippets da Cloudflare);
#   - vindo de um IP da Cloudflare, o app recebe o IP REAL do visitante (do
#     CF-Connecting-IP) SOZINHO no X-Forwarded-For — o que o visitante mandou nesse
#     cabeçalho não passa —, "https", a porta 443, o Host do site, e nada de
#     X-Forwarded-Prefix nem Forwarded;
#   - vindo de fora da Cloudflare, a conexão é fechada (444), mesmo com CF-Connecting-IP;
#   - Host/SNI desconhecido: a 80 fecha a conexão e a 443 recusa o aperto de mão TLS;
#   - a porta 80 do site manda para https, com o caminho;
#   - redirect em http:// montado pelo Apache do container (a barra no fim da URL) chega ao
#     visitante em https;
#   - o limite de corpo: 11 MB passam, 13 MB recebem 413.
#
# Como se finge "vir da Cloudflare": o container ganha dois IPs a mais na interface de
# loopback — um de uma faixa da Cloudflare, outro de fora — e o curl sai por um ou pelo
# outro (--interface). O "app" é um server de mentira na 127.0.0.1:8081 que devolve os
# cabeçalhos que recebeu.

set -u

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
IMAGEM="${SM_NGINX_IMAGEM:-nginx:1.24-alpine}"
NGINX="$REPO/deploy/nginx"
HOST="stabilmoney.victordemelo.com.br"
IP_CLOUDFLARE="173.245.48.10" # dentro de 173.245.48.0/20
IP_DE_FORA="192.0.2.99"       # TEST-NET-1: não é da Cloudflare

if ! command -v docker > /dev/null 2>&1 || ! docker info > /dev/null 2>&1; then
  echo "# sem Docker: testes do nginx PULADOS"
  exit 0
fi
if ! command -v openssl > /dev/null 2>&1; then
  echo "# sem openssl para o certificado de teste: testes do nginx PULADOS"
  exit 0
fi

T="$(mktemp -d "${TMPDIR:-/tmp}/sm-teste-nginx.XXXXXX")"
CONTAINER="sm-teste-nginx-$$"
trap 'docker rm -f "$CONTAINER" > /dev/null 2>&1; rm -rf "$T"' EXIT

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
  if [ -n "${2-}" ]; then printf '%s\n' "$2" | sed -n '1,12p' | sed 's/^/          | /'; fi
}
afirmar() {
  local descricao="$1"
  shift
  if "$@"; then ok "$descricao"; else falhou "$descricao" "${DETALHE-}"; fi
  DETALHE=""
}
DETALHE=""

# ------------------------------------------------ o /etc/nginx do teste

mkdir -p "$T/conf.d" "$T/snippets" "$T/letsencrypt/live/$HOST"
# Os arquivos do repositório, sem mudança. (A imagem inclui conf.d/*.conf; na VPS o site e
# o servidor padrão vão em sites-enabled/ — o nginx os lê do mesmo jeito.)
cp "$NGINX/stabilmoney.victordemelo.com.br.conf" "$T/conf.d/stabilmoney.conf"
cp "$NGINX/00-host-desconhecido.conf" "$T/conf.d/00-host-desconhecido.conf"
cp "$NGINX/cloudflare-origem.conf" "$T/conf.d/cloudflare-origem.conf"
cp "$NGINX/cloudflare-ip-real.conf" "$T/snippets/cloudflare-ip-real.conf"
# O "app": devolve os cabeçalhos que chegaram a ele. Sem limite de corpo, para o 413
# que aparecer ser o do site, e não o dele.
cat > "$T/conf.d/zz-app-de-mentira.conf" << 'FIM'
server {
    listen 127.0.0.1:8081;
    client_max_body_size 0;
    # O redirect que o APACHE do container monta sozinho (a barra no fim, do .htaccess do
    # Laravel; o DirectorySlash das pastas de public/): ele não sabe que a requisição original
    # era https, e escreve http:// com o Host que recebeu.
    location = /reset-password/TOKEN/ {
        return 301 http://$host/reset-password/TOKEN$is_args$args;
    }
    location / {
        default_type text/plain;
        return 200 "xff=$http_x_forwarded_for|proto=$http_x_forwarded_proto|xhost=$http_x_forwarded_host|porta=$http_x_forwarded_port|prefixo=$http_x_forwarded_prefix|forwarded=$http_forwarded|host=$http_host";
    }
}
FIM
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=$HOST" \
  -keyout "$T/letsencrypt/live/$HOST/privkey.pem" -out "$T/letsencrypt/live/$HOST/fullchain.pem" > /dev/null 2>&1 \
  || { echo "ABORTADO: não consegui gerar o certificado de teste" >&2; exit 2; }
chmod -R a+rX "$T"

# IPv6 ligado no container: o site e o servidor padrão também escutam em [::].
if ! docker run -d --name "$CONTAINER" --cap-add NET_ADMIN --sysctl net.ipv6.conf.all.disable_ipv6=0 \
  -v "$T/conf.d:/etc/nginx/conf.d:ro" -v "$T/snippets:/etc/nginx/snippets:ro" \
  -v "$T/letsencrypt:/etc/letsencrypt:ro" "$IMAGEM" > "$T/docker-run.log" 2>&1; then
  echo "ABORTADO: o container do nginx não subiu: $(cat "$T/docker-run.log")" >&2
  exit 2
fi
no_container() { docker exec "$CONTAINER" "$@"; }
no_container ip addr add "$IP_CLOUDFLARE/32" dev lo
no_container ip addr add "$IP_DE_FORA/32" dev lo
sleep 1

no_ar() {
  local saida
  saida="$(no_container nginx -t 2>&1)" || { DETALHE="$saida"; return 1; }
  docker ps --filter "name=$CONTAINER" --filter status=running -q | grep -q . \
    || { DETALHE="o container parou: $(docker logs "$CONTAINER" 2>&1 | tail -n 5)"; return 1; }
}
afirmar "nginx -t aceita o site, o servidor padrão e os dois arquivos da Cloudflare" no_ar

# curl de dentro do container. <ip de saída> <resto dos argumentos>
curl_de() {
  local origem="$1"
  shift
  no_container curl -sk --max-time 5 --interface "$origem" "$@"
}

# ------------------------------------------------ vindo da Cloudflare

RESPOSTA="$(curl_de "$IP_CLOUDFLARE" --resolve "$HOST:443:$IP_CLOUDFLARE" \
  -H "CF-Connecting-IP: 203.0.113.7" \
  -H "X-Forwarded-For: 6.6.6.6" \
  -H "X-Forwarded-Host: atacante.example" \
  -H "X-Forwarded-Proto: http" \
  -H "X-Forwarded-Prefix: /atacante" \
  -H "Forwarded: for=6.6.6.6;proto=http" \
  "https://$HOST/login" 2>&1)"
cabecalho() { # <nome=valor esperado>
  case "|$RESPOSTA|" in
    *"|$1|"*) return 0 ;;
  esac
  DETALHE="esperado '$1' em: $RESPOSTA"
  return 1
}
afirmar "da Cloudflare: o app recebe o IP REAL (CF-Connecting-IP) sozinho no X-Forwarded-For" cabecalho "xff=203.0.113.7"
afirmar "da Cloudflare: X-Forwarded-Proto é https, seja o que for que o visitante mandou" cabecalho "proto=https"
afirmar "da Cloudflare: X-Forwarded-Host e Host são os do site" cabecalho "xhost=$HOST"
afirmar "da Cloudflare: o Host que chega ao app é o do site" cabecalho "host=$HOST"
afirmar "da Cloudflare: X-Forwarded-Port é 443" cabecalho "porta=443"
afirmar "da Cloudflare: o X-Forwarded-Prefix do visitante é apagado" cabecalho "prefixo="
afirmar "da Cloudflare: o Forwarded do visitante é apagado" cabecalho "forwarded="

# ------------------------------------------------ vindo de fora da Cloudflare

fechou() { # <descrição> <código de saída> <saída>
  if [ "$2" -eq 0 ] || contem_resposta "$3"; then
    DETALHE="$1: respondeu (curl saiu com $2): $3"
    return 1
  fi
}
contem_resposta() { case "$1" in *xff=* | *HTTP/*) return 0 ;; esac; return 1; }

SAIDA="$(curl_de "$IP_DE_FORA" --resolve "$HOST:443:$IP_DE_FORA" -H "CF-Connecting-IP: 203.0.113.7" "https://$HOST/login" 2>&1)"
CODIGO=$?
afirmar "de FORA da Cloudflare (IP da VPS descoberto): a conexão é fechada, mesmo com CF-Connecting-IP" fechou "direto" "$CODIGO" "$SAIDA"

# ------------------------------------------------ Host desconhecido

SAIDA="$(curl_de "$IP_CLOUDFLARE" --resolve "atacante.example:443:$IP_CLOUDFLARE" "https://atacante.example/forgot-password" 2>&1)"
CODIGO=$?
handshake_recusado() {
  [ "$CODIGO" -eq 35 ] || { DETALHE="curl saiu com $CODIGO (35 = aperto de mão TLS recusado): $SAIDA"; return 1; }
}
afirmar "443 com SNI de outro domínio: o aperto de mão TLS é recusado (nem certificado sai)" handshake_recusado

SAIDA="$(curl_de "$IP_CLOUDFLARE" "https://$IP_CLOUDFLARE/" 2>&1)"
CODIGO=$?
afirmar "443 pelo IP, sem SNI (varredura): o aperto de mão TLS é recusado" handshake_recusado

SAIDA="$(curl_de "$IP_CLOUDFLARE" -H "Host: atacante.example" "http://$IP_CLOUDFLARE/forgot-password" 2>&1)"
CODIGO=$?
afirmar "80 com Host de outro domínio: a conexão é fechada sem resposta" fechou "80" "$CODIGO" "$SAIDA"

# ------------------------------------------------ porta 80 do site

REDIRECIONA="$(curl_de "$IP_CLOUDFLARE" -o /dev/null -w '%{http_code} %{redirect_url}' -H "Host: $HOST" \
  "http://$IP_CLOUDFLARE/login?voltar=1" 2>&1)"
redireciona() {
  [ "$REDIRECIONA" = "301 https://$HOST/login?voltar=1" ] || { DETALHE="$REDIRECIONA"; return 1; }
}
afirmar "80 do site: 301 para https, com o caminho e a query" redireciona

# ------------------------------------------------ redirect montado pelo Apache

# O Apache do container não tem TLS: um redirect que ELE monta (a barra no fim da URL, no
# .htaccess) sai em http://, com o caminho — e, na redefinição de senha, com o token e o
# e-mail. O navegador de quem ainda não tem o HSTS seguiria em texto puro.
APACHE_REDIRECT="$(curl_de "$IP_CLOUDFLARE" --resolve "$HOST:443:$IP_CLOUDFLARE" -o /dev/null -w '%{http_code} %{redirect_url}' \
  -H "CF-Connecting-IP: 203.0.113.7" "https://$HOST/reset-password/TOKEN/?email=vitima%40example.com" 2>&1)"
redirect_do_app_em_https() {
  [ "$APACHE_REDIRECT" = "301 https://$HOST/reset-password/TOKEN?email=vitima%40example.com" ] || { DETALHE="$APACHE_REDIRECT"; return 1; }
}
afirmar "redirect em http:// montado pelo app (Apache) sai em https para o visitante" redirect_do_app_em_https

# ------------------------------------------------ tamanho do corpo

no_container sh -c 'head -c 11534336 /dev/zero > /tmp/11mb && head -c 13631488 /dev/zero > /tmp/13mb'
corpo() { # <arquivo> <status esperado>
  local status
  status="$(curl_de "$IP_CLOUDFLARE" --resolve "$HOST:443:$IP_CLOUDFLARE" -o /dev/null -w '%{http_code}' \
    -H "CF-Connecting-IP: 203.0.113.7" --data-binary "@$1" "https://$HOST/meu-perfil" 2>&1)"
  [ "$status" = "$2" ] || { DETALHE="POST de $1: $status (esperado $2)"; return 1; }
}
afirmar "corpo de 11 MB (abaixo do post_max_size de 12M) chega ao app" corpo /tmp/11mb 200
afirmar "corpo de 13 MB (acima do post_max_size) recebe 413 do nginx" corpo /tmp/13mb 413

# ------------------------------------------ o arquivo da PRIMEIRA emissão do certificado

# Sozinho, num nginx SEM certificado nenhum: é assim que ele roda na VPS, antes de o
# Let's Encrypt emitir o primeiro. Se ele dependesse de certificado, o `nginx -t` o
# recusaria justamente no momento em que ele existe para ser usado.
mkdir -p "$T/so-emissao"
cp "$NGINX/stabilmoney-emitir-certificado.conf" "$T/so-emissao/stabilmoney.conf"
chmod -R a+rX "$T/so-emissao"
emissao_sem_certificado() {
  local saida
  saida="$(docker run --rm -v "$T/so-emissao:/etc/nginx/conf.d:ro" "$IMAGEM" nginx -t 2>&1)" \
    || { DETALHE="$saida"; return 1; }
  grep -q "listen 443" "$NGINX/stabilmoney-emitir-certificado.conf" \
    && { DETALHE="o arquivo da emissão não pode escutar a 443 (precisaria de certificado)"; return 1; }
  return 0
}
afirmar "o arquivo da primeira emissão passa no nginx -t sem certificado nenhum" emissao_sem_certificado

# ---------------------------------------------------------------- resumo

echo ""
echo "$PASSOU passaram, $FALHOU falharam  ($IMAGEM)"
if [ "$FALHOU" -gt 0 ]; then
  printf 'Falharam:%s\n' "$LISTA_FALHAS"
  echo "--- log do nginx:"
  docker logs "$CONTAINER" 2>&1 | tail -n 20
  exit 1
fi
