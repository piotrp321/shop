.PHONY: shell check phpstan deptrac cs-fix cs-check test test-unit migrate migration

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

test:
	$(RUN) api vendor/bin/phpunit

test-unit:
	$(RUN) api vendor/bin/phpunit --testsuite unit

# Doctrine Migrations is installed in a later task; these targets do not work before that.
migrate:
	$(RUN) api bin/console doctrine:migrations:migrate --no-interaction

migration:
	$(RUN) api bin/console doctrine:migrations:diff
