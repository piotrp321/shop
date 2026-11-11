.PHONY: store-dynamic-routes

CI_AFTER_E2E += store-dynamic-routes

# The production build must not prerender /robots.txt, /sitemap.xml and the /sitemaps/ routes. Needs the running stack
# with STORE_MODE=production; CI passes RUN="docker compose exec -T".
store-dynamic-routes:
	$(MAKE) -C apps/store check-dynamic-routes RUN="$(RUN)"
