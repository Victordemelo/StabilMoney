# shellcheck shell=bash
#
# Stabil Money — funções compartilhadas por backup-db.sh e restore-db.sh.
#
# Este arquivo NÃO é executado: os dois scripts o carregam com `.`. Quase tudo aqui
# é puro (arquivo e texto, sem Docker), de propósito: é o pedaço que decide se um
# backup presta e QUAL backup restaurar, e isso precisa ser testável em qualquer
# máquina — tests/scripts/backup-restore.test.sh roda estas funções no CI, que não
# tem o MySQL do projeto. As poucas funções que falam com o Docker ficam no fim.
#
# Compatível com o bash 3.2 do macOS e com as ferramentas BSD (Mac) e GNU (VPS):
# nada de `sort -V`, `stat -c`, `date +%N`, `mapfile` ou `${var,,}`.

SM_LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ------------------------------------------------------------------------ saída

# Cor só quando quem lê é um terminal. No cron a saída vai para um arquivo de log,
# e os códigos ANSI viravam lixo ("[32mOK") no meio das linhas. NO_COLOR é a
# convenção de https://no-color.org para desligar a cor mesmo no terminal.
SM_COR_OUT=0
SM_COR_ERR=0
sm_iniciar_cores() {
  SM_COR_OUT=0
  SM_COR_ERR=0
  if [ -z "${NO_COLOR:-}" ]; then
    if [ -t 1 ]; then SM_COR_OUT=1; fi
    if [ -t 2 ]; then SM_COR_ERR=1; fi
  fi
}

# Os ajudantes de saída NUNCA falham: sem ninguém lendo (ver sm_ignorar_desconexao),
# a mensagem se perde, mas o script segue. Com `set -e`, uma escrita que falhasse
# derrubaria o script no meio do que estivesse fazendo.
#
# A saída padrão passa por um printf EXTERNO (`env printf`), nunca pelo embutido do
# bash. O bash 3.2 do macOS guarda no buffer o que o printf embutido não conseguiu
# escrever (saída fechada), e no fork seguinte o processo filho herda esse buffer e
# o despeja onde estiver escrevendo: num teste, um $(od ...) capturou "1f8b" MAIS a
# mensagem que tinha falhado antes, e o script leu um .gz como se fosse SQL puro.
# Com o printf externo, a falha fica no processo filho e morre com ele. (A saída de
# erro não tem buffer, então o `vermelho` pode usar o embutido.)
sm_escrever() { env printf "$@" 2>/dev/null || true; }
vermelho() {
  if [ "$SM_COR_ERR" = 1 ]; then printf '\033[31m%s\033[0m\n' "$*" >&2 || true; else printf '%s\n' "$*" >&2 || true; fi
}
amarelo() {
  if [ "$SM_COR_OUT" = 1 ]; then sm_escrever '\033[33m%s\033[0m\n' "$*"; else sm_escrever '%s\n' "$*"; fi
}
verde() {
  if [ "$SM_COR_OUT" = 1 ]; then sm_escrever '\033[32m%s\033[0m\n' "$*"; else sm_escrever '%s\n' "$*"; fi
}
info() { sm_escrever '%s\n' "$*"; }
erro() {
  vermelho "ERRO: $*"
  exit 1
}

# Restauração pela metade é pior que nenhuma. Se a sessão SSH cair (SIGHUP) ou se
# quem lê a saída fechar o cano (SIGPIPE, como em `... | head`), o bash roda o trap
# de saída e MORRE — no restore, isso podia acontecer entre o DROP das tabelas e o
# fim da aplicação do dump, com o aviso de recuperação impresso num terminal que
# já não existe. Ignorando os dois sinais, o script termina o que começou; só as
# mensagens sem destino se perdem. Ctrl+C (INT) e kill (TERM) continuam valendo:
# são interrupções de propósito, e o trap diz como voltar ao estado de antes.
sm_ignorar_desconexao() {
  trap '' HUP PIPE
}

