#!/usr/bin/env bash
#
# Stabil Money — backup do banco MySQL.
#
# Roda um mysqldump dentro do container `db` do docker-compose e grava um arquivo
# .sql.gz datado, com rotação automática. Depois de gravar, CONFERE que o dump tem
# tabelas de verdade — backup vazio que ninguém percebe é pior que backup nenhum.
#
# Uso:
#   ./scripts/backup-db.sh                  # backup no diretório padrão
#   ./scripts/backup-db.sh --saida /mnt/hd  # em outro diretório
#   ./scripts/backup-db.sh --manter 30      # guarda os 30 mais recentes
#   ./scripts/backup-db.sh --banco outro    # outro banco que não o do .env
#
# Variáveis de ambiente equivalentes: SM_BACKUP_DIR, SM_BACKUP_KEEP, SM_DB_SERVICE.
#
# SEGURANÇA — a senha do banco NUNCA aparece na linha de comando (`ps` do host lê
# a linha de comando de qualquer processo). Ela é escrita, via STDIN, num arquivo
# de defaults com umask 077 DENTRO do container, e apagado no final (trap).
# Por isso também não usamos `mysqldump -p$SENHA`.

set -euo pipefail

# ---------------------------------------------------------------- utilitários

vermelho() { printf '\033[31m%s\033[0m\n' "$*" >&2; }
verde()    { printf '\033[32m%s\033[0m\n' "$*"; }
info()     { printf '%s\n' "$*"; }
erro()     { vermelho "ERRO: $*"; exit 1; }

uso() {
  sed -n '3,21p' "$0" | sed 's/^# \{0,1\}//'
  exit 0
}

# Lê uma chave do .env sem executá-lo (`source .env` rodaria comandos que
# estivessem lá dentro). Devolve o padrão quando a chave não existe.
ler_env() {
  chave="$1"; padrao="${2-}"
  if [ ! -f "$ARQUIVO_ENV" ]; then printf '%s' "$padrao"; return; fi
  linha="$(grep -E "^[[:space:]]*${chave}=" "$ARQUIVO_ENV" | tail -n 1 || true)"
  if [ -z "$linha" ]; then printf '%s' "$padrao"; return; fi
  valor="${linha#*=}"
  # Remove aspas envolventes, se houver.
  case "$valor" in
    \"*\") valor="${valor#\"}"; valor="${valor%\"}" ;;
    \'*\') valor="${valor#\'}"; valor="${valor%\'}" ;;
  esac
  printf '%s' "$valor"
}

# Escapa para o formato my.cnf (dentro de aspas duplas, `\` e `"` são especiais).
escapar_cnf() {
  printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'
}

# ---------------------------------------------------------------- configuração

RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
cd "$RAIZ"

ARQUIVO_ENV="$RAIZ/.env"
DESTINO="${SM_BACKUP_DIR:-$RAIZ/storage/backups}"
MANTER="${SM_BACKUP_KEEP:-14}"
SERVICO_DB="${SM_DB_SERVICE:-db}"

BANCO=""

while [ $# -gt 0 ]; do
  case "$1" in
    --saida)   DESTINO="${2:?--saida precisa de um diretório}"; shift 2 ;;
    --manter)  MANTER="${2:?--manter precisa de um número}";    shift 2 ;;
    --servico) SERVICO_DB="${2:?--servico precisa de um nome}"; shift 2 ;;
    --banco)   BANCO="${2:?--banco precisa de um nome}";        shift 2 ;;
    -h|--help) uso ;;
    *) erro "opção desconhecida: $1 (use --help)" ;;
  esac
done

case "$MANTER" in
  ''|*[!0-9]*) erro "--manter precisa ser um número inteiro (recebido: $MANTER)" ;;
esac
[ "$MANTER" -ge 1 ] || erro "--manter precisa ser pelo menos 1"

[ -f "$ARQUIVO_ENV" ] || erro "não achei o .env em $ARQUIVO_ENV"

# Os defaults abaixo são os mesmos do docker-compose.yml.
DB_DATABASE="$(ler_env DB_DATABASE stabilmoney)"
DB_USERNAME="$(ler_env DB_USERNAME user)"
DB_PASSWORD="$(ler_env DB_PASSWORD password)"
if [ -n "$BANCO" ]; then DB_DATABASE="$BANCO"; fi

# O nome vira parte do nome do arquivo e do padrão de rotação.
case "$DB_DATABASE" in
  *[!A-Za-z0-9_]*|'') erro "nome de banco inválido: '$DB_DATABASE'" ;;
esac

# `docker compose` (v2) ou `docker-compose` (v1)?
if docker compose version >/dev/null 2>&1; then
  DC=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
  DC=(docker-compose)
else
  erro "não achei o Docker Compose. Suba os containers com: docker compose up -d"
fi

# Aviso amigável quando o container está parado. Se a listagem não funcionar (Compose
# v1 não tem --status), seguimos em frente: o próprio exec falha logo adiante.
SERVICOS_ATIVOS="$("${DC[@]}" ps --status running --services 2>/dev/null || true)"
if [ -n "$SERVICOS_ATIVOS" ] && ! printf '%s\n' "$SERVICOS_ATIVOS" | grep -qx "$SERVICO_DB"; then
  erro "o serviço '$SERVICO_DB' não está rodando. Suba com: docker compose up -d"
