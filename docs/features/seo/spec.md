# SEO: mapa strony, robots i dane organizacji

Punkt 4 „Zakresu MVP” (SEO sklepu) i sekcja „Sklep: renderowanie i SEO” w `docs/vision.md`. Funkcja obejmuje mapę strony
z katalogu, `robots.txt` i dane strukturalne organizacji na stronie głównej. Pomiar Core Web Vitals przez Lighthouse
w CI, który issue #266 też obejmuje, jest poza zakresem (LIGHTHOUSE, #289).

Issues: #266
Zakres: `apps/api/src/Catalog`, `apps/api/tests/Functional/Catalog`, `apps/api/tests/Integration/Catalog`, `apps/api/tests/Unit/Catalog`, `apps/api/config`, `apps/api/openapi.json`, `apps/store/src/api/schema.d.ts`, `infra/varnish`, `apps/store/src/seo`, `apps/store/src/site.ts`, `apps/store/src/site.test.ts`, `apps/store/src/catalog`, `apps/store/app/page.tsx`, `apps/store/app/page.test.tsx`, `apps/store/app/szukaj/page.test.tsx`, `apps/store/app/layout.tsx`, `apps/store/app/robots.ts`, `apps/store/app/robots.test.ts`, `apps/store/app/sitemap.xml`, `apps/store/app/sitemaps`, `apps/store/scripts/check-dynamic-routes.mjs`, `apps/store/tests/check-dynamic-routes.test.ts`, `apps/store/Makefile`, `.github/workflows/ci.yml`, `e2e/tests/seo.spec.ts`, `apps/api/CLAUDE.md`, `apps/store/CLAUDE.md`, `docs/vision.md`

