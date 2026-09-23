#!/usr/bin/env bash
#
# Stabil Money — `docker` FALSO para os testes dos scripts de backup e restauração.
#
# O teste (tests/scripts/backup-restore.test.sh) copia este arquivo como `docker`
# para um diretório que vai na FRENTE do PATH. Ele finge ser o docker compose do
# projeto, com um serviço "db" rodando MySQL — mas o "banco" são arquivos:
#
#   $SM_FALSO_DIR/bancos/<banco>/<tabela>     conteúdo = número de linhas da tabela
#
# e cada operação fica registrada em $SM_FALSO_DIR/registro, uma por linha:
#
#   ESCRITA <banco> <o quê>     qualquer mudança num banco (criar, apagar, tabelas, linhas)
#   DUMP <banco>                um mysqldump
#   USE <banco>                 troca de banco no meio de um arquivo aplicado
#   PERDEU <banco> <tabela>     perda silenciosa simulada (ver abaixo)
#
# É isso que deixa o teste provar a ORDEM das operações — nada destrutivo antes da
# validação do arquivo e do ensaio — em qualquer máquina, sem Docker nem MySQL.
# Este arquivo nunca chama o docker de verdade.
#
# Reproduz as permissões que o docker-compose.yml cria: o usuário do app
# (SM_FALSO_USUARIO_APP, padrão "user") só enxerga o banco do app
# (SM_FALSO_BANCO_APP, padrão "stabilmoney"); o root enxerga tudo.
#
# Botões para simular falhas:
#   SM_FALSO_PERDER_TABELA=<t>  aceita o CREATE TABLE <t> e os INSERTs dela sem
#                               erro, mas não guarda nada (perda silenciosa)
#   SM_FALSO_DUMP_SEM_RODAPE=1  o mysqldump sai com 0, mas sem o rodapé (stream cortado)
#   SM_FALSO_DAEMON_FORA=1      o Docker não responde
#   SM_FALSO_SEM_ROOT=1         o container não tem MYSQL_ROOT_PASSWORD

set -u

D="${SM_FALSO_DIR:?SM_FALSO_DIR não definido}"
USUARIO_APP="${SM_FALSO_USUARIO_APP:-user}"
BANCO_APP="${SM_FALSO_BANCO_APP:-stabilmoney}"
mkdir -p "$D/bancos" "$D/cnf"

registrar() { printf '%s\n' "$*" >> "$D/registro"; }

daemon_fora() {
  echo "Cannot connect to the Docker daemon at unix:///var/run/docker.sock. Is the docker daemon running?" >&2
  exit 1
}

chave_do_cnf() { printf '%s' "$1" | tr '/' '_'; }

usuario_do_cnf() { cat "$D/cnf/$(chave_do_cnf "$1")" 2>/dev/null || true; }

# Usuário do app só enxerga o banco do app; root enxerga tudo.
pode() { [ "$1" = root ] || [ "$2" = "$BANCO_APP" ]; }

nome_entre_crases() { printf '%s' "$1" | sed -n 's/^[^`]*`\([^`]*\)`.*/\1/p'; }

esquema_da_consulta() {
  printf '%s\n' "$1" | sed -n "s/.*table_schema *= *'\([A-Za-z0-9_]*\)'.*/\1/p" | head -n 1
}

