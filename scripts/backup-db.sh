#!/usr/bin/env bash
#
# Stabil Money — backup do banco MySQL.
#
# Roda um mysqldump dentro do container `db` do docker-compose e grava um arquivo
# .sql.gz datado, com rotação automática. ANTES de publicar o arquivo, confere que
# ele é um dump inteiro: gzip íntegro, o rodapé "-- Dump completed" que o mysqldump
# só escreve quando termina, e pelo menos uma tabela. Backup cortado que ninguém
# percebe é pior que backup nenhum: é ele que alguém vai tentar restaurar.
#
# Uso:
#   ./scripts/backup-db.sh                  # backup no diretório padrão
#   ./scripts/backup-db.sh --saida /mnt/hd  # em outro diretório
#   ./scripts/backup-db.sh --manter 30      # guarda os 30 mais recentes
#   ./scripts/backup-db.sh --banco outro    # outro banco que não o do .env
#
# Opções:
#   --saida DIR           onde gravar (padrão: storage/backups)
#   --manter N            quantos backups comuns deste banco guardar (padrão: 14)
#   --banco NOME          outro banco que não o DB_DATABASE do .env (usa o root)
#   --root                usa o root do MySQL também para o banco do app
#   --servico NOME        serviço do docker-compose (padrão: db)
#   --env ARQUIVO         outro .env (padrão: o da raiz do projeto)
#   --antes-de-restaurar  uso do restore-db.sh: backup de segurança, com nome
#                         próprio (.antes-de-restaurar) e SEM rotação nenhuma
#
# Variáveis de ambiente equivalentes: SM_BACKUP_DIR, SM_BACKUP_KEEP, SM_DB_SERVICE,
# SM_ENV_FILE. NO_COLOR desliga as cores (sem terminal elas já saem desligadas).
#
# Nome do arquivo: <banco>-AAAAMMDD-HHMMSS.sql.gz. Um segundo backup no mesmo
# segundo vira ...-HHMMSS-2.sql.gz — nunca sobrescreve o primeiro.
#
# SEGURANÇA — a senha do banco NUNCA aparece na linha de comando (`ps` do host lê
# a linha de comando de qualquer processo, inclusive os dos containers). A do
# usuário do app vai por STDIN para um arquivo de defaults criado com umask 077
# DENTRO do container; a de root nem sai de lá (scripts/lib/cnf-root.sh). O
# arquivo some no final (trap), e por isso também não usamos `mysqldump -p$SENHA`.

set -euo pipefail
# Os dumps têm dados pessoais de TODOS os usuários. Com umask 077 o arquivo nasce
# 600 — inclusive enquanto está sendo escrito, e não só depois do chmod.
umask 077

DIR_CHAMADA="$(pwd)"
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck source=lib/backup-comum.sh
. "$RAIZ/scripts/lib/backup-comum.sh"
sm_iniciar_cores
sm_completar_path
sm_ignorar_desconexao

# ---------------------------------------------------------------- configuração

ARQUIVO_ENV="${SM_ENV_FILE:-$RAIZ/.env}"
DESTINO="${SM_BACKUP_DIR:-$RAIZ/storage/backups}"
MANTER="${SM_BACKUP_KEEP:-14}"
SERVICO_DB="${SM_DB_SERVICE:-db}"
BANCO=""
COMO_ROOT=0
ANTES_DE_RESTAURAR=0

while [ $# -gt 0 ]; do
  case "$1" in
    --saida)   DESTINO="${2:?--saida precisa de um diretório}"; shift 2 ;;
    --manter)  MANTER="${2:?--manter precisa de um número}";    shift 2 ;;
    --servico) SERVICO_DB="${2:?--servico precisa de um nome}"; shift 2 ;;
    --banco)   BANCO="${2:?--banco precisa de um nome}";        shift 2 ;;
    --env)     ARQUIVO_ENV="${2:?--env precisa de um arquivo}"; shift 2 ;;
    --root)    COMO_ROOT=1; shift ;;
    --antes-de-restaurar) ANTES_DE_RESTAURAR=1; shift ;;
    -h|--help) sm_imprimir_uso "$0"; exit 0 ;;
    *) erro "opção desconhecida: $1 (use --help)" ;;
  esac
done

DESTINO="$(sm_caminho_absoluto "$DESTINO" "$DIR_CHAMADA")"
ARQUIVO_ENV="$(sm_caminho_absoluto "$ARQUIVO_ENV" "$DIR_CHAMADA")"
cd "$RAIZ"

case "$MANTER" in
  ''|*[!0-9]*) erro "--manter precisa ser um número inteiro (recebido: $MANTER)" ;;
esac
[ "$MANTER" -ge 1 ] || erro "--manter precisa ser pelo menos 1"

[ -f "$ARQUIVO_ENV" ] || erro "não achei o .env em $ARQUIVO_ENV"

# Os padrões abaixo são os mesmos do docker-compose.yml.
DB_APP="$(sm_ler_env "$ARQUIVO_ENV" DB_DATABASE stabilmoney)"
[ -n "$BANCO" ] || BANCO="$DB_APP"

# O nome vira parte do nome do arquivo, do padrão de rotação e do SQL.
sm_nome_de_banco_valido "$BANCO" || erro "nome de banco inválido: '$BANCO' (só letras, números e _)"

# O usuário do app nasce (MYSQL_USER no docker-compose) com permissão SÓ no banco
# do app. Para qualquer outro banco — um ensaio, por exemplo — só o root enxerga.
if [ "$BANCO" != "$DB_APP" ] && [ "$COMO_ROOT" -ne 1 ]; then
  COMO_ROOT=1
  info "'$BANCO' não é o banco do app (DB_DATABASE=$DB_APP): o backup usa o root do MySQL."
