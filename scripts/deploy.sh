#!/usr/bin/env bash
#
# Stabil Money — publica a versão nova NA VPS, na ordem certa.
#
# Roda no servidor, na pasta do projeto, como o usuário DONO da pasta (o que clonou
# com o Deploy Key e está no grupo docker) — nunca com sudo:
#
#   cd /opt/apps/stabilmoney && bash scripts/deploy.sh
#
# É o "git pull && docker compose up -d --build" dos outros apps da VPS — e faz esses
# dois passos —, mais o que eles sozinhos NÃO fazem aqui. Com a pasta montada no
# container (ver docker-compose.prod.yml), o `git pull` põe o código novo no ar na
# hora, mas:
#   - o vendor/ não muda sozinho (composer.lock novo → "Class not found");
#   - as migrations não rodam (código novo com banco velho → erro de SQL);
#   - os caches continuam os do deploy anterior (rota nova dá 404 até o route:cache);
#   - os assets não são reconstruídos (tela nova sem estilo, ou erro 500 por arquivo
#     que falta no manifest do Vite);
#   - e nada disso acontece com backup antes nem com o app em manutenção.
# Rodar o comando genérico ANTES deste script não estraga nada: aqui o `git pull` só
# não terá o que puxar, e o resto acontece igual.
#
# O que ele faz, nesta ordem (o porquê de cada passo está no código):
#    1. conferências — o .env, as portas do compose, o git; nada muda se falharem
#    2. git fetch
#    3. backup do banco (scripts/backup-db.sh)
#    4. manutenção ligada (o /up passa a responder 503 "manutencao")
#    5. git merge --ff-only (o "git pull")
#    6. docker compose up -d --build do banco e do app
#    7. composer install --no-dev
#    8. permissões de storage/, bootstrap/cache e .env
#    9. config:clear + migrate --force
#   10. config:cache, route:cache, event:cache, view:cache
#   11. build dos assets (Node num container) — sempre DEPOIS do view:cache
#   12. apaga o public/hot
#   13. manutenção desligada + o resto dos serviços (o agendador)
#   14. conferência final: /up, HTML sem o Vite de dev, tamanho do CSS
#
# Uso:
#   bash scripts/deploy.sh                    # o deploy de sempre
#   bash scripts/deploy.sh --primeiro-deploy  # a primeira vez, com o banco ainda vazio
#   bash scripts/deploy.sh --sem-git          # republica o código que já está na pasta
#
# Opções:
#   --primeiro-deploy  banco vazio: pula o backup (não há o que salvar, e o
#                      backup-db.sh recusa dump sem tabela). Só na primeira vez.
#   --sem-git          não busca nem aplica commits. Para depois de mexer no .env (o
#                      config:cache precisa ser refeito) ou para voltar a uma versão
#                      anterior (git checkout <commit>, e depois isto).
#
# Se algo falhar depois de a manutenção ser ligada, o app FICA em manutenção — o lado
# seguro: "volto já" é melhor que tela quebrada ou dado gravado pela metade — e a
# mensagem final diz o que fazer. Rodar de novo é seguro: todo passo pode repetir.
#
# Variáveis (usadas pelos testes): SM_DEPLOY_BACKUP (script de backup), SM_DEPLOY_ESPERA
# (segundos entre as tentativas do /up final), NO_COLOR.

set -euo pipefail

RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck source=lib/backup-comum.sh
. "$RAIZ/scripts/lib/backup-comum.sh"
# shellcheck source=lib/deploy-comum.sh
. "$RAIZ/scripts/lib/deploy-comum.sh"
sm_iniciar_cores
sm_completar_path
# Uma queda do SSH no meio não pode deixar o deploy pela metade: ele vai até o fim
# (ou até o primeiro erro), e a saída sem destino se perde. Ver backup-comum.sh.
sm_ignorar_desconexao

PRIMEIRO=0
SEM_GIT=0
while [ $# -gt 0 ]; do
  case "$1" in
    --primeiro-deploy) PRIMEIRO=1; shift ;;
    --sem-git)         SEM_GIT=1; shift ;;
    -h|--help)         sm_imprimir_uso "$0"; exit 0 ;;
    *) erro "opção desconhecida: $1 (use --help)" ;;
  esac
done

cd "$RAIZ"
ARQUIVO_ENV="$RAIZ/.env"
INICIO="$(date +%s)"

passo() { info ""; verde "==> $*"; }