tabelas_de() { # <banco> — uma por linha, em ordem
  local f
  [ -d "$D/bancos/$1" ] || return 0
  for f in "$D/bancos/$1"/*; do
    [ -f "$f" ] && basename "$f"
  done
}

# ------------------------------------------------------------------ mysql

consulta_conhecida() { # <usuario> <sql> — 0 se reconheceu e respondeu
  local usuario="$1" sql="$2" esquema t n
  case "$sql" in
    *"sm:manifesto"*)
      esquema="$(esquema_da_consulta "$sql")"
      pode "$usuario" "$esquema" || return 0
      for t in $(tabelas_de "$esquema"); do
        printf '%s\t%s\n' "$t" "$(cat "$D/bancos/$esquema/$t")"
      done
      return 0
      ;;
    *"SELECT COUNT(*) FROM information_schema.tables"*)
      esquema="$(esquema_da_consulta "$sql")"
      n=0
      if pode "$usuario" "$esquema"; then n="$(tabelas_de "$esquema" | wc -l | tr -d ' ')"; fi
      echo "$n"
      return 0
      ;;
    *"SELECT CONCAT('DROP '"*)
      esquema="$(esquema_da_consulta "$sql")"
      pode "$usuario" "$esquema" || return 0
      for t in $(tabelas_de "$esquema"); do
        printf 'DROP TABLE IF EXISTS `%s`;\n' "$t"
      done
      return 0
      ;;
  esac
  return 1
}

negar() { # <usuario> <banco> <linha>
  echo "ERROR 1044 (42000) at line $3: Access denied for user '$1'@'%' to database '$2'" >&2
  exit 1
}

exigir_banco() { # <banco atual> <linha>
  if [ -z "$1" ]; then
    echo "ERROR 1046 (3D000) at line $2: No database selected" >&2
    exit 1
  fi
}

# Executa o SQL linha a linha — o formato do mysqldump, e o dos comandos -e.
aplicar() { # <usuario> <banco atual> <sql>
  local usuario="$1" atual="$2" sql="$3" linha nome f k n=0
  while IFS= read -r linha; do
    n=$((n + 1))
    case "$linha" in
      "CREATE DATABASE"*)
        nome="$(nome_entre_crases "$linha")"
        pode "$usuario" "$nome" || negar "$usuario" "$nome" "$n"
        if [ -d "$D/bancos/$nome" ]; then
          case "$linha" in
            *"IF NOT EXISTS"*) ;;
            *) echo "ERROR 1007 (HY000) at line $n: Can't create database '$nome'; database exists" >&2; exit 1 ;;
          esac
        else
          mkdir -p "$D/bancos/$nome"
          registrar "ESCRITA $nome criou o banco"
        fi
        ;;
      "DROP DATABASE"*)
        nome="$(nome_entre_crases "$linha")"
        pode "$usuario" "$nome" || negar "$usuario" "$nome" "$n"
        if [ -d "$D/bancos/$nome" ]; then
          rm -rf "${D:?}/bancos/$nome"
          registrar "ESCRITA $nome apagou o banco"
        fi
        ;;
      "USE "* | "use "*)
        nome="$(nome_entre_crases "$linha")"
        pode "$usuario" "$nome" || negar "$usuario" "$nome" "$n"
        if [ ! -d "$D/bancos/$nome" ]; then
          echo "ERROR 1049 (42000) at line $n: Unknown database '$nome'" >&2
          exit 1
        fi
        atual="$nome"
        registrar "USE $nome"
        ;;
      "DROP TABLE IF EXISTS "*)
        exigir_banco "$atual" "$n"
        nome="$(nome_entre_crases "$linha")"
        if [ -f "$D/bancos/$atual/$nome" ]; then
          rm -f "$D/bancos/$atual/$nome"
          registrar "ESCRITA $atual apagou a tabela $nome"
        fi
        ;;
      "CREATE TABLE "*)
        exigir_banco "$atual" "$n"
        nome="$(nome_entre_crases "$linha")"
        if [ "$nome" = "${SM_FALSO_PERDER_TABELA-}" ]; then
          registrar "PERDEU $atual $nome"
        else
          echo 0 > "$D/bancos/$atual/$nome"
          registrar "ESCRITA $atual criou a tabela $nome"
        fi
        ;;
      "INSERT INTO "*)
        exigir_banco "$atual" "$n"
        nome="$(nome_entre_crases "$linha")"
        f="$D/bancos/$atual/$nome"
        if [ ! -f "$f" ]; then
          [ "$nome" = "${SM_FALSO_PERDER_TABELA-}" ] && continue
          echo "ERROR 1146 (42S02) at line $n: Table '$atual.$nome' doesn't exist" >&2
          exit 1
        fi
        # Os textos dos testes de ponta a ponta não têm "),(" dentro.
        k=$(($(printf '%s' "$linha" | grep -o '),(' | wc -l) + 1))
        echo $(($(cat "$f") + k)) > "$f"
        registrar "ESCRITA $atual +$k linhas em $nome"
        ;;
    esac
  done <<EOF
$sql
EOF
}

executar_mysql() {
  local usuario="" sql="" tem_e=0 banco=""
  while [ $# -gt 0 ]; do
    case "$1" in
      --defaults-extra-file=*) usuario="$(usuario_do_cnf "${1#*=}")" ;;
      -e) sql="${2-}"; tem_e=1; shift ;;
      --execute=*) sql="${1#*=}"; tem_e=1 ;;
      -*) ;;
      *) banco="$1" ;;
    esac
    shift
  done
  if [ -z "$usuario" ]; then
    echo "ERROR 1045 (28000): Access denied (sem credencial)" >&2
    exit 1
  fi
  [ "$tem_e" = 1 ] || sql="$(cat)"
  if [ -n "$banco" ]; then
    pode "$usuario" "$banco" || negar "$usuario" "$banco" 0
    if [ ! -d "$D/bancos/$banco" ]; then
      echo "ERROR 1049 (42000): Unknown database '$banco'" >&2
      exit 1
    fi
  fi
  consulta_conhecida "$usuario" "$sql" && exit 0
  aplicar "$usuario" "$banco" "$sql"
  exit 0
}

# -------------------------------------------------------------- mysqldump

executar_mysqldump() {
  local usuario="" banco="" a t k i linha
  for a in "$@"; do
    case "$a" in
      --defaults-extra-file=*) usuario="$(usuario_do_cnf "${a#*=}")" ;;
      -*) ;;
      *) banco="$a" ;;
    esac
  done
  if [ -z "$usuario" ]; then
    echo "mysqldump: Got error: 1045: Access denied (sem credencial)" >&2
    exit 2
  fi
  if ! pode "$usuario" "$banco"; then
    echo "mysqldump: Got error: 1044: Access denied for user '$usuario'@'%' to database '$banco' when selecting the database" >&2
    exit 2
  fi
  if [ ! -d "$D/bancos/$banco" ]; then
    echo "mysqldump: Got error: 1049: Unknown database '$banco' when selecting the database" >&2
    exit 2
  fi
  registrar "DUMP $banco"
  printf '%s\n' "-- MySQL dump 10.13  Distrib 8.0.45, for Linux (docker falso)" "--" \
    "-- Host: localhost    Database: $banco" "-- ------------------------------------------------------" ""
  for t in $(tabelas_de "$banco"); do
    k="$(cat "$D/bancos/$banco/$t")"
    printf '%s\n' "DROP TABLE IF EXISTS \`$t\`;" "CREATE TABLE \`$t\` (" "  \`id\` int NOT NULL" ") ENGINE=InnoDB;"
    if [ "$k" -gt 0 ]; then
      linha="INSERT INTO \`$t\` VALUES "
      i=1
      while [ "$i" -le "$k" ]; do
        if [ "$i" -gt 1 ]; then linha="$linha,"; fi
        linha="$linha($i)"
        i=$((i + 1))
      done
      printf '%s;\n' "$linha"
    fi
  done
  [ -z "${SM_FALSO_DUMP_SEM_RODAPE-}" ] || exit 0
  printf '%s\n' "" "-- Dump completed on 2026-09-22 12:00:00"
  exit 0
}

# ---------------------------------------------------------------- sh / rm

# sh -c "umask 077; cat > '/tmp/.sm-x.cnf'"   credencial do app, pelo STDIN
# sh -s -- /tmp/.sm-x.cnf                     credencial de root: o script vem pelo STDIN
executar_sh() {
  local entrada cnf="" a usuario
  entrada="$(cat)"
  for a in "$@"; do
    case "$a" in
      */tmp/.sm-*) cnf="$(printf '%s' "$a" | grep -o '/tmp/\.sm-[A-Za-z0-9._-]*\.cnf' | tail -n 1)" ;;
    esac
  done
  if [ -z "$cnf" ]; then
    echo "docker-falso: sh sem arquivo .cnf: $*" >&2
    exit 99
  fi
  case "$entrada" in
    *MYSQL_ROOT_PASSWORD*)
      if [ -n "${SM_FALSO_SEM_ROOT-}" ]; then
        echo "sm:sem-senha-root"
        exit 3
      fi
      echo root > "$D/cnf/$(chave_do_cnf "$cnf")"
      echo "sm:commands=1"
      ;;
    *)
      usuario="$(printf '%s\n' "$entrada" | sed -n 's/^user="\(.*\)"$/\1/p' | head -n 1)"
      printf '%s\n' "$usuario" > "$D/cnf/$(chave_do_cnf "$cnf")"
      ;;
  esac
  exit 0
}

