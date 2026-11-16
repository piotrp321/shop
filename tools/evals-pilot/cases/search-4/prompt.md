Review taska SEARCH-4 (Full-text search, filters and facets in GET /api/v1/products), tryb FULL, pierwsze review.

- base_sha: {{BASE}}
- head_sha: {{HEAD}}
- Tryb: FULL
- Specyfikacja: `docs/features/search/spec.md` (w checkoucie; przeczytaj też `CLAUDE.md` i `apps/api/CLAUDE.md`).
- Kryteria akceptacji:
  1. „asfalt” finds „Buty szosowe Tarvik Asfalt 200”; „kurtki” finds jackets, „spiwor” sleeping bags, „plecal” backpacks; two words with one matching nothing give no result, also with a typo
  2. A name match ranks above a description match and an exact match above a typo match
  3. „Kolor: czarne” + „Rozmiar: 42” returns only products with a black size 42 variant; price and availability apply to the same variant
  4. Every facet value count equals the total after adding the value; availableCount equals the total with available=true; the selected value stays with 0
  5. Price sorting follows from_gross with products without a price last and name, id as tie-breakers
  6. Every invalid parameter gives 400 with violations; a functional test calls the API with the address built by the generated client (repeated option)
  7. openapi.json and the store client are up to date; apps/api/CLAUDE.md describes the parameters
  8. make check and make test pass for api and store
- `scope` taska: apps/api Catalog, apps/api/openapi.json, store TS client, docs. Spec section „Catalog: GET /api/v1/products (wyszukiwanie, filtry, fasety)” (parameters, Zapytanie, Fasety, headers) and decisions S1, S2, S3, S8, S11. Query parameters q, option (repeated <axis>:<value>, split at the first colon), priceMin, priceMax, available, sort with their limits and 400 violations (relevance without q, priceMin > priceMax). Query: category and phrase in query (exact cross_fields AND with field weights name > category > description on stemmed and folded fields, typo branch most_fields on folded fields with fuzziness AUTO, prefix_length 1, max_expansions 20, boost 0.3), the variant condition (axes OR within an axis, AND across axes, price and availability on the same nested variant) in post_filter, sort options with name.sort and id as tie-breakers, products without a purchasable variant excluded by price and availability. Facets: categories (children of the filter with slug, path and count), options (count = total after adding the value, reverse_nested, limits, selected value kept with 0), price from purchasable variants matching the axes, availableCount. Cache-Control and xkey unchanged. OpenAPI export and the store client in the same PR. Not included: store UI (SEARCH-5, SEARCH-6). Docs: apps/api/CLAUDE.md (parameters of listProducts).
- tdd.status: lost_verified_on_base
- Dziennik dowodów TDD: `{{INPUT_DIR}}/tdd-evidence.jsonl`. Wpisy `head` i `dirty` dziennika odnoszą się do commitów gałęzi taska; w tym checkoucie zadanie jest jednym commitem `head_sha` na `base_sha`, więc RED sprawdzasz po nazwach testów w `output_tail` i po plikach w `dirty`.

Sprawdź diff taska (`git diff {{BASE}} {{HEAD}}`). Nie zmieniaj plików.
