#!/usr/bin/env bash
#
# Stabil Money — atualiza as faixas de IP da Cloudflare no nginx do host.
#
# A Cloudflare publica as faixas de onde ELA se conecta aos servidores
# (https://www.cloudflare.com/ips/). O nginx do host usa essa lista em dois arquivos:
#
#   /etc/nginx/snippets/cloudflare-ip-real.conf   de quais conexões ele aceita o
#                                                 CF-Connecting-IP (o IP real do visitante)
#   /etc/nginx/conf.d/cloudflare-origem.conf      a variável $stabilmoney_via_cloudflare, que
#                                                 fecha a conexão de quem fala direto com a
#                                                 VPS, sem passar pela Cloudflare
#
# Faixa nova que falta aqui = visitante que chega por ela aparece com o IP da Cloudflare
# e é barrado pelo site. As faixas mudam raramente, mas mudam: rode uma vez por mês
# (a linha de cron está no guia, docs/deploy-oracle-cloudflare.md).
#
# Uso, no servidor (como root — escreve em /etc/nginx e recarrega o nginx):
#   sudo /usr/local/sbin/stabilmoney-atualizar-ips-cloudflare
#
# Regerar as cópias do repositório (no computador de desenvolvimento, sem nginx):
#   bash deploy/nginx/atualizar-ips-cloudflare.sh --dir deploy/nginx --sem-nginx
#
# Opções:
#   --dir DIR     procura os dois arquivos em DIR (padrão: os lugares acima)
#   --sem-nginx   não roda `nginx -t` nem recarrega
#
# O que ele garante:
#   - só ATUALIZA arquivo que já existe: a instalação é manual (guia), e quem usa o
#     real_ip que o portfólio já tinha não ganha um segundo de surpresa;
#   - nada é escrito se a lista baixada não parecer uma lista de faixas (página de erro,
#     resposta vazia, lista curta demais, linha que não é faixa);
#   - `nginx -t` reprovou? Os arquivos anteriores voltam e o nginx não é recarregado;
#   - nada mudou? Não escreve nem recarrega.
#
# Variáveis: SM_CF_URL_V4 e SM_CF_URL_V6 (de onde baixar), SM_CF_MIN_V4 e SM_CF_MIN_V6
# (quantas faixas no mínimo: padrão 10 e 4 — em 23/09/2026 eram 15 e 7).

set -euo pipefail
umask 022

URL_V4="${SM_CF_URL_V4:-https://www.cloudflare.com/ips-v4}"
URL_V6="${SM_CF_URL_V6:-https://www.cloudflare.com/ips-v6}"
MIN_V4="${SM_CF_MIN_V4:-10}"
MIN_V6="${SM_CF_MIN_V6:-4}"
ARQ_REAL="/etc/nginx/snippets/cloudflare-ip-real.conf"
ARQ_ORIGEM="/etc/nginx/conf.d/cloudflare-origem.conf"
USAR_NGINX=1

falha() {
  printf 'ERRO: %s\n' "$*" >&2
  exit 1
}

while [ $# -gt 0 ]; do
  case "$1" in
    --dir)
      [ -n "${2-}" ] || falha "--dir precisa de um diretório"
      ARQ_REAL="$2/cloudflare-ip-real.conf"
      ARQ_ORIGEM="$2/cloudflare-origem.conf"
      shift 2
      ;;
    --sem-nginx) USAR_NGINX=0; shift ;;
    -h | --help)
      awk 'NR == 1 { next } /^#/ { sub(/^# ?/, ""); print; next } { exit }' "$0"
      exit 0
      ;;
    *) falha "opção desconhecida: $1 (use --help)" ;;
  esac
done

TMP="$(mktemp -d "${TMPDIR:-/tmp}/sm-cf-ips.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT

ALVOS=""
for alvo in "$ARQ_REAL" "$ARQ_ORIGEM"; do
  if [ -f "$alvo" ]; then
    [ -w "$alvo" ] && [ -w "$(dirname "$alvo")" ] || falha "sem permissão para escrever em $alvo — rode com sudo."
    ALVOS="$ALVOS $alvo"
  fi
done
[ -n "$ALVOS" ] || falha "não achei $ARQ_REAL nem $ARQ_ORIGEM. A primeira instalação é à mão (docs/deploy-oracle-cloudflare.md); este script só atualiza."