# <descrição> <comando...> — roda; se falhar, para com a descrição (o trap diz o resto).
rodar() {
  local descricao="$1"
  shift
  if ! "$@"; then
    erro "$descricao falhou."
  fi
}

# `docker compose exec` SEM terminal e com o STDIN vazio: o exec repassaria o STDIN do
# script e poderia ficar esperando uma resposta que ninguém vai digitar.
no_app() { "${DC[@]}" exec -T "$@" < /dev/null; }

# Todo artisan roda como www-data, o usuário do Apache: arquivo criado como root dentro
# de storage/ (o log do dia, uma view compilada) fica ilegível para o site, que passa a
# dar erro 500 ao tentar escrever nele.
artisan() { rodar "php artisan $*" no_app -u www-data app php artisan "$@"; }

servico_rodando() { # <serviço>
  local rodando
  rodando="$("${DC[@]}" ps --status running --services 2> /dev/null < /dev/null || true)"
  # Here-string, e não `... | grep -q`: com pipefail, o grep -q que sai no primeiro
  # acerto pode fazer o cano inteiro "falhar" (quem escrevia leva erro de escrita).
  grep -qx "$1" <<< "$rodando"
}

# ------------------------------------------------------------------ o que falhar

TRAVA=""
MANUTENCAO=0
ANTES=""
BACKUP_FEITO=""

ao_sair() {
  local codigo=$?
  set +e
  if [ -n "$TRAVA" ]; then rmdir "$TRAVA" 2> /dev/null; fi
  if [ "$codigo" -ne 0 ] && [ "$MANUTENCAO" -eq 1 ]; then
    vermelho ""
    vermelho "O app CONTINUA EM MANUTENÇÃO (o /up responde 503 \"manutencao\")."
    vermelho "  - corrigiu o problema acima? Rode de novo: bash scripts/deploy.sh"
    vermelho "  - para voltar a atender com o que está na pasta:"
    vermelho "      docker compose exec -u www-data app php artisan up"
    if [ -n "$ANTES" ]; then
      vermelho "  - para voltar à versão anterior ($ANTES):"
      vermelho "      git reset --hard $ANTES && bash scripts/deploy.sh --sem-git"
    fi
    if [ -n "$BACKUP_FEITO" ]; then
      vermelho "    (se as migrations já tinham rodado, o banco de antes está em:"
      vermelho "      $BACKUP_FEITO — bash scripts/restore-db.sh ensaia antes de restaurar)"
    fi
  fi
  exit "$codigo"
}
trap ao_sair EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# ================================================================== 1. conferências

passo "1/14 Conferências — nada muda se alguma falhar"

# Como root, o git recusa a pasta de outro usuário ("dubious ownership") e os
# arquivos criados aqui ficariam do root, fora do alcance do usuário de deploy.
if [ "$(id -u)" -eq 0 ]; then
  erro "não rode com sudo nem como root: rode como o usuário dono da pasta (o que está no grupo docker)."
fi
DONO_DA_PASTA="$(ls -ldn "$RAIZ" | awk '{ print $3 }')"
if [ "$DONO_DA_PASTA" != "$(id -u)" ]; then
  erro "a pasta $RAIZ é do usuário de id $DONO_DA_PASTA, e você é $(id -un) (id $(id -u)). Rode como o dono da pasta."
fi
command -v curl > /dev/null 2>&1 || erro "não achei o curl (a conferência final precisa dele): sudo apt install curl"
[ -f "$ARQUIVO_ENV" ] || erro "não achei o .env em $ARQUIVO_ENV (passo a passo em docs/deploy-oracle-cloudflare.md)."

# O .env contra os bloqueadores do checklist de publicação.
CONFERENCIA="$(sm_conferir_env_de_producao "$ARQUIVO_ENV")"
sed -n 's/^aviso: /  aviso: /p' <<< "$CONFERENCIA" | while IFS= read -r l; do amarelo "$l"; done
if grep -q '^erro: ' <<< "$CONFERENCIA"; then
  sed -n 's/^erro: /  /p' <<< "$CONFERENCIA" | while IFS= read -r l; do vermelho "$l"; done
  erro "o .env não está pronto para produção (itens acima). Nada foi mudado."
fi
TRUSTED_PROXIES="$(sm_ler_env "$ARQUIVO_ENV" TRUSTED_PROXIES)"
APP_URL="$(sm_ler_env "$ARQUIVO_ENV" APP_URL)"
HOST_DO_APP="$(printf '%s' "$APP_URL" | sed -n 's#^https://\([^/:]*\).*#\1#p')"

