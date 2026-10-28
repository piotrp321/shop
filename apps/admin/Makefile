.PHONY: shell check lint typecheck test

# Override RUN to run outside Docker Compose, e.g. inside CI.
RUN ?= docker compose exec

shell:
	$(RUN) admin sh

check: lint typecheck

lint:
	$(RUN) admin npx eslint .

typecheck:
	$(RUN) admin npx vue-tsc --noEmit

test:
	$(RUN) admin npx vitest run