# ---------------------------------------------------------------- despacho

case "${1-}" in
  info | version)
    [ -z "${SM_FALSO_DAEMON_FORA-}" ] || daemon_fora
    echo "docker falso"
    exit 0
    ;;
  compose) shift ;;
  *)
    echo "docker-falso: não sei fazer: docker $*" >&2
    exit 99
    ;;
esac

case "${1-}" in
  version)
    echo "Docker Compose version v2 (falso)"
    exit 0
    ;;
  ps)
    [ -z "${SM_FALSO_DAEMON_FORA-}" ] || daemon_fora
    echo "db"
    exit 0
    ;;
  exec) shift ;;
  *)
    echo "docker-falso: não sei fazer: docker compose $*" >&2
    exit 99
    ;;
esac

[ -z "${SM_FALSO_DAEMON_FORA-}" ] || daemon_fora
if [ "${1-}" = "-T" ]; then shift; fi
servico="${1-}"
shift
if [ "$servico" != "db" ]; then
  echo "service \"$servico\" is not running" >&2
  exit 1
fi
comando="${1-}"
shift

case "$comando" in
  sh) executar_sh "$@" ;;
  mysql) executar_mysql "$@" ;;
  mysqldump) executar_mysqldump "$@" ;;
  rm)
    for a in "$@"; do
      case "$a" in
        /tmp/*) rm -f "$D/cnf/$(chave_do_cnf "$a")" ;;
      esac
    done
    exit 0
    ;;
  *)
    echo "docker-falso: não sei rodar '$comando' no container" >&2
    exit 99
    ;;
esac
