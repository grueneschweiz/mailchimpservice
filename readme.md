# Mailchimp Service

[![build status](https://github.com/grueneschweiz/mailchimpservice/actions/workflows/ci.yml/badge.svg)](https://github.com/grueneschweiz/mailchimpservice/actions/workflows/ci.yml)
[![Coverage Status](https://coveralls.io/repos/github/grueneschweiz/mailchimpservice/badge.svg)](https://coveralls.io/github/grueneschweiz/mailchimpservice)

This project marks the glue between the
[weblingservice](https://github.com/grueneschweiz/weblingservice) and Mailchimp. It uses and wraps Mailchimp's
[REST API](https://mailchimp.com/developer/marketing/api/root/).

It is based on the fabulous [Laravel](https://laravel.com/) framework to speed up the development. Check out
the [docs](https://laravel.com/docs/)
and start contributing 😍.

## Contributing ...
... is cool, simple and helps to make the 🌍 a better place 🤩
1. Install [docker](https://store.docker.com/search?offering=community&type=edition)
1. Start docker
1. Clone this repo `git clone https://github.com/grueneschweiz/mailchimpservice.git`
1. `cd` into the folder containing the repo
1. Execute `docker-compose run wsapp_mailchimp composer install` and have a ☕️ while
   it installs.
1. Execute `docker-compose run wsapp_mailchimp sh -c 'cp .env.example .env && php artisan key:generate'` to generate the
   app secrets.
1. Execute `docker-compose up -d` to start up the stack. The first time you run
   this command, it will take a minute or two. Subsequent calls will be much faster.
1. After a few seconds: Visit [localhost:9001](http://localhost:9001). If you
   get a connection error, wait 30 seconds then try again.

### Docker Cheat Sheet

- Install: `docker-compose run wsapp_mailchimp composer install`
- Start up: `docker-compose up -d`
- Shut down: `docker-compose down`
- Execute Laravel CLI commands (enter container): `docker exec -it wsapp_mailchimp bash` use `exit` to escape the
  container.
- Add dependency using composer: `docker-compose un wsapp_mailchimp composer require DEPENDENCY`

### Testing
In the main folder run `php vendor/phpunit/phpunit/phpunit tests` to run the tests locally.

### Tooling
#### Mailhog
All mail you send out of the application will be caught by [Mailhog](http://localhost:9020)

#### MySQL

Use the handy [phpMyAdmin](http://localhost:9010) or access the mysql CLI using
`docker exec -it wsmysql_mailchimp mysql --user=laravel --password=laravel laravel` 
