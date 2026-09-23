#!/usr/bin/env bash
#
# Testes do deploy/nginx/atualizar-ips-cloudflare.sh. Rodam em qualquer máquina, SEM
# rede, SEM nginx e sem root:
#
#   bash tests/scripts/atualizar-ips-cloudflare.test.sh
#
# O script roda DE VERDADE, com `curl`, `nginx` e `systemctl` falsos na frente do PATH:
# o curl entrega as listas que o teste escolher (as oficiais de 23/09/2026 ficam em
# tests/scripts/fixtures/), o nginx -t aprova ou reprova, e cada chamada é registrada.
# Prova que lista ruim nunca chega ao nginx, que nginx -t reprovado devolve os arquivos
# anteriores, e que os arquivos do repositório são exatamente o que o script gera.

set -u

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
SCRIPT="$REPO/deploy/nginx/atualizar-ips-cloudflare.sh"
FIXTURES="$REPO/tests/scripts/fixtures"
T="$(mktemp -d "${TMPDIR:-/tmp}/sm-teste-cf-ips.XXXXXX")"
trap 'rm -rf "$T"' EXIT

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
contem() { case "$1" in *"$2"*) return 0 ;; esac; return 1; }

# ------------------------------------------------------------------ os falsos

mkdir -p "$T/bin"
cat > "$T/bin/curl" << 'FIM'
#!/usr/bin/env bash
# curl falso: entrega SM_FALSO_V4 / SM_FALSO_V6 (arquivos) conforme a URL.
saida=""; url=""
while [ $# -gt 0 ]; do
  case "$1" in
    -o) saida="$2"; shift 2 ;;
    --max-time | --retry) shift 2 ;;
    -*) shift ;;
    *) url="$1"; shift ;;
  esac
done
echo "CURL $url" >> "$SM_FALSO_REGISTRO"
if [ -n "${SM_FALSO_CURL_FALHA-}" ]; then echo "curl: (6) Could not resolve host" >&2; exit 6; fi
case "$url" in
  *v4) cp "$SM_FALSO_V4" "$saida" ;;
  *v6) cp "$SM_FALSO_V6" "$saida" ;;
  *) exit 22 ;;
esac
FIM
cat > "$T/bin/nginx" << 'FIM'
#!/usr/bin/env bash
echo "NGINX $*" >> "$SM_FALSO_REGISTRO"
if [ "${1-}" = "-t" ] && [ -n "${SM_FALSO_NGINX_REPROVA-}" ]; then
  echo 'nginx: [emerg] invalid parameter "2400:cb00::/33x" in /etc/nginx/conf.d/cloudflare-origem.conf:20' >&2
  echo "nginx: configuration file /etc/nginx/nginx.conf test failed" >&2
  exit 1
fi
exit 0
FIM
cat > "$T/bin/systemctl" << 'FIM'
#!/usr/bin/env bash
echo "SYSTEMCTL $*" >> "$SM_FALSO_REGISTRO"
exit 0
FIM
chmod +x "$T/bin/"*

# ------------------------------------------------------------------ cenários

# Um cenário: uma pasta com os dois arquivos do repositório (o estado "instalado").
novo_cenario() { # <nome>
  C="$T/cenarios/$1"
  mkdir -p "$C/nginx"
  cp "$REPO/deploy/nginx/cloudflare-ip-real.conf" "$REPO/deploy/nginx/cloudflare-origem.conf" "$C/nginx/"
  : > "$C/registro"
  V4="$FIXTURES/cloudflare-ips-v4.txt"
  V6="$FIXTURES/cloudflare-ips-v6.txt"
}
rodar() { # [args]
  env PATH="$T/bin:$PATH" SM_FALSO_REGISTRO="$C/registro" SM_FALSO_V4="$V4" SM_FALSO_V6="$V6" \
    bash "$SCRIPT" --dir "$C/nginx" "$@" > "$C/saida" 2>&1 < /dev/null
  CODIGO=$?
  SAIDA="$(cat "$C/saida")"
}
conteudo() { grep -v '^#' "$1"; }
iguais_ao_repositorio() {
  local f
  for f in cloudflare-ip-real.conf cloudflare-origem.conf; do
    [ "$(conteudo "$C/nginx/$f")" = "$(conteudo "$REPO/deploy/nginx/$f")" ] || { DETALHE="$f mudou"; return 1; }
  done
}
registrou() { grep -qF -- "$1" "$C/registro"; }
nao_recarregou() {
  if registrou "SYSTEMCTL" || registrou "NGINX -s"; then DETALHE="recarregou: $(cat "$C/registro")"; return 1; fi
}
recusou_sem_mudar() { # <trecho da mensagem>
  [ "$CODIGO" -ne 0 ] || { DETALHE="saiu com 0: $SAIDA"; return 1; }
  contem "$SAIDA" "$1" || { DETALHE="faltou '$1': $SAIDA"; return 1; }
  iguais_ao_repositorio || return 1
  nao_recarregou || return 1
  ! registrou "NGINX -t" || { DETALHE="chegou ao nginx -t"; return 1; }
}

