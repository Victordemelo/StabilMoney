#!/usr/bin/env bash
#
# Testes dos scripts de backup e restauração (scripts/backup-db.sh e
# scripts/restore-db.sh). Rodam em qualquer máquina — Mac (bash 3.2, awk BSD) ou
# Linux (bash 5, mawk/gawk) — SEM Docker e SEM MySQL:
#
#   bash tests/scripts/backup-restore.test.sh
#
# Duas camadas:
#
#   1. UNIDADE — as funções de scripts/lib/backup-comum.sh: a validação do arquivo
#      (gzip truncado, SQL cortado num INSERT, sem rodapé, comandos que trocam de
#      banco), a contagem de linhas que o dump promete, a escolha do "mais recente",
#      a rotação e o nome que nunca sobrescreve.
#
#   2. PONTA A PONTA — os scripts DE VERDADE, com um `docker` falso na frente do PATH
#      (tests/scripts/docker-falso.sh). Ele guarda o "banco" em arquivos e registra
#      cada operação, e é isso que prova a ORDEM: nada destrutivo antes de o arquivo
#      ser validado e ensaiado.
#
# SM_SCRIPTS_SOB_TESTE=<dir> roda a camada 2 contra OUTRA cópia dos scripts. Foi
# assim que se provou que estes testes reprovam a versão anterior à correção:
#
#   mkdir -p /tmp/antigo
#   git show <commit>:scripts/backup-db.sh  > /tmp/antigo/backup-db.sh
#   git show <commit>:scripts/restore-db.sh > /tmp/antigo/restore-db.sh
#   SM_SCRIPTS_SOB_TESTE=/tmp/antigo bash tests/scripts/backup-restore.test.sh
#
# (sem lib/ no diretório, a camada 1 é pulada). Cada teste diz qual achado da
# auditoria de 06/09/2026 (docs/auditoria-volume-e-backup-2026-09-06.md) ele cobre.

set -u

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
SOB_TESTE="$(cd "${SM_SCRIPTS_SOB_TESTE:-$REPO/scripts}" && pwd)"
T="$(mktemp -d "${TMPDIR:-/tmp}/sm-teste-backup.XXXXXX")"
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
  if [ -n "${2-}" ]; then printf '%s\n' "$2" | head -n 12 | sed 's/^/          | /'; fi
}
# afirmar "<descrição>" <comando...> — passa se o comando der 0.
afirmar() {
  local descricao="$1"
  shift
  if "$@"; then ok "$descricao"; else falhou "$descricao" "${DETALHE-}"; fi
  DETALHE=""
}
DETALHE=""

contem() { case "$1" in *"$2"*) return 0 ;; esac; return 1; }

# ---------------------------------------------------------- dumps de mentira

# Imitam a saída do mysqldump 8.0: cabeçalho, uma seção por tabela e o rodapé.
cabecalho_sql() { # <banco de origem>
  printf '%s\n' \
    "-- MySQL dump 10.13  Distrib 8.0.45, for Linux (aarch64)" \
    "--" \
    "-- Host: localhost    Database: $1" \
    "-- ------------------------------------------------------" \
    "-- Server version	8.0.45" \
    "" \
    "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;" \
    "/*!50503 SET NAMES utf8mb4 */;" \
    "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;" \
    ""
}
estrutura_sql() { # <tabela>
  printf '%s\n' \
    "--" \
    "-- Table structure for table \`$1\`" \
    "--" \
    "" \
    "DROP TABLE IF EXISTS \`$1\`;" \
    "/*!40101 SET @saved_cs_client     = @@character_set_client */;" \
    "/*!50503 SET character_set_client = utf8mb4 */;" \
    "CREATE TABLE \`$1\` (" \
    "  \`id\` bigint unsigned NOT NULL AUTO_INCREMENT," \
    "  \`texto\` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL," \
    "  PRIMARY KEY (\`id\`)" \
    ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;" \
    "/*!40101 SET character_set_client = @saved_cs_client */;" \
    ""
}
dados_sql() { # <tabela> <linhas>
  local i=1 linha
  printf '%s\n' "LOCK TABLES \`$1\` WRITE;" "/*!40000 ALTER TABLE \`$1\` DISABLE KEYS */;"
  if [ "$2" -gt 0 ]; then
    linha="INSERT INTO \`$1\` VALUES "
    while [ "$i" -le "$2" ]; do
      if [ "$i" -gt 1 ]; then linha="$linha,"; fi
      linha="$linha($i,'linha $i de $1, com um texto comprido para o gzip ter o que comprimir')"
      i=$((i + 1))
    done
    printf '%s;\n' "$linha"
  fi
  printf '%s\n' "/*!40000 ALTER TABLE \`$1\` ENABLE KEYS */;" "UNLOCK TABLES;" ""
}
rodape_sql() {
  printf '%s\n' "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;" "" "-- Dump completed on 2026-09-22 23:15:21"
}
# dump_sql <origem> <tabela=linhas>... — um dump inteiro, com rodapé
dump_sql() {
  local origem="$1" par
  shift
  cabecalho_sql "$origem"
  for par in "$@"; do
    estrutura_sql "${par%%=*}"
    dados_sql "${par%%=*}" "${par#*=}"
  done
  rodape_sql
}
# dump_gz <arquivo> <origem> <tabela=linhas>...
dump_gz() {
  local arquivo="$1"
  shift
  dump_sql "$@" | gzip -9 > "$arquivo"
}