# ------------------------------------------------------------------ baixar e conferir

baixar() { # <url> <destino>
  curl -fsS --max-time 30 --retry 2 -o "$2" "$1" || falha "não consegui baixar $1 — nada foi mudado."
}
baixar "$URL_V4" "$TMP/v4.baixado"
baixar "$URL_V6" "$TMP/v6.baixado"

# Uma faixa por linha, sem \r, sem espaços, sem linha vazia, sem repetição — e com o
# \n no fim que a lista da Cloudflare às vezes não traz.
normalizar() {
  tr -d '\r' < "$1" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' | awk 'NF && !visto[$0]++'
}
normalizar "$TMP/v4.baixado" > "$TMP/v4"
normalizar "$TMP/v6.baixado" > "$TMP/v6"

# Linhas que NÃO são faixa IPv4 (octetos até 255, prefixo de /8 a /32).
invalidas_v4() {
  awk -F '[./]' '
    !/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+\/[0-9]+$/ { print; next }
    $1 > 255 || $2 > 255 || $3 > 255 || $4 > 255 || $5 < 8 || $5 > 32 { print }
  ' "$1"
}
# Linhas que NÃO são faixa IPv6 (hexadecimal e ":", com "::" ou 7 ":", prefixo de /16 a /128).
invalidas_v6() {
  awk -F '/' '
    !/^[0-9A-Fa-f:]+\/[0-9]+$/ { print; next }
    $2 < 16 || $2 > 128 { print; next }
    { n = gsub(/:/, ":", $1); if (index($1, "::") == 0 && n != 7) print }
  ' "$1"
}

conferir_lista() { # <arquivo> <nome> <mínimo> <função de validação> <variável do mínimo>
  local ruins n
  ruins="$("$4" "$1")"
  if [ -n "$ruins" ]; then
    falha "a lista $2 baixada tem linha que não é faixa de IP ($(printf '%s\n' "$ruins" | sed -n 1p | cut -c1-80)) — página de erro no lugar da lista? Nada foi mudado."
  fi
  n="$(wc -l < "$1" | tr -d ' ')"
  if [ "$n" -lt "$3" ]; then
    falha "a lista $2 tem só $n faixa(s), e o mínimo esperado é $3. Nada foi mudado. (Se a Cloudflare realmente encolheu a lista, confira em https://www.cloudflare.com/ips/ e rode com $5=$n.)"
  fi
}
conferir_lista "$TMP/v4" IPv4 "$MIN_V4" invalidas_v4 SM_CF_MIN_V4
conferir_lista "$TMP/v6" IPv6 "$MIN_V6" invalidas_v6 SM_CF_MIN_V6
cat "$TMP/v4" "$TMP/v6" > "$TMP/todas"

# ------------------------------------------------------------------ gerar

HOJE="$(date -u +%Y-%m-%d)"

gerar_ip_real() {
  cat << FIM
# =============================================================================
# IP real do visitante atrás da Cloudflare.
#
# Incluído DENTRO do server do site (include snippets/cloudflare-ip-real.conf;):
# no contexto do server, nunca duplica o real_ip que outro site já tenha.
#
# A conexão chega de um IP da Cloudflare; o do visitante vem no CF-Connecting-IP.
# O nginx só acredita nesse cabeçalho quando a CONEXÃO vem destas faixas — de
# qualquer outro lugar ele é ignorado, e ninguém forja o próprio IP.
#
# GERADO por deploy/nginx/atualizar-ips-cloudflare.sh a partir de
# https://www.cloudflare.com/ips-v4 e https://www.cloudflare.com/ips-v6.
# Não edite à mão: rode o script. Lista atualizada em $HOJE.
# =============================================================================

FIM
  awk '{ printf "set_real_ip_from %s;\n", $0 }' "$TMP/todas"
  printf '\n%s\n' "real_ip_header CF-Connecting-IP;"
}

