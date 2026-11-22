.PHONY: shell check phpstan deptrac cs-fix cs-check test test-unit init init-test warm-test-cache migrate migration openapi

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

# Extra PHPUnit arguments for `test` and `test-unit`, e.g. PHPUNIT_ARGS='--filter CartTest' for a focused run.
PHPUNIT_ARGS ?=

# Without `--testsuite` in PHPUNIT_ARGS the suites run one after another, each in its own PHPUnit process: PHPUnit
# keeps every test object (and the data of its data providers) until the run ends, so one process over all ~8000
# tests peaks at about 250 MB, right at memory_limit (256 MB). With `--testsuite` (CI runs one suite per part) the
# arguments go to a single run.
PHPUNIT_SUITES = $(if $(findstring --testsuite,$(PHPUNIT_ARGS)),all,unit integration demo-data demo-data-orders functional)

test: init-test warm-test-cache
	$(foreach suite,$(PHPUNIT_SUITES),$(RUN) api vendor/bin/phpunit $(if $(filter all,$(suite)),,--testsuite $(suite)) $(PHPUNIT_ARGS) &&) true

# Brings the dev database schema up to date and creates the search alias and index if missing (idempotent);
# `make up` runs it once the services are healthy.
init:
	$(RUN) api bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
	$(RUN) api bin/console catalog:search-index:setup

# The same for the tests: shop_test schema, and the disposable test_products index created anew; `test` runs it first.
init-test:
	$(RUN) api bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test
	$(RUN) api bin/console catalog:search-index:setup --recreate --env=test

# Compiles the containers of the kernels that the tests boot, so that PHPUnit only loads them. A container compiled
# inside the test process stays in its memory (about 50 MB of loaded classes and definitions each) and, late in a long
# run that already holds the memory of thousands of tests, exceeds memory_limit: `make test` then failed after
# `var/cache/test*` was removed. PHPUnit runs with APP_DEBUG=0 (phpunit.dist.xml), so `test` and `test_messaging` are
# warmed without debug; `test_oidc` is booted with debug by the tests (`'debug' => true`), which is the console default.
# `test_redis_pool` is built by a test kernel class and is compiled by its own (early) tests.
warm-test-cache:
	$(RUN) api env APP_DEBUG=0 bin/console cache:warmup --env=test
	$(RUN) api env APP_DEBUG=0 bin/console cache:warmup --env=test_messaging
	$(RUN) api env APP_DEBUG=1 bin/console cache:warmup --env=test_oidc

test-unit:
	$(RUN) api vendor/bin/phpunit --testsuite unit $(PHPUNIT_ARGS)

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