# Os arquivos versionados são exatamente o que o script gera com a lista oficial.
novo_cenario repositorio
rodar
repositorio_em_dia() {
  [ "$CODIGO" -eq 0 ] || { DETALHE="$SAIDA"; return 1; }
  contem "$SAIDA" "Nada mudou: 15 faixas IPv4 e 7 IPv6" || { DETALHE="$SAIDA"; return 1; }
  iguais_ao_repositorio && nao_recarregou
}
afirmar "os arquivos do repositório são o que o script gera com a lista oficial (sem recarregar nada)" repositorio_em_dia

faixas_nos_dois() {
  local f n
  for f in $(tr -d '\r' < "$FIXTURES/cloudflare-ips-v4.txt") $(tr -d '\r' < "$FIXTURES/cloudflare-ips-v6.txt"); do
    grep -qxF "set_real_ip_from $f;" "$REPO/deploy/nginx/cloudflare-ip-real.conf" || { DETALHE="falta $f no ip-real"; return 1; }
    grep -qxF "    $f 1;" "$REPO/deploy/nginx/cloudflare-origem.conf" || { DETALHE="falta $f no origem"; return 1; }
  done
  n="$(grep -c '^set_real_ip_from ' "$REPO/deploy/nginx/cloudflare-ip-real.conf")"
  [ "$n" = 22 ] || { DETALHE="$n faixas no ip-real (esperado 22)"; return 1; }
  grep -qx 'real_ip_header CF-Connecting-IP;' "$REPO/deploy/nginx/cloudflare-ip-real.conf" || { DETALHE="sem real_ip_header"; return 1; }
  grep -q '^geo \$realip_remote_addr \$stabilmoney_via_cloudflare {$' "$REPO/deploy/nginx/cloudflare-origem.conf" \
    || { DETALHE="o geo não olha o \$realip_remote_addr"; return 1; }
}
afirmar "as 22 faixas oficiais (15 IPv4 + 7 IPv6) estão nos dois arquivos, e o geo olha o endereço ORIGINAL da conexão" faixas_nos_dois

novo_cenario faixa-nova
printf '%s\n' "$(cat "$FIXTURES/cloudflare-ips-v4.txt")" "198.51.100.0/24" > "$C/v4-com-nova"
V4="$C/v4-com-nova"
rodar
atualizou() {
  [ "$CODIGO" -eq 0 ] || { DETALHE="$SAIDA"; return 1; }
  grep -qxF "set_real_ip_from 198.51.100.0/24;" "$C/nginx/cloudflare-ip-real.conf" || { DETALHE="ip-real sem a faixa nova"; return 1; }
  grep -qxF "    198.51.100.0/24 1;" "$C/nginx/cloudflare-origem.conf" || { DETALHE="origem sem a faixa nova"; return 1; }
  contem "$SAIDA" "+ 198.51.100.0/24" || { DETALHE="não contou o que entrou: $SAIDA"; return 1; }
  registrou "NGINX -t" || { DETALHE="não rodou nginx -t"; return 1; }
  registrou "SYSTEMCTL reload nginx" || { DETALHE="não recarregou: $(cat "$C/registro")"; return 1; }
}
afirmar "faixa nova na lista: os dois arquivos ganham a faixa, o nginx -t roda e o nginx recarrega" atualizou

novo_cenario faixa-removida
grep -v '^131.0.72.0/22' "$FIXTURES/cloudflare-ips-v4.txt" > "$C/v4-sem-uma"
V4="$C/v4-sem-uma"
rodar
contou_removida() {
  [ "$CODIGO" -eq 0 ] || { DETALHE="$SAIDA"; return 1; }
  contem "$SAIDA" "- 131.0.72.0/22" || { DETALHE="$SAIDA"; return 1; }
  ! grep -q "131.0.72.0/22" "$C/nginx/cloudflare-origem.conf" || { DETALHE="a faixa removida continua"; return 1; }
}
afirmar "faixa que saiu da lista: sai dos arquivos, e o log diz qual foi" contou_removida

novo_cenario html
printf '%s\n' '<!DOCTYPE html>' '<html><body>Attention Required! | Cloudflare</body></html>' > "$C/pagina"
V4="$C/pagina"
rodar
afirmar "página HTML no lugar da lista: recusa sem tocar em nada" recusou_sem_mudar "não é faixa de IP"

novo_cenario vazia
: > "$C/vazia"
V6="$C/vazia"
rodar
afirmar "lista vazia: recusa sem tocar em nada" recusou_sem_mudar "tem só 0 faixa"

