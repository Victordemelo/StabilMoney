#!/usr/bin/env bash
#
# Stabil Money — restauração do banco MySQL a partir de um backup.
#
# Backup que ninguém sabe restaurar não é backup. Este script é o par do
# backup-db.sh e faz, nesta ordem:
#   1. escolhe o arquivo: o informado, ou o backup COMUM mais novo do banco alvo;
#   2. valida o arquivo: gzip íntegro, rodapé "-- Dump completed", nada que troque
#      de banco — e anota o que ele PROMETE: as tabelas e as linhas de cada uma;
#   3. ENSAIA: aplica o arquivo num banco temporário, confere que tudo que ele
#      promete chegou, e apaga o banco temporário;
#   4. pede confirmação (digitando o nome do banco);
#   5. tira um backup de segurança do estado atual (a rede antes do salto);
#   6. apaga as tabelas atuais, aplica o arquivo e confere de novo, agora no destino.
# Até o passo 6, NADA do banco de destino é tocado: arquivo cortado, corrompido ou
# que não restaura por inteiro para no passo 2 ou no 3.
#
# Uso:
#   ./scripts/restore-db.sh                          # o backup mais recente do banco do .env
#   ./scripts/restore-db.sh storage/backups/x.sql.gz # um arquivo específico
#   ./scripts/restore-db.sh --ensaio                 # só prova que o backup restaura
#   ./scripts/restore-db.sh x.sql.gz --banco stabil_ensaio   # restaura noutro banco
#   ./scripts/restore-db.sh x.sql.gz --sim           # sem perguntar (cuidado)
#
# Opções:
#   --banco NOME         banco de destino (padrão: DB_DATABASE do .env)
#   --ensaio             para no passo 3: não pede nada e não toca em banco nenhum
#   --sim, -y            não pede confirmação
#   --sem-backup-previo  pula o passo 5 (não recomendado)
#   --origem-diferente   aceita restaurar no banco do app o backup de OUTRO banco
#   --servico NOME       serviço do docker-compose (padrão: db)
#   --env ARQUIVO        outro .env (padrão: o da raiz do projeto)
#
# Aceita .sql.gz e .sql. Sem arquivo, procura em SM_BACKUP_DIR (padrão:
# storage/backups) só os backups COMUNS do banco alvo, pela data do nome — os de
# segurança (.antes-de-restaurar) e os de outros bancos nunca são escolhidos
# sozinhos. Variáveis: SM_BACKUP_DIR, SM_DB_SERVICE, SM_ENV_FILE, NO_COLOR.
#
# CREDENCIAL — roda como ROOT do MySQL. O usuário do app só tem permissão no banco
# do app, e o ensaio precisa criar e apagar um banco (era por isso que o ensaio em
# "--banco stabil_ensaio" dava "1044 Access denied"). A senha de root é lida DENTRO
# do container, na variável MYSQL_ROOT_PASSWORD que o docker-compose.yml entrega ao
# serviço do banco: não passa pelo host nem pela linha de comando de processo
# nenhum (scripts/lib/cnf-root.sh). Por rodar como root, o script recusa restaurar
# em cima dos bancos do próprio MySQL e recusa arquivo com USE/CREATE DATABASE.

set -euo pipefail
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
DIR_BACKUPS="${SM_BACKUP_DIR:-$RAIZ/storage/backups}"
SERVICO_DB="${SM_DB_SERVICE:-db}"
ARQUIVO=""
BANCO=""
CONFIRMADO=0
BACKUP_PREVIO=1
ENSAIO=0
OUTRA_ORIGEM=0

