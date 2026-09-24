#!/usr/bin/env bash
#
# Testes do ajuste de permissões do scripts/deploy.sh (passos 4 e 8) — o trecho que roda
# como ROOT, dentro do container do app, sobre storage/ e bootstrap/cache: justamente as
# pastas em que o SITE (www-data) escreve.
#
#   bash tests/scripts/permissoes-do-deploy.test.sh
#
# Precisa de Docker: o chown para www-data exige root e o usuário www-data da imagem. Sem
# Docker, diz que foi pulado e sai com 0. SM_PERMISSOES_IMAGEM troca a imagem (padrão:
# php:8.4-apache, a base do Dockerfile — o mesmo find/chown GNU da VPS). O container roda
# sem rede.
#
# O trecho testado é EXTRAÍDO do deploy.sh, e não copiado: é o que roda de verdade.
# SM_SCRIPTS_SOB_TESTE=<dir> extrai de OUTRA cópia de scripts/ (como no deploy.test.sh),
# para provar que o teste reprova uma versão quebrada.
#
# O que ele prova:
#   - o de sempre: storage/ e bootstrap/cache do www-data com o grupo do deploy (2770/660),
#     storage/backups intocado (700/600 do deploy) e o .env do deploy com grupo www-data (640);
#   - um ATALHO deixado pelo site em storage/ não passa o alvo para o www-data. Antes, com
#     `-exec chown` (que segue atalho), storage/logs/x -> .git/hooks dava .git/hooks ao
#     www-data no deploy seguinte — e o `git fetch`/`git merge` do deploy roda os ganchos de
#     lá como o usuário de deploy NO HOST, que está no grupo docker (= root na VPS).

set -u

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
SOB_TESTE="$(cd "${SM_SCRIPTS_SOB_TESTE:-$REPO/scripts}" && pwd)"
IMAGEM="${SM_PERMISSOES_IMAGEM:-php:8.4-apache}"

if ! command -v docker > /dev/null 2>&1 || ! docker info > /dev/null 2>&1; then
  echo "# sem Docker: testes das permissões do deploy PULADOS"
  exit 0
fi

T="$(mktemp -d "${TMPDIR:-/tmp}/sm-teste-permissoes.XXXXXX")"
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
  if [ -n "${2-}" ]; then printf '%s\n' "$2" | sed -n '1,14p' | sed 's/^/          | /'; fi
}

# ------------------------------------------------ o trecho do deploy.sh

# Do `no_app app sh -c '` até a linha `' sh "$(id -g)" "$(id -u)"` da função ajustar_permissoes.
awk '
  /no_app app sh -c .$/ { dentro = 1; next }
  dentro && /^ *. sh "\$\(id -g\)" "\$\(id -u\)"$/ { exit }
  dentro { print }
' "$SOB_TESTE/deploy.sh" > "$T/permissoes.sh"

if ! grep -q 'chown' "$T/permissoes.sh"; then
  echo "ABORTADO: não achei o ajuste de permissões (no_app app sh -c '...') em $SOB_TESTE/deploy.sh" >&2
  exit 2
fi

# ------------------------------------------------ o cenário, dentro do container

# Um projeto como o da VPS: dono o usuário de deploy (uid 1000). Roda o ajuste (primeiro
# deploy), o site deixa atalhos em storage/, roda o ajuste de novo (deploy seguinte) e
# imprime "chave=valor" para o lado de fora conferir.
cat > "$T/cenario.sh" << 'FIM'
set -eu
useradd -u 1000 -M -s /bin/sh deploy
mkdir -p /proj && cd /proj
mkdir -p storage/logs storage/framework/views storage/backups bootstrap/cache .git/hooks
echo 'APP_KEY=segredo' > .env
echo 'log' > storage/logs/laravel.log
echo 'dump' > storage/backups/stabilmoney-20260924-030000.sql.gz
echo '<?php' > artisan
chown -R 1000:1000 /proj
chmod 700 storage/backups
chmod 600 storage/backups/stabilmoney-20260924-030000.sql.gz

estado() { stat -c '%U:%G %a' "$1"; }

sh /permissoes.sh 1000 1000
echo "storage=$(estado storage)"
echo "log=$(estado storage/logs/laravel.log)"
echo "cache=$(estado bootstrap/cache)"
echo "backups=$(estado storage/backups)"
echo "dump=$(estado storage/backups/stabilmoney-20260924-030000.sql.gz)"
echo "env=$(estado .env)"

# O site (www-data) deixa atalhos para fora de storage/.
su -s /bin/sh www-data -c 'ln -s /proj/.git/hooks /proj/storage/logs/atalho-hooks'
su -s /bin/sh www-data -c 'ln -s /proj/artisan /proj/storage/framework/views/atalho-artisan'

sh /permissoes.sh 1000 1000
echo "hooks=$(estado .git/hooks)"
echo "artisan=$(estado artisan)"
if su -s /bin/sh www-data -c 'echo "#!/bin/sh" > /proj/.git/hooks/post-merge' 2> /dev/null; then
  echo "gancho=gravado"
else
  echo "gancho=recusado"
fi
FIM

if ! docker run --rm --network none \
  -v "$T/permissoes.sh:/permissoes.sh:ro" -v "$T/cenario.sh:/cenario.sh:ro" \
  "$IMAGEM" bash /cenario.sh > "$T/saida" 2>&1; then
  echo "ABORTADO: o cenário não rodou no container ($IMAGEM):" >&2
  sed 's/^/  | /' "$T/saida" >&2
  exit 2
fi

valor() { sed -n "s/^$1=//p" "$T/saida" | tail -n 1; }
afirmar() { # <descrição> <chave> <esperado>
  local obtido
  obtido="$(valor "$2")"
  if [ "$obtido" = "$3" ]; then ok "$1"; else falhou "$1" "$2: esperado '$3', veio '$obtido'"; fi
}

afirmar "storage/: dono www-data, grupo do deploy, 2770" storage "www-data:deploy 2770"
afirmar "arquivo em storage/: dono www-data, grupo do deploy, 660" log "www-data:deploy 660"
afirmar "bootstrap/cache: dono www-data, grupo do deploy, 2770" cache "www-data:deploy 2770"
afirmar "storage/backups fica com o deploy (700): o site não lê os dumps" backups "deploy:deploy 700"
afirmar "o dump dentro de storage/backups continua 600 do deploy" dump "deploy:deploy 600"
afirmar ".env: dono o deploy, grupo www-data, 640" env "deploy:www-data 640"
afirmar "atalho do site para .git/hooks: o alvo continua do deploy" hooks "deploy:deploy 755"
afirmar "atalho do site para um arquivo do código: o alvo continua do deploy" artisan "deploy:deploy 644"
afirmar "o site não consegue gravar um gancho do git (o deploy o rodaria no host)" gancho "recusado"

# ---------------------------------------------------------------- resumo

echo ""
echo "$PASSOU passaram, $FALHOU falharam  ($IMAGEM)"
if [ "$FALHOU" -gt 0 ]; then
  printf 'Falharam:%s\n' "$LISTA_FALHAS"
  echo "--- saída do cenário:"
  sed 's/^/  | /' "$T/saida"
  exit 1
fi
