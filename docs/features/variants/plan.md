# VARIANTS – plan zadań

Specyfikacja: `docs/features/variants/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | VARIANTS-1 | |
| 2 | VARIANTS-2, VARIANTS-3 | |
| 3 | VARIANTS-4 | |
| 4 | VARIANTS-5 | |

Stan prób zadań zgłoszonych do AO: `ao status`. Kolumnę „Status” (PR, zmergowane) uzupełnia użytkownik przy
integracji zadań fali.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "VARIANTS",
  "spec": "docs/features/variants/spec.md",
  "tasks": [
    {
      "id": "VARIANTS-1",
      "title": "Product variants in Catalog with a data-keeping migration",
      "scope": "apps/api Catalog only. Domain: VariantId, VariantAxes, VariantValues, ProductVariant, ProductVariants in the Product aggregate with the invariants (1-100 variants, 0-2 axes, values per axis, unique trimmed case-insensitive combination DuplicateVariantValues, default variant without axes), label(), key function. CreateProduct command with variantAxes and variants, VariantAlreadyExists from the primary key violation of catalog_product_variant. Persistence: catalog_product.variant_axes jsonb, table catalog_product_variant with option_values jsonb and generated values_key (lower(option_values::text)) STORED with unique (product_id, values_key) and (product_id, position), ProductVariantRecord, DoctrineProductRepository mapping, DbalCatalogReset clears variants. New migration: one default variant per existing product with id = product id. New queries GetVariantSummaries, GetVariant, ListProductVariants (with variantAxes and labels), ListVariantIdentities (variantId, key, taxClass). Demo expander: every demo product gets one default variant with UUID v5 of variant:<slug>/standard (catalog.yaml unchanged in shape; axes come in VARIANTS-5) and rejects a repeated key. Spec sections „Catalog” (Model domenowy, Persystencja, Migracja a dane demo, Zapytania) and „Stan przejściowy”. Not included: removing old Catalog queries (each goes with its last consumer), Pricing, Inventory, Cart, store, admin, demo axes.",
      "acceptance_criteria": [
        "Unit tests written first (RED, then GREEN): Product variant invariants incl. duplicate combinations after trimming and with different case (also Polish letters), 0-2 axes, 1-100 variants, default variant; label; key",
        "Integration: mapping and cascade of variants; the unique (product_id, values_key) index rejects direct SQL with a combination differing only in case, also for Polish letters; VariantAlreadyExists from the primary key violation; doctrine:schema:validate and the migration diff show no differences",
        "Migration test on a database with products from before the change: every product has one default variant with the product's id, position 0, no values; no other data changed",
        "GetVariantSummaries, GetVariant, ListProductVariants and ListVariantIdentities return the shapes from the spec with one SQL query per call (100 ids); tests use variant ids different from product ids",
        "make seed gives every demo product exactly one default variant; existing make e2e passes unchanged",
        "make -C apps/api check and make -C apps/api test pass"
      ],
      "risk": "CRITICAL",
      "depends_on": []
    },
    {
      "id": "VARIANTS-2",
      "title": "Prices and price history per variant, admin prices by variant",
      "scope": "apps/api Pricing: VariantId in the price aggregate (ProductPrice -> VariantPrice), PriceSnapshot, history and ports; migration renaming pricing_product_price to pricing_variant_price and product_id to variant_id in both tables with values kept; SetVariantPrice with Catalog GetVariant and VariantNotFound (404); GetCurrentPrices for 1-4800 variant ids in one query; ListBackofficePrices as products of SearchBackofficeProducts with their variants (ListProductVariants) and prices; GetPriceHistory by variant; reconcile-tax and pricing:seed-demo through ListVariantIdentities and the variant key (fixtures/prices.yaml patterns matching <slug>/standard); public GET /api/v1/prices in the transitional mode of spec section „Stan przejściowy”; admin endpoints PUT /api/v1/admin/variants/{variantId}/price, GET /api/v1/admin/variants/{variantId}/price-history, GET /api/v1/admin/prices with products and variants; removal of Catalog GetProductTaxClass and ListProductsForPricing; openapi export and generated clients. apps/admin: prices view with a row per variant under its product and a „Wariant” column, price change and history on the variant row. Docs: docs/features/pricing/spec.md (variant key, new endpoints, transitional /prices), apps/api/CLAUDE.md (pricing seed and reconcile-tax by variant), apps/admin/CLAUDE.md (prices list). Spec sections „Pricing”, „API” (panel), „Panel”, „Stan przejściowy”. Not included: Inventory, Cart, the store, removal of /prices (VARIANTS-4).",
      "acceptance_criteria": [
        "Every price and price history entry keeps its id and points to the default variant after the migration (migration test on data from before the change)",
        "Price changes, version conflicts, history and reconcile work per variant; unknown variant gives 404; tests use variant ids different from product ids",
        "GetCurrentPrices returns prices of 4800 variants with one query",
        "GET /api/v1/prices keeps its product contract in the transitional mode: a product with exactly one variant gets that variant's current price, a product with more variants is omitted",
        "Admin lists products of a page with a row per variant and changes the price of one variant; functional tests cover 200, 400/422, 401, 403, 404 and 409 of every changed endpoint",
        "pricing:seed-demo prices every demo variant; a variant without a pattern stops the seed without changes",
        "docs/features/pricing/spec.md, apps/api/CLAUDE.md and apps/admin/CLAUDE.md describe the variant key",
        "make -C apps/api check, make -C apps/api test, make -C apps/admin check, make -C apps/admin test and make e2e pass"
      ],
      "risk": "CRITICAL",
      "depends_on": ["VARIANTS-1"]
    },
    {
      "id": "VARIANTS-3",
      "title": "Stock, reservations and stock history per variant, admin stock by variant",
      "scope": "apps/api Inventory: VariantId in StockItem, Reservation lines, StockMovement and ports; migration renaming product_id to variant_id in inventory_stock_item, inventory_reservation_line and inventory_stock_movement with values kept; ReceiveStock, CorrectStock, CreateEmptyStock by variant with Catalog GetVariant and VariantNotFound (404); lockMany by ascending variant_id; ReserveStock lines {variantId, quantity}; GetStockLevels for 1-4800 variant ids in one query with level; ListBackofficeStock as products with their variants; GetStockHistory by variant; inventory:seed-demo through ListVariantIdentities, receipt key seed:<variantId>, fixtures/stock.yaml patterns matching <slug>/standard; public GET /api/v1/availability in the transitional mode of spec section „Stan przejściowy”; admin endpoints under /api/v1/admin/variants/{variantId}/stock/receipts, /stock/corrections, /stock-history and GET /api/v1/admin/stock with products and variants; removal of Catalog GetProductExists and ListProductIdentities; openapi export and generated clients; e2e stock-receipt.spec.ts finding the row by product name and variant. apps/admin: stock view with a row per variant and a „Wariant” column, receipt, correction and history on the variant row. Docs: docs/features/inventory/spec.md, apps/api/CLAUDE.md (lockMany, inventory seed, receipt idempotency „for another variant”), apps/admin/CLAUDE.md (stock list). Spec sections „Inventory”, „API” (panel), „Panel”, „Stan przejściowy”. Not included: Pricing, Cart, the store, removal of /availability (VARIANTS-4).",
      "acceptance_criteria": [
        "Every stock record, reservation line, stock movement and receipt key keeps its id and points to the default variant after the migration (migration test on data from before the change)",
        "Concurrency criteria C1-C6 pass on variants; tests use variant ids different from product ids",
        "Receipts, corrections, reservations and history work per variant; unknown variant gives 404; functional tests cover 200/201, 400, 401, 403, 404, 409, 415 and 422 of every changed endpoint",
        "GetStockLevels returns levels of 4800 variants with one query",
        "GET /api/v1/availability keeps its product contract in the transitional mode: a product with exactly one variant gets that variant's level, a product with more variants gets out_of_stock",
        "inventory:seed-demo gives every demo variant a stock record; a variant without a pattern stops the seed without changes",
        "docs/features/inventory/spec.md, apps/api/CLAUDE.md and apps/admin/CLAUDE.md describe the variant key",
        "make -C apps/api check, make -C apps/api test, make -C apps/admin check, make -C apps/admin test and make e2e pass"
      ],
      "risk": "CRITICAL",
      "depends_on": ["VARIANTS-1"]
    },
    {
      "id": "VARIANTS-4",
      "title": "Cart lines per variant and product offers with a variant choice on the tile",
      "scope": "apps/api Cart: CartLine by VariantId, migration renaming cart_line.product_id to variant_id with unique (cart_id, variant_id) and values kept; ports ProductCatalog::summaries (Catalog GetVariantSummaries) and variantsOf (ListProductVariants), CartPrices, StockAvailability with level; CartView lines with variantId and product {productId, name, variantLabel, image}; limit messages „…jednej pozycji”, „…50 różnych pozycji”; cart endpoints with {variantId} in paths and body and the variantId violation messages; GetProductOffers with ProductOfferSummary in Cart_Domain using ProductOffer::purchasability, GET /api/v1/product-offers (public, max-age=60); removal of GET /api/v1/prices, GET /api/v1/availability with their transitional mode and Catalog GetProductSummaries; openapi export and generated clients. apps/store: loadCatalog with one product-offers call, ProductTile server part (name, photo, from price, product level, E3 unavailable tile) and client part (variant choice per D8, price and label of the chosen variant, „Do koszyka”), cart BFF /api/cart/items/[variantId] and POST {variantId, quantity}, CartPage variant label. e2e: cart-guest, cart-merge and the two-tab test choose a variant when the tile has a choice. Docs: docs/features/cart/spec.md (variant key, offers, limit messages), apps/api/CLAUDE.md (cart lines by variant), apps/store/CLAUDE.md (product-offers, [variantId]). Spec sections „Cart”, „Oferta produktów listy”, „API” (sklep), „Sklep”, „Scenariusze błędów”. Not included: demo data axes (VARIANTS-5), product page, images per variant.",
      "acceptance_criteria": [
        "Every cart line keeps its id and points to the default variant after the migration (migration test on data from before the change); existing carts work",
        "Unit tests written first (RED, then GREEN) for ProductOfferSummary: variants without a price, with an outdated price and with available = 0 are left out, equal and different prices, no purchasable variant gives fromGross null and out_of_stock, best level",
        "No second implementation of the purchase rule in Cart: offers use ProductOffer::purchasability",
        "GET /api/v1/product-offers answers 400 for none, too many or invalid ids and 200 with one item per requested id (repeated id once), currency PLN, labels and variantAxes, never stock counts; GetProductOffers calls each port once",
        "Cart limits are 50 variants and 99 units of one variant, merge sums the same variant, two variants of one product are two lines; concurrency criteria K1-K8 pass on variants; tests use variant ids different from product ids",
        "GET /api/v1/prices and GET /api/v1/availability are gone from the API and openapi.json",
        "Vitest: tile with one variant, with two or more variants (no default choice, unavailable option disabled, chosen price and label, button active after the choice), from price, unavailable product without price, list and button, failed offers call; cart line with the variant label; BFF with variantId",
        "docs/features/cart/spec.md, apps/api/CLAUDE.md and apps/store/CLAUDE.md describe the variant key and the offers",
        "make -C apps/api check, make -C apps/api test, make -C apps/store check, make -C apps/store test and make e2e pass"
      ],
      "risk": "CRITICAL",
      "depends_on": ["VARIANTS-2", "VARIANTS-3"]
    },
    {
      "id": "VARIANTS-5",
      "title": "Demo catalog with variant axes, per-variant demo prices and stock",
      "scope": "apps/api: fixtures/catalog.yaml with variantAxes and axisValues per template (table in spec section „Dane demo”, products = brand × model, names and slugs without colour, Hantle regulowane without axes), DemoCatalogExpander and definition parsing for axes and values with variant ids UUID v5 of variant:<key>, fixtures/prices.yaml (different prices between variants at least for Przewodnik, Mapa turystyczna and Danie liofilizowane) and fixtures/stock.yaml (all variants of Kajak turystyczny Vilmar Rzeka 2 at 0, a Hantle regulowane product above 0, a product with variants at 0 and above 0, comments for the e2e tests). e2e: price-change.spec.ts on a Hantle regulowane product, stock-receipt.spec.ts on Kajak turystyczny Vilmar Rzeka 2 with a receipt for the variant „zielony”, a new test where a guest adds two sizes of one product and the cart has two lines with labels. Docs: docs/vision.md section „Dane demo” (about 200 products, about 1100 variants), docs/features/walking-skeleton/spec.md (catalog.yaml with axes instead of colours in the product). Spec sections „Dane demo” and „Testy” (E2E). Not included: code of Pricing, Inventory, Cart or the store beyond the fixtures and e2e.",
      "acceptance_criteria": [
        "make seed loads 191 demo products with the axes of the spec table, about 1100 variants, every variant with a price and a stock record",
        "The expander rejects a repeated variant key; a unit test covers it",
        "The demo data has the stock and price cases required by the spec, with comments in stock.yaml naming the products used by e2e",
        "price-change.spec.ts: the admin changes the price of a Hantle regulowane product (one row in the panel) and the tile shows the new amount without „od”",
        "stock-receipt.spec.ts: Kajak turystyczny Vilmar Rzeka 2 shows „Niedostępny”, a warehouse receipt for the variant „zielony” makes the tile show „Dostępny”",
        "New e2e: a guest chooses two sizes of one product on the tile and the cart has two lines with the variant labels",
        "docs/vision.md and docs/features/walking-skeleton/spec.md describe the demo data with variants",
        "make -C apps/api check, make -C apps/api test and make e2e pass"
      ],
      "risk": "STANDARD",
      "depends_on": ["VARIANTS-4"]
    }
  ]
}
```