sm_encontrar_compose

# <saída do docker compose config> <nome do arquivo, para a mensagem>
conferir_compose() {
  local config="$1" rotulo="$2" abertas gateway
  abertas="$(printf '%s\n' "$config" | sm_portas_fora_do_loopback)"
  if [ -n "$abertas" ]; then
    vermelho "O $rotulo publica porta FORA de 127.0.0.1:"
    printf '%s\n' "$abertas" | while IFS= read -r l; do vermelho "    $l"; done
    vermelho "Porta publicada em 0.0.0.0 fica aberta para a internet MESMO com o firewall do"
    vermelho "host fechado: o Docker escreve as próprias regras de iptables, na frente das dele."
    erro "recusado. Toda porta tem de ser \"127.0.0.1:<porta>:<porta do container>\" — e o COMPOSE_FILE do .env tem de ser o docker-compose.prod.yml."
  fi
  gateway="$(printf '%s\n' "$config" | sm_gateway_da_rede_padrao)"
  if [ -z "$gateway" ]; then
    erro "o $rotulo não fixa o gateway da rede (networks.default.ipam) — sem isso não há valor certo para o TRUSTED_PROXIES."
  fi
  if ! sm_lista_contem "$TRUSTED_PROXIES" "$gateway"; then
    erro "TRUSTED_PROXIES=$TRUSTED_PROXIES não é o gateway da rede do $rotulo ($gateway). É por ele que o nginx chega ao app: sem ele na lista, todo visitante aparece com o mesmo IP e os limites por IP travam o site inteiro."
  fi
  if [ -z "$(printf '%s\n' "$config" | sm_porta_publicada app)" ]; then
    erro "o $rotulo não publica porta nenhuma para o serviço app — o nginx não teria onde chegar."
  fi
}

if ! CONFIG="$("${DC[@]}" config 2>&1 < /dev/null)"; then
  vermelho "$CONFIG"
  erro "o 'docker compose config' falhou (acima). Falta alguma variável no .env?"
fi
conferir_compose "$CONFIG" "docker-compose.prod.yml"

# O servidor só recebe código pelo git: mudança feita à mão aqui some no próximo deploy
# (ou impede o merge no meio dele, com o app já em manutenção).
git rev-parse --is-inside-work-tree > /dev/null 2>&1 || erro "$RAIZ não é um clone do git."
SUJEIRA="$(git status --porcelain 2> /dev/null)"
if [ -n "$SUJEIRA" ]; then
  vermelho "Arquivos mudados ou novos na pasta (fora do .gitignore):"
  # `sed -n 1,20p`, e não `head`: o head fecha o cano antes do fim, e com o SIGPIPE
  # ignorado (sm_ignorar_desconexao) + pipefail, o erro de escrita derrubaria o script.
  printf '%s\n' "$SUJEIRA" | sed -n '1,20p' | while IFS= read -r l; do vermelho "    $l"; done
  erro "a pasta do servidor não está limpa. Mudança de código vai pelo repositório; para descartar: git checkout -- <arquivo>."
fi

GIT_DIR_ABS="$(git rev-parse --absolute-git-dir)"
# Dois deploys ao mesmo tempo se atropelariam (um ligando a manutenção, o outro
# desligando). `mkdir` é atômico: só um consegue criar.
if mkdir "$GIT_DIR_ABS/stabilmoney-deploy.lock" 2> /dev/null; then
  TRAVA="$GIT_DIR_ABS/stabilmoney-deploy.lock"
else
  erro "já há um deploy rodando. Se tiver certeza de que não há (o anterior caiu no meio), apague a trava: rmdir $GIT_DIR_ABS/stabilmoney-deploy.lock"
fi
ANTES="$(git rev-parse --short HEAD)"

# ================================================================== 2. git fetch

if [ "$SEM_GIT" -eq 1 ]; then
  passo "2/14 git fetch — pulado (--sem-git): publicando $ANTES, o que está na pasta"