novo_cenario curta
head -n 3 "$FIXTURES/cloudflare-ips-v4.txt" > "$C/curta"
V4="$C/curta"
rodar
afirmar "lista curta demais (3 faixas IPv4): recusa e diz como liberar se for de verdade" recusou_sem_mudar "SM_CF_MIN_V4=3"

novo_cenario prefixo-absurdo
printf '%s\n' "$(cat "$FIXTURES/cloudflare-ips-v4.txt")" "0.0.0.0/0" > "$C/com-tudo"
V4="$C/com-tudo"
rodar
afirmar "faixa /0 (a internet inteira) na lista: recusa" recusou_sem_mudar "não é faixa de IP"

novo_cenario ipv6-quebrado
printf '%s\n' "$(cat "$FIXTURES/cloudflare-ips-v6.txt")" "2400:cb00/32" > "$C/v6-quebrado"
V6="$C/v6-quebrado"
rodar
afirmar "endereço IPv6 malformado na lista: recusa" recusou_sem_mudar "não é faixa de IP"

novo_cenario sem-rede
SM_FALSO_CURL_FALHA=1 rodar
afirmar "sem rede (curl falhou): recusa sem tocar em nada" recusou_sem_mudar "não consegui baixar"

novo_cenario crlf
tr -d '\r' < "$FIXTURES/cloudflare-ips-v6.txt" | sed 's/$/\r/' > "$C/v6-crlf"
V6="$C/v6-crlf"
rodar
aceitou_crlf() {
  [ "$CODIGO" -eq 0 ] || { DETALHE="$SAIDA"; return 1; }
  contem "$SAIDA" "Nada mudou" || { DETALHE="$SAIDA"; return 1; }
}
afirmar "lista com fim de linha do Windows (\\r\\n) e sem \\n no fim: lida igual" aceitou_crlf

novo_cenario nginx-reprova
printf '%s\n' "$(cat "$FIXTURES/cloudflare-ips-v4.txt")" "198.51.100.0/24" > "$C/v4-com-nova"
V4="$C/v4-com-nova"
SM_FALSO_NGINX_REPROVA=1 rodar
devolveu_anteriores() {
  [ "$CODIGO" -ne 0 ] || { DETALHE="saiu com 0: $SAIDA"; return 1; }
  contem "$SAIDA" "arquivos anteriores voltaram" || { DETALHE="$SAIDA"; return 1; }
  contem "$SAIDA" "test failed" || { DETALHE="não mostrou o erro do nginx: $SAIDA"; return 1; }
  iguais_ao_repositorio && nao_recarregou
}
afirmar "nginx -t reprovou: os arquivos anteriores voltam e o nginx não recarrega" devolveu_anteriores

novo_cenario so-origem
rm -f "$C/nginx/cloudflare-ip-real.conf"
printf '%s\n' "$(cat "$FIXTURES/cloudflare-ips-v4.txt")" "198.51.100.0/24" > "$C/v4-com-nova"
V4="$C/v4-com-nova"
rodar
so_o_que_existe() {
  [ "$CODIGO" -eq 0 ] || { DETALHE="$SAIDA"; return 1; }
  [ ! -e "$C/nginx/cloudflare-ip-real.conf" ] || { DETALHE="criou o ip-real que não estava instalado"; return 1; }
  grep -qxF "    198.51.100.0/24 1;" "$C/nginx/cloudflare-origem.conf" || { DETALHE="não atualizou o origem"; return 1; }
}
afirmar "só o arquivo instalado é atualizado (quem usa o real_ip do portfólio não ganha um segundo)" so_o_que_existe

novo_cenario nada-instalado
rm -f "$C/nginx/"*.conf
rodar
nada_instalado() {
  [ "$CODIGO" -ne 0 ] || { DETALHE="saiu com 0"; return 1; }
  contem "$SAIDA" "este script só atualiza" || { DETALHE="$SAIDA"; return 1; }
  [ -z "$(ls "$C/nginx")" ] || { DETALHE="criou: $(ls "$C/nginx")"; return 1; }
}
afirmar "nenhum arquivo instalado: recusa e aponta o guia (não cria nada)" nada_instalado

novo_cenario sem-nginx
printf '%s\n' "$(cat "$FIXTURES/cloudflare-ips-v4.txt")" "198.51.100.0/24" > "$C/v4-com-nova"
V4="$C/v4-com-nova"
rodar --sem-nginx
sem_nginx() {
  [ "$CODIGO" -eq 0 ] || { DETALHE="$SAIDA"; return 1; }
  ! registrou "NGINX" || { DETALHE="chamou o nginx"; return 1; }
  nao_recarregou
}
afirmar "--sem-nginx (regerar os arquivos do repositório): atualiza sem chamar o nginx" sem_nginx

# ---------------------------------------------------------------- resumo

echo ""
echo "$PASSOU passaram, $FALHOU falharam"
if [ "$FALHOU" -gt 0 ]; then
  printf 'Falharam:%s\n' "$LISTA_FALHAS"
  exit 1
fi