**Zależności.** Funkcja opiera się na katalogu z PRODUCT-PAGE (#93) i SEARCH (#94) oraz na publikacji i wycofaniu
produktów z CATALOG-ADMIN. Nie zależy od wyglądu sklepu.

## Cel i zakres

- **Mapa strony**: `sitemap.xml` jako indeks map, z mapą stron listingu (`/` i każda kategoria `/c/<ścieżka>`) oraz
  porcjami map produktów (każdy opublikowany produkt `/p/<slug>`). Dane z API przez wygenerowanego klienta; API dzieli
  produkty na porcje bez ładowania katalogu do pamięci, także przy 10 milionach produktów.
- **`robots.txt`**: blokuje strony bez treści do indeksowania (koszyk, kasa, konto, logowanie, route handlery sklepu),
  nie blokuje stron z `noindex, follow` (listing z filtrami, `/szukaj`), żeby robot widział ich dyrektywę, i wskazuje
  indeks map.
- **Dane strukturalne strony głównej**: JSON-LD `Organization` i `WebSite` na `/`. Nazwa sklepu w jednej stałej.

Poza zakresem:
- wiele języków i `hreflang`, przekierowania po zmianie sluga kategorii, pozycjonowanie treści marketingowych (issue);
- JSON-LD `ItemList` listingu (D4);
- zdjęcia w mapie strony (rozszerzenie `image:`), kolejne strony listingu (`?page=n`) i `/szukaj` w mapie;
- logo graficzne w `Organization` (marka to znak słowny; D3);
- przełącznik indeksowania środowiska demo (D6): decyzja o indeksowaniu publicznego demo należy do wdrożenia;
- Lighthouse w CI (`@lhci/cli`, mediana z 3 przebiegów, blokuje tylko wynik SEO): LIGHTHOUSE (#289); decyzja
  o blokujących LCP i CLS: #290.

## Decyzje

| ID | Decyzja |
|---|---|
| D1 | Mapa strony to indeks map (`/sitemap.xml`) z mapą listingu (`/sitemaps/listing.xml`) i porcjami produktów (`/sitemaps/products/<klucz>.xml`). Porcja obejmuje N = 10 000 kolejnych `id` wszystkich produktów (opublikowanych i szkiców), więc ma najwyżej N adresów plus produkty utworzone w jej zakresie od ostatniego wyliczenia granic. |
| D2 | Granice porcji liczy API po kluczu głównym `catalog_product` (`id` na pozycjach N+1, 2N+1, … wszystkich produktów rosnąco), bez nowego indeksu. Pierwsza porcja ma otwarty początek, ostatnia otwarty koniec, więc każdy produkt należy do dokładnie jednej porcji niezależnie od wieku granic. Produktów się nie usuwa (`ProductStatus`), a nowe `id` z panelu (UUIDv7) trafiają na koniec, więc granice są stabilne. Klucz porcji jest nieprzezroczysty jak `ProductCursor`. Wynik zapytania granic trzyma pula cache API (TTL 1 h), więc jego koszt nie zależy od Varnisha ani od ruchu wprost do API; odpowiedź z granicami cache'uje też Varnish (1 h, tag `catalog`). |
| D3 | `Organization`: `name` (nazwa sklepu), `url` (`STORE_URL` ze `/`), bez `logo`, `sameAs` i danych kontaktowych. `WebSite`: `name`, `url`, bez `SearchAction`. Oba tylko na `/`. |
| D4 | Bez JSON-LD `ItemList` na listingu. |
| D5 | `robots.txt`: `Disallow` dla `/cart`, `/checkout`, `/konto`, `/auth/`, `/api/`; reszta dozwolona; linia `Sitemap:` z adresem indeksu. |
| D6 | `robots.txt` i mapa strony są takie same w każdym środowisku (bez przełącznika indeksowania). |
| D7 | `lastmod` produktu to czas ostatniej zmiany produktu w Catalog (`updated_at`); zmiany cen i stanów go nie zmieniają. Listing bez `lastmod`. |
| D8 | Odczyt porcji czyta najwyżej 50 001 wierszy niezależnie od klucza (także podanego ręcznie z dowolnym zakresem): limit działa na zakresie `id` przed filtrem `status`, więc szkice w zakresie też się liczą. Więcej niż 50 000 produktów w zakresie (przyrost porcji między odświeżeniami granic, ręczny klucz) daje opublikowane spośród pierwszych 50 000 i wpis `warning` w logu API; resztę obejmą porcje po następnym wyliczeniu granic (≤ 1 h). Porcji nie cache'uje Varnish (odpowiedzi rzędu MB przy dowolnych kluczach wypychałyby z niego katalog), więc klucz nie musi być podpisany: każdy odczyt ma ograniczony koszt, a klucz zawiera tylko publiczne `id`. |

## Mapa strony

### API (Catalog)

Dwa publiczne endpointy w `apps/api/openapi.json` (kontekst Catalog, zapytania na `query.bus`, odczyt przez DBAL
jak `DbalProductDetailsSource`; kontrolery `SitemapProductChunksController` i `SitemapProductsController`, źródło
`DbalSitemapProductSource`):

- `GET /api/v1/sitemap/product-chunks` → `{ chunks: [{ key }] }`: porcje w kolejności `id`. Granice to `id` produktów
  na pozycjach N+1, 2N+1, … (od 1) w kolejności `id` wszystkich produktów (`row_number()` po kluczu głównym). Porcja
  `i` obejmuje `id` od granicy `i` włącznie (pierwsza: od początku) do granicy `i + 1` wyłącznie (ostatnia: do końca).
  Przykład: N = 3 i 7 produktów → porcje z produktami 1–3, 4–6 i 7; dokładnie N produktów → jedna porcja. `key` koduje
  oba końce (`SitemapChunkKey`: base64url bez dopełnienia z JSON `{"f": id|null, "t": id|null}`, wzorzec
  `ProductCursor`). Pusty katalog → jedna porcja z obu końcami otwartymi (pusta porcja jest poprawną, pustą mapą).
- `GET /api/v1/sitemap/products?chunk=<key>` → `{ items: [{ slug, updatedAt }] }`: opublikowane produkty z zakresu
  porcji, rosnąco po `id`: najpierw najwyżej 50 001 produktów zakresu (`id ≥ f`, `id < t`, `ORDER BY id LIMIT`), potem
  filtr `status = 'published'` (D8; porcja ma zwykle około 10 000 wierszy). `updatedAt` w ISO 8601 z przesunięciem.
  Brak `chunk`, `chunk` podany jako tablica (`chunk[]=…`) albo niepoprawny klucz (format, nie-UUID, `f ≥ t`, napis
  dłuższy niż 200 znaków) → 400 z problem details. Zakres bez opublikowanych → `items: []`.

Rozmiar porcji N (10 000) i limit wierszy (50 000) to parametry kontenera `app.sitemap.chunk_size` i
`app.sitemap.row_limit` w `apps/api/config/services.yaml` (testy ustawiają 3 i 10). Zapytanie granic to skan klucza
głównego (bez tabeli); jego wynik trzyma pula `cache.sitemap` (jak `cache.oidc` w `config/packages/framework.yaml`,
w testach `cache.adapter.array`) z TTL 1 h przez dekorator portu `SitemapProductSource` w Infrastructure
(`CachingSitemapProductSource`, `CacheInterface::get`, z ochroną przed równoczesnym przeliczaniem), więc skan wykonuje
się najwyżej raz na godzinę na instancję API. `make seed` nie czyści puli: nieaktualne granice dają poprawne porcje (otwarte końce), najwyżej
o nierównych rozmiarach do wygaśnięcia. Zapytanie porcji to zakres klucza głównego z limitem.

Dostęp: oba endpointy bez tokena, reguła `PUBLIC_ACCESS` (`GET`, `HEAD`) dla `^/api/v1/sitemap/(product-chunks|products)$`
w `access_control` (`config/packages/security.yaml`), przed regułą `ROLE_CUSTOMER`; niepoprawny token daje 401 jak
w innych publicznych odczytach katalogu.

Odpowiedzi 200:
- `product-chunks`: `Cache-Control: public, max-age=60, s-maxage=3600`, tag `catalog` (`CacheTagger`); Varnish je
  cache'uje (reguła ścieżek w `infra/varnish/default.vcl` obejmuje `^/api/v1/sitemap/product-chunks$`, test
  `varnishtest` w `infra/varnish/tests/sitemap.vtc`).
- `products`: `Cache-Control: public, max-age=300`, bez tagów; ścieżka nie należy do reguły Varnisha (`pass`, ten sam test
  `varnishtest`). Wyjątek od zasady z `docs/vision.md`, że odpowiedzi katalogu mają tagi obiektów: porcja nie ma tagów
  produktów (10 000 tagów nie mieści się w nagłówku), a świeżość daje krótki `max-age`.

400, 401 i 503 (błąd odczytu) przez `PublicReadErrorCacheSubscriber`, czyli `private, no-store`.

Kategorie: istniejący `GET /api/v1/categories` (całe drzewo, przez Varnish), bez zmian.

### Sklep

Moduł `apps/store/src/seo/` (budowanie XML w `xml.ts`, odpowiedzi tras mapy w `sitemap.ts`, reguły `robots.txt`
w `robots-rules.ts`, `STORE_URL` w `store-url.ts`, JSON-LD strony głównej w `home-json-ld.ts`) i trasy
w `apps/store/app/`:

- `/sitemap.xml` – `sitemapindex`: najpierw `<STORE_URL>/sitemaps/listing.xml`, potem po jednej pozycji na porcję
  z `product-chunks` w kolejności API: `<STORE_URL>/sitemaps/products/<key>.xml` (klucz przez `encodeURIComponent`).
  Bez `lastmod`.
- `/sitemaps/listing.xml` – `urlset`: `<STORE_URL>/` i `<STORE_URL>/c/<path>` każdej kategorii drzewa (wszystkie
  poziomy, kolejność drzewa w głąb; segmenty ścieżki kodowane przez `encodeURIComponent`), bez `lastmod`.
- `/sitemaps/products/<key>.xml` – `urlset`: `<STORE_URL>/p/<slug>` (slug przez `encodeURIComponent`) z `lastmod` =
  `updatedAt` produktu; `items: []` → 200 z pustym `urlset`. Klucz idzie do API bez zmian (sklep go nie dekoduje); 400 API → 404 sklepu. Adres
  bez końcówki `.xml` → 404. Odpowiedź 404 ma `Cache-Control: no-store`.
- Własne route handlery (konwencja `sitemap.ts` z `generateSitemaps` nie tworzy `sitemapindex` ani 404 dla złego
  klucza). Odpowiedź 200: `Content-Type: application/xml; charset=utf-8`, `Cache-Control: public, max-age=300`,
  XML z ucieczką znaków (`&`, `<`, `>`, `"`, `'`).
- Renderowane przy każdym żądaniu bez data cache Next (cache to Varnish i pula API, jak przy listingu), odczyty przez
  `API_CATALOG_URL` (brak zmiennej to błąd konfiguracji, wyjątek, jak brak `STORE_URL`). Błąd API (inny niż 400
  porcji), sieć albo nieoczekiwana treść → 503 z `Cache-Control: no-store`
  i wpis w logu serwera; sklep nigdy nie wysyła pustej ani częściowej mapy zamiast błędu.
- `STORE_URL` czytany przy żądaniu (nie przy buildzie), także w buildzie produkcyjnym; brak zmiennej to błąd
  konfiguracji (wyjątek, 500), jak na `/` i `/p/`.

## `robots.txt`

`app/robots.ts` (konwencja metadanych Next, `MetadataRoute.Robots`) z wymuszonym renderowaniem przy żądaniu
(`export const dynamic = "force-dynamic"`, tak samo trzy route handlery mapy: `app/sitemap.xml/route.ts`,
`app/sitemaps/listing.xml/route.ts` i `app/sitemaps/products/[file]/route.ts`), bo trasa metadanych jest domyślnie
statyczna i utrwaliłaby `STORE_URL` z builda; reguły w `src/seo/robots-rules.ts`. Wynik:

```
User-Agent: *
Disallow: /cart
Disallow: /checkout
Disallow: /konto
Disallow: /auth/
Disallow: /api/

Sitemap: <STORE_URL>/sitemap.xml
```

- Strony z `noindex, follow` (listing z filtrami lub sortowaniem, `/szukaj`) zostają dozwolone: zablokowany adres
  robot może zindeksować bez treści z linków, a dyrektywy `noindex` nie zobaczy (D5).
- `/cart`, `/checkout` (z `/checkout/confirmation`) i `/konto/…` mają już `noindex`; blokada w `robots.txt` oszczędza
  budżet indeksowania. `/auth/` i `/api/` to route handlery sklepu bez HTML.
- Brak `STORE_URL`: błąd konfiguracji (500), jak w mapie.
- Test na buildzie produkcyjnym: `make -C apps/store check-dynamic-routes` (skrypt w kontenerze `store` czyta
  `.next/prerender-manifest.json`) kończy się kodem 1, gdy `/robots.txt`, `/sitemap.xml` albo trasa
  `/sitemaps/…` jest prerenderowana, i kodem 2 bez manifestu albo gdy `STORE_MODE` kontenera nie jest `production`
  (manifest po wcześniejszym buildzie zostaje na bind moucie także w trybie `dev`). CI uruchamia go w jobie
  `e2e` po starcie stosu (`STORE_MODE=production`). Testy skryptu: `apps/store/tests/check-dynamic-routes.test.ts`.

## Dane strukturalne strony głównej

`/` dostaje dwa dodatkowe `<script type="application/ld+json">` (ten sam `serializeJsonLd` co strona produktu):

- `{"@context": "https://schema.org", "@type": "Organization", "name": <nazwa sklepu>, "url": "<STORE_URL>/"}`;
- `{"@context": "https://schema.org", "@type": "WebSite", "name": <nazwa sklepu>, "url": "<STORE_URL>/"}`.

Na `/` zawsze, gdy strona pokazuje listing (z wynikami albo pustą listą, także z parametrami filtrów i strony); nie na
„Nieprawidłowym filtrze” (`noindex`), przy przekierowaniu `?category=` ani na 404. Nie na `/c/`, `/szukaj` ani `/p/`.

Nazwa sklepu to jedna stała `STORE_NAME` (wartość „Rytm”) w `apps/store/src/site.ts`. Używają jej tytuły i opisy
w `src/catalog/page-metadata.ts` i `src/catalog/product-metadata.ts`, `title` i `description` w `app/layout.tsx`,
znak słowny w `src/catalog/SiteHeader.tsx` i JSON-LD strony głównej (`src/seo/home-json-ld.ts`).

## Scenariusze błędów

| Sytuacja | Zachowanie |
|---|---|
| Pusty katalog albo same szkice | jedna porcja; jej mapa to pusty `urlset` (200) |
| Brak albo niepoprawny klucz porcji | API 400; sklep 404 dla `/sitemaps/products/<key>.xml` |
| `/sitemaps/products/<x>` bez `.xml` | 404 |
| API, Varnish albo drzewo kategorii niedostępne | mapa (indeks, listing, porcja) → 503 `no-store`, log serwera |
| Brak `STORE_URL` | mapa i `robots.txt` → błąd konfiguracji (500) |
| Produkt wycofany z publikacji | znika z porcji po wygaśnięciu `max-age` (≤ 5 min odpowiedzi API i sklepu, razem ≤ 10 min); jego strona to 404 z `noindex` |
| Nowy produkt opublikowany | pojawia się w swojej porcji po tym samym czasie (≤ 10 min) |
| Produkty utworzone po wyliczeniu granic | trafiają do porcji, w której zakresie leży ich `id` (z otwartymi końcami pierwszej i ostatniej nic nie wypada); nowe `id` z panelu (UUIDv7) powiększają ostatnią porcję do następnego wyliczenia granic (≤ 1 h) |
| Ponad 50 000 produktów w zakresie porcji (masowy przyrost w godzinie, ręczny klucz z szerokim zakresem) | API zwraca opublikowane spośród pierwszych 50 000, `warning` w logu; nadmiar po następnym wyliczeniu granic trafia do nowych porcji (D8) |
| Żądanie wprost do API z pominięciem Varnisha | granice z puli cache API (skan najwyżej raz na godzinę na instancję); porcja: jeden odczyt ograniczony limitem |

## Kryteria akceptacji

1. `/sitemap.xml` to poprawny `sitemapindex` z mapą listingu i jedną pozycją na porcję z API; po `make seed` (około 200
   produktów) jest jedna porcja produktów.
2. `/sitemaps/listing.xml` ma `/` i każdą kategorię drzewa jako `/c/<ścieżka>`; porcja produktów ma każdy opublikowany
   produkt (`/p/<slug>`, `lastmod`) i żadnego szkicu; wycofany produkt nie występuje w odpowiedzi API.
3. API dzieli produkty na porcje po N `id` (test integracyjny z małym N z konfiguracji testu, np. N = 3 i 7 produktów
   → porcje 1–3, 4–6, 7; N produktów → jedna porcja); porcje są rozłączne i razem obejmują wszystkie opublikowane
   produkty, także produkt utworzony z `id` mniejszym od pierwszej granicy
   i większym od ostatniej po wyliczeniu granic.
4. Brak albo niepoprawny klucz porcji: API 400, sklep 404; błąd API: sklep 503 `no-store`.
5. `/robots.txt` ma dokładnie reguły z D5 i bezwzględny adres indeksu map z `STORE_URL`; na buildzie produkcyjnym
   ani `/robots.txt`, ani trasy mapy nie są prerenderowane (`check-dynamic-routes` w CI).
6. `/` ma JSON-LD `Organization` i `WebSite` z nazwą sklepu (`STORE_NAME`) i `<STORE_URL>/`; `/c/…`, `/szukaj` i `/p/…`
   ich nie mają; tytuły metadanych i `layout.tsx` biorą nazwę z tej samej stałej.
7. `product-chunks` ma `s-maxage=3600` i tag `catalog`, a Varnish ją cache'uje; `products` ma `max-age=300`, a Varnish
   jej nie cache'uje (testy VCL); drugie wywołanie zapytania granic w ciągu TTL nie czyta bazy (test puli); oba
   endpointy odpowiadają 200 bez tokena.
8. Zakres porcji ponad limit (małe limity w teście, także ze szkicami) czyta najwyżej limit + 1 wierszy i zostawia
   wpis `warning`.

## Testy

- **API, jednostkowe**: kodowanie i dekodowanie klucza porcji (poprawny z oboma końcami i z otwartymi, zły base64, zły
  JSON, nie-UUID, `f ≥ t`, zbyt długi napis); dekorator puli granic (drugie wywołanie bez zapytania, wygaśnięcie po
  godzinie, porcja zawsze ze źródła).
- **API, integracyjne** (Postgres): granice na danych powyżej progu (małe N), rozłączność i pokrycie, otwarte końce,
  pomijanie szkiców, kolejność `id`, `updatedAt`, pusty katalog, limit wierszy porcji (mały limit z konfiguracji)
  z ostrzeżeniem w logu, przyrost ostatniej porcji po wyliczeniu granic.
- **API, funkcjonalne**: oba endpointy (200 bez tokena, 400 z brakiem i złym kluczem, pusty wynik, nagłówki cache
  i `xkey`, 401 z niepoprawnym tokenem), zgodność z `openapi.json`.
- **Varnish**: `varnishtest` (`infra/varnish/tests/sitemap.vtc`) – `/api/v1/sitemap/product-chunks` jest cache'owana,
  `/api/v1/sitemap/products?chunk=…` nie.
- **Sklep (Vitest)**: budowanie XML (ucieczki, kolejność, adresy bezwzględne), trasy mapy na dublerze API (200, pusty
  `urlset`, 404 porcji, 503 przy błędzie), reguły `robots.txt`, JSON-LD `Organization` i `WebSite` na `/`.
- **E2E**: `/robots.txt`, `/sitemap.xml` → mapa listingu i porcja produktów po `make seed` (adres produktu demo jest
  w porcji i otwiera stronę 200), JSON-LD na `/`.
- **Build produkcyjny**: `check-dynamic-routes` w CI (krok w jobie `e2e`); testy skryptu pokrywają kod 0, kod 1
  (każda z tras, trasa z parametrami pod `/sitemaps/`, lista wszystkich) oraz kod 2 bez manifestu, przy manifeście bez
  JSON albo bez `routes` i przy `STORE_MODE` innym niż `production`.

## Ryzyka i ograniczenia

- Zapytanie granic to skan całego klucza głównego `catalog_product` (przy 10 mln: indeks rzędu kilkuset MB, czas
  rzędu sekund) najwyżej raz na godzinę na instancję API (pula cache). Przy większej skali zastępuje je zapisany
  podział (tabela granic odświeżana w tle); dziś bez potrzeby.
- Porcje idą do bazy przy każdym żądaniu spoza `max-age` (bez Varnisha): robot przechodzący 1000 porcji przy 10 mln to
  1000 ograniczonych odczytów zakresu klucza głównego. Ochrona przed zalewem żądań (rate limiting publicznych odczytów)
  należy do HARDENING (#267).
- Produkty demo mają `id` UUIDv5 (losowe), produkty z panelu UUIDv7. Nowy produkt z panelu w katalogu demo trafia do
  środka zakresu i przesuwa późniejsze granice przy następnym wyliczeniu (nowe adresy porcji). Poprawność to nie
  zmienia; stabilność granic dotyczy katalogu z `id` UUIDv7.
- Porcja z wieloma szkicami ma mniej niż 10 000 adresów.
- `lastmod` nie odzwierciedla zmian cen i stanów (D7); stronę produktu odświeża i tak ISR.
- Nowy produkt pojawia się w mapie z opóźnieniem do około 10 min (`max-age` API i sklepu), bez unieważniania po
  publikacji.