while [ $# -gt 0 ]; do
  case "$1" in
    --banco)              BANCO="${2:?--banco precisa de um nome}";        shift 2 ;;
    --servico)            SERVICO_DB="${2:?--servico precisa de um nome}"; shift 2 ;;
    --env)                ARQUIVO_ENV="${2:?--env precisa de um arquivo}"; shift 2 ;;
    --sim|-y)             CONFIRMADO=1; shift ;;
    --sem-backup-previo)  BACKUP_PREVIO=0; shift ;;
    --ensaio)             ENSAIO=1; shift ;;
    --origem-diferente)   OUTRA_ORIGEM=1; shift ;;
    -h|--help)            sm_imprimir_uso "$0"; exit 0 ;;
    -*) erro "opção desconhecida: $1 (use --help)" ;;
    *)
      [ -z "$ARQUIVO" ] || erro "informe um arquivo só (recebi '$ARQUIVO' e '$1')"
      ARQUIVO="$(sm_caminho_absoluto "$1" "$DIR_CHAMADA")"
      shift
      ;;
  esac
done

DIR_BACKUPS="$(sm_caminho_absoluto "$DIR_BACKUPS" "$DIR_CHAMADA")"
ARQUIVO_ENV="$(sm_caminho_absoluto "$ARQUIVO_ENV" "$DIR_CHAMADA")"
cd "$RAIZ"

[ -f "$ARQUIVO_ENV" ] || erro "não achei o .env em $ARQUIVO_ENV"
DB_APP="$(sm_ler_env "$ARQUIVO_ENV" DB_DATABASE stabilmoney)"
[ -n "$BANCO" ] || BANCO="$DB_APP"

# O nome do banco entra em SQL — só identificador simples.
sm_nome_de_banco_valido "$BANCO" || erro "nome de banco inválido: '$BANCO' (só letras, números e _)"
if sm_banco_de_sistema "$BANCO"; then
  erro "'$BANCO' é um banco do próprio MySQL (usuários, permissões). Restaurar em cima dele derrubaria o servidor inteiro."
fi

# Tudo que o script cria fica aqui e some no final.
TRABALHO="$(mktemp -d "${TMPDIR:-/tmp}/sm-restore.XXXXXX")"
PROMETIDO="$TRABALHO/prometido"
CNF_CRIADO=0
TEMP_DB=""
TEMP_DB_CRIADO=0
FASE_DESTRUTIVA=0
SEGURANCA=""

# ---------------------------------------------------------------- limpeza

apagar_temporario() {
  [ "$TEMP_DB_CRIADO" = 1 ] || return 0
  # Trava: só apaga um banco com o formato do temporário do ensaio — nunca o alvo.
  sm_nome_de_temporario "$TEMP_DB" || return 1
  [ "$TEMP_DB" != "$BANCO" ] || return 1
  consulta_root "DROP DATABASE IF EXISTS \`$TEMP_DB\`" >/dev/null || return 1
  TEMP_DB_CRIADO=0
}

limpar() {
  local code=$?
  set +e
  if [ "$FASE_DESTRUTIVA" = 1 ]; then
    vermelho ""
    vermelho "A restauração parou no meio: o banco '$BANCO' pode estar INCOMPLETO."
    if [ -n "$SEGURANCA" ]; then
      vermelho "O estado de antes está no backup de segurança. Para voltar a ele:"
      vermelho "  ./scripts/restore-db.sh '$SEGURANCA' --banco $BANCO"
    else
      vermelho "Não há backup de segurança desta rodada (--sem-backup-previo ou banco vazio)."
    fi
  fi
  if [ "$TEMP_DB_CRIADO" = 1 ] && ! apagar_temporario; then
    vermelho "AVISO: não consegui apagar o banco temporário '$TEMP_DB' — apague à mão."
  fi
  if [ "$CNF_CRIADO" = 1 ]; then
    "${DC[@]}" exec -T "$SERVICO_DB" rm -f "$CNF" >/dev/null 2>&1 </dev/null
  fi
  rm -rf -- "$TRABALHO"
  exit "$code"
}
trap limpar EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# ------------------------------------------------ 1. escolher o arquivo

