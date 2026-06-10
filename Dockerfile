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