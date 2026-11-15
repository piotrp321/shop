Review taska VARIANTS-1 (Product variants in Catalog with a data-keeping migration), tryb FULL, pierwsze review.

- base_sha: {{BASE}}
- head_sha: {{HEAD}}
- Tryb: FULL
- Specyfikacja: `docs/features/variants/spec.md` (w checkoucie; przeczytaj też `CLAUDE.md` i `apps/api/CLAUDE.md`).
- Kryteria akceptacji:
  1. Unit tests written first (RED, then GREEN): Product variant invariants incl. duplicate combinations after trimming and with different case (also Polish letters), 0-2 axes, 1-100 variants, default variant; label; key
  2. Integration: mapping and cascade of variants; the unique (product_id, values_key) index rejects direct SQL with a combination differing only in case, also for Polish letters; VariantAlreadyExists from the primary key violation; doctrine:schema:validate and the migration diff show no differences
  3. Migration test on a database with products from before the change: every product has one default variant with the product's id, position 0, no values; no other data changed
  4. GetVariantSummaries, GetVariant, ListProductVariants and ListVariantIdentities return the shapes from the spec with one SQL query per call (100 ids); tests use variant ids different from product ids
  5. make seed gives every demo product exactly one default variant; existing make e2e passes unchanged
  6. make -C apps/api check and make -C apps/api test pass
- `scope` taska: apps/api Catalog only. Domain: VariantId, VariantAxes, VariantValues, ProductVariant, ProductVariants in the Product aggregate with the invariants (1-100 variants, 0-2 axes, values per axis, unique trimmed case-insensitive combination DuplicateVariantValues, default variant without axes), label(), key function. CreateProduct command with variantAxes and variants, VariantAlreadyExists from the primary key violation of catalog_product_variant. Persistence: catalog_product.variant_axes jsonb, table catalog_product_variant with option_values jsonb and generated values_key (lower(option_values::text)) STORED with unique (product_id, values_key) and (product_id, position), ProductVariantRecord, DoctrineProductRepository mapping, DbalCatalogReset clears variants. New migration: one default variant per existing product with id = product id. New queries GetVariantSummaries, GetVariant, ListProductVariants (with variantAxes and labels), ListVariantIdentities (variantId, key, taxClass). Demo expander: every demo product gets one default variant with UUID v5 of variant:<slug>/standard (catalog.yaml unchanged in shape; axes come in VARIANTS-5) and rejects a repeated key. Spec sections „Catalog” (Model domenowy, Persystencja, Migracja a dane demo, Zapytania) and „Stan przejściowy”. Not included: removing old Catalog queries (each goes with its last consumer), Pricing, Inventory, Cart, store, admin, demo axes.
- tdd.status: verified
- Dziennik dowodów TDD: `{{INPUT_DIR}}/tdd-evidence.jsonl`. Wpisy `head` i `dirty` dziennika odnoszą się do commitów gałęzi taska; w tym checkoucie zadanie jest jednym commitem `head_sha` na `base_sha`, więc RED sprawdzasz po nazwach testów w `output_tail` i po plikach w `dirty`.

Sprawdź diff taska (`git diff {{BASE}} {{HEAD}}`). Nie zmieniaj plików.