if [ -z "$ARQUIVO" ]; then
  ARQUIVO="$(sm_backup_mais_recente "$DIR_BACKUPS" "$BANCO")"
  if [ -z "$ARQUIVO" ]; then
    erro "nenhum backup de '$BANCO' em $DIR_BACKUPS. Rode ./scripts/backup-db.sh antes, ou informe o arquivo. (Os de segurança, .antes-de-restaurar, e os de outros bancos não entram na escolha automática.)"
  fi
  info "Nenhum arquivo informado — usando o backup mais recente de '$BANCO':"
  info "  $ARQUIVO"
  ULTIMO_SEGURANCA="$(sm_listar_backups "$DIR_BACKUPS" "$BANCO" "$SM_SUFIXO_SEGURANCA" | tail -n 1)"
  if [ -n "$ULTIMO_SEGURANCA" ]; then
    info "  (há backup de segurança em $DIR_BACKUPS — ele não entra na escolha automática;"
    info "   para restaurá-lo, informe o arquivo: $(basename "$ULTIMO_SEGURANCA"))"
  fi
fi
[ -f "$ARQUIVO" ] || erro "arquivo não encontrado: $ARQUIVO"

# ------------------------------------------------ 2. validar (sem tocar em banco)

info ""
info "Conferindo o arquivo..."
if ! sm_validar_dump "$ARQUIVO" "$PROMETIDO"; then
  erro "$SM_MOTIVO. Nada foi alterado."
fi
ORIGEM_DUMP="$(sm_manifesto_valor "$PROMETIDO" origem)"
CONCLUIDO="$(sm_manifesto_valor "$PROMETIDO" concluido)"
RESUMO="$(sm_manifesto_resumo "$PROMETIDO")"
TAMANHO="$(du -h "$ARQUIVO" | cut -f1 | tr -d ' ')"
info "  íntegro: $RESUMO · $TAMANHO · backup do banco '${ORIGEM_DUMP:-?}' · mysqldump concluído em ${CONCLUIDO:-?}"

# O dump de OUTRO banco por cima do banco do app é o acidente clássico (o arquivo do
# ensaio restaurado na produção). Ensaio e restauração noutro banco não passam por
# aqui: não sobrescrevem a produção.
if [ "$ENSAIO" -eq 0 ] && [ "$BANCO" = "$DB_APP" ] && [ "$ORIGEM_DUMP" != "$BANCO" ] && [ "$OUTRA_ORIGEM" -ne 1 ]; then
  erro "este arquivo é backup do banco '${ORIGEM_DUMP:-desconhecido}', não de '$BANCO' (o banco do app). Nada foi alterado. Se é isso mesmo (trazer dados de outro ambiente), repita com --origem-diferente."
fi

# ------------------------------------------------ credencial de root no container

sm_encontrar_compose
sm_exigir_servico "$SERVICO_DB"

CARIMBO="$(date +%Y%m%d%H%M%S)"
CNF="/tmp/.sm-restore-$$-${CARIMBO}.cnf"
CNF_CRIADO=1
sm_gravar_cnf_root "$SERVICO_DB" "$CNF"

# --commands=OFF (quando o cliente do container conhece a opção) desliga os
# comandos do próprio cliente mysql, como "\!", que rodaria um programa no
# container. O DELIMITER dos triggers e rotinas continua funcionando.
mysql_root() { # [opções] [banco] — SQL pelo STDIN
  "${DC[@]}" exec -T "$SERVICO_DB" mysql "--defaults-extra-file=$CNF" \
    --default-character-set=utf8mb4 ${SM_OPCAO_COMMANDS:+"$SM_OPCAO_COMMANDS"} "$@"
}
consulta_root() { # "SQL" — resultado sem cabeçalho, uma linha por registro
  mysql_root -N -B -e "$1" </dev/null
}
aplicar_arquivo() { # <banco>
  sm_ler_dump "$ARQUIVO" | mysql_root "$1"
}