gerar_origem() {
  cat << FIM
# =============================================================================
# \$stabilmoney_via_cloudflare — 1 quando a CONEXÃO veio de uma faixa da
# Cloudflare, 0 quando não. Nível http (conf.d/): o server do Stabil Money fecha
# (444) a conexão de quem fala direto com a VPS, sem passar pela Cloudflare.
#
# Olha \$realip_remote_addr — o endereço ORIGINAL da conexão, antes de o real_ip
# trocá-lo pelo do visitante. Com \$remote_addr seria o IP do visitante, e todo
# mundo seria barrado. (Pelo mesmo motivo, allow/deny não serviriam aqui.)
#
# GERADO por deploy/nginx/atualizar-ips-cloudflare.sh a partir de
# https://www.cloudflare.com/ips-v4 e https://www.cloudflare.com/ips-v6.
# Não edite à mão: rode o script. Lista atualizada em $HOJE.
# =============================================================================

geo \$realip_remote_addr \$stabilmoney_via_cloudflare {
    default 0;
FIM
  awk '{ printf "    %s 1;\n", $0 }' "$TMP/todas"
  printf '%s\n' "}"
}

# O que importa num arquivo: as linhas que não são comentário.
conteudo() { grep -v '^#' "$1" 2> /dev/null || true; }

MUDOU=""
for alvo in $ALVOS; do
  case "$(basename "$alvo")" in
    cloudflare-ip-real.conf) gerar_ip_real > "$TMP/novo" ;;
    cloudflare-origem.conf) gerar_origem > "$TMP/novo" ;;
  esac
  if [ "$(conteudo "$alvo")" = "$(conteudo "$TMP/novo")" ]; then
    continue
  fi
  cp -p "$alvo" "$TMP/anterior-$(basename "$alvo")"
  cp "$TMP/novo" "$TMP/pronto-$(basename "$alvo")"
  MUDOU="$MUDOU $alvo"
done

N4="$(wc -l < "$TMP/v4" | tr -d ' ')"
N6="$(wc -l < "$TMP/v6" | tr -d ' ')"
if [ -z "$MUDOU" ]; then
  printf '%s\n' "Nada mudou: $N4 faixas IPv4 e $N6 IPv6, iguais às dos arquivos ($(printf '%s' "$ALVOS" | sed 's/^ //'))."
  exit 0
fi

# O que entrou e o que saiu, para o log do cron contar a história.
# (`|| true`: arquivo sem faixa nenhuma faz o grep sair com 1, e o pipefail derrubaria o script.)
ANTIGAS="$(for alvo in $MUDOU; do conteudo "$alvo"; done | grep -Eo '[0-9A-Fa-f:.]+/[0-9]+' | sort -u || true)"
NOVAS="$(sort -u "$TMP/todas")"
# Here-string, e não `printf | grep -q`: com pipefail, o grep -q que acha cedo pode fazer
# o cano inteiro "falhar" — e a faixa que existia seria listada como nova.
while IFS= read -r f; do
  grep -qxF "$f" <<< "$ANTIGAS" || printf '  + %s\n' "$f"
done <<< "$NOVAS"
while IFS= read -r f; do
  [ -n "$f" ] || continue
  grep -qxF "$f" <<< "$NOVAS" || printf '  - %s\n' "$f"
done <<< "$ANTIGAS"

# ------------------------------------------------------------------ instalar

# Troca atômica: escreve ao lado com nome que NÃO termina em .conf (o nginx inclui
# conf.d/*.conf — um arquivo pela metade com esse nome seria lido) e renomeia.
for alvo in $MUDOU; do
  cp "$TMP/pronto-$(basename "$alvo")" "$alvo.novo.$$"
  chmod 644 "$alvo.novo.$$"
  mv -f "$alvo.novo.$$" "$alvo"
done

voltar_os_anteriores() {
  for alvo in $MUDOU; do
    cp -p "$TMP/anterior-$(basename "$alvo")" "$alvo"
  done
}

if [ "$USAR_NGINX" -eq 1 ]; then
  if ! SAIDA="$(nginx -t 2>&1)"; then
    voltar_os_anteriores
    printf '%s\n' "$SAIDA" >&2
    falha "o nginx -t reprovou a lista nova (acima). Os arquivos anteriores voltaram, e o nginx não foi recarregado."
  fi
  if command -v systemctl > /dev/null 2>&1; then
    systemctl reload nginx || falha "o systemctl reload nginx falhou — confira: sudo systemctl status nginx"
  else
    nginx -s reload || falha "o nginx -s reload falhou"
  fi
  printf '%s\n' "Atualizado e recarregado:$MUDOU ($N4 faixas IPv4, $N6 IPv6)."
else
  printf '%s\n' "Atualizado (sem nginx):$MUDOU ($N4 faixas IPv4, $N6 IPv6)."
fi
