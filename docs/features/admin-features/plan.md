# ADMIN-FEATURES – plan zadań

Specyfikacja: `docs/features/admin-features/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | ADMIN-FEATURES-1, ADMIN-FEATURES-2, ADMIN-FEATURES-5 | |
| 2 | ADMIN-FEATURES-3, ADMIN-FEATURES-10 | |
| 3 | ADMIN-FEATURES-4, ADMIN-FEATURES-6, ADMIN-FEATURES-11, ADMIN-FEATURES-12, ADMIN-FEATURES-13 | |
| 4 | ADMIN-FEATURES-7, ADMIN-FEATURES-9 | |
| 5 | ADMIN-FEATURES-8 | |
| 6 | ADMIN-FEATURES-14 | |
| 7 | ADMIN-FEATURES-15 | |

Stan epiku prowadzonego przez AO jest w `ao status`, a plan usuwa `FINALIZE`.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "ADMIN-FEATURES",
  "spec": "docs/features/admin-features/spec.md",
  "tasks": [
    {
      "id": "ADMIN-FEATURES-1",
      "title": "Panel reads require a staff role (#408)",
      "scope": "apps/api Backoffice security (spec: 0, Model zagrożeń rows 1-2): StaffUser::getRoles() grants ROLE_STAFF only with at least one of admin, support, warehouse, marketing; security.yaml access_control lets GET /api/v1/admin/me through with IS_AUTHENTICATED only and keeps ROLE_STAFF for the rest of /api/v1/admin; ProductVoter VIEW_PRODUCTS requires a staff role; functional test over every /api/v1/admin route and method taken from the router (path parameters filled with a random UUID). apps/admin: /me with an empty role list shows the no-role message on every route (also a deep link such as /products) instead of the menu and the content, with sign-out kept. Docs: docs/features/catalog-admin/spec.md and docs/features/pricing/spec.md sentences about reads by every staff member. Not: new voters of later tasks, any new endpoint.",
      "acceptance_criteria": [
        "A backoffice token without a staff role gets 403 on every /api/v1/admin route and method from the router except GET /admin/me (200); a shop token and no token get 401; the test enumerates routes from the router, so a new route without ROLE_STAFF fails it without editing the test.",
        "Every existing role keeps its current access (existing functional tests pass unchanged).",
        "The panel shows „Twoje konto nie ma roli w panelu. Skontaktuj się z administratorem.” for /me without roles, with „Wyloguj” available (Vitest).",
        "make -C apps/api check test and make -C apps/admin check test pass."
      ],
      "risk": "CRITICAL",
      "depends_on": []
    },
    {
      "id": "ADMIN-FEATURES-2",
      "title": "Bounded result counts on panel lists",
      "scope": "apps/api: shared BoundedCount {value, capped} OpenAPI schema and counting helper (count of at most limit+1 rows of the page condition, spec: 3 Liczniki wyników, A3); total on the first page (no after) of GET /admin/products, /prices, /stock, /orders (all filters incl. refund=pending), /promotions, /discount-codes with limit 10000, total null on later pages; openapi-admin.json and the admin client regenerated. apps/admin: useCursorPager keeps the first-page total until the query changes; ListPager shows „Pokazano 1–25 z 1 234” or „z ponad 10 000”. Docs: apps/api/CLAUDE.md (bounded counts), apps/admin/CLAUDE.md (ListPager), docs/features/admin-design/spec.md footer sentence. Not: sorting, new filters, dashboard counts.",
      "acceptance_criteria": [
        "Every listed endpoint returns total on the first page and null with after; above 10000 matching rows total is {value: 10000, capped: true}; the count query reads at most 10001 rows (StatementCounter or EXPLAIN test).",
        "The pager shows the total of the first page on later pages and drops it when the query changes (Vitest).",
        "make -C apps/api check test, make -C apps/admin check test and make api-client without diff pass."
      ],
      "risk": "STANDARD",
      "depends_on": []
    },
    {
      "id": "ADMIN-FEATURES-3",
      "title": "Sorting of product, price, stock and order lists",
      "scope": "apps/api (spec: 3 Sortowanie, P6, A10): sort parameter with the closed key lists for GET /admin/products, /prices, /stock (name, -name, -updatedAt, updatedAt) and /orders (-placedAt, placedAt, -total, total); keyset cursors carrying the sort key (a cursor of another sort is 400); refund=pending rejects sort; sort=total and -total reject q (400); new indexes (updated_at, id) with the draft partial variant and (total_gross, id), (status, total_gross, id) in non-transactional migrations following the CONCURRENTLY rule from task 5 (spec: Wdrożenie). apps/admin: Select „Sortuj” in ListToolbar on the four lists, sort in the URL via useListUrlState, back to the first page on change, disallowed options disabled with a description, aria-sort on the visible sorted column. Docs: apps/admin/CLAUDE.md (list standard: sorting, the „sortowania i zaznaczania nie ma” sentence now only about selection), docs/adr/admin-ui-library.md and docs/features/admin-design/spec.md (no @tanstack/vue-table for sorting), docs/features/order-management/spec.md and docs/features/catalog-admin/spec.md (sorting). Not: order search and date range (task 4), customers sort (task 14).",
      "acceptance_criteria": [
        "Each key sorts in both directions with a stable keyset across pages (functional tests with ties on the sort column); a cursor from another sort and every disallowed combination is 400 with the field.",
        "EXPLAIN of each sorted first page uses the sort index (no Sort node over a Seq Scan) on the test database.",
        "Migrations with CREATE INDEX CONCURRENTLY run outside a transaction and OrderingSchemaTest / Catalog schema tests stay green.",
        "The Select sets sort in the URL, an external URL change applies it with one request, and the card layout of orders below 640 px offers the same sort (Vitest).",
        "make -C apps/api check test, make -C apps/admin check test and make api-client without diff pass."
      ],
      "risk": "STANDARD",
      "depends_on": ["ADMIN-FEATURES-2", "ADMIN-FEATURES-5"]
    },
    {
      "id": "ADMIN-FEATURES-4",
      "title": "Order search by fragment and date range",
      "scope": "apps/api Ordering (spec: 3 Wyszukiwanie zamówień, P8): q keeps the exact number (^[Rr]-[0-9]+$) and the exact full e-mail (Ordering EmailAddress format), other text of 3-100 characters (also with @) searches a fragment over one GIN trigram index on lower(number || U+001F || email || U+001F || full_name) (migration CONCURRENTLY) with the query condition being exactly that expression LIKE '%' || lower(fragment) || '%' escaping \\, % and _; shorter or longer other text or text with a control character is 400 at q; placedFrom and placedTo as Europe/Warsaw dates (inclusive) converted to UTC instants, placedFrom after placedTo is 400; both combine with status, q and the date sort; sort=total and refund=pending|overdue reject the date range (400). apps/admin: hint text of the order search, „Od” and „Do” date inputs in the order list toolbar, in the URL. Docs: docs/features/order-management/spec.md, apps/api/CLAUDE.md (list endpoint). Not: customers (task 14).",
      "acceptance_criteria": [
        "A fragment of a number, an e-mail (also with @, e.g. jan@gm) and a surname finds the order; % and _ in the text match literally; two characters are 400; the exact number and the full e-mail behave as before (functional tests).",
        "Results equal a per-field match for every fragment, and a fragment joining the end of one field with the start of the next finds nothing (functional test).",
        "On representative data (at least 100000 orders in the test database after ANALYZE) EXPLAIN of a number, e-mail and surname fragment shows a Bitmap Index Scan on the combined index and no Seq Scan on ordering_order (integration test).",
        "Date bounds follow Europe/Warsaw days including a DST change day (test); an inverted range is 400.",
        "The search label stays „Szukaj po numerze zamówienia lub e-mailu”, so existing E2E pass unchanged; date inputs round-trip through the URL (Vitest).",
        "make -C apps/api check test and make -C apps/admin check test pass."
      ],
      "risk": "STANDARD",
      "depends_on": ["ADMIN-FEATURES-3"]
    },
    {
      "id": "ADMIN-FEATURES-5",
      "title": "Stock record for every variant and the post-rollout backfill",
      "scope": "apps/api (spec: Wdrożenie; 1 Rekord stanu każdego wariantu, A9): Catalog integration event ProductCreated in Catalog\\Application\\Event translated like ProductChanged, existing subscribers of the domain ProductCreated (IndexProduct) moved to it; Inventory consumer of the integration ProductCreated and ProductChanged reading the product variants through the existing Inventory → Catalog port and upserting 0/0 records with INSERT … ON CONFLICT (variant_id) DO UPDATE SET product_id = excluded.product_id WHERE product_id IS NULL, and deleting records of removed variants only WHERE physical = 0 AND reserved = 0; nullable column inventory_stock_item.product_id; partial indexes (product_id) WHERE physical - reserved = 0 and BETWEEN 1 AND 5 with a schema test binding the predicate to AvailabilityLevel::LOW_STOCK_THRESHOLD; the migration rule (schema only, CREATE INDEX CONCURRENTLY IF NOT EXISTS after dropping an INVALID index of that name, non-transactional) written to apps/api/CLAUDE.md; command inventory:stock-records:sync (batches by product through the Catalog port, idempotent); command app:backfill (Shared UI) running the registered backfill steps in order and recording each in app_backfill (name PK, completed_at), skipping completed ones unless --force; Job backfill in infra/k8s/charts/shop after waitRollout of api and worker; bin/seed-demo runs app:backfill --force at the end; inventory:seed-demo creates records with product_id for every variant. Docs: apps/api/CLAUDE.md (Inventory section, seed steps, migration rule, backfill), docs/features/inventory/spec.md, infra/k8s/CLAUDE.md (Job backfill). Not: the level filter and the dashboard queues (tasks 6, 7); the read models (tasks 8, 14 add their steps to app:backfill).",
      "acceptance_criteria": [
        "After a product with variants is created or edited, every variant has a record with product_id, also when a receipt created the record before the event; a removed variant loses a 0/0 record and keeps a record with stock; movements stay; a redelivered event changes nothing (integration tests).",
        "inventory:stock-records:sync leaves no variant without a record and no record without product_id on a database with older data, and a second run changes nothing; app:backfill skips completed steps and --force reruns them (integration tests, success and failure path of a step).",
        "A migration retried after a failed CREATE INDEX CONCURRENTLY (INVALID index left) succeeds (migration test); the partial index predicates match the AvailabilityLevel threshold (schema test fails when the constant changes alone).",
        "The Job backfill renders after the rollout wave (make -C infra/k8s check, smoke test unchanged).",
        "make -C apps/api check test pass; make seed creates a record for every demo variant."
      ],
      "risk": "CRITICAL",
      "depends_on": []
    },
    {
      "id": "ADMIN-FEATURES-6",
      "title": "List filters for the dashboard links: stock level, products without images, overdue refunds",
      "scope": "apps/api (spec: 1 Filtry list z pulpitu): GET /admin/stock?level=out_of_stock|low_stock paged by Inventory on product_id with its own keyset cursor from the partial indexes, names and variant labels from Catalog through the port, matchesLevel on variant rows, level excludes q, category, sort and productId (400); GET /admin/products?images=none with a new partial index catalog_product (id) WHERE images = '[]'::jsonb (CONCURRENTLY), combined with q, category, status; GET /admin/orders?refund=overdue as a subset of refund=pending with the same sort and cursor; totals as in task 2. apps/admin: „Poziom” Select on the stock list (clears and disables search and category with the description), „Zdjęcia” Select on the product list, „Tylko po terminie” switch on refunds, all in the URL. Docs: docs/features/inventory/spec.md, docs/features/catalog-admin/spec.md, docs/features/order-management/spec.md, apps/admin/CLAUDE.md. Not: dashboard (task 7).",
      "acceptance_criteria": [
        "level returns exactly the products with a variant at that level, pages stably by product_id, and rejects q, category, sort and productId with 400 (functional tests).",
        "images=none and refund=overdue return exactly the matching rows and combine as specified; EXPLAIN uses the partial indexes.",
        "Each filter round-trips through the URL and the level filter disables search and category with the description (Vitest); the inventory E2E file stays green.",
        "make -C apps/api check test and make -C apps/admin check test pass."
      ],
      "risk": "STANDARD",
      "depends_on": ["ADMIN-FEATURES-3", "ADMIN-FEATURES-5"]
    },
    {
      "id": "ADMIN-FEATURES-7",
      "title": "Dashboard queues with bounded counts and the estimated product count",
      "scope": "apps/api (spec: 1 Kolejki, Granica modułów pulpitu, A1, A3): GetDashboard composes queues through new Backoffice ports OrderQueues and StockQueues with query.bus adapters to new GetOrderQueues (Ordering) and GetStockQueues (Inventory), Deptrac edges Backoffice_Infrastructure → Ordering_Application and Inventory_Application; each queue a BoundedCount with limit 1000 or null by the owner context voter (VIEW_ORDERS, COMPLETE_REFUND, new VIEW_PENDING_PAYMENTS in OrderVoter for admin and support, MANAGE_STOCK, MANAGE_PRODUCTS); catalog.productCount from pg_class.reltuples with estimated: true, null without an estimate and never count(*); productsWithoutImages moved to queues; ANALYZE catalog_product at the end of catalog:seed-demo and as an app:backfill step; system.searchIndex.inSync with the 1% tolerance and null without an estimate. apps/admin: KpiTile estimated marker and „—” with the description, „Kategorie” tile linked to /categories, „Bez zdjęć” to /products?images=none, TodoCard rows with counts and the new rows, „1000+”, „Stan systemu” text without an estimate. Docs: apps/api/CLAUDE.md, apps/admin/CLAUDE.md (dashboard), docs/features/admin-design/spec.md (dashboard). E2E: e2e/tests/staff-dashboard.spec.ts for admin, support, warehouse, marketing. Not: sales (task 8), the categories screen itself (task 13; the tile link lands there).",
      "acceptance_criteria": [
        "Each role sees exactly its queues and null for others (functional test per role); counts above 1000 are {value: 1000, capped: true}.",
        "EXPLAIN of every queue query has no Seq Scan on ordering_order, inventory_stock_item or catalog_product; productCount runs no count query, including when reltuples is negative (productCount: null).",
        "The dashboard shows counts, „1000+”, the estimate marker and „—” without an estimate; every tile and TodoCard row links to its filtered list (Vitest, staff-dashboard E2E).",
        "make -C apps/api check test, make -C apps/admin check test and make api-client without diff pass."
      ],
      "risk": "STANDARD",
      "depends_on": ["ADMIN-FEATURES-1", "ADMIN-FEATURES-2", "ADMIN-FEATURES-6"]
    },
    {
      "id": "ADMIN-FEATURES-8",
      "title": "Sales summary from hourly pre-aggregation",
      "scope": "apps/api Ordering (spec: 1 Sprzedaż, A2, P1, P2, P5): table ordering_sales_hour (hour_start, shard 0-15, order_count, gross) with DBAL upsert inside the MarkOrderPaid transaction only for the paid result (exception written to apps/api/CLAUDE.md); GetSalesSummary for today, 7 and 30 days over completed hours with equal-length comparison periods (today vs yesterday by Europe/Warsaw clock hours, 168 and 720 rolling hours), revenue, count, average and change in basis points with the null rules; command ordering:read-models:rebuild (sales part: hour by hour, target rows created if missing and locked FOR UPDATE before counting the orders of that hour, written in the same transaction, so it is correct alongside live payments) registered as an app:backfill step and run by bin/seed-demo after ordering:seed-demo; ordering:seed-demo truncates the table; VIEW_SALES attribute of OrderVoter (admin) and the SalesSummary Backoffice port in the dashboard. apps/admin: three sales KpiTiles with the change label, „Dane do <godzina>” and „Pierwsze dane po 1:00”. Docs: apps/api/CLAUDE.md (seed steps, rebuild, exception), apps/admin/CLAUDE.md. E2E: sales tiles for admin in staff-dashboard.spec.ts. Not: customers read model (task 14).",
      "acceptance_criteria": [
        "A paid order adds exactly once; a repeated webhook, alreadyPaid, a refused payment and a rolled-back webhook add nothing (functional and integration tests).",
        "Periods end at the start of the current hour; between 0:00 and 1:00 today is null; comparison periods have equal length, tested at a Europe/Warsaw day boundary and on both DST change days.",
        "ordering:read-models:rebuild yields the same table as the incremental writes on the same orders, also when run concurrently with payments (concurrency test with two processes), and can be run twice with the same result.",
        "Only admin gets sales (null for other roles); the panel shows the tiles only with sales (Vitest, E2E).",
        "make -C apps/api check test, make -C apps/admin check test and make api-client without diff pass."
      ],
      "risk": "CRITICAL",
      "depends_on": ["ADMIN-FEATURES-5", "ADMIN-FEATURES-7"]
    },
    {
      "id": "ADMIN-FEATURES-9",
      "title": "Prices and stock card in the product form",
      "scope": "apps/api (spec: 2): productId filter on GET /admin/prices and /admin/stock returning one product (drafts too) with next null, an unknown product as an empty list, productId excluding q, category, level, sort and after (400). apps/admin: price and stock dialogs (change price, receive, correct, histories) moved to shared components in src/prices and src/inventory used by both lists and the new „Ceny i stany” FormSection in ProductFormView for saved products, actions by role, saving in a dialog not touching the form dirty state, „Bez ceny” badge and the warning Alert, the toast after creating a product, the note for unsaved variant rows, re-read after saving the product. Docs: apps/admin/CLAUDE.md (products, prices, inventory), docs/features/pricing/spec.md, docs/features/inventory/spec.md. Not: list behaviour changes.",
      "acceptance_criteria": [
        "productId returns the product with all its variants, an empty list for an unknown product and 400 with any excluded parameter (functional tests).",
        "The card shows saved variants with price and stock, offers actions only to the roles from the spec, and a dialog save keeps unsaved form fields and dirty unchanged (Vitest).",
        "Price and stock lists keep their behaviour with the shared dialogs (existing Vitest and admin-screens E2E pass).",
        "make -C apps/api check test, make -C apps/admin check test and make api-client without diff pass."
      ],
      "risk": "STANDARD",
      "depends_on": ["ADMIN-FEATURES-6"]
    },
    {
      "id": "ADMIN-FEATURES-10",
      "title": "Category management API with redirects",
      "scope": "apps/api Catalog (spec: 4 Reguły, Przekierowania, P9, P10): columns position (default 0) and version (default 1) added by a schema-only migration; sibling order position, then pl_PL name (Collator), then id in CategoryTreeBuilder, so with all positions 0 after deployment and after make seed the store order is exactly today's; create, move and reorder (under the tree lock) first renumber the target siblings 0, 1, 2… in the current order and then place the category; commands CreateCategory (end of siblings), ReviseCategory, MoveCategory (also reorder), DeleteCategory with version checks, depth limit 4 (category-too-deep), cycle (category-cycle), only empty delete (category-not-empty with BoundedCount counts), all under a Catalog advisory transaction lock, subtree path update by one DBAL UPDATE (exception in apps/api/CLAUDE.md) and identity map refresh; MANAGE_CATEGORIES voter (admin, marketing); admin endpoints POST /admin/categories, PUT /admin/categories/{id}, POST /admin/categories/{id}/move, DELETE /admin/categories/{id}, GET /admin/categories?withCounts=true (direct product counts, BoundedCount 1000); catalog_category_redirect with FK ON DELETE CASCADE and the restore rule; public GET /api/v1/categories/redirect?path= (1-4 CategorySlug segments, otherwise 400; security.yaml PUBLIC_ACCESS, Cache-Control public max-age=60, tag catalog) with the Varnish rule and a .vtc test in infra/varnish; integration event CategoryTreeChanged in the outbox and a consumer purging and revalidating the catalog tag. Docs: apps/api/CLAUDE.md, docs/vision.md (category order in the mega menu, category redirects with 308 in the SEO section). Not: reindexing (task 11), store redirect (task 12), panel screen (task 13).",
      "acceptance_criteria": [
        "Each command enforces its rule with the specified 409 type; a stale version is 409; two concurrent moves that would form a cycle end with one success and one 409 (concurrency test with two processes).",
        "A slug change and a move record redirects for the category and its descendants; the redirect endpoint returns the current path after further changes, 404 after delete and after a path is reused by an existing category; it is publicly reachable and cached by Varnish (functional test and .vtc).",
        "The public and panel tree keep today's order after the migration and after make seed (test comparing with the name order; existing store E2E unchanged); the first manual reorder moves only the chosen category and keeps the relative order of the others; a tree change purges and revalidates the catalog tag once per event (integration test with the storefront cache double).",
        "Roles: support and warehouse get 403 on every write before body validation (functional tests).",
        "make -C apps/api check test, make api-client without diff and the Varnish tests pass."
      ],
      "risk": "CRITICAL",
      "depends_on": ["ADMIN-FEATURES-1", "ADMIN-FEATURES-2"]
    },
    {
      "id": "ADMIN-FEATURES-11",
      "title": "Jobs transport and the batch chain reindexing category subtrees",
      "scope": "apps/api (spec: 4 Odświeżenie sklepu i reindeksacja, Z1): Doctrine transport jobs (queue_name=jobs) in messenger.yaml, consumed by the worker in infra/docker-compose.yml and in infra/k8s/charts/shop (command and liveness check); table catalog_category_reindex (id, category_id, cursor, next_batch, finished_at) created by the CategoryTreeChanged consumer for rename and move together with the first ReindexCategoryBatch message; handler in one command.bus transaction: SELECT … FOR UPDATE on the run row, batch compared with next_batch (other or finished: no-op), ids of the next 500 products from the search index (term category_ancestor_ids = category, sort by id, search_after = cursor; no new SQL indexes), ProductIndexing for each, cursor and next_batch saved and the next message dispatched in the same transaction; the empty batch sets finished_at and invalidates the catalog tag again; jobs retries 5 times with backoff, then failed with an error log, the run stays unfinished and catalog:reindex repairs the index; catalog:seed-demo clears catalog_category_reindex and the jobs queue. The chain pattern documented in apps/api/CLAUDE.md for work triggered by a domain change and run by the worker (deliberately run commands such as catalog:reindex and cart:purge-expired keep their patterns). Not: create and delete do not reindex; no backoffice_job (part 2).",
      "acceptance_criteria": [
        "A rename and a move reindex exactly the products of the subtree (main and additional categories) with the new category_names and category_ancestor_ids (integration test on Elasticsearch).",
        "Two concurrent deliveries of the same batch message process it once and dispatch one next message (concurrency test with two processes); a delivery after finished_at changes nothing.",
        "The worker in compose and in the chart consumes jobs (make -C infra/k8s check passes, the smoke test unchanged).",
        "make -C apps/api check test pass."
      ],
      "risk": "CRITICAL",
      "depends_on": ["ADMIN-FEATURES-5", "ADMIN-FEATURES-10"]
    },
    {
      "id": "ADMIN-FEATURES-12",
      "title": "Store follows category redirects",
      "scope": "apps/store (spec: 4 Przekierowania): /c/[...path] outside the tree asks GET /api/v1/categories/redirect through API_CATALOG_URL and answers permanentRedirect (308) to /c/<path> keeping the query parameters; 404 of the redirect keeps notFound(); an API error behaves as the tree error today; the store client regenerated from openapi.json. Docs: apps/store/CLAUDE.md (category redirects and tree order). E2E: rename a category slug through the panel API and follow the old address to the new one. Not: API changes.",
      "acceptance_criteria": [
        "An old category address redirects with 308 to the current one and keeps query parameters; an unknown address is 404 (store tests and E2E).",
        "make -C apps/store check test and make e2e pass."
      ],
      "risk": "STANDARD",
      "depends_on": ["ADMIN-FEATURES-10"]
    },
    {
      "id": "ADMIN-FEATURES-13",
      "title": "Categories screen in the panel",
      "scope": "apps/admin (spec: 4 Ekran „Kategorie”): route /categories in the menu Sklep › Katalog › Kategorie, tree on Reka UI Tree with name, slug and BoundedCount from withCounts=true, node actions by MANAGE_CATEGORIES (add subcategory, edit, move to with CategoryCombobox and the root option and the subtree disabled, up, down, delete with ConfirmDialog), native HTML5 drag and drop as a shortcut, „Dodaj kategorię”, error handling per 409 type with the version conflict refresh, toasts with the redirect note; useCategoryTree keeps the tree without counts for list filters. Docs: apps/admin/CLAUDE.md (categories module, menu). E2E: create, rename, move and delete an empty category; axe at 1280 and 768 px and 320 px reflow; keyboard-only path. Not: API changes.",
      "acceptance_criteria": [
        "Every action works by keyboard and screen-reader menu alone; drag and drop gives the same commands (Vitest for the composable, E2E).",
        "Each 409 type shows its message at the field or in the dialog; a version conflict refreshes the tree.",
        "No axe WCAG 2.2 A/AA violations at 1280 and 768 px, no horizontal scroll at 320 px (E2E).",
        "make -C apps/admin check test and make e2e pass."
      ],
      "risk": "STANDARD",
      "depends_on": ["ADMIN-FEATURES-10"]
    },
    {
      "id": "ADMIN-FEATURES-14",
      "title": "Customers read model and API",
      "scope": "apps/api Ordering (spec: 5 Model odczytu, API, A4, P11): table ordering_customer with indexes (trigram CONCURRENTLY), DBAL upsert in the PlaceOrder transaction only for a new order (not the idempotent replay) with the contact guarded by last_order_at, and in MarkOrderPaid for the paid result; ordering:read-models:rebuild extended to customers (batches of customers, target rows locked FOR UPDATE before counting, correct alongside live orders); ordering:seed-demo truncates it; VIEW_CUSTOMERS attribute of OrderVoter (admin, support); GET /admin/customers (q fragment 3-100, sort -lastOrderAt, name, -orderCount, -paidGross, q only with -lastOrderAt, BoundedCount total), GET /admin/customers/{customerId} (contact, summary, up to 10 distinct addresses from the last 50 orders), GET /admin/orders?customerId= (VIEW_ORDERS, rejects q, sort and date range), customerId in the panel order details. Docs: apps/api/CLAUDE.md, docs/vision.md (customers in the panel from the Ordering read model), docs/features/order-management/spec.md, docs/features/demo-data/spec.md (generator calls ordering:read-models:rebuild and ANALYZE and creates a stock record for every variant). Not: panel (task 15).",
      "acceptance_criteria": [
        "The read model matches the orders after rebuild, after a rebuild run concurrently with placements and payments (concurrency test with two processes) and after further placements (including idempotent replays, which change nothing) and payments; concurrent placements of one customer keep the newest contact (integration tests).",
        "List search, sorts, cursors, total, card and addresses behave as specified; support and admin get 200, warehouse and marketing 403 (functional tests per role).",
        "orders?customerId returns only that customer's orders and rejects the excluded parameters with 400.",
        "make -C apps/api check test and make api-client without diff pass."
      ],
      "risk": "CRITICAL",
      "depends_on": ["ADMIN-FEATURES-4", "ADMIN-FEATURES-8"]
    },
    {
      "id": "ADMIN-FEATURES-15",
      "title": "Customers screens in the panel",
      "scope": "apps/admin (spec: 5 Panel): menu Sklep › Klienci with routes /customers and /customers/:customerId guarded by canViewCustomers; list on the list standard (search, sort Select, total, URL state); customer card in the order detail layout (Kontakt, Podsumowanie, Adresy, the customer's order list; sidebar first in the DOM like the order details); „Karta klienta” link in the order details only with customerId and canViewCustomers. Docs: apps/admin/CLAUDE.md (customers module, menu, router guards), docs/features/admin-design/spec.md (customer card in the focus-order exceptions), e2e/CLAUDE.md shard weights after the epic's new E2E files. E2E: support opens a customer from an order and back; axe at 1280 and 768 px, 320 px reflow; warehouse is denied. Not: API changes.",
      "acceptance_criteria": [
        "admin and support reach the list and the card; other roles land on the dashboard with the denied message (Vitest for the guard, E2E).",
        "The order details link to the customer card only for a customer order and roles with access.",
        "No axe WCAG 2.2 A/AA violations at 1280 and 768 px, no horizontal scroll at 320 px; E2E shard weights rebalanced.",
        "make -C apps/admin check test and make e2e pass."
      ],
      "risk": "STANDARD",
      "depends_on": ["ADMIN-FEATURES-14"]
    }
  ]
}
```
