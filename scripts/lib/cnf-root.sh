# Stabil Money — roda DENTRO do container do banco. Não execute no host.
#
# O backup-db.sh (--root) e o restore-db.sh mandam este arquivo pelo STDIN de
# `sh -s -- <arquivo.cnf>`. Ele grava em "$1" um arquivo de defaults do cliente
# mysql com a senha de ROOT que o docker-compose.yml entregou ao container
# (MYSQL_ROOT_PASSWORD = DB_ROOT_PASSWORD do .env). Assim a senha não passa pelo
# host e não aparece na linha de comando de processo nenhum: o texto deste script
# não tem segredo, e o printf abaixo é embutido do shell, não um programa.
#
# Responde numa linha só, que o chamador lê:
#   sm:commands=1 / sm:commands=0   credencial gravada e aceita pelo MySQL; diz se
#                                   o cliente mysql daqui tem --commands (ver restore)
#   sm:sem-senha-root               o container não tem a senha de root
#   sm:root-recusado                o MySQL recusou a senha
umask 077

senha="${MYSQL_ROOT_PASSWORD-}"
# A imagem oficial também aceita a senha num arquivo (Docker secrets).
if [ -z "$senha" ] && [ -n "${MYSQL_ROOT_PASSWORD_FILE-}" ] && [ -r "$MYSQL_ROOT_PASSWORD_FILE" ]; then
  senha="$(cat "$MYSQL_ROOT_PASSWORD_FILE")"
fi
if [ -z "$senha" ]; then
  echo "sm:sem-senha-root"
  exit 3
fi

# Dentro de aspas duplas no formato my.cnf, \ e " são especiais.
esc="$(printf '%s' "$senha" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g')"
printf '[client]\nuser=root\npassword="%s"\n' "$esc" > "$1" || exit 4

# A senha do .env só vale no PRIMEIRO boot do volume: trocada depois, o MySQL segue
# com a antiga. Melhor descobrir aqui, com uma mensagem clara, que no meio do caminho.
if ! mysql --defaults-extra-file="$1" -N -B -e 'SELECT 1' >/dev/null 2>&1; then
  echo "sm:root-recusado"
  exit 5
fi

if mysql --help 2>/dev/null | grep -q -- '--commands'; then
  echo "sm:commands=1"
else
  echo "sm:commands=0"
fi
