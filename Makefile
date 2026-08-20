COMPOSE ?= docker-compose
INSTALL_ARGS ?=
APP_UID ?= $(shell id -u)
APP_GID ?= $(shell id -g)
export APP_UID APP_GID

.PHONY: setup fix-permissions clean-containers up down build test format e2e debug reset

setup:
	$(COMPOSE) build app
	$(COMPOSE) up -d mysql
	$(MAKE) fix-permissions
	$(COMPOSE) run --rm app composer install
	$(COMPOSE) run --rm vite npm install
	$(COMPOSE) run --rm app php -r "file_exists('.env') || copy('.env.example', '.env');"
	$(COMPOSE) run --rm app php artisan key:generate
	$(COMPOSE) run --rm app php artisan migrate
	$(COMPOSE) run --rm app php artisan mycoins:install $(INSTALL_ARGS)
	$(COMPOSE) run --rm vite npm run build

fix-permissions:
	mkdir -p node_modules public/build
	$(COMPOSE) run --rm --user 0:0 vite chown -R $(APP_UID):$(APP_GID) /var/www/html/node_modules /var/www/html/public/build

clean-containers:
	$(COMPOSE) rm -sf app vite

up: clean-containers
	$(COMPOSE) up app vite mysql

down:
	$(COMPOSE) down

build:
	$(COMPOSE) run --rm vite npm run build

test:
	$(COMPOSE) exec -T mysql sh -c 'mysql -uroot -p"$$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS my_coins_testing; GRANT ALL PRIVILEGES ON my_coins_testing.* TO '\''$$MYSQL_USER'\''@'\''%'\'';"'
	$(COMPOSE) run --rm app php artisan test

format:
	$(COMPOSE) run --rm app vendor/bin/pint

e2e:
	$(COMPOSE) exec -T mysql sh -c 'mysql -uroot -p"$$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS my_coins_e2e; GRANT ALL PRIVILEGES ON my_coins_e2e.* TO '\''$$MYSQL_USER'\''@'\''%'\'';"'
	$(COMPOSE) rm -sf app
	COMPOSE_DB_DATABASE=my_coins_e2e PLAYWRIGHT_TEST=1 $(COMPOSE) up -d --no-deps app
	COMPOSE_DB_DATABASE=my_coins_e2e PLAYWRIGHT_TEST=1 $(COMPOSE) exec -T app php -r "is_file('public/hot') && unlink('public/hot');"
	COMPOSE_DB_DATABASE=my_coins_e2e PLAYWRIGHT_TEST=1 $(COMPOSE) exec -T app php artisan migrate:fresh --seed --force
	PLAYWRIGHT_BASE_URL=http://$$(docker inspect --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' $$($(COMPOSE) ps -q app)):8000 $(COMPOSE) run --rm browser npm run test:e2e
	$(COMPOSE) rm -sf app

debug: clean-containers
	XDEBUG_MODE=debug $(COMPOSE) up app vite

reset:
	$(COMPOSE) run --rm app php artisan optimize:clear
