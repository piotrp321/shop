Review taska CATALOG-ADMIN-4 (Admin API for products and image upload), tryb FULL, pierwsze review.

- base_sha: {{BASE}}
- head_sha: {{HEAD}}
- Tryb: FULL
- Specyfikacja: `docs/features/catalog-admin/spec.md` (w checkoucie; przeczytaj też `CLAUDE.md` i `apps/api/CLAUDE.md`).
- Kryteria akceptacji:
  1. Every endpoint has functional tests: no token, customer token, staff without the role (403 on writes and upload), bad data with the exact violations list, 404, every 409 type and 503 when Garage is unavailable
  2. POST then PUT with a stale version is 409 product-modified; publish without images is 409 product-not-publishable
  3. Upload: a 5 MB JPEG is 201 with key and a thumbnail preview; SVG, text named .jpg and an image over 50 MP are 422 with a Polish detail; a duplicated image key and a missing original are 422 at images[i].key
  4. GET /products filters by status draft and published with keyset paging
  5. openapi-admin.json is exported and the admin client generated in the same PR; openapi.json is unchanged
  6. docs/vision.md (catalog edited by admin and marketing) and apps/api/CLAUDE.md (upload limits, ImageSize::Thumbnail, admin product endpoints) describe the new state
  7. make -C apps/api check, make -C apps/api test and make -C apps/admin check pass
- `scope` taska: apps/api: Catalog UI (controllers, DTO, voter), Application (queries for the panel), Infrastructure (ProductImageStorage::exists, ImageSize::Thumbnail), docker PHP and Caddy limits, openapi-admin.json; apps/admin: generated schema.d.ts; docs. Spec section „API panelu”, decisions K3, K7, K8, A5. Endpoints under /api/v1/admin: GET /products (q, category, status, cursor), GET /products/{id} (ProductEditView), POST /products, PUT /products/{id}, POST /products/{id}/publish, POST /products/{id}/unpublish, POST /product-images (multipart), GET /tax-classes. ProductVoter: VIEW_PRODUCTS for every staff member, MANAGE_PRODUCTS for admin and marketing. DTO validation with field paths for every rule listed in the spec, including duplicates by VariantValues::comparisonKey; ProductConflictProblem enum. Upload limits upload_max_filesize 64M and post_max_size 70M, Content-Length check. Not included: panel screens.
- tdd.status: verified
- Dziennik dowodów TDD: `{{INPUT_DIR}}/tdd-evidence.jsonl`. Wpisy `head` i `dirty` dziennika odnoszą się do commitów gałęzi taska; w tym checkoucie zadanie jest jednym commitem `head_sha` na `base_sha`, więc RED sprawdzasz po nazwach testów w `output_tail` i po plikach w `dirty`.

Sprawdź diff taska (`git diff {{BASE}} {{HEAD}}`). Nie zmieniaj plików.
