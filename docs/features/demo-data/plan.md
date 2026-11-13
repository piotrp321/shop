# DEMO-DATA – plan zadań

Specyfikacja: `docs/features/demo-data/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go. Plan obejmuje część 1 epiku (#482); część 2 (#486, DEMO-LOAD) ma
własny plan, przygotowany po merge'u części 1 (spec, P11).

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | DEMO-DATA-1, DEMO-DATA-2 | |
| 2 | DEMO-DATA-3, DEMO-DATA-4, DEMO-DATA-6 | |
| 3 | DEMO-DATA-5 | |

Stan epiku prowadzonego przez AO jest w `ao status`, a plan usuwa `FINALIZE`.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "DEMO-DATA",
  "spec": "docs/features/demo-data/spec.md",
  "tasks": [
    {
      "id": "DEMO-DATA-1",
      "title": "Search index rebuild survives transient Elasticsearch errors",
      "scope": "apps/api Catalog search (spec: Przebudowa indeksu wyszukiwarki): the Elasticsearch adapter (ElasticsearchSearchIndexMaintenance) retries the bulk items reported as transient in BulkResult and transient errors of the whole bulk request (429, 5xx, timeout) with increasing backoff, up to 5 attempts, keeping version_type external_gte; permanent errors and exhausted attempts end the rebuild as today; the new index is filled with refresh_interval -1, restored before verification and alias switch; progress (documents, rate) logged per batch. ProductSearchIndexRebuilder changes minimally. docs/features/search/spec.md updated. Not: parallel or resumable rebuild, offer reads (H5 time).",
      "acceptance_criteria": [
        "A rebuild whose bulk writes fail transiently (items and whole requests) a few times finishes successfully with every document indexed (integration test on Elasticsearch with an injected failing transport or fake maintenance).",
        "Permanent item errors and transient errors that exhaust 5 attempts end the rebuild as today: the new index is discarded and the alias keeps pointing to the previous index.",
        "During the fill the new index has refresh_interval -1; after a successful rebuild the active index has the default refresh interval.",
        "The rebuild logs documents done and rate per batch.",
        "docs/features/search/spec.md describes retries and the refresh setting; make -C apps/api check and test pass."
      ],
      "risk": "STANDARD",
      "depends_on": []
    },
    {
      "id": "DEMO-DATA-2",
      "title": "DemoData module, deterministic generator core and the catalog tail",
      "scope": "apps/api: flat module src/DemoData with its own Deptrac layer (Native, Vendor, Shared_Infrastructure and the Domain layers of Catalog, Pricing and Ordering for value rules only; spec P1, P1a), fixtures/demo-data/profiles.yaml (profiles small, medium, server, full with counts and diskPeakGb estimates from the spec; one seed namespace and one fixed part size shared by all profiles), deterministic pseudo-random streams per part, --anchor (default start of the current UTC day), UUID v5 and v7 helpers (spec: Identyfikatory, czas i determinizm), COPY writer on the DBAL connection (Pdo\\Pgsql::copyFromArray, synchronous_commit off, tables keep their indexes), catalog dictionaries (about 100 leaf categories in the existing departments, about 120 product types with axes, tax class, price range and photo group, about 300 fictional brands, model names, description fragments, CREDITS.md) and step 1 (categories, products with about 3 variants on average per P3, variants with values_key, category links, drafts, imageless every 50th, slugs unique by construction with a suffix from the global product index, disjoint from catalog.yaml); step 2 for profile small only (the 37 photos from fixtures/images stored once in Garage under originals/<sha256>.<ext>, keys assigned by type group; pool download is DEMO-DATA-6); bin/generate-demo (sh, set -eu: bin/seed-demo, steps, catalog:reindex, storefront:cache:purge-all; profile, anchor and process count arguments; --force-reset in prod; parts run in parallel, each process awaited, a failing part stops the others and fails the step) tested like bin/seed-demo with a substituted console in tests/Unit/Bin, and make seed-scale in make/demo-data.mk; API tests run the demo-data steps on shop_test without bin/seed-demo and cache purge, in the test process and transaction, with the different-process-count determinism test in a separate group that cleans its tables (spec: Testy i lokalnie); demo-data:verify with the catalog checks; apps/api/CLAUDE.md (module, COPY persistence exception, outbox exception, commands). Not: prices, accounts, orders, stock (later tasks), chart.",
      "acceptance_criteria": [
        "make -C apps/api check passes with the DemoData layer depending only on Native, Vendor, Shared_Infrastructure and the Catalog, Pricing and Ordering Domain layers; no context depends on DemoData; DemoData uses no aggregate or repository.",
        "The catalog steps with profile small end with code 0 in the API tests on shop_test; two runs with the same anchor give identical generator rows in the catalog tables (checksum per table, seed rows excluded), also with a different process count.",
        "Contract tests: generated values_key and category path equal what Catalog code produces; slugs come from the Catalog Slug rule and are unique, also against catalog.yaml (verify check).",
        "A generated tail product is readable through the real store product page API, category listing and search after catalog:reindex, and through the admin product list.",
        "demo-data:verify fails with a listed violation for a published product without a variant (injected in the test) and passes on generated data.",
        "Distribution test on small: about 3 variants per product on average, about 40% single-variant products, drafts about 2%, every 50th product imageless (tolerances in the test).",
        "Script test (substituted console): a failing step and a failing parallel part stop bin/generate-demo with a non-zero code and the following steps do not run; in prod without --force-reset the script refuses.",
        "apps/api/CLAUDE.md describes the DemoData module, the COPY and outbox exceptions with the tables it writes, the test approach, bin/generate-demo and make seed-scale."
      ],
      "risk": "STANDARD",
      "depends_on": []
    },
    {
      "id": "DEMO-DATA-3",
      "title": "Generated prices with history, ended promotions and historical discount codes",
      "scope": "apps/api DemoData step 3 (spec: Realizm, Ceny; P8): a price per generated variant from the type range and a retail price ladder, net and VAT by calling the Pricing Domain rule PriceBreakdown (P1a) and the tax class rate, initial history entry (source seed) before the first possible order, about 30% of variants with a change in the last 90 days (source staff, actor from generated admin staff keys), a few dozen ended promotions (up to 1000 variants each, no overlapping periods on a variant), a pure effective-price function of variant and time (last history entry before t reduced by the promotion running at t, Pricing Domain PromotionalPrice) for use by orders, a dozen ended historical discount codes with periods matching the order history; demo-data:verify price checks. Staff actor ids are UUID v5 of staff:<n> in the profile namespace (accounts themselves are DEMO-DATA-4). Not: active promotions or codes, orders.",
      "acceptance_criteria": [
        "Every generated variant has exactly one current price and at least one history entry; prices come from the Pricing Domain rules (no copy of the rounding in DemoData).",
        "The effective-price function returns the history price and the promotional price at the right instants (unit test with boundaries).",
        "After planning a promotion through the admin API on a tail variant with a recent generated change and ended promotions, the Omnibus lowest prior price returned by Pricing equals the value expected from the generated history.",
        "Generated promotions are all ended, have at most 1000 variants, and no variant is in two promotions with overlapping periods; generated discount codes are all ended and reconstitute through the Pricing repository.",
        "demo-data:verify reports a variant without a price (injected) and passes on generated data.",
        "Determinism: two runs with the same anchor give identical pricing rows.",
        "make -C apps/api check and test pass."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "DEMO-DATA-2"
      ]
    },
    {
      "id": "DEMO-DATA-4",
      "title": "Generated customer and staff accounts in Keycloak with a safe regeneration lifecycle",
      "scope": "apps/api DemoData step 4 (spec: Konta w Keycloak, W1, P5, P9, Model zagrożeń): person dictionaries from GUS open data (first names and surnames with weights, PESEL register on dane.gov.pl; cities with valid postal codes, street names; sources and licences in CREDITS.md), deterministic person per key without Polish characters in logins (customer email <first>.<last>.<n>@example.(com|net|org) as username, staff username <first>.<last>.<n>, phone), staff role as a function of n only (cycle 10 admin / 200 support / 200 warehouse / 90 marketing per 500), account id UUID v5(namespace, customer|staff:<fingerprint>:<n>) with the fingerprint a hash of the person dictionaries and the role rule, partialImport through the Keycloak admin REST (URL, admin login default admin and password KC_BOOTSTRAP_ADMIN_PASSWORD from options or environment; realm names as options, default shop and backoffice), batches of 2000 in 4 streams, accounts disabled and without credentials; ownership: group demo-data-generated per realm created if missing (description attribute), membership assigned in the same user representation of partialImport, no user attributes; one idempotent reconciliation per run: ensure the group, collect the full member list, delete members outside the target set one by one only after reading the account groups and confirming membership (non-members skipped and reported, 404 skipped, nothing deleted without the group), import missing accounts with SKIP and check every SKIPPED result (not a member or a different id = conflict); conflicts fail the step with a list; demo-data:verify account checks (group members per realm equal the target set). API tests use temporary realms created and removed by the test and a namespace unique per test run. Not: orders.",
      "acceptance_criteria": [
        "Integration test on Keycloak with temporary realms and a unique namespace: generated customers and staff belong to demo-data-generated, have computed ids, usernames and emails in the generator format, staff roles by account number, are disabled and have no credentials; a password login and a reset-password request for a generated customer give no session and no mail.",
        "Integration test (W1): after each run (another namespace, smaller and larger count, another fingerprint, an interrupted step, a batch rejected in the middle of the import, a member count larger than one page of the member list) the group members are exactly the target set with current data.",
        "Accounts outside the group survive every run unchanged: an account with a username in the generator format and an id computed from the seed, an ordinary account and an account created like create-admin; with the group missing nothing is deleted.",
        "A username or email taken by a non-member (SKIPPED result) and an id collision (rejected batch) fail the step with the conflict listed and change no foreign account.",
        "Rerunning with the same options changes nothing (no deletes, no imports); the development realms are never touched by the tests.",
        "Every generated email ends with @example.com, @example.net or @example.org.",
        "make -C apps/api check and test pass."
      ],
      "risk": "CRITICAL",
      "depends_on": [
        "DEMO-DATA-2"
      ]
    },
    {
      "id": "DEMO-DATA-5",
      "title": "Generated order history with payments, discount usages, reservations and stock",
      "scope": "apps/api DemoData steps 5-8 (spec: Realizm Zamówienia, Historia działań, Identyfikatory, Stany po zamówieniach, Spójność, P4, P7, P8): orders over 3 years before the anchor; a global order index i with parts as contiguous index ranges, placement time a monotonic function of i (inverse of the traffic distribution with growth, seasonality by department and time of day), number R-<first sequence value + i>, customer and variants derived from i and the part stream; tail products published before placement only, Pareto popularity, 60/40 customer/guest, long-tail orders per customer from generated accounts (same person data as the account), guest emails <first>.<last>.g<n>@example.*, guest access tokens and idempotency keys of orders and payments from a secure random source (not the seed), 1-4 lines with quantities 1-3; unit price = effective price at placement (DEMO-DATA-3), promotion copies on lines in a promotion, code discount split by calling Ordering Domain LineAmounts, order numbers by Ordering Domain OrderNumber (P1a); statuses by age (no new orders), returns and paid staff cancellations with refunds settled after 14 days, timeout cancellations by system with released code usage; staff actors with roles allowed by order-management P6 (system when paid and expired); about 10% with historical codes and usage rows; amounts satisfying Ordering rules and CHECK constraints; status history; payments for paid orders (same amount); setval of the sequence after the last number; step 6 as one SQL pass: active reservations for paid orders, committed/released reservations and movements (source system, reference = reservation key) in the 90-day window with opening balance receipts (reference opening:<variantId>, source seed), final physical at least the peak of concurrently active reservations in the window, about 8% of variants without window orders at 0; demo-data:verify with every check from the spec incl. line prices (a deterministic sample of orders in medium, server and full, all in small); docs/vision.md section Dane demo (generator, profiles). Not: chart, k3d.",
      "acceptance_criteria": [
        "The steps with profile small end with code 0 in the API tests and demo-data:verify passes; it reports each violation from the spec list when injected in the test, including a line price different from the effective price.",
        "Two runs with the same anchor give identical generator rows in every table (seed rows, guest access tokens and idempotency keys excluded), also with a different process count; two runs give different tokens and idempotency keys, and a placement request rebuilt from the seed does not return an existing order.",
        "Order numbers grow with placement time without a post-load pass; the sequence is set past the last number.",
        "Line amounts, promotion copies, code discount split and order numbers come from the Ordering and Pricing Domain rules (no copies in DemoData).",
        "Every generated order with its status history, payment, stock item, price, promotion and discount code of profile small reconstitutes through its module repository without error.",
        "Real paths work on generated data: admin order list with filters, order detail with history and payment, shipping and cancelling a generated paid order through the admin API, customer order history with a token whose sub is a generated customer, price and stock history of a variant.",
        "Distribution tests on small within tolerances: guest share, statuses by age, returns and cancellations, discount codes, variants without stock; staff actors have roles allowed for each transition.",
        "No stock movement or stock item violates physical >= reserved; the last movement of every variant matches its stock.",
        "After the steps of small the outbox and scheduled queues are empty and no order mail is sent.",
        "make seed-scale PROFILE=small on the task stack ends with code 0 (the whole script on real services, result in the PR), and make seed afterwards restores the E2E data.",
        "docs/vision.md Dane demo describes the generator and the profiles; make -C apps/api check and test pass."
      ],
      "risk": "CRITICAL",
      "depends_on": [
        "DEMO-DATA-3",
        "DEMO-DATA-4"
      ]
    },
    {
      "id": "DEMO-DATA-6",
      "title": "Demo photo pool of about 400 Unsplash photos in a private versioned R2 bucket",
      "scope": "Photo pool (spec: Zdjęcia, P6, P10, U5): about 400 photos from images.unsplash.com (Unsplash License) in the tone of the current fixtures, 3-6 per product type photo group defined in DEMO-DATA-2 dictionaries, scaled to 1600 px on the longer side as JPEG; archive uploaded as immutable object demo-photos/v<N>/photos.tar to the user's private Cloudflare R2 bucket with apps/api/bin/upload-demo-photos (rclone image pinned by digest as in the chart, copyto --immutable) using the write token from the user's file outside the repo (~/.config/shop/demo-photos.env, DEMO_PHOTOS_*), never printing credentials; every file passes the Catalog upload rules (type by content, size, dimensions) before the upload; apps/api/fixtures/demo-data/photos.yaml (version, object key, files with SHA-256 (the archive is only transport), type group, alt text, source page, author, licence; no bucket address or credentials) and CREDITS; DemoData step 2 for profiles other than small downloads the archive through a separate Flysystem filesystem in config/packages/flysystem.yaml (AsyncAws S3 adapter, existing dependency) with read credentials from DEMO_PHOTOS_* variables, checks the SHA-256 of every file and the complete file set (mismatch or missing file: error without changes in Garage and the database), stores each file once in Garage, and without DEMO_PHOTOS_* fails before the first step; make seed-scale passes DEMO_PHOTOS_* from the user's file for profiles other than small. Not: changes to the 37 fixtures photos, a public archive or GitHub release.",
      "acceptance_criteria": [
        "The archive object exists in the private bucket under the key from photos.yaml, the file sums match photos.yaml, every file passes the Catalog upload rules, has type group, alt text, source, author and licence, and every product type group has 3-6 photos; the bucket has no public access (stated with the check used in the PR).",
        "Script test with a substituted docker: upload-demo-photos passes copyto --immutable and never prints credential values.",
        "Step 2 with a mismatching file checksum or a missing file fails without writing to Garage or the database (test with a local archive); with a matching archive every file is stored once under originals/<sha256>.<ext> (integration test on Garage); without DEMO_PHOTOS_* a profile other than small fails before the first step.",
        "Neither photos.yaml nor the step logs contain DEMO_PHOTOS_* values (test).",
        "Profile small keeps using fixtures/images without downloading; make -C apps/api check and test pass."
      ],
      "risk": "FAST",
      "depends_on": [
        "DEMO-DATA-2"
      ]
    }
  ]
}
```
