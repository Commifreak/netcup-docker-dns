FROM php:8.3-apache
RUN apt-get update -y \
  && apt-get install -y libxml2-dev \
  && apt-get clean -y \
  && docker-php-ext-install soap
COPY src/ /var/www/html/