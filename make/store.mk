.PHONY: store-dynamic-routes store-isr-memory-cache

CI_AFTER_E2E += store-dynamic-routes store-isr-memory-cache

# The production build must not prerender /robots.txt, /sitemap.xml and the /sitemaps/ routes. Needs the running stack
# with STORE_MODE=production; CI passes RUN="docker compose exec -T".
store-dynamic-routes:
	$(MAKE) -C apps/store check-dynamic-routes RUN="$(RUN)"

# The ISR cache of the production build stays in memory within its limit: no cache file on disk, the oldest entry is
# evicted after the overflow. Needs the seeded stack with STORE_MODE=production; CI passes RUN="docker compose exec -T".
store-isr-memory-cache:
	$(MAKE) -C apps/store check-isr-memory-cache RUN="$(RUN)"