else
  passo "2/14 git fetch"
  rodar "git fetch" git fetch --prune --quiet
  git rev-parse --abbrev-ref --symbolic-full-name '@{u}' > /dev/null 2>&1 \
    || erro "o branch atual não acompanha nenhum branch remoto (git branch -u origin/main)."
  A_FRENTE="$(git rev-list --count '@{u}..HEAD')"
  if [ "$A_FRENTE" -gt 0 ]; then
    erro "o servidor tem $A_FRENTE commit(s) que o GitHub não tem. Commit se faz no computador de desenvolvimento, nunca aqui."
  fi
  NOVOS="$(git rev-list --count 'HEAD..@{u}')"
  if [ "$NOVOS" -eq 0 ]; then
    info "Nenhum commit novo: republicando $ANTES (caches, assets e migrations são refeitos)."
  else
    info "$NOVOS commit(s) novo(s):"
    git log --oneline --no-decorate -n 20 'HEAD..@{u}' | sed 's/^/    /'
    # A versão nova mexe no compose? Confere ANTES de tirar o app do ar, com o
    # arquivo que vai chegar — a mesma régua de portas e gateway.
    if ! git diff --quiet HEAD '@{u}' -- docker-compose.prod.yml; then
      COMPOSE_NOVO="$(mktemp "${TMPDIR:-/tmp}/sm-compose-novo.XXXXXX")"
      git show '@{u}:docker-compose.prod.yml' > "$COMPOSE_NOVO"
      if ! CONFIG_NOVO="$("${DC[@]}" -f "$COMPOSE_NOVO" --project-directory "$RAIZ" config 2>&1 < /dev/null)"; then
        rm -f "$COMPOSE_NOVO"
        vermelho "$CONFIG_NOVO"
        erro "o docker-compose.prod.yml da versão nova não passa no 'docker compose config' (acima)."
      fi
      rm -f "$COMPOSE_NOVO"
      conferir_compose "$CONFIG_NOVO" "docker-compose.prod.yml da versão nova"
    fi
  fi
fi

# ================================================================== 3. backup

if [ "$PRIMEIRO" -eq 1 ]; then
  passo "3/14 Backup — pulado (--primeiro-deploy: o banco ainda está vazio)"
else
  passo "3/14 Backup do banco, antes de qualquer mudança (checklist, item 11)"
  # Com o app ainda NO AR: se o backup falhar, ninguém fica sem o site.
  RESULTADO_DO_BACKUP="$(mktemp "${TMPDIR:-/tmp}/sm-deploy-backup.XXXXXX")"
  COMANDO_DE_BACKUP="${SM_DEPLOY_BACKUP:-$RAIZ/scripts/backup-db.sh}"
  # O backup-db.sh escreve em SM_ARQUIVO_RESULTADO onde gravou: é o arquivo que a
  # mensagem de falha aponta para voltar o banco. Pelo `bash`, e não direto: assim não
  # depende de o arquivo estar marcado como executável no clone.
  if ! SM_ARQUIVO_RESULTADO="$RESULTADO_DO_BACKUP" bash "$COMANDO_DE_BACKUP" < /dev/null; then
    rm -f "$RESULTADO_DO_BACKUP"
    erro "o backup falhou — nada foi mudado e o app continua no ar. Se este é o PRIMEIRO deploy (banco vazio), use --primeiro-deploy."
  fi
  BACKUP_FEITO="$(cat "$RESULTADO_DO_BACKUP" 2> /dev/null || true)"
  rm -f "$RESULTADO_DO_BACKUP"
fi

# ================================================================== 4. manutenção

# <quem é o dono do .env> — dono www-data e grupo do usuário de deploy em storage/ e
# bootstrap/cache; .env legível pelo Apache e por mais ninguém.
ajustar_permissoes() {
  # Dentro do container, como root (o único que pode dar o arquivo ao www-data):
  #  - storage/ e bootstrap/cache: DONO www-data (o Apache e o agendador escrevem ali)
  #    e GRUPO do usuário de deploy (o git atualiza os arquivos versionados e dá para
  #    ler o log sem sudo). 2770/660: o "2" faz arquivo novo herdar o grupo; "outros"
  #    ficam sem acesso — log e foto de perfil são dado pessoal. Nada de 777.
  #  - storage/backups fica DE FORA: os dumps têm o banco inteiro, e são do usuário de
  #    deploy (700/600). Dar ao www-data seria dar ao site a leitura dos backups.
  #  - .env: dono o usuário de deploy (que o edita), grupo www-data (o Apache só lê), 640.
  #
  # 🚨 `chown -h` + `-execdir`, nunca `-exec chown` (24/09/2026). Quem escreve em storage/ é
  # o SITE (www-data), e isto roda como ROOT: o `chown` sem -h SEGUE atalho, e um atalho
  # deixado em storage/ (storage/logs/x -> /var/www/html/.git/hooks) passava o alvo para o
  # www-data a cada deploy. Com .git/hooks do www-data, o próximo `git fetch`/`git merge`
  # deste script roda o gancho dele como o usuário de deploy NO HOST — que está no grupo
  # docker. Uma falha no site virava root na VPS. O `-execdir` roda cada chown DENTRO da
  # pasta que o find percorreu (./nome), então trocar uma pasta do caminho por um atalho no
  # meio do caminho também não desvia o chown. `permissoes-do-deploy.test.sh`.
  no_app app sh -c '
    set -e
    find storage bootstrap/cache -path storage/backups -prune -o -execdir chown -h "www-data:$1" {} +
    find storage bootstrap/cache -path storage/backups -prune -o -type d -execdir chmod 2770 {} +
    find storage bootstrap/cache -path storage/backups -prune -o -type f -execdir chmod 660 {} +
    chown "$2:www-data" .env
    chmod 640 .env
  ' sh "$(id -g)" "$(id -u)"
}