fi

# ---------------------------------------------------------------- preparação

mkdir -p "$DESTINO"
chmod 700 "$DESTINO" 2>/dev/null || true

# Cinto de segurança: os dumps têm dados pessoais de TODOS os usuários em texto
# legível. Um .gitignore dentro do próprio diretório impede que um `git add .`
# distraído mande isso para o repositório, mesmo que ninguém tenha lembrado de
# adicionar a linha no .gitignore da raiz. Só faz sentido dentro do projeto.
case "$DESTINO" in
  "$RAIZ"/*) [ -f "$DESTINO/.gitignore" ] || printf '*\n' > "$DESTINO/.gitignore" ;;
esac

CARIMBO="$(date +%Y%m%d-%H%M%S)"
ARQUIVO="$DESTINO/${DB_DATABASE}-${CARIMBO}.sql.gz"
ARQ_TMP="${ARQUIVO}.parcial"

# O .cnf vive dentro do container e some no final, aconteça o que acontecer.
CNF_CONTAINER="/tmp/.sm-backup-$$-${CARIMBO}.cnf"

limpar() {
  code=$?
  "${DC[@]}" exec -T "$SERVICO_DB" rm -f "$CNF_CONTAINER" >/dev/null 2>&1 || true
  rm -f -- "$ARQ_TMP" "/tmp/sm-backup-erro.$$" 2>/dev/null || true
  exit $code
}
trap limpar EXIT INT TERM

printf '[client]\nuser="%s"\npassword="%s"\n' \
  "$(escapar_cnf "$DB_USERNAME")" "$(escapar_cnf "$DB_PASSWORD")" \
  | "${DC[@]}" exec -T "$SERVICO_DB" sh -c "umask 077; cat > '$CNF_CONTAINER'" \
  || erro "não consegui gravar as credenciais temporárias no container"

# ---------------------------------------------------------------- o dump

info "Banco:   $DB_DATABASE (container '$SERVICO_DB')"
info "Destino: $ARQUIVO"

# --single-transaction: consistente sem travar as tabelas (InnoDB).
# --no-tablespaces:     sem isso o MySQL 8 exige o privilégio PROCESS.
# --routines/--triggers: leva stored procedures e triggers junto.
set +e
"${DC[@]}" exec -T "$SERVICO_DB" mysqldump \
  "--defaults-extra-file=$CNF_CONTAINER" \
  --single-transaction \
  --quick \
  --no-tablespaces \
  --routines \
  --triggers \
  --default-character-set=utf8mb4 \
  "$DB_DATABASE" 2>/tmp/sm-backup-erro.$$ | gzip -9 > "$ARQ_TMP"
STATUS=${PIPESTATUS[0]}
set -e

if [ "$STATUS" -ne 0 ]; then
  vermelho "--- saída do mysqldump ---"
  cat /tmp/sm-backup-erro.$$ >&2 || true
  rm -f /tmp/sm-backup-erro.$$
  erro "o mysqldump falhou (código $STATUS). Nada foi gravado."
fi
# Avisos do mysqldump (ex.: charset) não invalidam o backup, mas o operador deve ver.
if [ -s /tmp/sm-backup-erro.$$ ]; then cat /tmp/sm-backup-erro.$$ >&2; fi
rm -f /tmp/sm-backup-erro.$$

# ------------------------------------------------- conferência (o passo que salva)

# Descompacta o arquivo inteiro: valida o gzip E conta as tabelas de uma vez.
TABELAS="$(gunzip -c "$ARQ_TMP" 2>/dev/null | grep -c '^CREATE TABLE' || true)"
[ -n "$TABELAS" ] || TABELAS=0

if [ "$TABELAS" -lt 1 ]; then
  erro "o dump saiu SEM nenhum 'CREATE TABLE'. Arquivo descartado — isso não é backup."
fi

mv -- "$ARQ_TMP" "$ARQUIVO"
chmod 600 "$ARQUIVO"

TAMANHO="$(du -h "$ARQUIVO" | cut -f1)"
verde "OK — $TABELAS tabelas, $TAMANHO"

# ---------------------------------------------------------------- rotação

# O nome carrega a data em ISO, então ordem alfabética = ordem cronológica.
REMOVIDOS=0
while IFS= read -r velho; do
  [ -n "$velho" ] || continue
  rm -f -- "$velho"
  info "rotação: apagado $(basename "$velho")"
  REMOVIDOS=$((REMOVIDOS + 1))
done < <(find "$DESTINO" -maxdepth 1 -type f -name "${DB_DATABASE}-*.sql.gz" \
           | sort -r | awk -v n="$MANTER" 'NR>n')

RESTANTES="$(find "$DESTINO" -maxdepth 1 -type f -name "${DB_DATABASE}-*.sql.gz" | wc -l | tr -d ' ')"
info "Backups guardados: $RESTANTES (limite: $MANTER)"

info ""
info "Para inspecionar:  gunzip -c '$ARQUIVO' | less"
info "Para restaurar:    ./scripts/restore-db.sh '$ARQUIVO'"