fi

sm_encontrar_compose
sm_exigir_servico "$SERVICO_DB"

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
SUFIXO=""
if [ "$ANTES_DE_RESTAURAR" -eq 1 ]; then SUFIXO="$SM_SUFIXO_SEGURANCA"; fi

# Arquivo de trabalho oculto e ÚNICO por processo: dois backups ao mesmo tempo não
# escrevem no mesmo lugar, e a rotação nunca o enxerga.
ARQ_TMP="$DESTINO/.${BANCO}-${CARIMBO}.$$.parcial"
ERRO_DUMP="$(mktemp "${TMPDIR:-/tmp}/sm-backup-erro.XXXXXX")"
MANIFESTO="$(mktemp "${TMPDIR:-/tmp}/sm-backup-manifesto.XXXXXX")"

# O .cnf vive dentro do container e some no final, aconteça o que acontecer.
CNF_CONTAINER="/tmp/.sm-backup-$$-${CARIMBO}.cnf"
CNF_CRIADO=0

limpar() {
  local code=$?
  set +e
  if [ "$CNF_CRIADO" = 1 ]; then
    "${DC[@]}" exec -T "$SERVICO_DB" rm -f "$CNF_CONTAINER" >/dev/null 2>&1 </dev/null
  fi
  rm -f -- "$ARQ_TMP" "$ERRO_DUMP" "$MANIFESTO"
  exit "$code"
}
trap limpar EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

CNF_CRIADO=1
if [ "$COMO_ROOT" -eq 1 ]; then
  sm_gravar_cnf_root "$SERVICO_DB" "$CNF_CONTAINER"
  QUEM="root"
else
  DB_USERNAME="$(sm_ler_env "$ARQUIVO_ENV" DB_USERNAME user)"
  DB_PASSWORD="$(sm_ler_env "$ARQUIVO_ENV" DB_PASSWORD password)"
  sm_gravar_cnf_app "$SERVICO_DB" "$CNF_CONTAINER" "$DB_USERNAME" "$DB_PASSWORD" \
    || erro "não consegui gravar as credenciais temporárias no container"
  QUEM="usuário do app"
fi

# ---------------------------------------------------------------- o dump

info "Banco:   $BANCO (container '$SERVICO_DB', $QUEM)"
info "Destino: $DESTINO"

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
  "$BANCO" 2>"$ERRO_DUMP" </dev/null | gzip -9 > "$ARQ_TMP"
# Copia o array inteiro de uma vez: qualquer comando, até uma atribuição, zera o
# PIPESTATUS.
STATUS=("${PIPESTATUS[@]}")
STATUS_DUMP=${STATUS[0]}
STATUS_GZIP=${STATUS[1]}
set -e

if [ "$STATUS_DUMP" -ne 0 ]; then
  vermelho "--- saída do mysqldump ---"
  cat "$ERRO_DUMP" >&2 || true
  erro "o mysqldump falhou (código $STATUS_DUMP). Nada foi gravado."
fi
if [ "$STATUS_GZIP" -ne 0 ]; then
  erro "o gzip falhou (código $STATUS_GZIP) — disco cheio? Nada foi gravado."
fi
# Avisos do mysqldump (ex.: charset) não invalidam o backup, mas o operador deve ver.
if [ -s "$ERRO_DUMP" ]; then cat "$ERRO_DUMP" >&2 || true; fi

# ------------------------------------------------- conferência (o passo que salva)

# Código de saída 0 não basta: um stream cortado no caminho (conexão com o
# container, disco) pode terminar "sem erro" com metade do banco. O rodapé é a
# prova de que o mysqldump chegou ao fim.
if ! sm_validar_dump "$ARQ_TMP" "$MANIFESTO"; then
  erro "o dump saiu inválido: $SM_MOTIVO. Arquivo descartado — isso não é backup."
fi

ARQUIVO="$(sm_publicar_sem_sobrescrever "$ARQ_TMP" "$DESTINO" "${BANCO}-${CARIMBO}" "$SUFIXO")" \
  || erro "não consegui dar nome ao arquivo em $DESTINO (99 backups no mesmo segundo?)"
chmod 600 "$ARQUIVO"

# O restore-db.sh pergunta por aqui onde foi parar o backup de segurança.
if [ -n "${SM_ARQUIVO_RESULTADO:-}" ]; then printf '%s\n' "$ARQUIVO" > "$SM_ARQUIVO_RESULTADO"; fi

TAMANHO="$(du -h "$ARQUIVO" | cut -f1 | tr -d ' ')"
verde "OK — $(sm_manifesto_resumo "$MANIFESTO"), $TAMANHO"
info "Arquivo: $ARQUIVO"

# ---------------------------------------------------------------- rotação

if [ "$ANTES_DE_RESTAURAR" -eq 1 ]; then
  # O backup de segurança NÃO rotaciona nada. Antes ele rodava a rotação padrão (14)
  # sobre os backups do cron: quem guardava 30 ficava com 14 no primeiro restore.
  info "Backup de segurança: não entra na rotação — apague à mão quando não precisar mais."
else
  sm_rotacionar "$DESTINO" "$BANCO" "$MANTER"
  RESTANTES="$(sm_listar_backups "$DESTINO" "$BANCO" | wc -l | tr -d ' ')"
  info "Backups guardados de '$BANCO': $RESTANTES (limite: $MANTER)"
fi

info ""
info "Para inspecionar:  gunzip -c '$ARQUIVO' | less"
info "Para ensaiar:      ./scripts/restore-db.sh --ensaio '$ARQUIVO'"