if servico_rodando app && [ -f vendor/autoload.php ]; then
  passo "4/14 Manutenção ligada (o /up passa a responder 503 \"manutencao\")"
  rodar "o ajuste de permissões" ajustar_permissoes
  # A partir daqui, qualquer falha deixa o app em manutenção (ver ao_sair).
  MANUTENCAO=1
  # --retry=60: a página de manutenção diz "tente de novo em 1 minuto" (Retry-After).
  artisan down --retry=60
else
  passo "4/14 Manutenção — pulada: o app ainda não está de pé (primeiro deploy)"
fi

# ================================================================== 5. git merge

if [ "$SEM_GIT" -eq 1 ]; then
  passo "5/14 git merge — pulado (--sem-git)"
else
  passo "5/14 git merge --ff-only (o \"git pull\")"
  rodar "git merge --ff-only" git merge --ff-only --quiet '@{u}'
  info "Código em $(git rev-parse --short HEAD) (antes: $ANTES)."
fi

# ================================================================== 6. containers

passo "6/14 docker compose up -d --build (banco e app)"
# De novo, agora com o arquivo que VAI subir: a régua vale para o que está na pasta.
if ! CONFIG="$("${DC[@]}" config 2>&1 < /dev/null)"; then
  vermelho "$CONFIG"
  erro "o 'docker compose config' falhou (acima)."
fi
conferir_compose "$CONFIG" "docker-compose.prod.yml"
PORTA_DO_APP="$(printf '%s\n' "$CONFIG" | sm_porta_publicada app)"
# --wait: só segue com o MySQL aceitando conexão (healthcheck do compose). O agendador
# sobe no passo 13: sem o vendor/ (primeiro deploy) ele morreria em loop.
rodar "docker compose up" "${DC[@]}" up -d --build --wait db app < /dev/null

# ================================================================== 7. composer

passo "7/14 composer install --no-dev"
# --no-dev: phpunit, faker, collision, pint e companhia não vão para o servidor — não
# rodam lá, e pacote a menos é código a menos exposto. (O CI audita os de dev também:
# eles rodam no CI e na máquina de quem desenvolve.) --optimize-autoloader: o mapa de
# classes pronto, sem procurar arquivo a cada requisição. Como root no container
# (dono do vendor/); o passo 8 devolve o que ele tocar em storage/ e bootstrap/cache.
rodar "composer install" no_app -e COMPOSER_ALLOW_SUPERUSER=1 app \
  composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress

# ================================================================== 8. permissões

passo "8/14 Permissões de storage/, bootstrap/cache e .env"
rodar "o ajuste de permissões" ajustar_permissoes

# ================================================================== 9. migrate

passo "9/14 migrate --force"
# config:clear ANTES: o config:cache do deploy anterior fica no disco, e com ele o
# Laravel nem lê o .env — o migrate iria para a configuração antiga (checklist, item 10).
artisan config:clear
# --force: fora de `local` o artisan pede confirmação, e aqui não há quem responda.
artisan migrate --force

# ================================================================== 10. caches

passo "10/14 Caches: config, rotas, eventos e views"
artisan config:cache
artisan route:cache
artisan event:cache
# view:cache ANTES do build: o Tailwind varre as views COMPILADAS
# (@source '../../storage/framework/views/*.php' no app.css). Sem elas o CSS sai ~15%
# menor e o build termina verde — o site perde estilos sem aviso (checklist, item 10).
artisan view:cache

# ================================================================== 11. assets

