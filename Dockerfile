# Usamos a imagem oficial do PHP 8.4 com Apache
FROM php:8.4-apache

# Instala as dependências de sistema necessárias para o Laravel
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    git \
    curl

# Limpa o cache do apt para deixar a imagem mais leve
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Instala as extensões do PHP que o Laravel e o MySQL precisam
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Habilita o mod_rewrite do Apache (necessário para as rotas do Laravel)
RUN a2enmod rewrite

# Altera a raiz do Apache para a pasta "public" do Laravel
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Instala o Composer copiando-o da imagem oficial
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Define o diretório de trabalho padrão
WORKDIR /var/www/html

# Ajusta as permissões da pasta (opcional, mas evita erros no storage)
RUN chown -R www-data:www-data /var/www/html

# ---------------------------------------------------------------------------
# Configuração do PHP
# ---------------------------------------------------------------------------
# A imagem oficial entrega php.ini-production e php.ini-development em
# $PHP_INI_DIR e NÃO ativa nenhum dos dois: sem esta linha o `php --ini` responde
# "Loaded Configuration File: (none)" e valem apenas os padrões COMPILADOS —
# entre eles display_errors=On, que imprime na resposta qualquer erro de PHP
# anterior ao boot do Laravel — com o caminho absoluto do arquivo e a linha.
#
# Usamos `cp` e não `mv` de propósito: o php.ini-production original fica no
# lugar para servir de referência quando alguém precisar diferenciar o que é
# padrão do que é nosso.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Ajustes exigidos pelo app. Vão em conf.d/ (lido DEPOIS do php.ini, então vence
# o php.ini-production acima) e com prefixo "zz-" porque o PHP percorre o
# diretório em ordem alfabética — "zz" garante a última palavra, depois dos
# docker-php-ext-*.ini que a imagem oficial gera.
RUN printf '%s\n' \
    '; ---------------------------------------------------------------------------' \
    '; Stabil Money — ajustes de PHP exigidos pelo app.' \
    ';' \
    '; Cada valor abaixo existe por causa de uma regra do código, citada no comentário.' \
    '; Ao mexer numa dessas regras, volte aqui.' \
    '; ---------------------------------------------------------------------------' \
    '' \
    '; --- Upload da foto de perfil ---------------------------------------------' \
    '; O app aceita avatar de até 2 MB: regra "max:2048" em ProfileUpdateRequest,' \
    '; StoreDependentRequest e UpdateDependentRequest. O padrão do PHP (e do próprio' \
    '; php.ini-production) é 2M — ou seja, EMPATADO com a regra do Laravel, sem folga' \
    '; nenhuma para o overhead do multipart. Consequência medida: um arquivo no limite' \
    '; é recusado pelo PHP ANTES de o Laravel ver qualquer coisa, $_FILES chega vazio e' \
    '; a pessoa lê "O campo foto é obrigatório" em vez de "A foto pode ter no máximo' \
    '; 2 MB". Com 8M (4x a regra) quem recusa é sempre o Laravel, que tem a mensagem' \
    '; certa em português.' \
    ';' \
    '; Por que não subir mais: cada byte aqui é buffer que o processo aceita ANTES de' \
    '; qualquer autenticação, e ImageMetadata::strip() mantém em memória os bytes' \
    '; originais e a cópia limpa ao mesmo tempo (~2x o tamanho do arquivo).' \
    'upload_max_filesize = 8M' \
    '' \
    '; Tem de ser MAIOR que upload_max_filesize: mede o corpo inteiro (arquivo +' \
    '; campos + _token + fronteiras do multipart). Estourar este limite é pior que' \
    '; estourar o do arquivo — o PHP esvazia $_POST e $_FILES, o _token some junto e o' \
    '; Laravel responde 419 "página expirada", erro que não tem relação nenhuma com o' \
    '; tamanho do arquivo. 12M = os 8M do arquivo + folga para o resto do formulário.' \
    'post_max_size = 12M' \
    '' \
    '; O app tem 3 campos de arquivo (profile/edit e dependents/index), nenhum com' \
    '; "multiple", e nunca envia mais de um por requisição. Os 20 do padrão só ampliam' \
    '; de graça o trabalho do parser de multipart.' \
    'max_file_uploads = 5' \
    '' \
    '; --- Memória ---------------------------------------------------------------' \
    '; Declarado explicitamente no valor padrão, DE PROPÓSITO: a auditoria de volume' \
    '; mediu pico de 7,1 MB por requisição no dashboard, e mesmo o caminho mais pesado' \
    '; (upload de 8 MB somado às duas cópias do ImageMetadata) não passa de ~25 MB.' \
    '; 128M é ~18x o pico medido — o gargalo não é aqui, e subir o número apenas' \
    '; aumenta o quanto uma requisição descontrolada consome antes de morrer.' \
    '; A suíte de testes inteira roda neste valor. Se um dia faltar memória, MEÇA' \
    '; antes de mexer.' \
    'memory_limit = 128M' \
    '' \
    '; --- Fuso horário ----------------------------------------------------------' \
    '; Mesmo padrão de config/app.php (APP_TIMEZONE=America/Sao_Paulo). Na prática o' \
    '; Laravel chama date_default_timezone_set() no boot e manda dentro do app; isto' \
    '; vale para o que roda ANTES do framework subir (erro de PHP no log do Apache) e' \
    '; evita o "UTC" silencioso que o php.ini-production deixa como padrão.' \
    '; Atenção: o fuso da CONEXÃO com o banco é outra coisa e continua em DB_TIMEZONE' \
    '; (+00:00) — ver CLAUDE.md, "Modelo de dinheiro".' \
    'date.timezone = America/Sao_Paulo' \
    '' \
    '; --- Exposição -------------------------------------------------------------' \
    '; Remove o cabeçalho X-Powered-By: PHP/8.4.x de toda resposta. O' \
    '; php.ini-production deixa isso LIGADO; anunciar a versão exata do runtime só' \
    '; ajuda quem está escolhendo qual exploit tentar.' \
    'expose_php = Off' \
    '' \
    '; --- Leitura da configuração -----------------------------------------------' \
    '; O php.ini-production troca o padrão compilado "EGPCS" por "GPCS", e isso deixa' \
    '; $_ENV VAZIO. O Laravel lê variáveis de ambiente de $_ENV e de $_SERVER; hoje' \
    '; elas vêm do arquivo .env (que o Dotenv escreve nos dois), então nada quebraria' \
    '; — mas no dia em que a VPS injetar segredo pelo "environment:" do compose,' \
    '; metade das fontes teria sumido como efeito colateral de ativar o' \
    '; php.ini-production. Medido neste container: com GPCS, $_ENV["FOO"] não existe e' \
    '; $_SERVER["FOO"] existe. Restaurar o padrão compilado mantém as duas.' \
    'variables_order = "EGPCS"' \
    > "$PHP_INI_DIR/conf.d/zz-stabilmoney.ini"
