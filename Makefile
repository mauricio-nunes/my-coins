COMPOSE ?= docker-compose
APP_UID ?= $(shell id -u)
APP_GID ?= $(shell id -g)
export APP_UID APP_GID

.PHONY: setup fix-permissions clean-containers up down build test format e2e debug reset

setup:
	$(COMPOSE) build app
	$(MAKE) fix-permissions
	$(COMPOSE) run --rm app composer install
	$(COMPOSE) run --rm vite npm install
	$(COMPOSE) run --rm app php -r "file_exists('.env') || copy('.env.example', '.env');"
	$(COMPOSE) run --rm app php artisan key:generate
	$(COMPOSE) run --rm vite npm run build

fix-permissions:
	mkdir -p node_modules public/build
	$(COMPOSE) run --rm --user 0:0 vite chown -R $(APP_UID):$(APP_GID) /var/www/html/node_modules /var/www/html/public/build

clean-containers:
	$(COMPOSE) rm -sf app vite

up: clean-containers
	$(COMPOSE) up app vite

down:
	$(COMPOSE) down

build:
	$(COMPOSE) run --rm vite npm run build

test:
	$(COMPOSE) run --rm app php artisan test

format:
	$(COMPOSE) run --rm app vendor/bin/pint

e2e:
	$(COMPOSE) rm -sf app
	PLAYWRIGHT_TEST=1 $(COMPOSE) up -d app
	$(COMPOSE) run --rm browser npm run test:e2e

debug: clean-containers
	XDEBUG_MODE=debug $(COMPOSE) up app vite

reset:
	$(COMPOSE) run --rm app php artisan optimize:clear
