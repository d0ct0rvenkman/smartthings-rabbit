FROM php:7-cli
COPY --from=composer /usr/bin/composer /usr/bin/composer
RUN apt-get update
RUN apt-get -y install git zlib1g-dev libzip-dev unzip
RUN mkdir -p /etc/smartthings-rabbit
RUN docker-php-ext-install bcmath
RUN docker-php-ext-install pcntl
RUN docker-php-ext-install sockets
RUN docker-php-ext-install zip
RUN pecl install -o -f redis &&  rm -rf /tmp/pear &&  docker-php-ext-enable redis
COPY . /usr/src/smartthings-rabbit
WORKDIR /usr/src/smartthings-rabbit
RUN rm -rf smartapps vendor

# versions of php-amqplib after 2.8.1 cause the workers to consume 100% CPU.
# install a specific version until that's sorted out
RUN /usr/bin/composer require php-amqplib/php-amqplib:2.8.1
CMD [ "bash" ]
