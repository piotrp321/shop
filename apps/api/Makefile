.PHONY: shell check phpstan deptrac cs-fix cs-check test test-unit init init-test migrate migration

# Override RUN to run outside Docker Compose, e.g. inside CI.
RUN ?= docker compose exec

shell:
	$(RUN) api sh

check: phpstan deptrac cs-check

phpstan:
	$(RUN) api vendor/bin/phpstan analyse --memory-limit=1G --no-progress

deptrac:
	$(RUN) api vendor/bin/deptrac analyse --no-progress

cs-check:
	$(RUN) api vendor/bin/php-cs-fixer fix --dry-run --diff --show-progress=none

cs-fix:
	$(RUN) api vendor/bin/php-cs-fixer fix

test: init-test
	$(RUN) api vendor/bin/phpunit

# Brings the dev database schema up to date; `make up` runs it once the services are healthy.
init:
	$(RUN) api bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# Brings the test database schema up to date; `test` runs it first.
init-test:
	$(RUN) api bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test

test-unit:
	$(RUN) api vendor/bin/phpunit --testsuite unit

migrate:
	$(RUN) api bin/console doctrine:migrations:migrate --no-interaction

migration:
	$(RUN) api bin/console doctrine:migrations:diff
