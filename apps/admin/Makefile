.PHONY: shell check audit lint typecheck test

# Override RUN to run outside Docker Compose, e.g. inside CI.
RUN ?= docker compose exec

shell:
	$(RUN) admin sh

check: lint typecheck audit

# Known vulnerabilities of the production dependencies in package-lock.json (`npm audit` asks the npm registry, so it
# needs the network): a "high" or "critical" advisory fails the target, lower ones are only listed. A failing tool or no
# network is a failure too, never a clean result. There are no exceptions: update the package.
audit:
	$(RUN) admin npm audit --omit=dev --audit-level=high

lint:
	$(RUN) admin npx eslint .

typecheck:
	$(RUN) admin npx vue-tsc --noEmit

test:
	$(RUN) admin npx vitest run
