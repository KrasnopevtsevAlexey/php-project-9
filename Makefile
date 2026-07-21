PORT ?= 8000

start:
	PHP_CLI_SERVER_WORKERS=5 php -S 0.0.0.0:$(PORT) -t public

install:
	composer install

lint:
	composer lint

test:
	php init.php
	PHP_CLI_SERVER_WORKERS=5 composer test
