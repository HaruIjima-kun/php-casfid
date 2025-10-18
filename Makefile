SHELL := /bin/sh

.PHONY: up down build ps sh logs install test stan lint fix

up:
\tdocker compose up -d

down:
\tdocker compose down

build:
\tdocker compose build --no-cache

ps:
\tdocker compose ps

sh:
\tdocker compose exec app sh

logs:
\tdocker compose logs -f app

install:
\tdocker compose exec app composer install

test:
\tdocker compose exec app composer test

stan:
\tdocker compose exec app composer stan

lint:
\tdocker compose exec app composer lint

fix:
\tdocker compose exec app composer fix
