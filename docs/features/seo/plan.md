# SEO – plan zadań

Specyfikacja: `docs/features/seo/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | SEO-1, SEO-3 | |
| 2 | SEO-2 | |

Stan prób zadań zgłoszonych do AO: `ao status`. Kolumnę „Status” (PR, zmergowane) uzupełnia użytkownik przy
integracji zadań fali.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "SEO",
  "spec": "docs/features/seo/spec.md",
  "tasks": [
    {
      "id": "SEO-1",
      "title": "Sitemap chunk endpoints in the Catalog API behind Varnish",
      "scope": "apps/api: Catalog Application (queries for the chunk boundaries and the published products of a chunk on query.bus, the opaque chunk key {f, t} with open ends after the ProductCursor pattern, InvalidSitemapChunk), Infrastructure (DBAL source: boundaries at positions N+1, 2N+1, … of all products by the primary key with row_number(); a port decorator caching the boundaries in a new pool cache.sitemap with TTL 1 h through CacheInterface::get, array adapter in tests; chunk read: at most limit + 1 products of the id range ordered by id, then the status = 'published' filter, a warning log entry over the limit), UI (GET /api/v1/sitemap/product-chunks: 200 public max-age=60 s-maxage=3600 tagged catalog; GET /api/v1/sitemap/products?chunk=<key>: 200 public max-age=300 without tags; 400 for a missing or invalid key, 401 and 503 through PublicReadErrorCacheSubscriber; a PUBLIC_ACCESS rule for GET and HEAD of both paths in config/packages/security.yaml before the ROLE_CUSTOMER rule); chunk size 10 000 and row limit 50 000 as container parameters in apps/api/config; openapi.json and the store client (make api-client); infra/varnish/default.vcl caches ^/api/v1/sitemap/product-chunks$ only, with new varnishtests in infra/varnish/tests (chunk-list cached, chunk passed). No migration and no new index. Spec sections „Mapa strony” → „API (Catalog)”, „Scenariusze błędów” (API rows), decisions D1, D2, D7, D8. Not included: the store routes, robots.txt, JSON-LD.",
      "acceptance_criteria": [
        "Unit tests of the chunk key: both ends and open ends round-trip; bad base64, bad JSON, a non-UUID end, f ≥ t and an over-long text are invalid",
        "Integration tests (PostgreSQL, small chunk size and row limit from the test configuration): N = 3 and 7 products give chunks 1–3, 4–6, 7 and exactly N products one chunk; the first chunk has an open start and the last an open end; chunks are disjoint and together cover every published product; drafts are skipped; items in id order with updatedAt; an empty catalog gives one chunk with both ends open and an empty item list; a product created after the boundaries with an id below the first and above the last boundary is in the first and the last chunk; a range over the row limit (also with drafts) reads at most limit + 1 rows and writes a warning log entry",
        "Test of the boundary cache decorator: a second call within the TTL does not query the database",
        "Functional tests of both endpoints: 200 without a token and with the documented shape, 400 without chunk and with an invalid key, an empty item list, Cache-Control of each endpoint as in the spec and the catalog tag in xkey of product-chunks only, 401 with an invalid token, the response matches openapi.json",
        "varnishtest: /api/v1/sitemap/product-chunks is cached, /api/v1/sitemap/products?chunk=… is not",
        "openapi.json and the store client are regenerated; apps/api/CLAUDE.md describes the sitemap endpoints (chunks, key, row limit, cache.sitemap, HTTP cache); docs/vision.md „Cache HTTP i unieważnianie” states the sitemap exception (chunks without tags and without Varnish, the chunk list tagged catalog)",
        "make -C apps/api check, make -C apps/api test, make varnish-test, make -C apps/store check pass"
      ],
      "risk": "STANDARD",
      "depends_on": []
    },
    {
      "id": "SEO-2",
      "title": "Sitemap index, listing and product chunk routes and robots.txt in the store",
      "scope": "apps/store: module src/seo (XML builders with escaping, robots rules), route handlers /sitemap.xml (sitemapindex: listing map, then one entry per chunk in API order), /sitemaps/listing.xml (/ and every category of GET /api/v1/categories as /c/<path>, depth-first, no lastmod) and /sitemaps/products/<key>.xml (/p/<slug> with lastmod = updatedAt, empty urlset for no items, API 400 → 404, no .xml suffix → 404), all rendered per request without the Next data cache through API_CATALOG_URL and the generated client, 200 with application/xml and public max-age=300, any other API failure → 503 no-store and a server log entry, STORE_URL read at request time (missing → configuration error); app/robots.ts with forced dynamic rendering and the D5 rules and the Sitemap line; make -C apps/store check-dynamic-routes (reads .next/prerender-manifest.json in the store container: exit 1 when /robots.txt, /sitemap.xml or a /sitemaps/… route is prerendered, exit 2 without the manifest or when the container STORE_MODE is not production) run in the e2e job of .github/workflows/ci.yml after the stack starts; a new E2E test file in e2e/tests. Spec sections „Mapa strony” → „Sklep”, „robots.txt”, „Scenariusze błędów” (store rows), decisions D1, D5, D6, D7. Not included: API changes, JSON-LD.",
      "acceptance_criteria": [
        "Vitest: XML builders escape &, <, >, \", ' and give absolute addresses in the specified order; the routes on an API double give 200 with the index, the listing map and a chunk map, an empty urlset for no items, 404 for API 400, 503 no-store for an API error, a network error and an unexpected body; the robots rules equal D5 with the Sitemap line from STORE_URL",
        "E2E after make seed: /robots.txt has the D5 rules; /sitemap.xml lists the listing map and one product chunk; the listing map has / and every category path; a demo product address from the chunk opens with 200",
        "check-dynamic-routes passes on the production build in CI; its error paths (a prerendered route, a missing manifest, dev mode) are shown in the task report with their exit codes",
        "docs/vision.md „Mapa strony i robots” describes the sitemap index with chunks from the API and the robots rules; apps/store/CLAUDE.md describes src/seo, the sitemap routes, robots.ts, the no-data-cache rule and check-dynamic-routes",
        "make -C apps/store check, make -C apps/store test and make e2e pass"
      ],
      "risk": "STANDARD",
      "depends_on": [
        "SEO-1"
      ]
    },
    {
      "id": "SEO-3",
      "title": "One store name constant and the Organization and WebSite JSON-LD on the home page",
      "scope": "apps/store: STORE_NAME in src/site.ts used by src/catalog/page-metadata.ts, src/catalog/product-metadata.ts, app/layout.tsx (title and description) and the word mark in src/catalog/SiteHeader.tsx, the value unchanged; app/page.tsx renders two application/ld+json scripts whenever it shows the listing (results or an empty list, with any filter or page parameters; not on the invalid filter page, the ?category= redirect or 404) (Organization and WebSite with name = STORE_NAME and url = <STORE_URL>/, through serializeJsonLd) built in src/seo; no JSON-LD change on /c/, /szukaj and /p/. Spec section „Dane strukturalne strony głównej”, decisions D3, D4. Not included: logo, sameAs, SearchAction, ItemList.",
      "acceptance_criteria": [
        "Vitest: / renders Organization and WebSite JSON-LD with STORE_NAME and <STORE_URL>/ with results, with an empty list and with query parameters, not on the invalid filter page; /c/…, /szukaj and /p/… pages do not have them; metadata titles and the layout take the name from STORE_NAME",
        "E2E: the home page HTML contains the Organization and WebSite JSON-LD",
        "docs/vision.md „Dane strukturalne” names Organization and WebSite on /; apps/store/CLAUDE.md describes src/site.ts (STORE_NAME) and the home page JSON-LD",
        "make -C apps/store check, make -C apps/store test and make e2e pass"
      ],
      "risk": "FAST",
      "depends_on": []
    }
  ]
}
```
