# Mailchimp Service

<!-- TODO: configure travis and coveralls: -->
<!-- [![Build Status](https://travis-ci.com/grueneschweiz/mailchimpservice.svg?branch=master)](https://travis-ci.com/grueneschweiz/mailchimpservice) -->
<!-- [![Coverage Status](https://coveralls.io/repos/github/grueneschweiz/mailchimpservice/badge.svg)](https://coveralls.io/github/grueneschweiz/mailchimpservice) -->

**UNDER DEVELOPEMENT**

This project aims to give a layer for Mailchimp in order to access it via another app or with Wordpress. It uses and wraps the Mailchimps RESTful API. 

It is based on the fabulous [Laravel](https://laravel.com/) framework
to speed up the development. Check out the [docs](https://laravel.com/docs/5.6)
and start contributing 😍.

## Contributing ...
... is cool, simple and helps to make the 🌍 a better place 🤩
1. Install [docker](https://store.docker.com/search?offering=community&type=edition)
1. Start docker
1. Clone this repo `git clone https://github.com/grueneschweiz/mailchimpservice.git`
1. `cd` into the folder containing the repo
1. Execute `docker-compose -f docker-compose.install.yml up` and have a ☕️ while 
it installs. `wscomposer_install_mailchimp` should exit with `code 0`.
1. Execute `docker-compose -f docker-compose.install.yml run composer 
cp .env.example .env && php artisan key:generate` to generate the app secrets. You may have to disable the slack log in config/logging.php for this.
1. Execute `docker-compose up -d` to start up the stack. The first time you run
this command, it will take a minute or two. Subsequent calls will be much faster.
1. After a few seconds: Visit [localhost:9001](http://localhost:9001). If you
get a connection error, wait 30 seconds then try again. 

### Docker Cheat Sheet
- Install: `docker-compose -f docker-compose.install.yml up`
- Start up: `docker-compose up -d`
- Shut down: `docker-compose down`
- Execute Laravel CLI commands (enter container): `docker exec -it wsapp_mailchimp bash` use `exit` to escape the container.
- Add dependency using composer: `docker-compose -f docker-compose.install.yml 
run composer composer require DEPENDENCY` (yes, `composer composer` is correct,
the first one defines the container to start the second one is the command to
execute)

### Testing
In the main folder run `php vendor/phpunit/phpunit/phpunit tests` to run the tests locally.

### Tooling
#### Mailhog
All mail you send out of the application will be caught by [Mailhog](http://localhost:9020)

#### MySQL
Use the handy [phpMyAdmin](http://localhost:9010) or access the mysql CLI using
`docker exec -it wsmysql mysql --user=laravel --password=laravel laravel` 