# O dump "difícil": textos com parênteses, "),(" dentro, aspas escapadas dos dois
# jeitos, barra invertida no fim do texto, binário, emoji, dois INSERTs na mesma
# tabela, uma tabela vazia e uma view (que não é tabela). Linhas por tabela
# contadas à mão: accounts 10, transactions 3, users 1, vazia 0.
dump_dificil() {
  cabecalho_sql stabilmoney
  estrutura_sql accounts
  printf '%s\n' "  \`nome\` varchar(10) COMMENT 'nome (livre), com \\'aspas\\' e ''outras''',"
  printf '%s\n' "LOCK TABLES \`accounts\` WRITE;"
  printf '%s\n' "INSERT INTO \`accounts\` VALUES (1,'Conta (principal)'),(2,'a),(b'),(3,'it\\'s \\\\ ok'),(4,'termina em barra \\\\'),(5,'Café ☕ (R\$ 1.234,56)'),(6,'aspas \\\"duplas\\\" e ''dobradas'''),(7,NULL),(8,_binary 'bin\\0\\'('),(9,'x\\\\'),(10,'quebra\\nde linha (escapada)');"
  printf '%s\n' "UNLOCK TABLES;" ""
  estrutura_sql transactions
  printf '%s\n' "INSERT INTO \`transactions\` VALUES (1,'x'),(2,'y');"
  printf '%s\n' "INSERT INTO \`transactions\` VALUES (3,'z');"
  estrutura_sql users
  printf '%s\n' "INSERT INTO \`users\` VALUES (1,'Victor','{\\\"a\\\":\\\"(1),(2)\\\"}',NULL);"
  estrutura_sql vazia
  printf '%s\n' "/*!50001 DROP VIEW IF EXISTS \`resumo\`*/;" "/*!50001 CREATE VIEW \`resumo\` AS SELECT " " 1 AS \`id\`*/;" ""
  rodape_sql
}

# ======================================================================
# 1. UNIDADE — scripts/lib/backup-comum.sh
# ======================================================================

