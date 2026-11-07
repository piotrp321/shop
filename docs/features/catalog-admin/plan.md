# CATALOG-ADMIN – plan zadań

Specyfikacja: `docs/features/catalog-admin/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | CATALOG-ADMIN-1 | |
| 2 | CATALOG-ADMIN-2, CATALOG-ADMIN-4 | |
| 3 | CATALOG-ADMIN-3, CATALOG-ADMIN-5 | |
| 4 | CATALOG-ADMIN-6 | |
| 5 | CATALOG-ADMIN-7 | |

Stan prób zadań zgłoszonych do AO: `ao status`. Kolumnę „Status” (PR, zmergowane) uzupełnia użytkownik przy
integracji zadań fali.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "CATALOG-ADMIN",
  "spec": "docs/features/catalog-admin/spec.md",
  "tasks": [
    {
      "id": "CATALOG-ADMIN-1",
      "title": "Product status, editing rules and ProductChanged in Catalog",
      "scope": "apps/api: Catalog Domain, Application, Infrastructure (persistence, demo loader), Inventory_Infrastructure (adapter only), migration, docs. Spec sections „Catalog: domena”, „Catalog: komendy i wersja”, „Dane demo”, decisions K1, K2, K4, K5, A1, A2, A6. ProductStatus and firstPublishedAt in Product; Product::create(..., status) without the K2 check; edit/publish/unpublish with K2 (no removal of the last image of a published product that had images), K4 (SlugLocked), K5 (variant identity after first publication: VariantIdentityLocked; removal of a variant in use: VariantInUse); variant rows to ProductVariants (kept ids, new UUIDv7, UnknownVariant). Domain event ProductChanged and the integration event Catalog\\Application\\Event\\ProductChanged published by the command handlers through the outbox (no Deptrac exception). Port VariantStockFacts in Catalog_Application with an adapter in Inventory_Infrastructure over GetStockLevels. Commands CreateProduct (status, actorId, optional slug from Slug::slugify), EditProduct, PublishProduct, UnpublishProduct with expectedVersion checked before any domain rule; ProductRepository::save(Product, ?int expectedVersion, ?string actorId, DateTimeImmutable at) with updated_by and updated_at only on writes that raise the version. TypedProblem types slug-taken, slug-locked, product-modified, product-not-publishable, variant-locked, variant-in-use; Polish detail messages of these exceptions and of UnknownVariant and ImageNotFound. Migration: status (published for existing rows), first_published_at, updated_by, updated_at, partial index (name, id) WHERE status = 'draft'. Demo loader creates Published products without ProductChanged. Not included: HTTP endpoints (CATALOG-ADMIN-4), storefront reads, index and cache (CATALOG-ADMIN-2), repricing (CATALOG-ADMIN-3).",
      "acceptance_criteria": [
        "Unit tests of Product: publish of a draft without images is ProductNotPublishable; publish sets status and firstPublishedAt once; publish of a published product and unpublish of a draft change nothing and record no event; edit of a published product removing its last image is ProductNotPublishable, edit of a published product without images saves",
        "Unit tests: slug change of a never published product is accepted, after the first publication (also after unpublish) it is SlugLocked",
        "Unit tests of K5: after the first publication „czarne” → „Czarne” is accepted with the same variantId, „M” → „XL” and adding, removing or reordering an axis are VariantIdentityLocked, a new variant is accepted; in a never published draft values and axes change freely; removal of a variant reported by VariantStockFacts is VariantInUse",
        "Unit tests of the handlers: a stale expectedVersion is product-modified before any other rule (also with a removed variant and with a locked slug); rows keep ids, rows without id get new ids, a foreign variantId is UnknownVariant; an empty slug is derived from the name",
        "Each successful EditProduct, publish of a draft and unpublish of a published product raises the version and updates updated_at and updated_by and publishes the integration ProductChanged {productId, slug, occurredAt} through the outbox (integration tests of the repository and the outbox)",
        "Integration test of the migration: existing products are published with first_published_at; the VariantStockFacts adapter reports physical > 0 and reserved > 0 and ignores variants without stock records",
        "make seed creates the demo products as published, including the ones without images",
        "Deptrac passes without changes to deptrac.yaml",
        "docs/vision.md (slug of a product is fixed from its first publication), apps/api/CLAUDE.md (product status, ProductChanged as an integration event, VariantStockFacts) and docs/features/variants/spec.md (editing and removal of variants) describe the new state",
        "make -C apps/api check and make -C apps/api test pass"
      ],
      "risk": "CRITICAL",
      "depends_on": []
    },
    {
      "id": "CATALOG-ADMIN-2",
      "title": "Hide drafts in the storefront, index tombstones and storefront invalidation",
      "scope": "apps/api: Catalog (queries, search index, Infrastructure consumer), Shared (CacheTags, StorefrontCache), Pricing_Infrastructure and Inventory_Infrastructure (product directory adapters only); apps/store: load-product.ts and /api/revalidate; docs. Spec sections „Catalog: odczyty sklepu (A3)”, „Catalog: unieważnienie sklepu (A2, A4)”, decisions A3, A4. GET /api/v1/products/{slug} is 404 for a draft, 404 tagged catalog and product-slug-<slug> for a valid slug. GetVariantSummaries and ListProductVariants (default) skip variants of drafts; ListProductVariants(productIds, includeDrafts) with taxClass in ProductVariantList; the Pricing and Inventory directory adapters pass includeDrafts: true. Index: field status in ProductIndexDefinition, ProductIndexing writes every product (drafts as status draft, external_gte), catalog:reindex indexes all products, every storefront query filters status: published in query (results, total, facets). IndexProduct handles both ProductCreated (as today) and the integration ProductChanged, both through ProductIndexing::reindex. CacheTags::productSlug, StorefrontCache::invalidateTags, consumer InvalidateStorefrontOnProductChange. Store: loadProduct tags the details read with product-slug-<slug> only for a slug in the Slug format; /api/revalidate accepts product-slug-<slug>. Dashboard unchanged. Not included: admin endpoints, repricing.",
      "acceptance_criteria": [
        "A draft is not in GET /api/v1/products (results, total, facets), GET /api/v1/products/{slug} is 404, its offer is that of an unknown product, a cart line with its variant is unavailable_product and placing an order with it is 409 CartNotOrderable (functional tests)",
        "A product created as a draft is indexed as a draft document from ProductCreated and is absent from storefront results; its publication (ProductChanged) makes it appear (integration tests)",
        "Price and stock lists of the panel show the variants of a draft (functional tests of /api/v1/admin/prices and /api/v1/admin/stock)",
        "Integration tests of the index: a newer draft write followed by a late older published write is stale and the document stays draft and absent from results and facets; the reverse order (publish after unpublish) too; a repeated write of the same version is accepted without changing the document",
        "ProductChanged purges product-<id> and product-slug-<slug> in Varnish and then revalidates both tags in the store; a failure goes to the events retries (unit and integration tests)",
        "The 404 of a valid slug carries xkey catalog product-slug-<slug>; the 200 keeps catalog product-<id>",
        "Store tests: /api/revalidate accepts product-slug-<slug> and rejects other formats; loadProduct adds the slug tag only for a slug in the Slug format",
        "On a production build a slug visited as 404 shows the product page right after its publication is handled (covered end-to-end in CATALOG-ADMIN-7)",
        "docs/features/product-page/spec.md, docs/features/search/spec.md, docs/features/cart/spec.md, docs/features/inventory/spec.md, apps/api/CLAUDE.md and apps/store/CLAUDE.md describe the new state",
        "make -C apps/api check, make -C apps/api test, make -C apps/store check and make -C apps/store test pass"
      ],
      "risk": "CRITICAL",
      "depends_on": ["CATALOG-ADMIN-1"]
    },
    {
      "id": "CATALOG-ADMIN-3",
      "title": "Reprice variant prices after a tax class change",
      "scope": "apps/api: Pricing Application (loop extracted from ReconcileTax) and Infrastructure (consumer and port adapter), docs. Spec section „Pricing: przeliczenie przy zmianie klasy (K6)”, decision K6. Consumer RepriceOnTaxClassChange on the events transport for the integration ProductChanged: variants and class from Catalog ListProductVariants([productId], includeDrafts: true); every price with a class other than the product's is repriced (same gross, source tax_reconciliation, VariantPriceChanged) by the reprice-save-retry loop shared with pricing:reconcile-tax. Not included: Catalog changes.",
      "acceptance_criteria": [
        "After a tax class change the prices of the product's variants have the new rate and the same gross and a history entry with source tax_reconciliation (integration test)",
        "A → B → A with the events handled in either order, and a repeated event, end with prices in class A; handling when prices already have the current class writes nothing (unit tests)",
        "A class without a rate sends the message to failed with a message; a second version conflict throws for a retry; an unknown product is handled without changes",
        "pricing:reconcile-tax keeps its behaviour on the extracted loop (existing tests pass)",
        "docs/features/pricing/spec.md and apps/api/CLAUDE.md describe the repricing",
        "make -C apps/api check and make -C apps/api test pass"
      ],
      "risk": "CRITICAL",
      "depends_on": ["CATALOG-ADMIN-2"]
    },
    {
      "id": "CATALOG-ADMIN-4",
      "title": "Admin API for products and image upload",
      "scope": "apps/api: Catalog UI (controllers, DTO, voter), Application (queries for the panel), Infrastructure (ProductImageStorage::exists, ImageSize::Thumbnail), docker PHP and Caddy limits, openapi-admin.json; apps/admin: generated schema.d.ts; docs. Spec section „API panelu”, decisions K3, K7, K8, A5. Endpoints under /api/v1/admin: GET /products (q, category, status, cursor), GET /products/{id} (ProductEditView), POST /products, PUT /products/{id}, POST /products/{id}/publish, POST /products/{id}/unpublish, POST /product-images (multipart), GET /tax-classes. ProductVoter: VIEW_PRODUCTS for every staff member, MANAGE_PRODUCTS for admin and marketing. DTO validation with field paths for every rule listed in the spec, including duplicates by VariantValues::comparisonKey; ProductConflictProblem enum. Upload limits upload_max_filesize 64M and post_max_size 70M, Content-Length check. Not included: panel screens.",
      "acceptance_criteria": [
        "Every endpoint has functional tests: no token, customer token, staff without the role (403 on writes and upload), bad data with the exact violations list, 404, every 409 type and 503 when Garage is unavailable",
        "POST then PUT with a stale version is 409 product-modified; publish without images is 409 product-not-publishable",
        "Upload: a 5 MB JPEG is 201 with key and a thumbnail preview; SVG, text named .jpg and an image over 50 MP are 422 with a Polish detail; a duplicated image key and a missing original are 422 at images[i].key",
        "GET /products filters by status draft and published with keyset paging",
        "openapi-admin.json is exported and the admin client generated in the same PR; openapi.json is unchanged",
        "docs/vision.md (catalog edited by admin and marketing) and apps/api/CLAUDE.md (upload limits, ImageSize::Thumbnail, admin product endpoints) describe the new state",
        "make -C apps/api check, make -C apps/api test and make -C apps/admin check pass"
      ],
      "risk": "CRITICAL",
      "depends_on": ["CATALOG-ADMIN-1"]
    },
    {
      "id": "CATALOG-ADMIN-5",
      "title": "Panel: product list and product form with images",
      "scope": "apps/admin: menu, routes, src/views (ProductsView, ProductFormView), src/products (useProductList, useProductForm, image upload), tests including AppMenu.test.ts; docs. Spec section „Panel (apps/admin)” except the variant editor. List with search, category and status filters and cursor stack; form sections „Dane” and „Zdjęcia” (sequential upload with per-file status, duplicate and 10-image limits, alt, reorder, remove), actions Zapisz, Opublikuj, Wycofaj z publikacji with version, errors by type and field paths, unsaved changes guard, read-only form without MANAGE_PRODUCTS, /products/new redirects without the role. Variants are shown read-only and sent unchanged; a new product has the default variant. Not included: variant editing (CATALOG-ADMIN-6).",
      "acceptance_criteria": [
        "Vitest: useProductList (debounced search from 2 characters, filters, cursor stack); useProductForm (POST then navigation, PUT with version, 409 by type, 422 at fields, actions disabled while a request runs and with unsaved changes); upload one by one with one failing file and the 10-image limit",
        "Staff without admin or marketing sees the list and a read-only form without actions; /products/new redirects them to the list",
        "The menu has „Produkty” (AppMenu test updated)",
        "apps/admin/CLAUDE.md describes src/products and the new views",
        "make -C apps/admin check and make -C apps/admin test pass"
      ],
      "risk": "STANDARD",
      "depends_on": ["CATALOG-ADMIN-4"]
    },
    {
      "id": "CATALOG-ADMIN-6",
      "title": "Panel: variant editor",
      "scope": "apps/admin: src/products (variant editor in the product form), tests, docs. Spec section „Panel (apps/admin)”, the „Warianty” part, decision K5. Axes 0–2 and rows; default variant row; confirmation before removing an existing variant; after the first publication axes cannot be added, removed or reordered and the explanation is shown; variant-locked and variant-in-use details at the „Warianty” section; 422 at row fields. The panel does not compute the comparison key.",
      "acceptance_criteria": [
        "Vitest: adding and removing axes and rows, the default variant row, the confirmation on removing an existing variant, sending kept variantIds and null for new rows",
        "Vitest: after the first publication axis controls are disabled and the explanation is shown; 409 variant-locked and variant-in-use show the API detail; 422 points at the row field",
        "apps/admin/CLAUDE.md describes the variant editor",
        "make -C apps/admin check and make -C apps/admin test pass"
      ],
      "risk": "STANDARD",
      "depends_on": ["CATALOG-ADMIN-5"]
    },
    {
      "id": "CATALOG-ADMIN-7",
      "title": "E2E: create, publish, edit and unpublish a product from the panel",
      "scope": "e2e: new catalog-admin.spec.ts and e2e/support helpers. Spec section „Testy” (E2E) and the acceptance criteria. A product with a unique name and slug per run, sorted after the demo data; no test assumes a fixed number of products. Upload size paths over the PHP limits on the running stack.",
      "acceptance_criteria": [
        "marketing creates a product with an image and a variant and publishes it; admin sets a price and receives stock; the product page shows the name, the image and the price",
        "A name change is visible on the product page; unpublishing makes the page 404",
        "On a production build (STORE_MODE=production) the slug visited before publication is 404 and shows the product page after publication",
        "A file over upload_max_filesize and a request over post_max_size get 422 „Plik jest za duży.” from the running API",
        "support sees the list and a read-only form",
        "make e2e passes locally and the e2e job passes in CI"
      ],
      "risk": "STANDARD",
      "depends_on": ["CATALOG-ADMIN-2", "CATALOG-ADMIN-3", "CATALOG-ADMIN-6"]
    }
  ]
}
```
