# Webling Service

[![Build Status](https://travis-ci.com/grueneschweiz/weblingservice.svg?branch=master)](https://travis-ci.com/grueneschweiz/weblingservice)
[![Coverage Status](https://coveralls.io/repos/github/grueneschweiz/weblingservice/badge.svg)](https://coveralls.io/github/grueneschweiz/weblingservice)

**UNDER DEVELOPEMENT**

This project aims to add some crucial but missing functionality to Webling,
while using Weblings RESTful API and exposing a new, higher lever RESTful
API. It is based on the fabulous [Laravel](https://laravel.com/) framework
to speed up the development. Check out the [docs](https://laravel.com/docs/5.6)
and start contributing 😍.

## Contributing ...
... is cool, simple and helps to make the 🌍 a better place 🤩
1. Install [docker](https://store.docker.com/search?offering=community&type=edition)
1. Start docker
1. Clone this repo `git clone https://github.com/grueneschweiz/weblingservice.git`
1. `cd` into the folder containing the repo
1. Execute `docker-compose -f docker-compose.install.yml up` and have a ☕️ while 
it installs. `wsnode_install` and `wscomposer_install` should exit with `code 0`.
1. Execute `docker-compose -f docker-compose.install.yml run composer 
cp .env.example .env && php artisan key:generate` to generate the app secrets
1. Execute `docker-compose up -d` to start up the stack. The first time you run
this command, it will take a minute or two. Subsequent calls will be much faster.
1. After a few seconds: Visit [localhost:8000](http://localhost:8000). If you
get a connection error, wait 30 seconds then try again. 

### Docker Cheat Sheet
- Install: `docker-compose -f docker-compose.install.yml up`
- Start up: `docker-compose up -d`
- Shut down: `docker-compose down`
- Execute Laravel CLI commands (enter container): `docker exec -it wsapp bash` use `exit` to escape the container.
- Add dependency using composer: `docker-compose -f docker-compose.install.yml 
run composer composer require DEPENDENCY` (yes, `composer composer` is correct,
the first one defines the container to start the second one is the command to
execute)
- Add dependency from npm: `docker-compose -f docker-compose.install.yml 
run node npm --install DEPENDENCY` (You may want to use --save or --save-dev as
well. Check out the [Docs](https://docs.npmjs.com/cli/install).)

### Tooling
#### Mailhog
All mail you send out of the application will be caught by [Mailhog](http://localhost:8020)

#### MySQL
Use the handy [phpMyAdmin](http://localhost:8010) or access the mysql CLI using
`docker exec -it wsmysql mysql --user=laravel --password=laravel laravel` 

#### Laravel mix
Works out of the box ☺️

#### NPM
Access the watching container using `docker exec -it wsnode bash`
