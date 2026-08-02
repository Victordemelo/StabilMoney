#!/usr/bin/env bash
#
# Stabil Money — restauração do banco MySQL a partir de um backup.
#
# Backup que ninguém sabe restaurar não é backup. Este script é o par obrigatório
# do backup-db.sh e faz, nesta ordem:
#   1. valida o arquivo ANTES de destruir qualquer coisa;
#   2. pede confirmação (digitando o nome do banco);
#   3. tira um backup de segurança do estado atual (a rede antes do salto);
#   4. apaga as tabelas atuais e aplica o dump;
#   5. confere quantas tabelas ficaram de pé.
#
# Uso:
#   ./scripts/restore-db.sh                              # usa o backup mais recente
#   ./scripts/restore-db.sh storage/backups/x.sql.gz     # um arquivo específico
#   ./scripts/restore-db.sh x.sql.gz --banco stabil_teste  # ensaio noutro banco
#   ./scripts/restore-db.sh x.sql.gz --sim               # sem perguntar (cuidado)
#
# Aceita .sql.gz e .sql.

set -euo pipefail

# ---------------------------------------------------------------- utilitários

vermelho() { printf '\033[31m%s\033[0m\n' "$*" >&2; }
amarelo()  { printf '\033[33m%s\033[0m\n' "$*"; }
verde()    { printf '\033[32m%s\033[0m\n' "$*"; }
info()     { printf '%s\n' "$*"; }
erro()     { vermelho "ERRO: $*"; exit 1; }

uso() {
  sed -n '3,20p' "$0" | sed 's/^# \{0,1\}//'
  exit 0
}

ler_env() {
  chave="$1"; padrao="${2-}"
  if [ ! -f "$ARQUIVO_ENV" ]; then printf '%s' "$padrao"; return; fi
  linha="$(grep -E "^[[:space:]]*${chave}=" "$ARQUIVO_ENV" | tail -n 1 || true)"
  if [ -z "$linha" ]; then printf '%s' "$padrao"; return; fi
  valor="${linha#*=}"
  case "$valor" in
    \"*\") valor="${valor#\"}"; valor="${valor%\"}" ;;
    \'*\') valor="${valor#\'}"; valor="${valor%\'}" ;;
  esac
  printf '%s' "$valor"
}

escapar_cnf() {
  printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'
}

# ---------------------------------------------------------------- configuração

RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
cd "$RAIZ"

ARQUIVO_ENV="$RAIZ/.env"
ORIGEM="${SM_BACKUP_DIR:-$RAIZ/storage/backups}"
SERVICO_DB="${SM_DB_SERVICE:-db}"
ARQUIVO=""
BANCO=""
CONFIRMADO=0
BACKUP_PREVIO=1

while [ $# -gt 0 ]; do
  case "$1" in
    --banco)              BANCO="${2:?--banco precisa de um nome}";   shift 2 ;;
    --servico)            SERVICO_DB="${2:?--servico precisa de um nome}"; shift 2 ;;
    --sim|-y)             CONFIRMADO=1; shift ;;
    --sem-backup-previo)  BACKUP_PREVIO=0; shift ;;
    -h|--help)            uso ;;
    -*) erro "opção desconhecida: $1 (use --help)" ;;
    *)  ARQUIVO="$1"; shift ;;
  esac
done

[ -f "$ARQUIVO_ENV" ] || erro "não achei o .env em $ARQUIVO_ENV"

DB_DATABASE="$(ler_env DB_DATABASE stabilmoney)"
DB_USERNAME="$(ler_env DB_USERNAME user)"
DB_PASSWORD="$(ler_env DB_PASSWORD password)"
[ -n "$BANCO" ] || BANCO="$DB_DATABASE"

# O nome do banco entra numa query (information_schema) — só aceita identificador.
case "$BANCO" in
  *[!A-Za-z0-9_]*|'') erro "nome de banco inválido: '$BANCO'" ;;
esac

if docker compose version >/dev/null 2>&1; then
  DC=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
  DC=(docker-compose)
else
  erro "não achei o Docker Compose. Suba os containers com: docker compose up -d"
fi

SERVICOS_ATIVOS="$("${DC[@]}" ps --status running --services 2>/dev/null || true)"
if [ -n "$SERVICOS_ATIVOS" ] && ! printf '%s\n' "$SERVICOS_ATIVOS" | grep -qx "$SERVICO_DB"; then
  erro "o serviço '$SERVICO_DB' não está rodando. Suba com: docker compose up -d"
fi

# ---------------------------------------------------- 1. escolher e validar o arquivo

if [ -z "$ARQUIVO" ]; then
  ARQUIVO="$(find "$ORIGEM" -maxdepth 1 -type f -name '*.sql.gz' 2>/dev/null | sort -r | head -n 1)"
  [ -n "$ARQUIVO" ] || erro "nenhum backup encontrado em $ORIGEM (rode ./scripts/backup-db.sh antes)"
  info "Nenhum arquivo informado — usando o mais recente."
fi

[ -f "$ARQUIVO" ] || erro "arquivo não encontrado: $ARQUIVO"

case "$ARQUIVO" in
  *.gz) LER=(gunzip -c "$ARQUIVO") ;;
  *)    LER=(cat "$ARQUIVO") ;;
esac

# Validar ANTES de apagar qualquer coisa: um dump truncado só se descobre lendo.
TABELAS_NO_DUMP="$("${LER[@]}" 2>/dev/null | grep -c '^CREATE TABLE' || true)"
[ -n "$TABELAS_NO_DUMP" ] || TABELAS_NO_DUMP=0
[ "$TABELAS_NO_DUMP" -ge 1 ] \
  || erro "o arquivo não tem nenhum 'CREATE TABLE' (corrompido, vazio ou não é um dump)."