if [ -f "$SOB_TESTE/lib/backup-comum.sh" ]; then
  echo "# unidade: $SOB_TESTE/lib/backup-comum.sh"
  # shellcheck source=../../scripts/lib/backup-comum.sh
  . "$SOB_TESTE/lib/backup-comum.sh"
  U="$T/unidade"
  mkdir -p "$U"
  M="$U/manifesto"

  valida() { sm_validar_dump "$1" "$M"; }
  recusa_com() { # <arquivo> <trecho esperado no motivo>
    if sm_validar_dump "$1" "$M"; then
      DETALHE="aceitou o arquivo"
      return 1
    fi
    if ! contem "$SM_MOTIVO" "$2"; then
      DETALHE="motivo: $SM_MOTIVO"
      return 1
    fi
    return 0
  }
  manifesto_tem() { # <linha esperada>
    if grep -qxF "$1" "$M"; then return 0; fi
    DETALHE="$(cat "$M")"
    return 1
  }

  # --- validação: o dump bom e difícil
  dump_dificil > "$U/dificil.sql"
  gzip -9 -c "$U/dificil.sql" > "$U/dificil.sql.gz"
  afirmar "B-1 dump bom (.sql.gz) é aceito" valida "$U/dificil.sql.gz"
  afirmar "B-1 origem lida do cabeçalho" manifesto_tem "$(printf 'origem\tstabilmoney')"
  afirmar "B-1 data do rodapé" manifesto_tem "$(printf 'concluido\t2026-09-22 23:15:21')"
  afirmar "B-1 conta linhas com '),(' e parênteses dentro de textos (accounts=10)" manifesto_tem "$(printf 'tabela\taccounts\t10')"
  afirmar "B-1 soma dois INSERTs da mesma tabela (transactions=3)" manifesto_tem "$(printf 'tabela\ttransactions\t3')"
  afirmar "B-1 JSON com '(1),(2)' é uma linha só (users=1)" manifesto_tem "$(printf 'tabela\tusers\t1')"
  afirmar "B-1 tabela sem INSERT promete 0 linhas (vazia=0)" manifesto_tem "$(printf 'tabela\tvazia\t0')"
  n_tabelas() { [ "$(grep -c '^tabela' "$M")" = 4 ] || { DETALHE="$(cat "$M")"; return 1; }; }
  afirmar "B-1 a view não entra como tabela (4 tabelas)" n_tabelas
  afirmar "B-1 dump bom (.sql puro) é aceito" valida "$U/dificil.sql"
  cp "$U/dificil.sql.gz" "$U/compactado-com-nome-errado.sql"
  afirmar "B-1 gzip com nome .sql é lido pelo conteúdo" valida "$U/compactado-com-nome-errado.sql"

  # --- validação: o que tem de ser recusado
  TAM="$(wc -c < "$U/dificil.sql.gz" | tr -d ' ')"
  head -c $((TAM * 6 / 10)) "$U/dificil.sql.gz" > "$U/truncado.sql.gz"
  afirmar "B-1 gzip truncado é recusado" recusa_com "$U/truncado.sql.gz" "gzip"
  { cat "$U/dificil.sql.gz"; printf 'lixo'; } > "$U/lixo-no-fim.sql.gz"
  afirmar "B-1 gzip com lixo no fim é recusado" recusa_com "$U/lixo-no-fim.sql.gz" "gzip"
  printf 'isto não é gzip' > "$U/falso.sql.gz"
  afirmar "B-1 .gz que não é gzip é recusado" recusa_com "$U/falso.sql.gz" "não é gzip"
  : > "$U/vazio.sql.gz"
  afirmar "B-1 arquivo vazio é recusado" recusa_com "$U/vazio.sql.gz" "vazio"

  OFF="$(grep -n "^INSERT INTO \`accounts\`" "$U/dificil.sql" | head -n 1 | cut -d: -f1)"
  { head -n $((OFF - 1)) "$U/dificil.sql"; sed -n "${OFF}p" "$U/dificil.sql" | cut -c1-60; } > "$U/cortado-no-insert.sql"
  afirmar "B-1 SQL cortado no meio de um INSERT é recusado" recusa_com "$U/cortado-no-insert.sql" "cortado"
  # Termina em ");" e tem rodapé: só o rastreio das aspas percebe o texto aberto.
  { cabecalho_sql stabilmoney; estrutura_sql users; printf '%s\n' "INSERT INTO \`users\` VALUES (1,'texto que nunca fecha);"; rodape_sql; } > "$U/texto-aberto.sql"
  afirmar "B-1 INSERT com texto que não fecha é recusado, mesmo com rodapé" recusa_com "$U/texto-aberto.sql" "meio de um texto"

  OFF="$(grep -n '^-- Table structure for table `users`' "$U/dificil.sql" | cut -d: -f1)"
  head -n $((OFF - 1)) "$U/dificil.sql" > "$U/sem-users.sql"
  afirmar "B-1 SQL cortado em fim de linha, sem a tabela users, é recusado (sem rodapé)" recusa_com "$U/sem-users.sql" "rodapé"
  gzip -9 -c "$U/sem-users.sql" > "$U/sem-users.sql.gz"
  afirmar "B-1 gzip íntegro de um dump sem rodapé é recusado" recusa_com "$U/sem-users.sql.gz" "rodapé"
  { cat "$U/dificil.sql"; printf '%s\n' "INSERT INTO \`transactions\` VALUES (4,'depois do rodapé');"; } > "$U/rodape-no-meio.sql"
  afirmar "B-1 rodapé que não é a última linha é recusado" recusa_com "$U/rodape-no-meio.sql" "rodapé"
  { cabecalho_sql stabilmoney; rodape_sql; } > "$U/sem-tabelas.sql"
  afirmar "B-1 dump sem nenhuma tabela é recusado" recusa_com "$U/sem-tabelas.sql" "CREATE TABLE"
  { cabecalho_sql stabilmoney; dados_sql fantasma 2; rodape_sql; } > "$U/insert-sem-create.sql"
  afirmar "B-1 INSERT em tabela sem CREATE TABLE é recusado" recusa_com "$U/insert-sem-create.sql" "sem o CREATE TABLE"

  # --- validação: o que sairia do banco de destino
  com_linha() { # <arquivo> <linha a inserir antes do rodapé>
    { cabecalho_sql stabilmoney; estrutura_sql users; dados_sql users 1; printf '%s\n' "$2"; rodape_sql; } > "$1"
  }
  com_linha "$U/use.sql" 'USE `stabilmoney`;'
  afirmar "novo: dump com USE (feito com --databases) é recusado" recusa_com "$U/use.sql" "troca de banco"
  com_linha "$U/create-database.sql" 'CREATE DATABASE /*!32312 IF NOT EXISTS*/ `stabilmoney` /*!40100 DEFAULT CHARACTER SET utf8mb4 */;'
  afirmar "novo: dump com CREATE DATABASE é recusado" recusa_com "$U/create-database.sql" "troca de banco"
  com_linha "$U/drop-database.sql" '/*!40000 DROP DATABASE IF EXISTS `stabilmoney`*/;'
  afirmar "novo: DROP DATABASE dentro de comentário condicional é recusado" recusa_com "$U/drop-database.sql" "troca de banco"
  com_linha "$U/shell.sql" '\! rm -rf /var/lib/mysql'
  afirmar "novo: comando de shell do cliente mysql (\\!) é recusado" recusa_com "$U/shell.sql" "cliente mysql"
  com_linha "$U/system.sql" 'system rm -rf /var/lib/mysql'
  afirmar "novo: comando 'system' do cliente mysql é recusado" recusa_com "$U/system.sql" "cliente mysql"
  com_linha "$U/barra-no-insert.sql" "INSERT INTO \`users\` VALUES (2,'ok'),(\\! id);"
  afirmar "novo: barra invertida fora de texto num INSERT é recusada" recusa_com "$U/barra-no-insert.sql" "barra invertida"
  com_linha "$U/qualificado.sql" "INSERT INTO \`stabilmoney\`.\`users\` VALUES (2,'x');"
  afirmar "novo: INSERT com o nome de outro banco na frente é recusado" recusa_com "$U/qualificado.sql" "outro banco"

  # --- desempenho: um INSERT de 20 mil linhas, todas com "),(" dentro do texto
  {
    cabecalho_sql stabilmoney
    estrutura_sql grande
    awk -v q="'" 'BEGIN { printf "INSERT INTO `grande` VALUES "; for (i = 1; i <= 20000; i++) { if (i > 1) printf ","; printf "(%d,%sa),(b \\%s%d%s)", i, q, q, i, q } print ";" }'
    rodape_sql
  } > "$U/grande.sql"
  conta_grande() {
    local ini fim
    ini="$(date +%s)"
    valida "$U/grande.sql" || { DETALHE="$SM_MOTIVO"; return 1; }
    fim="$(date +%s)"
    manifesto_tem "$(printf 'tabela\tgrande\t20000')" || return 1
    [ $((fim - ini)) -le 10 ] || { DETALHE="levou $((fim - ini)) s"; return 1; }
  }
  afirmar "B-1 conta 20 mil linhas numa linha de INSERT em poucos segundos" conta_grande

  # --- comparação: o que o arquivo promete × o que o banco tem
  printf 'origem\tstabilmoney\ntabela\tusers\t7\ntabela\ttransactions\t159\n' > "$U/prometido"
  printf 'tabela\ttransactions\t159\ntabela\tusers\t7\n' > "$U/igual"
  printf 'tabela\ttransactions\t159\n' > "$U/sem-users"
  printf 'tabela\ttransactions\t150\ntabela\tusers\t7\n' > "$U/faltam-linhas"
  printf 'tabela\ttransactions\t159\ntabela\tusers\t7\ntabela\tnova\t1\n' > "$U/sobrando"
  compara_igual() { sm_comparar_manifestos "$U/prometido" "$U/igual" a b >/dev/null; }
  compara_diferente() { # <obtido> <trecho>
    local saida
    if saida="$(sm_comparar_manifestos "$U/prometido" "$1" "o arquivo" "o banco")"; then
      DETALHE="disse que bate"
      return 1
    fi
    contem "$saida" "$2" || { DETALHE="$saida"; return 1; }
  }
  afirmar "B-1 comparação: tabelas e linhas iguais batem" compara_igual
  afirmar "B-1 comparação: tabela que não chegou é acusada" compara_diferente "$U/sem-users" '`users`: está em o arquivo'
  afirmar "B-1 comparação: linhas a menos são acusadas" compara_diferente "$U/faltam-linhas" "o banco tem 150"
  afirmar "B-1 comparação: tabela a mais é acusada" compara_diferente "$U/sobrando" '`nova`'

  # --- escolha do "mais recente" (B-3)
  B="$U/escolha"
  mkdir -p "$B"
  for nome in \
    stabilmoney-20260101-030000.sql.gz \
    stabilmoney-20260102-030000.sql.gz \
    stabilmoney-20260102-030000-2.sql.gz \
    stabilmoney-20260102-030000-10.sql.gz \
    stabilmoney-20300101-000000.antes-de-restaurar.sql.gz \
    teste-20990101-000000.sql.gz \
    stabilmoney_old-20991231-235959.sql.gz \
    stabilmoney-20991231-235959-1.sql.gz \
    stabilmoney-backup-manual.sql.gz \
    stabilmoney-2099-01-01.sql.gz; do
    : > "$B/$nome"
    touch -t 202609010000 "$B/$nome"
  done
  # O certo é o MAIS ANTIGO pela data de modificação: quem ordenasse por mtime erraria.
  touch -t 200001010000 "$B/stabilmoney-20260102-030000-10.sql.gz"
  mais_recente_e() { # <banco> <nome esperado>
    local achado
    achado="$(sm_backup_mais_recente "$B" "$1")"
    [ "$(basename "${achado:-nada}")" = "$2" ] || { DETALHE="escolheu: ${achado:-nada}"; return 1; }
  }
  afirmar "B-3 mais recente pela data do nome, com -10 depois de -2, ignorando outro banco, prefixo parecido, nome fora do padrão e o de segurança" \
    mais_recente_e stabilmoney stabilmoney-20260102-030000-10.sql.gz
  afirmar "B-3 o de outro banco só é escolhido para ele mesmo" mais_recente_e teste teste-20990101-000000.sql.gz
  sem_backup() { [ -z "$(sm_backup_mais_recente "$B" nenhum)" ]; }
  afirmar "B-3 banco sem backup não escolhe nada" sem_backup
  lista_seguranca() {
    [ "$(sm_listar_backups "$B" stabilmoney "$SM_SUFIXO_SEGURANCA" | xargs -n 1 basename)" = "stabilmoney-20300101-000000.antes-de-restaurar.sql.gz" ] ||
      { DETALHE="$(sm_listar_backups "$B" stabilmoney "$SM_SUFIXO_SEGURANCA")"; return 1; }
  }
  afirmar "B-3 os de segurança são listados à parte" lista_seguranca
  ordem_completa() {
    local esperado obtido
    esperado="stabilmoney-20260101-030000.sql.gz stabilmoney-20260102-030000.sql.gz stabilmoney-20260102-030000-2.sql.gz stabilmoney-20260102-030000-10.sql.gz"
    obtido="$(sm_listar_backups "$B" stabilmoney | xargs -n 1 basename | tr '\n' ' ' | sed 's/ $//')"
    [ "$obtido" = "$esperado" ] || { DETALHE="$obtido"; return 1; }
  }
  afirmar "B-3 lista em ordem cronológica, só os comuns do banco" ordem_completa

  # --- rotação (B-4)
  R="$U/rotacao"
  mkdir -p "$R"
  for i in 01 02 03 04 05; do : > "$R/stabilmoney-202601${i}-030000.sql.gz"; done
  : > "$R/stabilmoney-20260101-030000.antes-de-restaurar.sql.gz"
  : > "$R/teste-20250101-000000.sql.gz"
  : > "$R/stabilmoney-feito-a-mao.sql.gz"
  sm_rotacionar "$R" stabilmoney 2 > /dev/null
  rotacao_certa() {
    local sobrou
    sobrou="$(LC_ALL=C ls "$R" | tr '\n' ' ')"
    [ "$sobrou" = "stabilmoney-20260101-030000.antes-de-restaurar.sql.gz stabilmoney-20260104-030000.sql.gz stabilmoney-20260105-030000.sql.gz stabilmoney-feito-a-mao.sql.gz teste-20250101-000000.sql.gz " ] ||
      { DETALHE="sobrou: $sobrou"; return 1; }
  }
  afirmar "B-4 rotação guarda os N mais novos e não toca em segurança, outro banco nem nome fora do padrão" rotacao_certa

  # --- nome que nunca sobrescreve (B-5)
  P2="$U/publicar"
  mkdir -p "$P2"
  printf 'primeiro' > "$P2/.tmp1"
  printf 'segundo' > "$P2/.tmp2"
  printf 'terceiro' > "$P2/.tmp3"
  A1="$(sm_publicar_sem_sobrescrever "$P2/.tmp1" "$P2" stabilmoney-20260922-201521 "")"
  A2="$(sm_publicar_sem_sobrescrever "$P2/.tmp2" "$P2" stabilmoney-20260922-201521 "")"
  A3="$(sm_publicar_sem_sobrescrever "$P2/.tmp3" "$P2" stabilmoney-20260922-201521 "$SM_SUFIXO_SEGURANCA")"
  publicou_sem_sobrescrever() {
    [ "$(basename "$A1")" = stabilmoney-20260922-201521.sql.gz ] &&
      [ "$(basename "$A2")" = stabilmoney-20260922-201521-2.sql.gz ] &&
      [ "$(basename "$A3")" = stabilmoney-20260922-201521.antes-de-restaurar.sql.gz ] &&
      [ "$(cat "$A1")" = primeiro ] && [ "$(cat "$A2")" = segundo ] &&
      [ ! -e "$P2/.tmp1" ] && [ ! -e "$P2/.tmp2" ] ||
      { DETALHE="$A1 | $A2 | $A3 | $(ls -a "$P2" | tr '\n' ' ')"; return 1; }
  }
  afirmar "B-5 dois backups no mesmo segundo: o segundo vira -2 e o primeiro fica intacto" publicou_sem_sobrescrever
  publicado_e_escolhido() { [ "$(sm_backup_mais_recente "$P2" stabilmoney)" = "$A2" ] || { DETALHE="$(sm_backup_mais_recente "$P2" stabilmoney)"; return 1; }; }
  afirmar "B-5 o -2 do mesmo segundo é o mais recente" publicado_e_escolhido

  # --- nomes de banco e .env
  nomes_certos() {
    sm_nome_de_banco_valido stabil_ensaio_2 && ! sm_nome_de_banco_valido 'x;DROP' &&
      ! sm_nome_de_banco_valido '' && ! sm_nome_de_banco_valido 'stabil-ensaio' &&
      sm_banco_de_sistema mysql && sm_banco_de_sistema MySQL && sm_banco_de_sistema information_schema &&
      ! sm_banco_de_sistema stabilmoney &&
      sm_nome_de_temporario sm_verif_20260922201521_123 && ! sm_nome_de_temporario stabilmoney &&
      ! sm_nome_de_temporario sm_verif_x
  }
  afirmar "novo: nomes de banco — válido, de sistema e temporário do ensaio" nomes_certos
  printf 'DB_DATABASE=primeiro\nDB_DATABASE="stabil_x"\nDB_USERNAME='"'"'app'"'"'\r\n' > "$U/env"
  env_certo() {
    [ "$(sm_ler_env "$U/env" DB_DATABASE x)" = stabil_x ] && [ "$(sm_ler_env "$U/env" DB_USERNAME x)" = app ] &&
      [ "$(sm_ler_env "$U/env" DB_PASSWORD padrao)" = padrao ] ||
      { DETALHE="$(sm_ler_env "$U/env" DB_DATABASE x) / $(sm_ler_env "$U/env" DB_USERNAME x)"; return 1; }
  }
  afirmar "novo: .env lido sem executar — aspas, última ocorrência, CRLF e padrão" env_certo
else
  echo "# (sem lib/backup-comum.sh em $SOB_TESTE — camada de unidade pulada)"
fi

# ======================================================================
# 2. PONTA A PONTA — os scripts de verdade, com o docker falso
# ======================================================================

echo "# ponta a ponta: $SOB_TESTE"

mkdir -p "$T/bin"
cp "$REPO/tests/scripts/docker-falso.sh" "$T/bin/docker"
chmod +x "$T/bin/docker"
PATH_BASE="$T/bin:$PATH"

# Trava de segurança: estes testes NUNCA podem achar o docker de verdade.
if [ "$(PATH="$PATH_BASE" command -v docker)" != "$T/bin/docker" ]; then
  echo "ABORTADO: o docker falso não ficou na frente do PATH" >&2
  exit 2
fi

# Um projeto de mentira por cenário: cópia dos scripts sob teste, um .env e um
# "MySQL" com o banco do app já preenchido.
novo_cenario() { # <nome>
  C="$T/cenarios/$1"
  P="$C/proj"
  E="$C/estado"
  BK="$P/storage/backups"
  mkdir -p "$P/scripts" "$E/bancos"
  cp -R "$SOB_TESTE/." "$P/scripts/"
  chmod +x "$P/scripts/"*.sh 2>/dev/null
  printf 'DB_DATABASE=stabilmoney\nDB_USERNAME=user\nDB_PASSWORD=password\n' > "$P/.env"
  semear stabilmoney accounts=2 transactions=10 users=3
  : > "$E/registro"
}
semear() { # <banco> <tabela=linhas>...
  local banco="$1" par
  shift
  mkdir -p "$E/bancos/$banco"
  for par in "$@"; do echo "${par#*=}" > "$E/bancos/$banco/${par%%=*}"; done
}
estado_de() { # <banco> — "tabela=linhas ..." ou "(não existe)"
  local f
  if [ ! -d "$E/bancos/$1" ]; then
    echo "(não existe)"
    return
  fi
  for f in "$E/bancos/$1"/*; do
    [ -f "$f" ] && printf '%s=%s ' "$(basename "$f")" "$(cat "$f")"
  done
  echo
}
# rodar <script> [args...] — no projeto de mentira, sem terminal, sem cores forçadas.
rodar() {
  local script="$1"
  shift
  (cd "$P" && env PATH="${PATH_TESTE:-$PATH_BASE}" SM_PATH_EXTRA="${PATH_EXTRA_TESTE-}" SM_FALSO_DIR="$E" \
    bash "$P/scripts/$script" "$@") > "$C/saida" 2>&1 < /dev/null
  CODIGO=$?
  SAIDA="$(cat "$C/saida")"
}
falhou_sem_tocar() { # <banco> <estado de antes>
  if [ "$CODIGO" -eq 0 ]; then
    DETALHE="saiu com 0: $SAIDA"
    return 1
  fi
  if grep -q "^ESCRITA $1 " "$E/registro"; then
    DETALHE="escreveu em '$1': $(grep "^ESCRITA $1 " "$E/registro" | head -n 3 | tr '\n' ';')"
    return 1
  fi
  if [ "$(estado_de "$1")" != "$2" ]; then
    DETALHE="antes: $2 | depois: $(estado_de "$1")"
    return 1
  fi
}
saiu_ok() {
  [ "$CODIGO" -eq 0 ] || { DETALHE="código $CODIGO: $SAIDA"; return 1; }
}
estado_e() { # <banco> <esperado>
  [ "$(estado_de "$1")" = "$2" ] || { DETALHE="estado de '$1': $(estado_de "$1") | saída: $SAIDA"; return 1; }
}
sem_credencial_sobrando() {
  [ -z "$(ls "$E/cnf" 2>/dev/null)" ] || { DETALHE="ficou: $(ls "$E/cnf")"; return 1; }
}
sem_temporario_sobrando() {
  ! ls "$E/bancos" | grep -q '^sm_verif_' || { DETALHE="ficou: $(ls "$E/bancos")"; return 1; }
}

ANTES="$(novo_cenario referencia; estado_de stabilmoney)" # accounts=2 transactions=10 users=3

# --- B-1: arquivo ruim NUNCA encosta no banco -------------------------

novo_cenario b1-gzip-truncado
# Grande o bastante para o gunzip despejar blocos inteiros ANTES de acusar o fim
# inesperado (ele escreve de 32 em 32 ou 64 em 64 kB): é assim que um gzip truncado
# enganava a checagem antiga, que só contava CREATE TABLE no que conseguia ler.
dump_gz "$C/bom.sql.gz" stabilmoney accounts=800 transactions=1600 users=200
TAM="$(wc -c < "$C/bom.sql.gz" | tr -d ' ')"
head -c $((TAM * 6 / 10)) "$C/bom.sql.gz" > "$C/truncado.sql.gz"
pedaco_engana() {
  local n
  n="$(gzip -dc "$C/truncado.sql.gz" 2>/dev/null | grep -c '^CREATE TABLE')"
  [ "$n" -ge 1 ] || { DETALHE="o pedaço legível não tem CREATE TABLE ($n) — o cenário não reproduz a auditoria"; return 1; }
}
afirmar "B-1 (cenário) o gzip truncado ainda solta CREATE TABLE ao ser lido" pedaco_engana
rodar restore-db.sh "$C/truncado.sql.gz" --sim
afirmar "B-1 restore de gzip truncado falha SEM tocar no banco" falhou_sem_tocar stabilmoney "$ANTES"

novo_cenario b1-cortado-no-insert
dump_sql stabilmoney accounts=150 transactions=300 users=40 > "$C/bom.sql"
OFF="$(grep -n '^INSERT INTO `transactions`' "$C/bom.sql" | cut -d: -f1)"
{ head -n $((OFF - 1)) "$C/bom.sql"; sed -n "${OFF}p" "$C/bom.sql" | cut -c1-500; } > "$C/cortado.sql"
rodar restore-db.sh "$C/cortado.sql" --sim
afirmar "B-1 restore de SQL cortado num INSERT falha SEM tocar no banco" falhou_sem_tocar stabilmoney "$ANTES"

novo_cenario b1-sem-users
dump_sql stabilmoney accounts=150 transactions=300 users=40 > "$C/bom.sql"
OFF="$(grep -n '^-- Table structure for table `users`' "$C/bom.sql" | cut -d: -f1)"
head -n $((OFF - 1)) "$C/bom.sql" > "$C/sem-users.sql"
rodar restore-db.sh "$C/sem-users.sql" --sim
afirmar "B-1 restore de SQL sem rodapé e sem a tabela users falha SEM tocar no banco" falhou_sem_tocar stabilmoney "$ANTES"
nao_disse_ok() { ! contem "$SAIDA" "OK —" || { DETALHE="$SAIDA"; return 1; }; }
afirmar "B-1 ...e não imprime 'OK'" nao_disse_ok

novo_cenario b1-gz-sem-rodape
dump_sql stabilmoney accounts=150 transactions=300 users=40 | sed '$d' | gzip -9 > "$C/sem-rodape.sql.gz"
rodar restore-db.sh "$C/sem-rodape.sql.gz" --sim
afirmar "B-1 restore de gzip íntegro sem rodapé falha SEM tocar no banco" falhou_sem_tocar stabilmoney "$ANTES"

# O ensaio pega o que a leitura do arquivo não enxerga: aqui o "servidor" engole a
# tabela users sem dar erro. Tem de parar ANTES de apagar o destino.
novo_cenario b1-perda-silenciosa
dump_gz "$C/bom.sql.gz" stabilmoney accounts=5 transactions=7 users=9
SM_FALSO_PERDER_TABELA=users rodar restore-db.sh "$C/bom.sql.gz" --sim
afirmar "B-1 tabela que não chega é pega no ensaio, ANTES de apagar o destino" falhou_sem_tocar stabilmoney "$ANTES"
afirmar "B-1 ...e o banco temporário do ensaio é apagado" sem_temporario_sobrando
afirmar "B-1 ...e a credencial temporária some do container" sem_credencial_sobrando

novo_cenario b1-bom
dump_gz "$C/bom.sql.gz" stabilmoney accounts=5 transactions=7 users=9
rodar restore-db.sh "$C/bom.sql.gz" --sim
afirmar "B-1 restore de dump bom funciona" saiu_ok
afirmar "B-1 ...e o banco fica com exatamente o que o arquivo promete" estado_e stabilmoney "accounts=5 transactions=7 users=9 "
afirmar "B-1 ...sem banco temporário sobrando" sem_temporario_sobrando
afirmar "B-1 ...e sem credencial sobrando no container" sem_credencial_sobrando

# O lado do backup: stream que termina "com sucesso" sem o rodapé não vira arquivo.
novo_cenario b1-backup-cortado
SM_FALSO_DUMP_SEM_RODAPE=1 rodar backup-db.sh
nada_publicado() {
  [ "$CODIGO" -ne 0 ] || { DETALHE="saiu com 0: $SAIDA"; return 1; }
  [ -z "$(ls "$BK" 2>/dev/null | grep '\.sql\.gz$')" ] || { DETALHE="publicou: $(ls -a "$BK")"; return 1; }
  [ -z "$(ls -a "$BK" 2>/dev/null | grep 'parcial')" ] || { DETALHE="sobrou: $(ls -a "$BK")"; return 1; }
}
afirmar "B-1 backup recusa dump sem rodapé e não publica nada" nada_publicado

# --- B-2: o ensaio funciona sem grant manual ---------------------------

novo_cenario b2-ensaio
dump_gz "$C/bom.sql.gz" stabilmoney accounts=5 transactions=7 users=9
rodar restore-db.sh "$C/bom.sql.gz" --banco stabil_ensaio --sim
afirmar "B-2 restaurar em --banco stabil_ensaio funciona com o usuário do app sem grant" saiu_ok
afirmar "B-2 ...o ensaio fica com o conteúdo do arquivo" estado_e stabil_ensaio "accounts=5 transactions=7 users=9 "
afirmar "B-2 ...e o banco do app não é tocado" estado_e stabilmoney "$ANTES"

novo_cenario b2-ensaio-existente
semear stabil_ensaio accounts=1 users=1
dump_gz "$C/bom.sql.gz" stabilmoney accounts=5 transactions=7 users=9
rodar restore-db.sh "$C/bom.sql.gz" --banco stabil_ensaio --sim
afirmar "B-2 restaurar por cima de um ensaio que já existe (backup de segurança dele incluído)" saiu_ok
seguranca_do_ensaio() {
  ls "$BK" 2>/dev/null | grep -q '^stabil_ensaio-.*\.sql\.gz$' || { DETALHE="$(ls "$BK" 2>&1)"; return 1; }
}
afirmar "B-2 ...o backup de segurança do ensaio foi gravado" seguranca_do_ensaio

novo_cenario b2-backup-outro-banco
semear stabil_ensaio accounts=1 users=4
rodar backup-db.sh --banco stabil_ensaio
backup_do_ensaio() {
  saiu_ok || return 1
  ls "$BK" | grep -q '^stabil_ensaio-[0-9]\{8\}-[0-9]\{6\}\.sql\.gz$' || { DETALHE="$(ls "$BK")"; return 1; }
}
afirmar "B-2 backup de outro banco (--banco stabil_ensaio) funciona" backup_do_ensaio

# --- B-3: "mais recente" = o comum mais novo do banco alvo --------------

novo_cenario b3-escolha
mkdir -p "$BK"
dump_gz "$BK/stabilmoney-20260101-030000.sql.gz" stabilmoney accounts=1 users=1
dump_gz "$BK/stabilmoney-20260102-030000.sql.gz" stabilmoney accounts=1 users=2
dump_gz "$BK/stabilmoney-20260102-030000-2.sql.gz" stabilmoney accounts=1 users=3
dump_gz "$BK/stabilmoney-20260102-030000-10.sql.gz" stabilmoney accounts=1 users=10
dump_gz "$BK/stabilmoney-20300101-000000.antes-de-restaurar.sql.gz" stabilmoney accounts=1 users=77
dump_gz "$BK/teste-20990101-000000.sql.gz" teste accounts=1 users=99
dump_gz "$BK/stabilmoney_old-20991231-235959.sql.gz" stabilmoney_old accounts=1 users=98
dump_gz "$BK/stabilmoney-backup-manual.sql.gz" stabilmoney accounts=1 users=97
for f in "$BK"/*.sql.gz; do touch -t 202609010000 "$f"; done
touch -t 200001010000 "$BK/stabilmoney-20260102-030000-10.sql.gz"
rodar restore-db.sh --sim
afirmar "B-3 sem argumento, restaura o comum mais novo do banco (-10), não o de outro banco nem o de segurança" \
  estado_e stabilmoney "accounts=1 users=10 "

novo_cenario b3-escolha-ensaio
mkdir -p "$BK"
dump_gz "$BK/stabil_ensaio-20260101-000000.sql.gz" stabilmoney accounts=1 users=5
dump_gz "$BK/stabil_ensaio-20260201-000000.antes-de-restaurar.sql.gz" stabil_ensaio accounts=1 users=6
dump_gz "$BK/stabilmoney-20260101-000000.sql.gz" stabilmoney accounts=1 users=7
rodar restore-db.sh --banco stabil_ensaio --sim
afirmar "B-3 com --banco, escolhe entre os backups DAQUELE banco" estado_e stabil_ensaio "accounts=1 users=5 "
afirmar "B-3 ...e não toca no banco do app" estado_e stabilmoney "$ANTES"

# --- B-4: o backup de segurança não rotaciona os do cron ----------------

novo_cenario b4-rotacao
mkdir -p "$BK"
dump_gz "$C/modelo.sql.gz" stabilmoney accounts=1 users=1
for i in 01 02 03 04 05 06 07 08 09 10 11 12 13 14 15 16 17 18 19 20; do
  cp "$C/modelo.sql.gz" "$BK/stabilmoney-20260101-0000$i.sql.gz"
done
dump_gz "$C/bom.sql.gz" stabilmoney accounts=5 transactions=7 users=9
rodar restore-db.sh "$C/bom.sql.gz" --sim
guardou_os_vinte() {
  local n
  n="$(ls "$BK" | grep -c '^stabilmoney-20260101-0000[0-9][0-9]\.sql\.gz$')"
  [ "$n" = 20 ] || { DETALHE="sobraram $n dos 20 backups do cron: $(ls "$BK" | tr '\n' ' ')"; return 1; }
}
afirmar "B-4 restaurar não apaga nenhum dos 20 backups do cron" guardou_os_vinte
seguranca_com_nome_proprio() {
  local f
  f="$(ls "$BK" | grep '\.antes-de-restaurar\.sql\.gz$')"
  [ -n "$f" ] || { DETALHE="$(ls "$BK" | tr '\n' ' ')"; return 1; }
  gzip -dc "$BK/$f" | grep -q '^INSERT INTO `users` VALUES (1),(2),(3);' || { DETALHE="o de segurança não tem o estado de antes"; return 1; }
}
afirmar "B-4 ...o de segurança tem nome próprio e guarda o estado de ANTES" seguranca_com_nome_proprio
rodar restore-db.sh --sim
nao_escolheu_seguranca() {
  estado_e stabilmoney "accounts=1 users=1 " || return 1
}
afirmar "B-3/B-4 o restore seguinte, sem argumento, não pega o de segurança (mais novo)" nao_escolheu_seguranca

# --- B-5: cron — PATH, cores e dois backups no mesmo segundo ------------

novo_cenario b5-cores
rodar backup-db.sh
sem_cores() {
  saiu_ok || return 1
  if printf '%s' "$SAIDA" | grep -q "$(printf '\033')"; then
    DETALHE="$(printf '%s' "$SAIDA" | grep "$(printf '\033')" | head -n 2 | cat -v)"
    return 1
  fi
}
afirmar "B-5 sem terminal (log do cron), nada de código de cor ANSI" sem_cores

novo_cenario b5-mesmo-segundo
mkdir -p "$C/relogio"
DATA_REAL="$(command -v date)"
cat > "$C/relogio/date" <<EOF
#!/bin/sh
case "\${1-}" in
  +%Y%m%d-%H%M%S) echo 20260922-201521 ;;
  +%Y%m%d%H%M%S) echo 20260922201521 ;;
  *) exec "$DATA_REAL" "\$@" ;;
esac
EOF
chmod +x "$C/relogio/date"
PATH_TESTE="$C/relogio:$PATH_BASE" rodar backup-db.sh
PRIMEIRO="$SAIDA"
PATH_TESTE="$C/relogio:$PATH_BASE" rodar backup-db.sh
dois_arquivos() {
  local n
  n="$(ls "$BK" | grep -c '^stabilmoney-20260922-201521.*\.sql\.gz$')"
  [ "$n" = 2 ] || { DETALHE="$n arquivo(s): $(ls "$BK" | tr '\n' ' ') | $PRIMEIRO | $SAIDA"; return 1; }
}
afirmar "B-5 dois backups no mesmo segundo viram dois arquivos (nenhum sobrescrito)" dois_arquivos

# PATH mínimo, como o do cron, e sem o docker nele.
novo_cenario b5-path
mkdir -p "$C/bin-minimo"
for ferramenta in bash sh env cat cp mv ln rm mkdir chmod touch date find sort awk sed grep tr cut \
  head tail wc du gzip gunzip od mktemp dirname basename xargs ls id printf; do
  alvo="$(command -v "$ferramenta" 2>/dev/null)" && ln -s "$alvo" "$C/bin-minimo/$ferramenta"
done
PATH_TESTE="$C/bin-minimo" rodar backup-db.sh
fala_do_path() {
  [ "$CODIGO" -ne 0 ] || { DETALHE="saiu com 0"; return 1; }
  contem "$SAIDA" "PATH" || { DETALHE="$SAIDA"; return 1; }
  contem "$SAIDA" "'docker'" || { DETALHE="$SAIDA"; return 1; }
}
afirmar "B-5 sem docker no PATH, a mensagem fala do PATH (não de 'Docker Compose')" fala_do_path
PATH_TESTE="$C/bin-minimo" PATH_EXTRA_TESTE="$T/bin" rodar backup-db.sh
afirmar "B-5 com PATH mínimo, o script acha o docker nos diretórios de sempre" saiu_ok

novo_cenario b5-daemon
SM_FALSO_DAEMON_FORA=1 rodar backup-db.sh
fala_do_daemon() {
  [ "$CODIGO" -ne 0 ] || { DETALHE="saiu com 0"; return 1; }
  contem "$SAIDA" "não respondeu" || { DETALHE="$SAIDA"; return 1; }
}
afirmar "B-5 Docker parado: a mensagem diz que o Docker não respondeu" fala_do_daemon

# --- o que a restauração como root passou a exigir ----------------------

novo_cenario novo-ensaio
dump_gz "$C/bom.sql.gz" stabilmoney accounts=5 transactions=7 users=9
rodar restore-db.sh "$C/bom.sql.gz" --ensaio
ensaio_aprovado() {
  saiu_ok || return 1
  estado_e stabilmoney "$ANTES" || return 1
  grep -q '^ESCRITA sm_verif_[0-9]*_[0-9]* criou o banco' "$E/registro" || { DETALHE="não ensaiou: $(cat "$E/registro")"; return 1; }
  sem_temporario_sobrando || return 1
  [ "$(grep -c '^ESCRITA ' "$E/registro")" = "$(grep -c '^ESCRITA sm_verif_' "$E/registro")" ] ||
    { DETALHE="escreveu fora do temporário: $(grep '^ESCRITA ' "$E/registro" | grep -v sm_verif_)"; return 1; }
}
afirmar "novo: --ensaio aplica num banco temporário, confere, apaga e não toca em mais nada" ensaio_aprovado

novo_cenario novo-ensaio-ruim
dump_sql stabilmoney accounts=5 users=9 | sed '$d' > "$C/sem-rodape.sql"
rodar restore-db.sh "$C/sem-rodape.sql" --ensaio
ensaio_reprovado() {
  [ "$CODIGO" -ne 0 ] || { DETALHE="saiu com 0: $SAIDA"; return 1; }
  [ -z "$(grep '^ESCRITA ' "$E/registro")" ] || { DETALHE="$(cat "$E/registro")"; return 1; }
}
afirmar "novo: --ensaio de arquivo ruim reprova sem criar banco nenhum" ensaio_reprovado

novo_cenario novo-use
{
  cabecalho_sql stabilmoney
  printf '%s\n' 'CREATE DATABASE /*!32312 IF NOT EXISTS*/ `stabilmoney`;' 'USE `stabilmoney`;'
  estrutura_sql users
  dados_sql users 1
  rodape_sql
} > "$C/databases.sql"
rodar restore-db.sh "$C/databases.sql" --banco stabil_ensaio --sim
recusou_use() {
  falhou_sem_tocar stabilmoney "$ANTES" || return 1
  [ -z "$(grep '^ESCRITA ' "$E/registro")" ] || { DETALHE="$(cat "$E/registro")"; return 1; }
}
afirmar "novo: dump com USE/CREATE DATABASE é recusado antes de qualquer escrita (escaparia do ensaio)" recusou_use

novo_cenario novo-sistema
dump_gz "$C/bom.sql.gz" stabilmoney accounts=5 users=9
rodar restore-db.sh "$C/bom.sql.gz" --banco mysql --sim
recusou_sistema() {
  [ "$CODIGO" -ne 0 ] || { DETALHE="saiu com 0"; return 1; }
  [ -z "$(grep '^ESCRITA ' "$E/registro")" ] || { DETALHE="$(cat "$E/registro")"; return 1; }
  contem "$SAIDA" "próprio MySQL" || { DETALHE="$SAIDA"; return 1; }
}
afirmar "novo: restaurar em cima do banco 'mysql' é recusado" recusou_sistema

novo_cenario novo-origem
dump_gz "$C/do-ensaio.sql.gz" stabil_ensaio accounts=5 users=9
rodar restore-db.sh "$C/do-ensaio.sql.gz" --sim
recusou_origem() {
  falhou_sem_tocar stabilmoney "$ANTES" || return 1
  contem "$SAIDA" "--origem-diferente" || { DETALHE="$SAIDA"; return 1; }
}
afirmar "novo: backup de outro banco por cima do banco do app pede --origem-diferente" recusou_origem
rodar restore-db.sh "$C/do-ensaio.sql.gz" --sim --origem-diferente
afirmar "novo: ...e com --origem-diferente restaura" estado_e stabilmoney "accounts=5 users=9 "

# Quem lê a saída some no meio (sessão SSH que cai, `| head`): o restore não pode
# parar entre o DROP das tabelas e o fim da aplicação do dump.
novo_cenario novo-saida-fechada
dump_gz "$C/bom.sql.gz" stabilmoney accounts=5 transactions=7 users=9
(cd "$P" && env PATH="$PATH_BASE" SM_PATH_EXTRA="" SM_FALSO_DIR="$E" \
  bash "$P/scripts/restore-db.sh" "$C/bom.sql.gz" --sim 2>/dev/null < /dev/null | head -n 1 > /dev/null)
afirmar "novo: com a saída fechada no meio (| head -n 1), o restore vai até o fim" \
  estado_e stabilmoney "accounts=5 transactions=7 users=9 "
afirmar "novo: ...e ainda limpa a credencial do container" sem_credencial_sobrando

novo_cenario novo-sem-root
dump_gz "$C/bom.sql.gz" stabilmoney accounts=5 users=9
SM_FALSO_SEM_ROOT=1 rodar restore-db.sh "$C/bom.sql.gz" --sim
sem_root_explica() {
  falhou_sem_tocar stabilmoney "$ANTES" || return 1
  contem "$SAIDA" "MYSQL_ROOT_PASSWORD" || { DETALHE="$SAIDA"; return 1; }
}
afirmar "novo: container sem senha de root — mensagem clara e nada tocado" sem_root_explica

# ---------------------------------------------------------------- resumo

echo ""
echo "$PASSOU passaram, $FALHOU falharam"
if [ "$FALHOU" -gt 0 ]; then
  printf 'Falharam:%s\n' "$LISTA_FALHAS"
  exit 1
fi
