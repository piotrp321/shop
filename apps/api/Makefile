.PHONY: shell check phpstan deptrac cs-fix cs-check test test-unit init init-test migrate migration openapi

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

# Brings the dev database schema up to date and creates the search alias and index if missing (idempotent);
# `make up` runs it once the services are healthy.
init:
	$(RUN) api bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
	$(RUN) api bin/console catalog:search-index:setup

# The same for the tests: shop_test schema, and the disposable test_products index created anew; `test` runs it first.
init-test:
	$(RUN) api bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test
	$(RUN) api bin/console catalog:search-index:setup --recreate --env=test

test-unit:
	$(RUN) api vendor/bin/phpunit --testsuite unit

migrate:
	$(RUN) api bin/console doctrine:migrations:migrate --no-interaction

migration:
	$(RUN) api bin/console doctrine:migrations:diff

# Exports the OpenAPI documents: `default` (store) to openapi.json, `admin` (panel) to openapi-admin.json.
# Both are dumped to temporary files and validated first; only when both are good are the targets written, so a
# failing dump never leaves a truncated or empty target, and never changes one while the other fails. The target
# fails if either dump fails or is not valid JSON.
# The targets are overwritten in place (cat, not mv): their inode stays, so a file bind-mounted into a container
# (the store reads openapi.json that way) keeps seeing the current content.
openapi:
	$(RUN) api sh -c ' \
		trap "rm -f openapi.json.tmp openapi-admin.json.tmp" EXIT; \
		dump() { \
			bin/console nelmio:apidoc:dump --area="$$1" --no-interaction > "$$2.tmp" \
				&& php -r "json_decode(file_get_contents(\$$argv[1]), false, 512, JSON_THROW_ON_ERROR);" "$$2.tmp"; \
		}; \
		dump default openapi.json && dump admin openapi-admin.json \
			&& cat openapi.json.tmp > openapi.json && cat openapi-admin.json.tmp > openapi-admin.json'
