# SEARCH – plan zadań

Specyfikacja: `docs/features/search/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | SEARCH-1, SEARCH-2 | |
| 2 | SEARCH-3, SEARCH-5 | |
| 3 | SEARCH-4 | |
| 4 | SEARCH-6 | |

Stan prób zadań zgłoszonych do AO: `ao status`. Kolumnę „Status” (PR, zmergowane) uzupełnia użytkownik przy
integracji zadań fali.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "SEARCH",
  "spec": "docs/features/search/spec.md",
  "tasks": [
    {
      "id": "SEARCH-1",
      "title": "Elasticsearch image with the Polish analysis plugin",
      "scope": "infra, Makefile, CI, docs. Spec section „Infrastruktura” (Dockerfile part) and decision A2. New infra/elasticsearch/Dockerfile: FROM docker.elastic.co/elasticsearch/elasticsearch:9.4.0 and elasticsearch-plugin install --batch analysis-stempel; the elasticsearch service in infra/docker-compose.yml is built from it (build instead of image), also in CI (.github/workflows/ci.yml) and for the test stack. No change of the index definition yet (SEARCH-3). Docs: docs/vision.md (Polish analysis through analysis-stempel), docs/features/walking-skeleton/spec.md (Elasticsearch image with the plugin).",
      "acceptance_criteria": [
        "make up builds and starts Elasticsearch from infra/elasticsearch/Dockerfile and the stack becomes healthy",
        "elasticsearch-plugin list in the container shows analysis-stempel (shown with the command and its output)",
        "_analyze with the polish_stem filter gives the same token for „kurtki” and „kurtka” (shown with the command and its output)",
        "CI builds the image and the checks and e2e jobs pass",
        "docs/vision.md and docs/features/walking-skeleton/spec.md describe the image with the plugin",
        "make check and make test pass"
      ],
      "risk": "STANDARD",
      "depends_on": []
    },
    {
      "id": "SEARCH-2",
      "title": "Category slug and path, axis names without a colon",
      "scope": "apps/api Catalog, apps/api/openapi.json, apps/api/openapi-admin.json, both generated TS clients, docs. Spec sections „Catalog: slug kategorii (A3, S6)” and „Catalog: nazwa osi bez dwukropka (A3)”, decisions A3 and S6. Domain: CategorySlug value object (format of the product Slug, 1–60 characters), Category::createRoot, createChild and reconstitute take it; CreateCategory rejects a slug taken among siblings (CategorySlugTaken, Conflict), also among roots. Migration: catalog_category.slug filled with the category id for existing rows, NOT NULL, unique indexes (parent_id, slug) and partial (slug) WHERE parent_id IS NULL. The demo loader passes the slug of every node of fixtures/catalog.yaml. CategoryNode gets slug and path (slugs from the root joined with /, computed in GetCategoryTreeHandler) for GET /api/v1/categories and GET /api/v1/admin/categories; every breadcrumb element of GET /api/v1/products/{slug} gets path. VariantAxes::fromList rejects a name containing a colon (InvalidVariantAxes). OpenAPI export of both files and both clients regenerated in the same PR; store and admin behaviour unchanged. Not included: the store routes /c/ (SEARCH-5). Docs: docs/vision.md (category slug, category tree decision), docs/features/variants/spec.md (axis name without a colon).",
      "acceptance_criteria": [
        "A category is created with a slug; a slug taken by a sibling (also among roots) is a 409 conflict in the handler and is rejected by the unique indexes for direct SQL",
        "The migration fills existing rows with the category id and the columns are NOT NULL",
        "make seed creates the demo categories with the slugs from fixtures/catalog.yaml",
        "GET /api/v1/categories and GET /api/v1/admin/categories return slug and path of every node; the breadcrumb of GET /api/v1/products/{slug} has path",
        "VariantAxes rejects an axis name with a colon",
        "openapi.json, openapi-admin.json and both TS clients are up to date",
        "docs/vision.md and docs/features/variants/spec.md describe the slug and the axis rule",
        "make check and make test pass for api, store and admin"
      ],
      "risk": "CRITICAL",
      "depends_on": []
    },
    {
      "id": "SEARCH-3",
      "title": "Search index with variants, prices and availability",
      "scope": "apps/api Catalog, Cart_Infrastructure, Pricing_Infrastructure, Inventory_Infrastructure, Makefile, docs. Spec section „Catalog: indeks wyszukiwania” (Dokument, Port ProductOfferFacts, Zapis i kolejność, Wyzwalacze), „Infrastruktura” (make seed) and decisions A1, A2. ProductIndexDefinition: polish_text analyzer (lowercase, polish_stem, asciifolding) on name, category_names and short_description with folded subfields (lowercase, asciifolding), name.sort as today, nested variants (gross, purchasable, axes, options as <axis>U+001F<value> keywords), from_gross. ProductSearchSource adds variants with their axis values and category names. Port ProductOfferFacts in Catalog_Application, adapter in Cart_Infrastructure calling Cart GetProductOffers on query.bus in batches of 48. ProductIndexing service in Catalog_Application (reindex(productId), outside command.bus); IndexProduct delegates to it. Document version is a microsecond timestamp taken before the read, version_type external_gte. The rebuild (catalog:reindex) fetches the offer facts per batch. Handlers of VariantPriceChanged (Pricing_Infrastructure) and StockLevelChanged (Inventory_Infrastructure) on the events transport: productId from Catalog GetVariant, ProductIndexing::reindex; unknown variant or missing product acked; source seed acked without reindexing. make seed runs catalog:reindex after the three seeds and before storefront:cache:purge-all. Not included: query, filters and facets of GET /api/v1/products (SEARCH-4); the list keeps today's behaviour. Docs: docs/vision.md (index with prices and availability through the Cart offer port), apps/api/CLAUDE.md (index document, triggers, ProductOfferFacts, catalog:reindex in make seed), docs/features/walking-skeleton/spec.md (mapping, external_gte with a timestamp, writes during a rebuild, make seed commands), docs/features/variants/spec.md (the index contains variants).",
      "acceptance_criteria": [
        "The index document has the fields of the spec; gross, purchasable and from_gross follow the Cart offer rule (also for a product and a variant without offer facts)",
        "A price change and a stock level change reindex the product through the events transport; seed events, unknown variants and missing products are acked without reindexing",
        "An older read written after a newer one is the stale outcome (external_gte with the timestamp), shown by an integration test",
        "catalog:reindex builds the index with the offer facts; a failure of the offer facts stops the rebuild",
        "make seed ends with a full index including prices and availability before storefront:cache:purge-all",
        "Catalog depends on neither Pricing, Inventory nor Cart (Deptrac unchanged)",
        "The listed documents describe the index, its triggers and the rebuild as in the spec",
        "make check, make test and make e2e pass"
      ],
      "risk": "CRITICAL",
      "depends_on": ["SEARCH-1"]
    },
    {
      "id": "SEARCH-4",
      "title": "Full-text search, filters and facets in GET /api/v1/products",
      "scope": "apps/api Catalog, apps/api/openapi.json, store TS client, docs. Spec section „Catalog: GET /api/v1/products (wyszukiwanie, filtry, fasety)” (parameters, Zapytanie, Fasety, headers) and decisions S1, S2, S3, S8, S11. Query parameters q, option (repeated <axis>:<value>, split at the first colon), priceMin, priceMax, available, sort with their limits and 400 violations (relevance without q, priceMin > priceMax). Query: category and phrase in query (exact cross_fields AND with field weights name > category > description on stemmed and folded fields, typo branch most_fields on folded fields with fuzziness AUTO, prefix_length 1, max_expansions 20, boost 0.3), the variant condition (axes OR within an axis, AND across axes, price and availability on the same nested variant) in post_filter, sort options with name.sort and id as tie-breakers, products without a purchasable variant excluded by price and availability. Facets: categories (children of the filter with slug, path and count), options (count = total after adding the value, reverse_nested, limits, selected value kept with 0), price from purchasable variants matching the axes, availableCount. Cache-Control and xkey unchanged. OpenAPI export and the store client in the same PR. Not included: store UI (SEARCH-5, SEARCH-6). Docs: apps/api/CLAUDE.md (parameters of listProducts).",
      "acceptance_criteria": [
        "„asfalt” finds „Buty szosowe Tarvik Asfalt 200”; „kurtki” finds jackets, „spiwor” sleeping bags, „plecal” backpacks; two words with one matching nothing give no result, also with a typo",
        "A name match ranks above a description match and an exact match above a typo match",
        "„Kolor: czarne” + „Rozmiar: 42” returns only products with a black size 42 variant; price and availability apply to the same variant",
        "Every facet value count equals the total after adding the value; availableCount equals the total with available=true; the selected value stays with 0",
        "Price sorting follows from_gross with products without a price last and name, id as tie-breakers",
        "Every invalid parameter gives 400 with violations; a functional test calls the API with the address built by the generated client (repeated option)",
        "openapi.json and the store client are up to date; apps/api/CLAUDE.md describes the parameters",
        "make check and make test pass for api and store"
      ],
      "risk": "STANDARD",
      "depends_on": ["SEARCH-2", "SEARCH-3"]
    },
    {
      "id": "SEARCH-5",
      "title": "Category pages /c/, mega menu and breadcrumbs",
      "scope": "apps/store, e2e, docs. Spec sections „Sklep / Trasy i dane” (/, /c/[...path], /?category redirect, order of reads, no Next data cache), „Widok listingu” (breadcrumbs, h1 with the product count), „Nagłówek” (mega menu and its failure, without the search box), „Metadane i SEO” (category title, canonical, BreadcrumbList, empty and invalid pages, product page breadcrumb links) and decisions S4, S5 (pages without filters), S6, S7, S10. Store: /c/[...path] resolved in the category tree (404 when missing), permanentRedirect of /?category=<id> to /c/<path>, the tree read once per request (React.cache) for the layout, the metadata and the page, listing reads with cache no-store, header mega menu from the tree (desktop panel with levels 2–3, mobile Menu, keyboard and Escape, all links in the HTML, header without the menu and a server log when the tree fails), CategoryTree removed, breadcrumbs and JSON-LD BreadcrumbList on /c/, canonical /c/<path> with page, product page breadcrumb links and BreadcrumbList to /c/<path>. E2E: navigation through the mega menu or /c/ in catalog.spec.ts, price-change.spec.ts, stock-receipt.spec.ts, cart-variants.spec.ts, product-page.spec.ts and e2e/support. Not included: filters, sorting, facets UI, /szukaj and the header search box (SEARCH-6). Docs: docs/vision.md (category pages /c/<slug path> rendered with SSR and Varnish, not ISR), apps/store/CLAUDE.md (routes, order of reads, mega menu and its failure, SEO of the pages), docs/features/catalog-page-metadata/spec.md and docs/features/catalog-unknown-category/spec.md (/c/ addresses and the redirect), docs/features/product-page/spec.md (breadcrumb links, /c/ removed from out of scope).",
      "acceptance_criteria": [
        "/c/bieganie/buty shows the products of the category and its subcategories with breadcrumbs; an unknown path gives 404; /?category=<id> redirects permanently to /c/<path>",
        "The mega menu opens with the mouse and the keyboard and closes with Escape; links of all departments and levels 2–3 are in the HTML",
        "A failing category tree gives a header without the menu and the cart page still works (Vitest)",
        "/c/<path> has the canonical /c/<path> (with page above 1) and JSON-LD BreadcrumbList; the product page breadcrumb links to /c/<path>",
        "One entry makes one request per endpoint and listing reads do not use the Next data cache",
        "The listed E2E tests navigate without the removed category tree and pass",
        "The listed documents describe the routes, the menu and the SEO as in the spec",
        "make check, make test and make e2e pass"
      ],
      "risk": "STANDARD",
      "depends_on": ["SEARCH-2"]
    },
    {
      "id": "SEARCH-6",
      "title": "Search page, filters and sorting in the store",
      "scope": "apps/store, e2e, docs. Spec sections „Sklep / Trasy i dane” (/szukaj, kategoria, URL parameters, q ignored on / and /c/), „Widok listingu” (filter panel, active filters, sorting, empty results), „Nagłówek” (search box), „Metadane i SEO” (noindex follow of filters and sorting, /szukaj without canonical) and decisions S1, S2, S3, S5, S8, S9, S11. Store: header search form GET /szukaj, /szukaj with the 2-character rule and the kategoria parameter, URL parameters (q, sort, cena-od, cena-do as whole zloty converted to grosze only for digits, dostepne, repeated o, kategoria, page) mapped to the API, page removed on every filter or sort change, filter panel from facets (categories, every axis, price fields applied on Enter or blur, Tylko dostępne), active filters with removal and Wyczyść filtry, sort list, form with Pokaż wyniki without JavaScript, empty results message, invalid filter page. New shadcn/ui components as needed. E2E: search.spec.ts (header search → results → product page → add to cart; cases of acceptance criterion 1 of the spec) and listing-filters.spec.ts (filters, price, availability, sorting, clearing, noindex and canonical in the HTML, a price change in the panel changes the price filter result within 90 s). Docs: apps/store/CLAUDE.md (search page, URL parameters, filters), docs/features/catalog-page-metadata/spec.md (noindex of filters and sorting, /szukaj).",
      "acceptance_criteria": [
        "A phrase from the header leads to /szukaj with results and the product page; a phrase shorter than 2 characters shows the message without calling the product list",
        "Checkboxes and sorting navigate at once, the price on Enter or blur; every change removes page; active filters can be removed one by one and all at once",
        "Price sorting shows the tiles in the order of the „od” price with products without a price last",
        "A price change in the panel changes the price filter result within 90 s (E2E)",
        "Any filter or sort gives noindex, follow and the canonical without parameters; /szukaj has noindex, follow and no canonical; an invalid parameter shows Nieprawidłowy filtr with noindex",
        "The front computes nothing besides converting zloty to grosze; numbers, ranges and availability come from the API",
        "apps/store/CLAUDE.md and docs/features/catalog-page-metadata/spec.md describe the search page and the filters",
        "make check, make test and make e2e pass"
      ],
      "risk": "STANDARD",
      "depends_on": ["SEARCH-4", "SEARCH-5"]
    }
  ]
}
```