# Imprime o comentário do topo do script (a ajuda do --help).
sm_imprimir_uso() {
  awk 'NR == 1 { next } /^#/ { sub(/^# ?/, ""); print; next } { exit }' "$1"
}

# Caminho relativo vira absoluto a partir de onde o usuário CHAMOU o script — os
# scripts fazem `cd` para a raiz do projeto (é lá que o docker compose acha o
# docker-compose.yml), e um "storage/x.sql.gz" relativo passaria a apontar para
# outro lugar.
sm_caminho_absoluto() {
  case "$1" in
    /*) printf '%s' "$1" ;;
    *) printf '%s/%s' "$2" "$1" ;;
  esac
}

# ------------------------------------------------------------------------- .env

# Lê uma chave do .env SEM executá-lo (`source .env` rodaria qualquer comando que
# estivesse lá dentro). A última ocorrência vence; sem a chave, devolve o padrão.
sm_ler_env() {
  local arquivo="$1" chave="$2" padrao="${3-}" linha valor
  if [ ! -f "$arquivo" ]; then
    printf '%s' "$padrao"
    return 0
  fi
  linha="$(grep -E "^[[:space:]]*${chave}=" "$arquivo" | tail -n 1 || true)"
  if [ -z "$linha" ]; then
    printf '%s' "$padrao"
    return 0
  fi
  valor="${linha#*=}"
  valor="${valor%$'\r'}"
  case "$valor" in
    \"*\") valor="${valor#\"}"; valor="${valor%\"}" ;;
    \'*\') valor="${valor#\'}"; valor="${valor%\'}" ;;
  esac
  printf '%s' "$valor"
}

# Escapa para o formato my.cnf (dentro de aspas duplas, \ e " são especiais).
sm_escapar_cnf() {
  printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'
}

# --------------------------------------------------------------- nomes de banco

# Só identificador simples. O nome entra em nome de arquivo, em padrão de busca e
# em SQL — aceitar qualquer outra coisa abriria espaço para injeção.
sm_nome_de_banco_valido() {
  case "$1" in
    '' | *[!A-Za-z0-9_]*) return 1 ;;
  esac
  [ "${#1}" -le 64 ]
}

# Os bancos do próprio MySQL. A restauração roda como root (ver restore-db.sh):
# restaurar "em cima" de um deles apagaria as tabelas de usuários e permissões do
# servidor inteiro.
sm_banco_de_sistema() {
  case "$(printf '%s' "$1" | tr 'A-Z' 'a-z')" in
    mysql | sys | information_schema | performance_schema) return 0 ;;
  esac
  return 1
}

# O banco temporário do ensaio do restore-db.sh. A trava de "só apago o que eu
# criei" confere este formato antes de qualquer DROP DATABASE.
sm_nome_de_temporario() {
  case "$1" in
    sm_verif_*) ;;
    *) return 1 ;;
  esac
  printf '%s\n' "$1" | LC_ALL=C grep -qE '^sm_verif_[0-9]+_[0-9]+$'
}

# ------------------------------------------------------------ nomes dos backups
#
#   <banco>-AAAAMMDD-HHMMSS.sql.gz                          backup comum (cron ou à mão)
#   <banco>-AAAAMMDD-HHMMSS-N.sql.gz                        o N-ésimo do MESMO segundo
#   <banco>-AAAAMMDD-HHMMSS[-N].antes-de-restaurar.sql.gz   o de segurança do restore
#
# O "-" depois do banco é separador seguro: nome de banco só tem [A-Za-z0-9_], então
# "stabilmoney-" nunca casa com "stabilmoney_old-...". O de segurança tem sufixo
# próprio para NUNCA ser tomado por um backup comum — nem na escolha do "mais
# recente", nem na rotação, que só enxergam os comuns do banco pedido.

SM_SUFIXO_SEGURANCA=".antes-de-restaurar"

# Lista os backups de <banco> em <dir>, do MAIS ANTIGO para o MAIS NOVO. Sem
# <sufixo>, só os comuns; com "$SM_SUFIXO_SEGURANCA", só os de segurança.
#
# A ordem vem da data escrita no NOME. Não da data de modificação: `cp` e `rsync`
# sem -t reescrevem o mtime, e um backup copiado de volta para a VPS pareceria o
# mais novo. E não da ordem alfabética do nome inteiro: "-10" vem antes de "-2" no
# alfabeto, e o backup de OUTRO banco ("teste-2099...") passaria na frente — era
# assim que o `sort -r | head -1` antigo podia restaurar um ensaio na produção.
sm_listar_backups() {
  local dir="$1" banco="$2" sufixo="${3-}"
  [ -d "$dir" ] || return 0
  find "$dir" -maxdepth 1 -type f -name "${banco}-*" 2>/dev/null |
    LC_ALL=C awk -v banco="$banco" -v fim="${sufixo}.sql.gz" '
      {
        caminho = $0
        nome = caminho
        sub(/.*\//, "", nome)
        prefixo = banco "-"
        if (substr(nome, 1, length(prefixo)) != prefixo) next
        resto = substr(nome, length(prefixo) + 1)
        if (length(resto) <= length(fim)) next
        if (substr(resto, length(resto) - length(fim) + 1) != fim) next
        meio = substr(resto, 1, length(resto) - length(fim))
        # AAAAMMDD-HHMMSS, opcionalmente -N com N de 2 a 999999999.
        if (meio !~ /^[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]-[0-9][0-9][0-9][0-9][0-9][0-9](-[2-9]|-[1-9][0-9][0-9]*)?$/) next
        seq = (length(meio) > 15) ? substr(meio, 17) : 1
        if (length(seq) > 9) next
        printf "%s%s %09d\t%s\n", substr(meio, 1, 8), substr(meio, 10, 6), seq, caminho
      }' |
    LC_ALL=C sort |
    cut -f 2-
}

# O backup COMUM mais novo de <banco> em <dir> (vazio se não houver).
sm_backup_mais_recente() {
  sm_listar_backups "$1" "$2" | tail -n 1
}

# Publica <tmp> como <dir>/<base>[-N]<sufixo>.sql.gz SEM NUNCA sobrescrever: dois
# backups no mesmo segundo (o cron e um manual, ou dois restores seguidos) viram
# "...-201521.sql.gz" e "...-201521-2.sql.gz". Antes o segundo apagava o primeiro.
#
# O `ln` é a peça atômica: cria o nome só se ele não existir — falha com "File
# exists" em vez de sobrescrever —, então dois processos nunca ficam com o mesmo
# nome. Em sistema de arquivos sem hard link (exFAT de HD externo, alguns
# compartilhamentos de rede) o `ln` falha por outro motivo; aí o `mv` entra, depois
# de conferir que o nome está livre. Imprime o caminho final.
sm_publicar_sem_sobrescrever() {
  local tmp="$1" dir="$2" base="$3" sufixo="${4-}" n=1 final
  while [ "$n" -le 99 ]; do
    if [ "$n" -eq 1 ]; then
      final="$dir/${base}${sufixo}.sql.gz"
    else
      final="$dir/${base}-${n}${sufixo}.sql.gz"
    fi
    if [ ! -e "$final" ]; then
      if ln -- "$tmp" "$final" 2>/dev/null; then
        rm -f -- "$tmp"
        printf '%s\n' "$final"
        return 0
      fi
      if [ ! -e "$final" ]; then
        mv -- "$tmp" "$final" || return 1
        printf '%s\n' "$final"
        return 0
      fi
    fi
    n=$((n + 1))
  done
  return 1
}

# Apaga os backups COMUNS mais antigos de <banco>, deixando os <manter> mais novos.
# Nunca toca em backup de outro banco, de segurança (.antes-de-restaurar) ou em
# arquivo de nome fora do padrão: rotação que apaga o que não criou é o mesmo
# defeito da escolha do "mais recente".
sm_rotacionar() {
  local dir="$1" banco="$2" manter="$3" lista total apagar velho
  lista="$(sm_listar_backups "$dir" "$banco")"
  [ -n "$lista" ] || return 0
  total="$(printf '%s\n' "$lista" | wc -l | tr -d ' ')"
  apagar=$((total - manter))
  [ "$apagar" -gt 0 ] || return 0
  printf '%s\n' "$lista" | head -n "$apagar" | while IFS= read -r velho; do
    [ -n "$velho" ] || continue
    rm -f -- "$velho"
    info "rotação: apagado $(basename "$velho")"
  done
}

# --------------------------------------------------------- validação do arquivo

# 0 se o arquivo começa com a assinatura do gzip (1f 8b). Pelo CONTEÚDO, não pela
# extensão: um .sql que na verdade está compactado também é lido certo.
sm_eh_gzip() {
  [ "$(od -An -tx1 -N2 -- "$1" 2>/dev/null | tr -d ' \n')" = "1f8b" ]
}

# Escreve o SQL do dump no STDOUT, descompactando se for gzip. Erro de leitura NÃO
# é engolido: quem chama olha o código de saída (o `gunzip 2>/dev/null` antigo
# transformava um gzip truncado em "SQL que acaba mais cedo").
sm_ler_dump() {
  if sm_eh_gzip "$1"; then
    gzip -dc -- "$1"
  else
    cat -- "$1"
  fi
}

# Confere se <arquivo> é um dump INTEIRO e seguro de aplicar, e grava em <manifesto>
# o que ele promete (ver scripts/lib/manifesto-do-dump.awk). Devolve 0 se presta;
# senão devolve 1 e deixa o motivo, em português, em SM_MOTIVO.
#
# Exige, nesta ordem:
#   - gzip íntegro (gzip -t confere o CRC do fim do arquivo, então pega truncamento);
#   - o rodapé "-- Dump completed" como ÚLTIMA linha — o mysqldump só o escreve ao
#     terminar; um .sql cortado não tem, e um gzip truncado também não;
#   - nenhum INSERT cortado ou em tabela sem CREATE TABLE;
#   - nada que troque de banco (USE, CREATE/DROP DATABASE) ou chame comando do
#     cliente mysql;
#   - pelo menos uma tabela.
#
# A validação antiga só contava "CREATE TABLE ≥ 1": um arquivo cortado na metade
# passava, o restore apagava o banco e aplicava só a metade.
sm_validar_dump() {
  local arq="$1" manifesto="$2" saida primeiro errexit=0 st st_leitura st_awk
  SM_MOTIVO=""
  if [ ! -f "$arq" ]; then
    SM_MOTIVO="arquivo não encontrado: $arq"
    return 1
  fi
  if [ ! -r "$arq" ]; then
    SM_MOTIVO="sem permissão para ler $arq"
    return 1
  fi
  if [ ! -s "$arq" ]; then
    SM_MOTIVO="o arquivo está vazio"
    return 1
  fi
  if sm_eh_gzip "$arq"; then
    if ! saida="$(gzip -t -- "$arq" 2>&1)"; then
      # O gzip repete o caminho inteiro em cada linha; o operador já sabe qual é.
      saida="${saida//"$arq: "/}"
      SM_MOTIVO="o gzip está corrompido ou incompleto (gzip -t: $(printf '%s' "$saida" | tr '\n' ' ' | sed 's/ *$//'))"
      return 1
    fi
  else
    case "$arq" in
      *.gz)
        SM_MOTIVO="o nome termina em .gz, mas o conteúdo não é gzip"
        return 1
        ;;
    esac
  fi

  # O pipeline abaixo não pode derrubar o script chamador pelo `set -e` antes de
  # a gente ler o PIPESTATUS.
  case $- in *e*) errexit=1; set +e ;; esac
  sm_ler_dump "$arq" | LC_ALL=C awk -v q="'" -f "$SM_LIB_DIR/manifesto-do-dump.awk" > "$manifesto"
  # Copia o array inteiro de uma vez: qualquer comando, até uma atribuição, zera o
  # PIPESTATUS.
  st=("${PIPESTATUS[@]}")
  st_leitura=${st[0]}
  st_awk=${st[1]}
  if [ "$errexit" = 1 ]; then set -e; fi

  if [ "$st_leitura" -ne 0 ]; then
    SM_MOTIVO="não consegui ler o arquivo até o fim"
    return 1
  fi
  if [ "$st_awk" -ne 0 ]; then
    SM_MOTIVO="falhei ao analisar o conteúdo (awk saiu com $st_awk)"
    return 1
  fi
  primeiro="$(awk -F '\t' '$1 == "erro" { print "linha " $2 ": " $3; exit }' "$manifesto")"
  if [ -n "$primeiro" ]; then
    SM_MOTIVO="o conteúdo está cortado ou quebrado — $primeiro"
    return 1
  fi
  primeiro="$(awk -F '\t' '$1 == "proibido" { print "linha " $2 ": " $3 " — " $4; exit }' "$manifesto")"
  if [ -n "$primeiro" ]; then
    SM_MOTIVO="o arquivo tem um comando que a restauração não aceita ($primeiro). O backup-db.sh nunca gera isso"
    return 1
  fi
  if ! grep -q '^concluido' "$manifesto"; then
    SM_MOTIVO="falta o rodapé '-- Dump completed' no fim: o dump foi cortado antes de terminar (ou não saiu do mysqldump)"
    return 1
  fi
  if ! grep -q '^tabela' "$manifesto"; then
    SM_MOTIVO="o arquivo não tem nenhum CREATE TABLE — isso não é um backup"
    return 1
  fi
  return 0
}

# Um campo do manifesto (origem, concluido).
sm_manifesto_valor() {
  awk -F '\t' -v chave="$2" '$1 == chave { print $2; exit }' "$1"
}

# "19 tabelas, 1234 linhas"
sm_manifesto_resumo() {
  awk -F '\t' '$1 == "tabela" { t++; l += $3 } END { printf "%d tabelas, %.0f linhas", t, l }' "$1"
}

# Compara dois manifestos (o que o arquivo promete × o que um banco tem) e imprime
# uma linha por diferença. Devolve 0 se baterem. <rotulo_a> e <rotulo_b> dizem de
# onde veio cada um ("o arquivo", "o banco temporário"...).
#
# Compara NOME e NÚMERO DE LINHAS de cada tabela, de cada lado. A conferência
# antiga contava tabelas usando o próprio dump como régua: se o dump tinha sido
# cortado antes da tabela `users`, ele "prometia" 18 tabelas, chegavam 18, e o
# script dizia OK.
sm_comparar_manifestos() {
  local diferencas
  diferencas="$(LC_ALL=C awk -F '\t' -v arq_a="$1" -v rot_a="$3" -v rot_b="$4" '
    BEGIN {
      while ((getline l < arq_a) > 0) {
        split(l, c, "\t")
        if (c[1] == "tabela") a[c[2]] = c[3]
      }
      close(arq_a)
    }
    $1 == "tabela" { b[$2] = $3 }
    END {
      for (t in a) {
        if (!(t in b)) printf "`%s`: está em %s (%s linhas) e não em %s\n", t, rot_a, a[t], rot_b
        else if (a[t] + 0 != b[t] + 0) printf "`%s`: %s tem %s linhas; %s tem %s\n", t, rot_a, a[t], rot_b, b[t]
      }
      for (t in b) if (!(t in a)) printf "`%s`: está em %s (%s linhas) e não em %s\n", t, rot_b, b[t], rot_a
    }' "$2" | LC_ALL=C sort)"
  if [ -n "$diferencas" ]; then
    printf '%s\n' "$diferencas"
    return 1
  fi
  return 0
}

# ----------------------------------------------------------------------- Docker

# O cron roda com PATH mínimo (/usr/bin:/bin). No Mac o docker mora em
# /usr/local/bin ou /opt/homebrew/bin (no OrbStack, também em ~/.orbstack/bin): sem
# isto o script dizia "não achei o Docker Compose" quando o problema era o PATH.
# Os diretórios entram no FIM, então o PATH de quem chamou continua mandando.
# SM_PATH_EXTRA troca a lista (vazio = não acrescentar nada; é o que os testes usam).
sm_completar_path() {
  local extra="${SM_PATH_EXTRA-/usr/local/bin:/opt/homebrew/bin:${HOME:-/nao-existe}/.orbstack/bin:/snap/bin}"
  if [ -n "$extra" ]; then PATH="${PATH:+$PATH:}$extra"; fi
  export PATH
}

# Define DC (array) com o comando do Compose. Cada falha tem a sua mensagem, porque
# "sem docker no PATH", "docker sem Compose" e "Docker parado" pedem consertos
# diferentes.
sm_encontrar_compose() {
  if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
    DC=(docker compose)
  elif command -v docker-compose >/dev/null 2>&1; then
    DC=(docker-compose)
  elif command -v docker >/dev/null 2>&1; then
    erro "o docker está em $(command -v docker), mas sem o Docker Compose ('docker compose version' falhou e não há docker-compose)."
  else
    erro "não achei o comando 'docker' no PATH ($PATH). No cron o PATH é mínimo: ponha no topo da crontab uma linha PATH=... com o diretório que 'command -v docker' mostra no seu terminal."
  fi
}

# Confere que o Docker responde e que o serviço do banco está de pé. O STDIN vem de
# /dev/null em todo `exec` que não precisa dele: o `docker compose exec` repassa o
# STDIN do script, e engoliria a resposta da confirmação que vem depois.
sm_exigir_servico() {
  local servico="$1" saida
  if ! saida="$("${DC[@]}" ps --services 2>&1 </dev/null)"; then
    erro "o Docker não respondeu: $(printf '%s' "$saida" | head -n 3 | tr '\n' ' ')— ele está rodando? (no Mac com OrbStack: orb start)"
  fi
  # Compose v1 não tem --status; sem ele, seguimos e o próprio `exec` acusa.
  if saida="$("${DC[@]}" ps --status running --services 2>/dev/null </dev/null)"; then
    if ! printf '%s\n' "$saida" | grep -qx "$servico"; then
      erro "o serviço '$servico' não está rodando. Suba com: docker compose up -d"
    fi
  fi
}

# Credencial do usuário do APP: vem do .env do host e entra no container pelo STDIN,
# num arquivo criado com umask 077 — nunca pela linha de comando (o `ps` do host
# mostra a linha de comando de qualquer processo, inclusive os dos containers).
sm_gravar_cnf_app() { # <servico> <cnf no container> <usuário> <senha>
  printf '[client]\nuser="%s"\npassword="%s"\n' "$(sm_escapar_cnf "$3")" "$(sm_escapar_cnf "$4")" |
    "${DC[@]}" exec -T "$1" sh -c "umask 077; cat > '$2'"
}

# Credencial de ROOT, lida DENTRO do container (ver scripts/lib/cnf-root.sh). Deixa
# em SM_OPCAO_COMMANDS o "--commands=OFF" quando o cliente mysql do container o
# conhece (ver restore-db.sh).
SM_OPCAO_COMMANDS=""
sm_gravar_cnf_root() { # <servico> <cnf no container>
  local saida
  if ! saida="$("${DC[@]}" exec -T "$1" sh -s -- "$2" < "$SM_LIB_DIR/cnf-root.sh" 2>&1)"; then
    case "$saida" in
      *sm:sem-senha-root*)
        erro "o container '$1' não tem MYSQL_ROOT_PASSWORD. O docker-compose.yml a preenche com o DB_ROOT_PASSWORD do .env — confira o .env e recrie o container (docker compose up -d)."
        ;;
      *sm:root-recusado*)
        erro "o MySQL recusou a senha de root do container. Ela só vale no PRIMEIRO boot do volume: se o DB_ROOT_PASSWORD do .env mudou depois, alinhe com ALTER USER dentro do container."
        ;;
    esac
    erro "não consegui preparar a credencial de root no container: $(printf '%s' "$saida" | head -n 3 | tr '\n' ' ')"
  fi
  case "$saida" in
    *sm:commands=1*) SM_OPCAO_COMMANDS="--commands=OFF" ;;
    *) SM_OPCAO_COMMANDS="" ;;
  esac
}
