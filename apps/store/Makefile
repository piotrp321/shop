.PHONY: shell check lint typecheck test test-unit check-dynamic-routes

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

# Needs the stack with STORE_MODE=production (`STORE_MODE=production make up`): reads .next/prerender-manifest.json in the
# store container and fails when /robots.txt, /sitemap.xml or a /sitemaps/... route is prerendered (script exit code 1)
# or when it cannot tell: no manifest, or the container is not in production mode (script exit code 2). Make itself
# exits with 2 for any failed recipe; the code of the script is in the `Error N` line.
check-dynamic-routes:
	$(RUN) store node scripts/check-dynamic-routes.mjs
