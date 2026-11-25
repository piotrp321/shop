.PHONY: shell check audit lint typecheck test test-unit check-dynamic-routes check-isr-memory-cache

# Override RUN to run outside Docker Compose, e.g. inside CI.
RUN ?= docker compose exec

shell:
	$(RUN) store sh

check: lint typecheck audit

# Known vulnerabilities of the production dependencies in package-lock.json (`npm audit` asks the npm registry, so it
# needs the network): a "high" or "critical" advisory fails the target, lower ones are only listed. A failing tool or no
# network is a failure too, never a clean result. There are no exceptions: update the package.
audit:
	$(RUN) store npm audit --omit=dev --audit-level=high

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

# Needs the seeded stack with STORE_MODE=production: visits product pages and unique 404 slugs until the ISR cache
# overflows its memory limit and fails when a cache file was written to disk or the oldest entry was not evicted
# (script exit code 1), or when it cannot tell: not production, no build or API, an unexpected status (exit code 2).
check-isr-memory-cache:
	$(RUN) store node scripts/check-isr-memory-cache.mjs
