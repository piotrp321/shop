.PHONY: shell check lint typecheck test test-unit

# Override RUN to run outside Docker Compose, e.g. inside CI.
RUN ?= docker compose exec

shell:
	$(RUN) store sh

check: lint typecheck

lint:
	$(RUN) store npx eslint .

typecheck:
	$(RUN) store npm run typecheck

test:
	$(RUN) store npx vitest run

test-unit:
	$(RUN) store npx vitest run --exclude '**/*.integration.test.ts'
