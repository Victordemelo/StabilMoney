# Stabil Money — lê um dump do mysqldump (pelo STDIN) e imprime o que ele PROMETE.
#
# Quem usa: sm_validar_dump, em scripts/lib/backup-comum.sh. O backup-db.sh passa
# todo arquivo novo por aqui antes de publicá-lo, e o restore-db.sh passa o arquivo
# escolhido antes de encostar em qualquer banco. É uma passada só pelo conteúdo, e
# a saída tem uma linha por fato, com os campos separados por TAB:
#
#   origem     <banco>                 do cabeçalho "-- Host: ...    Database: <banco>"
#   concluido  <data>                  só se a ÚLTIMA linha não vazia for o rodapé
#                                      "-- Dump completed": o mysqldump só o escreve
#                                      quando termina, então arquivo cortado não tem
#   tabela     <nome>  <linhas>        uma por CREATE TABLE, com as linhas dos INSERTs
#   proibido   <nº da linha>  <motivo>  <trecho>
#   erro       <nº da linha>  <motivo>
#
# Precisa de -v q="'" (a aspa simples: escrevê-la aqui dentro exigiria malabarismo
# de escape no shell). Roda no awk do Mac (BSD), no mawk (Ubuntu, CI) e no gawk:
# nada de intervalos {n} em regex, classes [[:space:]] ou extensões do gawk.
#
# COMO AS LINHAS SÃO CONTADAS. O mysqldump escreve cada INSERT numa linha só,
# "INSERT INTO `t` VALUES (...),(...);", com as quebras de linha dos textos escapadas
# como \n. Cada "(" FORA de texto abre uma linha da tabela. Contar "),(" daria
# errado: um texto como 'a),(b' tem essa sequência dentro dele.
#
# O QUE É PROIBIDO, e por quê. A restauração aplica o arquivo num banco escolhido
# (--banco, ou o banco temporário do ensaio). Um dump feito com --databases traz
# "USE `stabilmoney`" e "CREATE DATABASE": aplicado "no ensaio", ele trocaria de banco
# no meio e escreveria na PRODUÇÃO. E comandos do cliente mysql ("\!", "system")
# rodam programas no container — o mysqldump nunca escreve nenhum dos dois.

BEGIN {
  ntab = 0
  ultima = ""
  origem = ""
  tem_origem = 0
  nerro = 0
  nproibido = 0
}

function registrar_erro(motivo) {
  nerro++
  if (nerro <= 5) printf "erro\t%d\t%s\n", NR, motivo
}

function registrar_proibido(motivo, texto) {
  nproibido++
  if (nproibido <= 5) printf "proibido\t%d\t%s\t%s\n", NR, motivo, substr(texto, 1, 80)
}

# Devolve o primeiro nome entre crases do texto. Deixa em `depois_do_nome` o que vem
# depois da crase que fecha, e liga `qualificado` quando o nome é seguido de ".`" —
# ou seja, `banco`.`tabela`, que escreveria noutro banco.
function nome_entre_crases(texto,    p, resto, f) {
  qualificado = 0
  depois_do_nome = ""
  p = index(texto, "`")
  if (p == 0) return ""
  resto = substr(texto, p + 1)
  f = index(resto, "`")
  if (f <= 1) return ""
  depois_do_nome = substr(resto, f + 1)
  if (substr(depois_do_nome, 1, 1) == ".") qualificado = 1
  return substr(resto, 1, f - 1)
}

