#!/usr/bin/env bash
#
# Stabil Money — `curl` FALSO para os testes do scripts/deploy.sh (a conferência final).
#
# Responde como o app responderia, pelo estado que o docker-falso-deploy.sh deixou:
#
#   .../up    (http, a porta local)  "manutencao" se o app estiver em manutenção;
#                                    senão SM_FALSO_UP (padrão "ok")
#   .../up    (https, o endereço público)  SM_FALSO_PUBLICO (padrão "ok")
#   .../login  HTML com os assets do build — ou com o Vite de dev se SM_FALSO_HTML_HOT=1
#
# Registra "CURL <url> host=<Host>" em $SM_FALSO_DIR/registro.

set -u

D="${SM_FALSO_DIR:?SM_FALSO_DIR não definido}"
url=""
host=""
while [ $# -gt 0 ]; do
  case "$1" in
    -H)
      case "$2" in Host:*) host="$(printf '%s' "$2" | sed 's/^Host: *//')" ;; esac
      shift 2
      ;;
    --max-time) shift 2 ;;
    http://* | https://*) url="$1"; shift ;;
    *) shift ;;
  esac
done

printf 'CURL %s host=%s\n' "$url" "$host" >> "$D/registro"

case "$url" in
  https://*/up) printf '%s' "${SM_FALSO_PUBLICO:-ok}" ;;
  */up)
    if [ -f "$D/manutencao" ]; then printf 'manutencao'; else printf '%s' "${SM_FALSO_UP:-ok}"; fi
    ;;
  */login)
    if [ -n "${SM_FALSO_HTML_HOT-}" ]; then
      printf '<script type="module" src="http://127.0.0.1:5173/@vite/client"></script>'
    else
      printf '<link rel="stylesheet" href="https://stabilmoney.victordemelo.com.br/build/assets/app.css">'
    fi
    ;;
esac
exit 0