info ""
info "Arquivo: $ARQUIVO"
info "         $(du -h "$ARQUIVO" | cut -f1) · $TABELAS_NO_DUMP tabelas"
info "Destino: banco '$BANCO' no container '$SERVICO_DB'"

# ---------------------------------------------------------------- 2. confirmação

if [ "$CONFIRMADO" -ne 1 ]; then
  amarelo ""
  amarelo "ATENÇÃO: isto APAGA todas as tabelas de '$BANCO' e as recria a partir do backup."
  amarelo "Tudo que foi gravado depois de $(basename "$ARQUIVO") será perdido."
  amarelo ""
  printf 'Digite o nome do banco para confirmar (%s): ' "$BANCO"
  RESPOSTA=""
  if [ -t 0 ]; then
    # Terminal normal.
    read -r RESPOSTA || RESPOSTA=""
  else
    # STDIN redirecionado: tenta o terminal de controle e, se não houver, aceita
    # o que veio pelo pipe (qualquer coisa diferente do nome do banco cancela).
    RESPOSTA="$(head -n 1 /dev/tty 2>/dev/null || true)"
    if [ -z "$RESPOSTA" ]; then read -r RESPOSTA || RESPOSTA=""; fi
  fi
  if [ "$RESPOSTA" != "$BANCO" ]; then
    info ""
    info "Cancelado — nada foi alterado."
    exit 1
  fi
fi

# ---------------------------------------------------------------- credenciais

CARIMBO="$(date +%Y%m%d-%H%M%S)"
CNF_CONTAINER="/tmp/.sm-restore-$$-${CARIMBO}.cnf"

limpar() {
  code=$?
  "${DC[@]}" exec -T "$SERVICO_DB" rm -f "$CNF_CONTAINER" >/dev/null 2>&1 || true
  exit $code
}
trap limpar EXIT INT TERM

# A senha vai por STDIN, nunca pela linha de comando (que o `ps` do host mostra).
printf '[client]\nuser="%s"\npassword="%s"\n' \
  "$(escapar_cnf "$DB_USERNAME")" "$(escapar_cnf "$DB_PASSWORD")" \
  | "${DC[@]}" exec -T "$SERVICO_DB" sh -c "umask 077; cat > '$CNF_CONTAINER'" \
  || erro "não consegui gravar as credenciais temporárias no container"

mysql_no_container() {
  "${DC[@]}" exec -T "$SERVICO_DB" mysql \
    "--defaults-extra-file=$CNF_CONTAINER" --default-character-set=utf8mb4 "$@"
}

# ------------------------------------------------- 3. backup de segurança do estado atual

# Só faz sentido se o banco de destino já existir e tiver algo dentro (num ensaio
# em banco novo não há o que salvar).
JA_EXISTE="$(mysql_no_container -N -B -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$BANCO'" \
  | tr -d '[:space:]' || echo 0)"
[ -n "$JA_EXISTE" ] || JA_EXISTE=0

if [ "$BACKUP_PREVIO" -eq 1 ] && [ "$JA_EXISTE" -gt 0 ]; then
  info ""
  info "Tirando um backup de segurança do estado ATUAL de '$BANCO' antes de sobrescrever..."
  if ! SM_DB_SERVICE="$SERVICO_DB" "$RAIZ/scripts/backup-db.sh" --banco "$BANCO" >/dev/null; then
    erro "o backup de segurança falhou — restauração abortada. Use --sem-backup-previo para forçar."
  fi
  verde "Backup de segurança gravado em $ORIGEM."
elif [ "$JA_EXISTE" -eq 0 ]; then
  info "O banco '$BANCO' está vazio ou não existe — nada a salvar antes."
fi

mysql_no_container -e "CREATE DATABASE IF NOT EXISTS \`$BANCO\` \
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" \
  || erro "não consegui acessar/criar o banco '$BANCO'"

# ------------------------------------------ 4. limpar o que existe e aplicar o dump

# Só o DROP TABLE do próprio dump não basta: tabela criada DEPOIS do backup (uma
# migration nova, por exemplo) não está lá e sobreviveria, deixando o banco num
# estado que nunca existiu.
info "Apagando as tabelas atuais de '$BANCO'..."
DROPS="$(mysql_no_container -N -B -e "
  SELECT CONCAT('DROP ', IF(table_type='VIEW','VIEW','TABLE'), ' IF EXISTS \`', table_name, '\`;')
  FROM information_schema.tables WHERE table_schema = '$BANCO'" || true)"

if [ -n "$DROPS" ]; then
  printf 'SET FOREIGN_KEY_CHECKS=0;\n%s\nSET FOREIGN_KEY_CHECKS=1;\n' "$DROPS" \
    | mysql_no_container "$BANCO" || erro "falhei ao limpar as tabelas antigas"
fi

info "Aplicando o backup..."
"${LER[@]}" | mysql_no_container "$BANCO" || erro "a restauração falhou no meio. O banco está inconsistente — restaure o backup de segurança."

# ---------------------------------------------------------------- 5. conferência

TABELAS_AGORA="$(mysql_no_container -N -B -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$BANCO'" | tr -d '[:space:]')"

info ""
if [ "$TABELAS_AGORA" -lt "$TABELAS_NO_DUMP" ]; then
  vermelho "Restaurou $TABELAS_AGORA tabelas, mas o dump tinha $TABELAS_NO_DUMP. Confira antes de usar."
  exit 1
fi

verde "OK — banco '$BANCO' restaurado com $TABELAS_AGORA tabelas."
info ""
info "Confira o app em http://localhost:8001 e, se algo estiver errado, o estado"
info "anterior está no backup de segurança mais recente em $ORIGEM."