# O que um banco TEM: cada tabela e quantas linhas ela tem, no mesmo formato do
# manifesto do arquivo. Uma ida só ao MySQL (a consulta monta o UNION sozinha).
manifesto_do_banco() { # <banco> <arquivo de saída>
  mysql_root -N -B > "$TRABALHO/contagem" <<SQL || return 1
/* sm:manifesto */
SET SESSION group_concat_max_len = 16777216;
SET @sm_q = (
  SELECT GROUP_CONCAT(
           CONCAT('SELECT ', QUOTE(table_name), ', COUNT(*) FROM \`$1\`.\`',
                  REPLACE(table_name, '\`', '\`\`'), '\`')
           ORDER BY table_name SEPARATOR ' UNION ALL ')
  FROM information_schema.tables
  WHERE table_schema = '$1' AND table_type = 'BASE TABLE');
SET @sm_q = IFNULL(@sm_q, 'SELECT 1, 1 FROM DUAL WHERE FALSE');
PREPARE sm_consulta FROM @sm_q;
EXECUTE sm_consulta;
DEALLOCATE PREPARE sm_consulta;
SQL
  awk -F '\t' 'NF >= 2 { print "tabela\t" $1 "\t" $2 }' "$TRABALHO/contagem" > "$2"
}

# ------------------------------------------------ 3. ensaio num banco temporário

# O ensaio responde "este arquivo restaura POR INTEIRO neste servidor?" antes de o
# destino ser tocado: aplica o arquivo num banco com nome próprio e compara, tabela
# por tabela, as linhas que chegaram com as que o arquivo promete. Pega o que a
# leitura do arquivo não enxerga — o MySQL recusar algum comando, faltar espaço em
# disco, uma tabela que não se cria.
TEMP_DB="sm_verif_${CARIMBO}_$$"
info ""
info "Ensaiando num banco temporário ('$TEMP_DB')..."
consulta_root "CREATE DATABASE \`$TEMP_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" >/dev/null \
  || erro "não consegui criar o banco temporário do ensaio. Nada foi alterado em '$BANCO'."
TEMP_DB_CRIADO=1
if ! aplicar_arquivo "$TEMP_DB"; then
  erro "o arquivo NÃO restaura: o MySQL recusou no ensaio (mensagem acima). Nada foi alterado em '$BANCO'."
fi
manifesto_do_banco "$TEMP_DB" "$TRABALHO/ensaio" || erro "não consegui conferir o banco temporário. Nada foi alterado em '$BANCO'."
if ! DIFERENCAS="$(sm_comparar_manifestos "$PROMETIDO" "$TRABALHO/ensaio" "o arquivo" "o banco temporário")"; then
  vermelho "$DIFERENCAS"
  erro "o ensaio não bateu com o que o arquivo promete (acima). Nada foi alterado em '$BANCO'."
fi
apagar_temporario || vermelho "AVISO: não consegui apagar o banco temporário '$TEMP_DB' — apague à mão."
verde "  ensaio OK: tudo que o arquivo promete chegou ($RESUMO)."

if [ "$ENSAIO" -eq 1 ]; then
  info ""
  verde "OK — este backup restaura por inteiro: $(basename "$ARQUIVO")"
  info "O banco temporário foi apagado; nenhum outro banco foi tocado."
  exit 0
fi

# ---------------------------------------------------------------- 4. confirmação

manifesto_do_banco "$BANCO" "$TRABALHO/hoje" || erro "não consegui ler o estado atual de '$BANCO'. Nada foi alterado."
TABELAS_HOJE="$(awk -F '\t' '$1 == "tabela"' "$TRABALHO/hoje" | wc -l | tr -d ' ')"

info ""
info "Arquivo: $ARQUIVO"
info "Destino: banco '$BANCO' no container '$SERVICO_DB' — hoje com $(sm_manifesto_resumo "$TRABALHO/hoje")"
if [ "$TABELAS_HOJE" -gt 0 ] && ! MUDANCAS="$(sm_comparar_manifestos "$PROMETIDO" "$TRABALHO/hoje" "o arquivo" "o banco hoje")"; then
  info "Diferenças entre o arquivo e o banco hoje (o que só existe hoje vai se perder):"
  printf '%s\n' "$MUDANCAS" | head -n 15 | sed 's/^/  /' || true
fi