# Separa o que está DENTRO de textos entre aspas simples do que está fora e devolve
# quantos "(" há fora dos textos — ou:
#   -1  o trecho termina com um texto aberto (linha cortada no meio de um valor);
#   -2  há uma barra invertida FORA de texto. O mysqldump nunca escreve isso, e o
#       cliente mysql trataria "\!" ali como comando (rodaria algo no shell).
#
# O split pela aspa alterna as partes entre fora e dentro de texto. Uma aspa só
# fecha o texto se não estiver escapada: se vier depois de um número PAR de barras
# invertidas ("\\" é uma barra escapada; "\'" é uma aspa dentro do texto). O ''
# dobrado do SQL padrão também dá certo: vira dois textos colados, sem nada fora.
function parenteses_fora_de_texto(trecho,    partes, n, i, dentro, parte, total, barras) {
  n = split(trecho, partes, q)
  dentro = 0
  total = 0
  for (i = 1; i <= n; i++) {
    parte = partes[i]
    if (!dentro) {
      if (index(parte, "\\") > 0) return -2
      total += gsub(/\(/, "(", parte)
      if (i < n) dentro = 1
    } else if (i < n) {
      barras = match(parte, /\\+$/) ? RLENGTH : 0
      if (barras % 2 == 0) dentro = 0
    }
  }
  return dentro ? -1 : total
}

{
  linha = $0
  sub(/\r$/, "", linha)
}

linha ~ /^[ \t]*$/ { next }

{ ultima = linha }

# Comentário de linha: "-- " (o -- do SQL exige espaço depois), "--" sozinho e "#".
substr(linha, 1, 3) == "-- " || linha == "--" || substr(linha, 1, 1) == "#" {
  if (!tem_origem && linha ~ /^-- Host: .*Database: /) {
    origem = linha
    sub(/^.*Database: /, "", origem)
    sub(/[ \t]+$/, "", origem)
    tem_origem = 1
  }
  next
}

substr(linha, 1, 14) == "CREATE TABLE `" || substr(linha, 1, 28) == "CREATE TABLE IF NOT EXISTS `" {
  nome = nome_entre_crases(linha)
  if (nome == "") { registrar_erro("CREATE TABLE sem nome"); next }
  if (qualificado) { registrar_proibido("tabela com o nome de um banco na frente (escreveria noutro banco)", linha); next }
  if (!(nome in linhas)) {
    ordem[++ntab] = nome
    linhas[nome] = 0
  }
  next
}

substr(linha, 1, 13) == "INSERT INTO `" || substr(linha, 1, 20) == "INSERT IGNORE INTO `" || substr(linha, 1, 14) == "REPLACE INTO `" {
  nome = nome_entre_crases(linha)
  if (qualificado) { registrar_proibido("tabela com o nome de um banco na frente (escreveria noutro banco)", linha); next }
  p = index(depois_do_nome, " VALUES ")
  if (nome == "" || p == 0) { registrar_erro("INSERT sem tabela ou sem VALUES"); next }
  valores = substr(depois_do_nome, p + 8)
  if (substr(valores, length(valores) - 1) != ");") { registrar_erro("INSERT cortado: a linha não termina em );"); next }
  k = parenteses_fora_de_texto(valores)
  if (k == -1) { registrar_erro("INSERT cortado no meio de um texto"); next }
  if (k == -2) { registrar_proibido("barra invertida fora de texto (comando do cliente mysql)", linha); next }
  if (!(nome in linhas)) { registrar_erro("INSERT na tabela `" nome "` sem o CREATE TABLE dela antes"); next }
  linhas[nome] += k
  next
}

# Todo o resto: não pode trocar de banco, mexer noutro banco nem chamar comando do
# cliente. O prefixo "/*!NNNNN" (comentário condicional, que o MySQL EXECUTA) é
# tirado antes de olhar: é assim que vem o "DROP DATABASE" de um dump --databases.
{
  s = linha
  sub(/^[ \t]+/, "", s)
  if (substr(s, 1, 3) == "/*!") {
    s = substr(s, 4)
    sub(/^[0-9]*[ \t]*/, "", s)
  }
  u = toupper(s)
  if (u ~ /^USE([ \t`;]|$)/ || u ~ /^(CREATE|DROP|ALTER)[ \t]+(DATABASE|SCHEMA)([ \t`;\/]|$)/) {
    registrar_proibido("comando que troca de banco ou mexe noutro banco (dump feito com --databases?)", linha)
    next
  }
  if (substr(s, 1, 1) == "\\" || u ~ /^(SYSTEM|SOURCE|CONNECT|TEE|PAGER|EDIT|QUIT|EXIT)([ \t;]|$)/) {
    registrar_proibido("comando do cliente mysql (fora do SQL)", linha)
    next
  }
  if (parenteses_fora_de_texto(linha) == -2) {
    registrar_proibido("barra invertida fora de texto (comando do cliente mysql)", linha)
    next
  }
}

END {
  if (tem_origem) printf "origem\t%s\n", origem
  if (substr(ultima, 1, 17) == "-- Dump completed") {
    c = ultima
    sub(/^-- Dump completed/, "", c)
    sub(/^ on /, "", c)
    printf "concluido\t%s\n", c
  }
  for (i = 1; i <= ntab; i++) printf "tabela\t%s\t%d\n", ordem[i], linhas[ordem[i]]
  if (nerro > 5) printf "erro\t0\t(e mais %d erros)\n", nerro - 5
  if (nproibido > 5) printf "proibido\t0\t(e mais %d)\t\n", nproibido - 5
}
