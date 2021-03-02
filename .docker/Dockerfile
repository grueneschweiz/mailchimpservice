FROM php:8.0-apache

# update the repository sources list
# and install basic dependencies
RUN apt-get update -y \
  && apt-get install -y \
    openssl \
    zip \
    unzip \
    git

# install & enable mysql driver
RUN docker-php-ext-install pdo_mysql

# install & enable xdebug
RUN pecl install xdebug
RUN docker-php-ext-enable xdebug

# change uid and gid of apache to docker user uid/gid
RUN usermod -u 1000 www-data && groupmod -g 1000 www-data

# reconfigure the virtual hosts to use the ones defined in .docker/apache-vhost.conf
COPY .docker/apache-vhost.conf /etc/apache2/sites-available/custom-vhost.conf
RUN a2dissite 000-default.conf
RUN a2ensite custom-vhost.conf

# use custom php.ini
COPY .docker/php.ini /usr/local/etc/php/

# activate mod rewrite
RUN a2enmod rewrite

# set our application folder as an environment variable
ENV APP_HOME /var/www/html

# set the working dir
WORKDIR $APP_HOME

# change ownership of our application
RUN chown -R www-data:www-data $APP_HOME