passo "11/14 Build dos assets (Node num container) — depois do view:cache"
rodar "o build dos assets" "${DC[@]}" run --rm assets < /dev/null
[ -f public/build/manifest.json ] || erro "o build terminou sem public/build/manifest.json."

# ================================================================== 12. public/hot

passo "12/14 public/hot"
# O arquivo que o `npm run dev` cria: existindo, TODAS as páginas buscam CSS e JS no
# servidor de desenvolvimento do Vite (127.0.0.1:5173), que não existe aqui — o app
# abre sem estilo e sem JavaScript (checklist, item 10.1).
if [ -e public/hot ]; then
  rm -f public/hot
  amarelo "public/hot existia e foi apagado (alguém rodou o npm run dev no servidor?)."
else
  info "Não existe — ok."
fi

# ================================================================== 13. no ar

passo "13/14 Manutenção desligada e o resto dos serviços"
artisan up
MANUTENCAO=0
# O agendador (e qualquer serviço novo do compose); --remove-orphans tira o que saiu do
# arquivo. O agendador não precisa reiniciar a cada deploy: a cada minuto ele roda o
# schedule:run num processo novo, que já enxerga o código e os caches novos.
rodar "docker compose up (demais serviços)" "${DC[@]}" up -d --remove-orphans < /dev/null

# ================================================================== 14. conferência

passo "14/14 Conferência final"
LOCAL="http://127.0.0.1:${PORTA_DO_APP}"

# O app responde pela porta do container (o caminho do nginx), com o Host do app — em
# produção qualquer outro Host recebe 400 (ver App\Support\EnderecoPublico).
SAUDE=""
for _ in 1 2 3 4 5; do
  SAUDE="$(curl -sS --max-time 10 -H "Host: $HOST_DO_APP" "$LOCAL/up" 2> /dev/null || true)"
  [ "$SAUDE" = "ok" ] && break
  sleep "${SM_DEPLOY_ESPERA:-2}"
done
if [ "$SAUDE" != "ok" ]; then
  erro "o /up respondeu '${SAUDE:-nada}' em $LOCAL. Veja: docker compose logs --tail=50 app  e  storage/logs/"
fi
info "/up: ok"

HTML="$(curl -sS --max-time 10 -H "Host: $HOST_DO_APP" "$LOCAL/login" 2> /dev/null || true)"
case "$HTML" in
  *5173* | */@vite/client*)
    erro "o HTML ainda aponta para o Vite de desenvolvimento (5173) — o public/hot voltou? (checklist, item 10.1)" ;;
esac
case "$HTML" in
  *"/build/assets/"*) info "HTML: assets do build ok" ;;
  *) erro "a tela de login não trouxe os assets do build (/build/assets/). Veja: docker compose logs --tail=50 app" ;;
esac

# O CSS construído, comparado com o do último deploy que chegou até aqui.
MEMORIA="$GIT_DIR_ABS/stabilmoney-deploy"
mkdir -p "$MEMORIA"
CSS="$(sm_bytes_de_css public/build)"
CSS_ANTERIOR="$(cat "$MEMORIA/css-bytes" 2> /dev/null || echo 0)"
case "$CSS_ANTERIOR" in '' | *[!0-9]*) CSS_ANTERIOR=0 ;; esac
if sm_css_encolheu "$CSS_ANTERIOR" "$CSS"; then
  amarelo "ATENÇÃO: o CSS encolheu de $CSS_ANTERIOR para $CSS bytes (mais de 10%). O"
  amarelo "view:cache rodou antes do build (passo 10)? Confira as telas (checklist, item 10)."
else
  info "CSS: $CSS bytes (antes: $CSS_ANTERIOR)"
fi
printf '%s\n' "$CSS" > "$MEMORIA/css-bytes"

# O caminho inteiro — Cloudflare, nginx, certificado. Só avisa: no primeiro deploy o
# nginx e o certificado ainda nem existem (o guia configura depois).
PUBLICO="$(curl -sS --max-time 15 "https://$HOST_DO_APP/up" 2> /dev/null || true)"
if [ "$PUBLICO" = "ok" ]; then
  info "https://$HOST_DO_APP/up: ok (Cloudflare → nginx → app)"
else
  amarelo "https://$HOST_DO_APP/up não respondeu 'ok' (respondeu: '${PUBLICO:-nada}')."
  amarelo "O app está no ar na porta local; confira o nginx (sudo nginx -t), o certificado e a Cloudflare."
fi

verde ""
verde "Deploy concluído em $(($(date +%s) - INICIO)) s — versão $(git rev-parse --short HEAD)."