if [ "$CONFIRMADO" -ne 1 ]; then
  amarelo ""
  amarelo "ATENÇÃO: isto APAGA todas as tabelas de '$BANCO' e as recria a partir do backup."
  amarelo "Tudo que foi gravado depois de $(basename "$ARQUIVO") será perdido."
  amarelo ""
  sm_escrever 'Digite o nome do banco para confirmar (%s): ' "$BANCO"
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

# ------------------------------------------------ 5. backup de segurança do estado atual

if [ "$BACKUP_PREVIO" -eq 1 ] && [ "$TABELAS_HOJE" -gt 0 ]; then
  info ""
  info "Tirando um backup de segurança do estado ATUAL de '$BANCO'..."
  # Nome próprio (.antes-de-restaurar) e SEM rotação: não apaga backup do cron e
  # nunca é escolhido como "o mais recente".
  if ! SM_ARQUIVO_RESULTADO="$TRABALHO/seguranca" SM_DB_SERVICE="$SERVICO_DB" \
      bash "$RAIZ/scripts/backup-db.sh" --banco "$BANCO" --root --saida "$DIR_BACKUPS" \
      --env "$ARQUIVO_ENV" --antes-de-restaurar >/dev/null; then
    erro "o backup de segurança falhou — restauração abortada, nada foi alterado. Use --sem-backup-previo para forçar."
  fi
  SEGURANCA="$(cat "$TRABALHO/seguranca")"
  verde "  $SEGURANCA"
elif [ "$TABELAS_HOJE" -eq 0 ]; then
  info "O banco '$BANCO' está vazio ou não existe — nada a salvar antes."
fi

# ------------------------------------------ 6. limpar o que existe e aplicar o dump

FASE_DESTRUTIVA=1
consulta_root "CREATE DATABASE IF NOT EXISTS \`$BANCO\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" >/dev/null \
  || erro "não consegui acessar/criar o banco '$BANCO'"

# Só o DROP TABLE do próprio dump não basta: tabela criada DEPOIS do backup (uma
# migration nova, por exemplo) não está lá e sobreviveria, deixando o banco num
# estado que nunca existiu.
info ""
info "Apagando as tabelas atuais de '$BANCO'..."
DROPS="$(consulta_root "SELECT CONCAT('DROP ', IF(table_type='VIEW','VIEW','TABLE'), ' IF EXISTS \`', REPLACE(table_name, '\`', '\`\`'), '\`;') FROM information_schema.tables WHERE table_schema = '$BANCO'")" \
  || erro "falhei ao listar as tabelas de '$BANCO'"
if [ -n "$DROPS" ]; then
  printf 'SET FOREIGN_KEY_CHECKS=0;\n%s\nSET FOREIGN_KEY_CHECKS=1;\n' "$DROPS" \
    | mysql_root "$BANCO" || erro "falhei ao limpar as tabelas antigas"
fi

info "Aplicando o backup..."
aplicar_arquivo "$BANCO" || erro "a aplicação falhou no meio."

# ---------------------------------------------------------------- 7. conferência

manifesto_do_banco "$BANCO" "$TRABALHO/destino" || erro "não consegui conferir '$BANCO' depois de restaurar."
if ! DIFERENCAS="$(sm_comparar_manifestos "$PROMETIDO" "$TRABALHO/destino" "o arquivo" "'$BANCO'")"; then
  vermelho "$DIFERENCAS"
  erro "a restauração não bateu com o que o arquivo promete (acima)."
fi
FASE_DESTRUTIVA=0

info ""
verde "OK — banco '$BANCO' restaurado e conferido: $RESUMO, exatamente o que o arquivo promete."
info ""
info "Confira o app e, se algo estiver errado, o estado anterior está em:"
info "  ${SEGURANCA:-(sem backup de segurança nesta rodada)}"
if [ "$BANCO" != "$DB_APP" ]; then
  info ""
  info "'$BANCO' não é o banco do app e fica aí até alguém apagá-lo. Para isso:"
  info "  docker compose exec $SERVICO_DB mysql -uroot -p   (a senha é o DB_ROOT_PASSWORD do .env)"
  info "  DROP DATABASE \`$BANCO\`;"
fi
