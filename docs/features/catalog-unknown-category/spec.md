# Nieznana kategoria w liście produktów

Status: zaakceptowana do realizacji (task `CAT-UNKNOWN-CATEGORY`). Ryzyko: FAST.

## Problem

`GET /api/v1/products?category=<uuid>` dla kategorii, która nie istnieje, zwraca `200` z pustą listą. Sklep
pokazuje wtedy pusty katalog pod adresem, który nie odpowiada żadnej kategorii (np. stary link po usunięciu
kategorii), a wyszukiwarki indeksują taką stronę.

## Zachowanie

API (`apps/api`):
- Gdy parametr `category` wskazuje identyfikator kategorii, której nie ma w bazie (PostgreSQL), endpoint zwraca
  `404` w formacie problem details (`application/problem+json`, `status: 404`, `detail` z komunikatem wyjątku
  projektu `CategoryNotFound`). Istnienie kategorii sprawdzamy w bazie, nie w Elasticsearch.
- Istniejąca kategoria bez produktów: nadal `200` z pustą listą i `total: 0`.
- Bez parametru `category`, ze stroną poza zakresem i z niepoprawnymi parametrami (`400`): bez zmian.
- Dokumentacja OpenAPI operacji `listProducts` opisuje odpowiedź `404`. Specyfikacja `apps/api/openapi.json`
  i klient TS sklepu są wygenerowane na nowo zgodnie z `CLAUDE.md`.

Sklep (`apps/store`):
- `loadCatalog` zwraca `{ kind: "unknown-category" }`, gdy lista produktów odpowiada `404`.
- Strona katalogu (`app/page.tsx`) dla `unknown-category` wywołuje `notFound()` z Next.js (odpowiedź 404).
- Pozostałe zachowanie bez zmian (`400` → `invalid-filter`, inne błędy rzucane dalej).

## Kryteria akceptacji

1. `GET /api/v1/products?category=<nieistniejący UUID>` → `404`, `application/problem+json`, `status: 404`.
2. `GET /api/v1/products?category=<istniejąca kategoria bez produktów>` → `200`, `items: []`, `total: 0`.
3. `GET /api/v1/products` i `?category=abc` (`400`) działają jak dotąd.
4. `openapi.json` zawiera `404` dla `listProducts`; klient TS sklepu jest aktualny.
5. `loadCatalog` dla `404` zwraca `unknown-category`, a strona katalogu daje 404.
6. Przechodzą `make -C apps/api check`, `make -C apps/api test`, `make -C apps/store check`, `make -C apps/store test`.

## Zakres i ograniczenia

- Bez zmian w panelu, w indeksie Elasticsearch i w drzewie kategorii.
- Bez nowych zależności.
