# PRODUCT-PAGE: strona produktu z ISR i Varnish

Punkt 4 „Zakresu MVP” z `docs/vision.md`: strona produktu w sklepie (ISR, SEO) i Varnish przed publicznymi
endpointami katalogu z unieważnianiem po tagach (xkey) oraz rewalidacją ISR po zdarzeniach domenowych.

Issues: #93
Zakres: `apps/api/src/Catalog`, `apps/api/src/Pricing`, `apps/api/src/Inventory`, `apps/api/src/Shared`, `apps/api/src/Cart/UI/Http/ProductOffersController.php`, `apps/api/tests`, `apps/api/config`, `apps/api/openapi.json`, `apps/store/src/api/schema.d.ts`, `apps/store/src/catalog`, `apps/store/src/api/client.ts`, `apps/store/app`, `infra/docker-compose.yml`, `infra/scripts/init-env.sh`, `Makefile`, `.github/workflows/ci.yml`, `e2e/tests/price-change.spec.ts`, `e2e/tests/stock-receipt.spec.ts`, `e2e/support`, `infra/varnish`, `docs/vision.md`, `CLAUDE.md`, `apps/api/CLAUDE.md`, `apps/store/CLAUDE.md`, `docs/features/variants/spec.md`, `docs/features/cart/spec.md`, `docs/features/pricing/spec.md`, `docs/features/inventory/spec.md`, `docs/features/walking-skeleton/spec.md`

**Zależność od VARIANTS (#89).** Specyfikacja opisuje kod po VARIANTS (zmergowane do `main`): warianty produktu
w Catalog, oferta produktów listy `GET /api/v1/product-offers` w Cart (`docs/features/variants/spec.md`).

## Cel i zakres

- Strona `/p/<slug>` w sklepie: nazwa, ścieżka kategorii, zdjęcia, krótki opis, cena, dostępność, wybór wariantu
  i dodanie do koszyka. HTML renderuje serwer i cache'uje ISR; metadane, adres kanoniczny i JSON-LD `Product`
  oraz `BreadcrumbList`.
- Kafelek listy prowadzi do strony produktu.
- Varnish (nowa usługa w `infra`) przed publicznymi `GET` katalogu: `/api/v1/products`, `/api/v1/products/{slug}`,
  `/api/v1/categories`, `/api/v1/product-offers`. Odpowiedzi mają jawny `Cache-Control` i tagi xkey.
- Zmiana ceny wariantu (Pricing) i poziomu dostępności wariantu (Inventory) publikuje zdarzenie domenowe; konsument
  unieważnia w Varnishu odpowiedzi z tagiem produktu, a potem rewaliduje strony sklepu z tym tagiem.
- `make seed` kończy się pełnym unieważnieniem (Varnish i ISR).

Poza zakresem:
- docelowy wygląd strony, galeria z powiększeniem, karuzela (VISUAL-DESIGN #97);
- wyszukiwarka i filtry (SEARCH #94);
- strony kategorii `/c/<slug>` z ISR; lista `/` zostaje renderowana przy każdym żądaniu (SSR) i korzysta z Varnisha;
- przekierowania 301 po zmianie sluga: slug się nie zmienia, edycji produktu nie ma (CATALOG-ADMIN #95);
- tagi i unieważnianie po zmianie kategorii albo produktu: kategorie i produkty powstają tylko w danych demo
  (`make seed` z pełnym unieważnieniem); edycja to CATALOG-ADMIN;
- `sitemap.xml`, `robots.txt`, JSON-LD `Organization`, pomiar Lighthouse w CI;
- zdjęcia per wariant (decyzja E2 VARIANTS), długi opis produktu, kody SKU/EAN, marka jako osobne pole;
- adres wariantu w URL (`?variant=`);
- Varnish w panelu „Stan systemu” (dashboard).

## Decyzje użytkownika

| # | Decyzja |
|---|---|
| P1 | Wybór wariantu na stronie: osobny wybór wartości dla każdej osi (np. „Kolor”, potem „Rozmiar”); dostępność wartości liczona hierarchicznie w kolejności osi (oś liczy się względem wyborów na osiach wcześniejszych, zmiana wcześniejszej osi czyści niezgodne wybory późniejszych); wybranej wartości nie da się odznaczyć, można wybrać inną. |
| P2 | Bez wyboru domyślnego (jak D8 VARIANTS), z wyjątkiem: produkt, który ma dokładnie jeden wariant możliwy do kupienia, ma go wybranego od razu (także gdy ma więcej wariantów). |
| P3 | „Do koszyka” dodaje 1 sztukę wybranego wariantu (jak kafelek); ilość zmienia się w koszyku. |
| P4 | Wszystkie zdjęcia produktu w kolejności, jedno pod drugim; pierwsze jest główne (`priority`). |
| P5 | Po rewalidacji pierwszy odwiedzający dostaje już nową stronę (rewalidacja blokująca, `expire: 0`); sprawdza to E2E na buildzie produkcyjnym. |
| P6 | Produkt bez wariantu możliwego do kupienia: „Niedostępny”, bez ceny, wyboru i przycisku (jak E3). |
| P7 | Błąd oferty na stronie produktu to błąd strony (nie jest cache'owany przez ISR), nie strona bez ceny. |
| P8 | Poza zakresem jak w sekcji „Poza zakresem” (m.in. 301 po zmianie sluga, `sitemap.xml`, `robots.txt`, `Organization`, `/c/<slug>`, wariant w URL, Lighthouse, Varnish na dashboardzie). |
| A1 | Sklep czyta katalog przez Varnish pod osobnym adresem (`API_CATALOG_URL`); koszyk, checkout i logowanie idą do API bezpośrednio. |
| A2 | Tagi i purge bez FOSHttpCacheBundle: własny nagłówek `xkey` i własny klient purge (bez nowych zależności PHP). Varnish z `vmod_xkey`; `PURGE` wymaga sekretu, nie tylko adresu z ACL. |
| A3 | Zdarzenia przez istniejący outbox: `VariantPriceChanged` przy każdym zapisie ceny; `StockLevelChanged` tylko przy zmianie poziomu dostępności wariantu; oba ze źródłem zmiany, a zdarzenia ze źródła `seed` nie unieważniają cache (robi to `purge-all`). |
| A4 | Konsumenci w `Infrastructure` contextu, który publikuje zdarzenie; jeden konsument robi kolejno purge Varnisha i rewalidację sklepu; ponowienia Messengera, obsługa idempotentna. Unieważnienie produktu obejmuje stronę produktu i każdą listę z tym produktem. |
| A5 | E2E w CI na buildzie produkcyjnym sklepu (`next build` + `next start`); lokalnie domyślnie `next dev`. |
| A6 | Cache ISR na dysku jednej instancji sklepu (domyślny w Next.js); współdzielony cache to osobne zadanie przy skalowaniu. |

## Przepływ danych

```
przeglądarka → sklep (Next.js, ISR /p/<slug>) → Varnish (API_CATALOG_URL) → API
                         │ koszyk, logowanie → API (API_INTERNAL_URL)
panel → API: zmiana ceny / stanu → outbox → RabbitMQ → konsument (worker):
        1. PURGE xkey product-<productId> w Varnishu
        2. POST sklep /api/revalidate {tags: [product-<productId>]}
```

## Tagi i nagłówki cache (API)

- Słownik tagów, wspólny dla Varnisha i ISR: `catalog` (każda odpowiedź katalogu i każda strona produktu)
  i `product-<productId>` (UUID małymi literami) dla każdego produktu, którego dane zawiera odpowiedź.
- Nazwy tagów buduje jedna klasa w `Shared_Infrastructure` (`product(id)` z UUID małymi literami i stała `catalog`);
  używają jej nagłówki odpowiedzi i `StorefrontCache` (`invalidateProduct(id)`, `invalidateAll()`), konsumenci nie
  składają napisów, więc nagłówek i purge nie mogą się rozjechać.
- Nagłówek odpowiedzi `xkey: <tag> <tag> …` (spacje). Ustawia go mały serwis tagów w `Shared_Infrastructure`
  (A2), wołany z kontrolerów. Varnish usuwa nagłówek przed wysłaniem odpowiedzi.

| Endpoint | `Cache-Control` | Tagi |
|---|---|---|
| `GET /api/v1/products` | `public, max-age=60` (bez zmian) | `catalog`, `product-<id>` każdego produktu strony |
| `GET /api/v1/categories` | `public, max-age=60` (bez zmian) | `catalog` |
| `GET /api/v1/products/{slug}` 200 | `public, max-age=60, s-maxage=3600` | `catalog`, `product-<id>` |
| `GET /api/v1/products/{slug}` 404 | `public, max-age=60` | `catalog` |
| `GET /api/v1/product-offers` 200 | `public, max-age=60, s-maxage=3600` | `catalog`, `product-<id>` każdego żądanego id |

- `s-maxage=3600` to siatka bezpieczeństwa dla zmian bez zdarzenia (np. koniec okresu stawki VAT, utracone
  unieważnienie). Zmiany ze zdarzeniem są widoczne po purge, bez czekania na TTL.
- Odpowiedzi 400, 401 i 503 tych endpointów nie są cache'owane (`no-store`).

## Catalog: szczegóły produktu

- Zapytanie `GetProductBySlug(slug)` (`query.bus`, DBAL, odczyt z PostgreSQL, nie z Elasticsearch) → `ProductDetails`
  albo `null`:
  `{id, slug, name, shortDescription, breadcrumb: [{id, name}], images: [ImageView], variantAxes: [string],
  variants: [{variantId, label, values: [string]}]}`.
  - `breadcrumb`: ścieżka od korzenia do kategorii głównej produktu (`ProductCategories::main`), z `CategoryPath`.
  - `images`: wszystkie zdjęcia produktu w kolejności pozycji (0–10), w rozmiarze `product`.
  - `variants` w kolejności pozycji; `label` z `ProductVariant::label()`, `values` w kolejności osi.
- Nowy rozmiar w `ImageSize`: `product` (karta produktu): 1:1, szerokości 640, 960, 1280, 1600, WebP, `fill`, `sm`.
- `GET /api/v1/products/{slug}` (`Catalog_UI`, `PUBLIC_ACCESS`): 200 `ProductDetails`; nieznany slug albo slug
  niezgodny z formatem `Slug`: 404 „Produkt nie istnieje.” (problem details). Nagłówki z tabeli wyżej. Kontrakt
  w `openapi.json`, klient sklepu wygenerowany.

## Pricing: zdarzenie zmiany ceny

- `VariantPriceChanged {variantId, occurredAt}` (`Pricing_Domain`, `DomainEvent`) rejestruje agregat `VariantPrice`
  przy każdej zapisanej zmianie: nowa cena, `changeGross`, `reprice` (zmiana z panelu, seed, `pricing:reconcile-tax`).
- Zdarzenie niesie też źródło zmiany (`PriceChangeSource`: `staff`, `seed`, `tax_reconciliation`).
- Publikuje je `DoctrineVariantPriceRepository::save` (outbox) w tej samej transakcji co zapis ceny i historii, jedno
  zdarzenie na zapis. To jedno miejsce obejmuje każdą ścieżkę zapisu, także `ReconcileTax`, który woła `save` bez
  `command.bus`. Zapis odrzucony (konflikt wersji, rollback) nie zostawia zdarzenia w outboxie.

## Inventory: zdarzenie zmiany poziomu dostępności

- `StockLevelChanged {variantId, level, occurredAt}` (`Inventory_Domain`) rejestruje `StockItem`, gdy operacja
  (przyjęcie, korekta, rezerwacja, zwolnienie, zatwierdzenie) zmienia `AvailabilityLevel::of(available)` wariantu.
  Operacja bez zmiany poziomu nie publikuje zdarzenia (zatwierdzenie rezerwacji nie zmienia `available`).
  `level` to poziom po zmianie. Poziom w pełni opisuje to, co widzi sklep: `purchasable` wymaga `available > 0`,
  czyli poziomu innego niż `out_of_stock`.
- Zdarzenie niesie też źródło zmiany (`StockChangeSource`: `staff`, `seed`, `system`).
- Publikuje je `DoctrineStockItemRepository::save` (outbox na tym samym połączeniu DBAL) w transakcji `command.bus`,
  razem z zapisem stanu i ruchów; rollback (także do savepointu przy zagnieżdżonym dispatchu) wycofuje je razem ze
  zmianą stanu.

## Unieważnianie (konsumenci zdarzeń)

- `StorefrontCache` w `Shared_Infrastructure`: `invalidate(tags)`:
  1. Varnish: `PURGE` z nagłówkami `xkey-purge: <tagi>` i `X-Purge-Token: <VARNISH_PURGE_SECRET>` (twarde usunięcie, `xkey.purge`, nie `softpurge`, żeby
     regenerowana strona nie dostała starej odpowiedzi);
  2. sklep: `POST <STORE_INTERNAL_URL>/api/revalidate` z `{tags}` i sekretem `STORE_REVALIDATE_SECRET`.
  Kolejność jest stała: rewalidacja ISR dopiero po udanym purge. Błąd (status inny niż 200, sieć, timeout) to wyjątek.
  Własne limity czasu klienta HTTP (domyślny klient API ma 2 s i 5 s): Varnish 5 s, sklep 30 s (pierwsze wywołanie
  trasy w `next dev` ją kompiluje).
- Konsumenci (transport `events`, idempotentni, A4):
  - `Pricing_Infrastructure`: `VariantPriceChanged` → `productId` z Catalog `GetVariant` → `invalidate([product-<id>])`;
    wariant nieznany Catalogowi: nic do unieważnienia, wiadomość obsłużona.
  - `Inventory_Infrastructure`: `StockLevelChanged` → to samo.
  - Zdarzenie ze źródła `seed` konsument potwierdza bez unieważnienia (A3): seed kończy się `purge-all`, a ok. 2 tys.
    wywołań HTTP po każdym `make seed` opóźniałoby zdarzenia testów E2E w kolejce jednego workera.
- Dostarczenie: istniejący outbox. Wiersz outboxu powstaje w transakcji zapisu ceny albo stanu i jest widoczny dla
  workera dopiero po jej commicie; wycofana transakcja nie zostawia zdarzenia. Worker przekazuje go do RabbitMQ
  (ponowienia transportu `outbox`), a konsument czyta z transportu `events`. Unieważnienie nigdy nie startuje przed
  commitem zmiany, więc regenerowana strona czyta już nowe dane.
- Ponowienia: wyjątek konsumenta (Varnish albo sklep niedostępny, status inny niż 200, timeout) to ponowienie
  Messengera (transport `events`: 3 próby po 1, 3 i 9 s), potem `failed` (`messenger:failed:retry`). Do udanego
  ponowienia dane są najwyżej tak stare jak siatka bezpieczeństwa (`s-maxage` i `revalidate` 3600 s).
- Idempotencja: purge tagu i rewalidacja tagu nie zależą od stanu ani od liczby powtórzeń. Powtórzone, spóźnione albo
  przestawione zdarzenie powoduje najwyżej dodatkowe odświeżenie z bieżących danych (wyjątek: wyścig w „Ryzykach”).
- Zakres: tag `product-<id>` mają szczegóły produktu, oferta (`/api/v1/product-offers`, także oferta strony listy
  z wieloma produktami) i strona listy produktów w API. Purge tagu usuwa więc z Varnisha każdą odpowiedź, w której
  produkt występuje: stronę produktu (przez jej ofertę) i każdą stronę listy (lista `/` sklepu nie jest cache'owana
  przez ISR, renderuje się przy każdym żądaniu i czyta ofertę przez Varnish). Rewalidacja ISR dotyczy stron
  produktu z tym tagiem.
- Komenda `bin/console storefront:cache:purge-all` (`Shared_UI`) unieważnia tag `catalog` w obu warstwach. Woła ją
  `make seed` na końcu. Każdą z dwóch warstw próbuje do 5 razy co 5 s (startujący sklep, kompilacja trasy w `next dev`).
  Kod wyjścia 0 – gotowe, 2 – błąd po ostatniej próbie (np. niedostępny Varnish lub sklep), z komunikatem.

## Varnish (`infra/varnish`)

- Usługa `varnish` w `infra/docker-compose.yml`: oficjalny obraz `varnish` z przypiętą wersją (gałąź 9.x). Obraz od
  wersji 7.1 zawiera zestaw varnish-modules, w tym `vmod_xkey`. VCL importuje `xkey`, więc obraz bez modułu kończy
  się błędem kompilacji VCL przy starcie. Zadanie infrastruktury pokazuje to komendami: plik
  `libvmod_xkey.so` w katalogu vmodów obrazu i `varnishd -C` z naszym VCL. Healthcheck, bez portu na hoście
  (tylko sieć compose), backend `api:80`. Plik `infra/varnish/default.vcl`.
- `PURGE` ma trzy zabezpieczenia: Varnish nie ma portu na hoście; ACL (prywatne zakresy RFC 1918 i `localhost`,
  bo podsieć compose różni się między projektami; to tani dodatek, nie zabezpieczenie: w sieci compose przepuszcza
  każdą usługę); nagłówek `X-Purge-Token` równy sekretowi `VARNISH_PURGE_SECRET`. Realnie chronią brak portu na
  hoście i token. VCL czyta sekret przez `std.getenv("VARNISH_PURGE_SECRET")` (standardowe polecenie obrazu,
  statyczny `default.vcl`); brak albo pusty sekret: `vcl_init` kończy się `fail`, kontener nie startuje (sprawdza
  to zadanie infrastruktury komendą i `.vtc`).
- VCL dokłada do wbudowanego VCL Varnisha tylko: `PURGE`, listę ścieżek, usuwanie `xkey` i `X-Cache`, oraz
  `default_ttl = 0` (`-t 0`) dla odpowiedzi bez `Cache-Control`. `pass` przy `Authorization` i `Cookie` oraz TTL
  z `s-maxage`/`max-age` daje wbudowany VCL; własny `vcl_recv` nie robi `return (hash)` przed jego sprawdzeniami.
- Zachowanie VCL:
  - `PURGE` z adresu w ACL i z poprawnym `X-Purge-Token`: z `xkey-purge` usuwa obiekty z tagami (`xkey.purge`)
    i odpowiada 200 z liczbą usuniętych; spoza ACL albo bez tokenu lub ze złym tokenem 403, bez `xkey-purge` 400;
    `PURGE` nigdy nie trafia do API;
  - `GET`/`HEAD` bez `Authorization` i bez `Cookie`, na ścieżce z listy (`/api/v1/products`, `/api/v1/products/*`,
    `/api/v1/categories`, `/api/v1/product-offers`): cache po pełnym URL (z query); każde inne żądanie: `pass`;
  - TTL wyłącznie z `Cache-Control` API (`s-maxage`, potem `max-age`); `private`, `no-store` albo brak nagłówka:
    bez cache;
  - przed wysłaniem usuwa `xkey`, dodaje `X-Cache: HIT|MISS`.
- Testy VCL: `varnishtest` (pliki `.vtc` w `infra/varnish/tests`) uruchamiane przez `make test` i w CI: cache
  i HIT, `pass` przy `Authorization`, `Cookie`, `POST` i ścieżce spoza listy, brak cache bez `Cache-Control`
  i przy `private`/`no-store`, purge po tagu (usuwa tylko obiekty z tagiem), odrzucony `PURGE` spoza ACL (konkretny
  adres spoza zakresów), bez tokenu i ze złym tokenem, brak `xkey` w odpowiedzi.

## Sklep (`apps/store`)

### Adresy API

- `API_CATALOG_URL` (`http://varnish`): odczyty katalogu (lista, kategorie, oferty, szczegóły produktu).
  `API_INTERNAL_URL` (`http://api`) bez zmian dla koszyka, logowania i scalania (A1).

### Strona `/p/[slug]`

- ISR: `revalidate = 3600` (siatka bezpieczeństwa), `generateStaticParams` zwraca pustą listę (strony powstają przy
  pierwszym wejściu, bez prerenderu 10 mln produktów przy buildzie). HTML nie zależy od sesji: licznik koszyka
  i konto ładują Client Components.
- Dane: `GET /api/v1/products/{slug}`, potem `GET /api/v1/product-offers?productId=<id>`, oba przez
  `API_CATALOG_URL`, wspólne dla `generateMetadata` i strony (`React.cache`). Tagi przekazuje `next: { tags }`
  w opcjach wywołania klienta (`src/api/client.ts`): odczyt szczegółów ma tag `catalog` (id nie jest jeszcze znane),
  odczyt oferty `catalog` i `product-<id>`. Strona dziedziczy sumę tagów odczytów, więc rewalidacja `product-<id>`
  albo `catalog` unieważnia HTML, a następne wejście pobiera od nowa oba odczyty (przez Varnish, już po purge).
  Odczyty nie trafiają do data cache Next na dłużej niż HTML strony.
- 404 API → `notFound()` (strona 404 z `noindex`). Inny błąd szczegółów lub oferty (status, sieć, nieoczekiwana
  treść) → wyjątek, `app/error.tsx` (P7), a wynik nie trafia do cache ISR. Po rewalidacji na żądanie (`expire: 0`,
  P5) wpis jest wygaszony, więc błąd daje stronę błędu, a następne wejście renderuje stronę od nowa. Przy
  wygaśnięciu czasowym (`revalidate = 3600`, odświeżenie w tle) błąd zostawia poprzednią wersję.
- Treść (tymczasowy, neutralny motyw na tokenach, komponenty shadcn/ui):
  - ścieżka: „Wszystkie produkty” › kategorie z `breadcrumb`, linki `/?category=<id>`;
  - `h1` z nazwą, zdjęcia przez `ProductPhoto` (P4; produkt bez zdjęć: `PhotoPlaceholder` jak na kafelku), krótki
    opis;
  - cena i dostępność: bez wyboru „od X zł” (gdy `hasPriceRange`) albo „X zł” i etykieta `level` produktu; po
    wyborze wariantu jego cena i etykieta `level`;
  - wybór wariantu (Client Component, P1): dla każdej osi lista wartości w kolejności pierwszego wystąpienia
    w wariantach; dostępność wartości liczona hierarchicznie, w kolejności osi:
    - pierwsza oś: wartość jest wyłączona (z dopiskiem „niedostępny”) tylko wtedy, gdy żaden wariant możliwy do
      kupienia jej nie ma;
    - każda kolejna oś: wartość jest wyłączona, gdy żaden wariant możliwy do kupienia nie ma jej razem z wartościami
      wybranymi na osiach wcześniejszych (wybory na osiach późniejszych się nie liczą);
    - oś późniejsza jest wyłączona, dopóki nie wybrano wartości wszystkich osi wcześniejszych;
    - zmiana wartości osi czyści wybory na osiach późniejszych, które stały się z nią niezgodne (cena i przycisk
      wracają wtedy do stanu bez pełnego wyboru); wybór nie może się zablokować, bo pierwszą oś zawsze da się zmienić
      na każdą wartość możliwą do kupienia;
    - kliknięcie wybranej wartości niczego nie zmienia (bez odznaczania, P1);
    „Do koszyka” (`AddToCartButton`) aktywne po wybraniu wartości wszystkich osi, dodaje 1 sztukę (P3);
  - produkt z dokładnie jednym wariantem możliwym do kupienia (spośród wielu): ten wariant jest wybrany od
    pierwszego renderu (wartości jego osi zaznaczone, jego cena i poziom, przycisk aktywny); inne wartości są
    dostępne według reguły wyżej (P2);
  - produkt z jednym wariantem: wartości osi jako tekst (bez wyboru), przycisk dodaje ten wariant;
  - produkt bez wariantu możliwego do kupienia (`fromGross = null`): „Niedostępny”, bez ceny, wyboru i przycisku (P6);
  - wariant ze szczegółów, którego nie ma w ofercie, jest niemożliwy do kupienia.
  Front niczego nie liczy poza dopasowaniem wybranych wartości do wariantu: ceny, poziomy i `purchasable` z oferty.
- Metadane (`generateMetadata`): tytuł `<nazwa> | Rytm`, opis = krótki opis, kanoniczny `<STORE_URL>/p/<slug>`,
  Open Graph: tytuł, opis, obraz = pierwsze zdjęcie w największej szerokości (bez zdjęć: bez obrazu).
- JSON-LD (`<script type="application/ld+json">`; każdy znak `<` w treści zakodowany jako ucieczka Unicode U+003C,
  jak w przewodniku JSON-LD Next.js):
  - `Product`: `name`, `description`, `image` (największa szerokość każdego zdjęcia), `url` (kanoniczny), `offers`:
    `Offer` dla każdego wariantu z ceną (`gross` ≠ `null`): `name` = etykieta, `price` (złote z groszy, kropka
    dziesiętna), `priceCurrency: PLN`, `availability` (`in_stock` → `InStock`, `low_stock` → `LimitedAvailability`,
    `out_of_stock` → `OutOfStock`), `url`;
  - `BreadcrumbList`: „Wszystkie produkty” i kategorie ścieżki z bezwzględnymi adresami.
- Kafelek listy: nazwa i zdjęcie to link do `/p/<slug>`; reszta kafelka bez zmian.

### Rewalidacja (`POST /api/revalidate`)

- Route handler: `Authorization: Bearer <STORE_REVALIDATE_SECRET>` (porównanie w stałym czasie); treść
  `{tags: string[]}`, 1–100 tagów, każdy `catalog` albo `product-<uuid małymi literami>`.
- `revalidateTag(tag, { expire: 0 })` dla każdego tagu (P5); 200 `{revalidated: <liczba>}`.
- Brak lub zły sekret: 401; zła treść lub inny `Content-Type` niż JSON: 400; brak `STORE_REVALIDATE_SECRET`
  w środowisku: 503. Wszystkie odpowiedzi `private, no-store`. Nie loguje sekretu.

## Infrastruktura i środowisko

- Dwa nowe sekrety w `infra/scripts/init-env.sh` (generowane jak inne): `STORE_REVALIDATE_SECRET` (czytają go `api`,
  `worker` i `store`) i `VARNISH_PURGE_SECRET` (czytają go `api`, `worker` i `varnish`), z `.env.local`. `STORE_INTERNAL_URL` (`http://store:3000`) i `VARNISH_URL` (`http://varnish`) w środowisku
  API (nie sekrety). `API_CATALOG_URL` w środowisku sklepu.
- Tryb sklepu: zmienna `STORE_MODE` (`dev` domyślnie: `next dev`; `production`: `next build`, potem `next start`)
  w usłudze `store`. CI uruchamia stos do E2E z `STORE_MODE=production` (A5) i przekazuje tryb do kontenera `e2e`.
  Healthcheck `store` i jego `start_period` obejmują w trybie `production` także `npm ci` i `next build`, więc
  `make up` (`--wait`) kończy się dopiero, gdy sklep przyjmuje żądania; `make seed` (`purge-all`) i rewalidacje
  workera nie trafiają w startujący sklep.
- Varnish dostaje healthcheck; `api` i `worker` nie zależą od jego startu (worker przy niedostępnym Varnishu
  ponawia wiadomość).
- `make seed`: po trzech seedach `storefront:cache:purge-all`.

## Zadania dokumentacyjne (razem z implementacją)

- `docs/vision.md`, „Cache HTTP i unieważnianie”: jeden konsument robi kolejno purge Varnisha i rewalidację ISR
  (zamiast dwóch konsumentów); tagi przez własny serwis zamiast FOSHttpCacheBundle (A2); Varnish przed odczytami
  pod osobnym adresem sklepu (A1); przykład tagów bez `category-<id>` (epik go nie wprowadza).
- `CLAUDE.md` (główny): `STORE_MODE` i E2E na buildzie produkcyjnym w CI.
- `apps/api/CLAUDE.md`, „Infrastruktura”: słownik tagów, serwis tagów, `StorefrontCache`, `storefront:cache:purge-all`,
  bez wzmianki o FOSHttpCacheBundle; „Reguły modelu”: kiedy zdarzenia publikuje handler (Catalog), a kiedy `save`
  repozytorium (Pricing, Inventory: wiele ścieżek zapisu, także poza `command.bus`).
- `apps/store/CLAUDE.md`: strona produktu (ISR, tagi, `API_CATALOG_URL`), `/api/revalidate`, oba adresy API.
- Specyfikacje istniejących funkcji, które opisują cache 60 s ofert, list i dostępności: `docs/features/variants/spec.md`
  (oferta `public, max-age=60`, ryzyko „opóźnienie do 60 s”), `docs/features/cart/spec.md` (nagłówki i cache oferty),
  `docs/features/pricing/spec.md` („widoczna najpóźniej po 60 s”), `docs/features/inventory/spec.md` (to samo dla
  dostępności), `docs/features/walking-skeleton/spec.md` (nagłówki `products` i `categories`, tagi `xkey`).
- Opis OpenAPI `getProductOffers` (`ProductOffersController`, „may be cached for 60 seconds”) i nowy opis cache
  w endpointach katalogu.

## Scenariusze błędów

| Sytuacja | Wynik |
|---|---|
| Nieznany slug | API 404; sklep: strona 404 z `noindex` |
| Błąd oferty albo szczegółów na stronie produktu | strona błędu, niecache'owana; przy odświeżeniu w tle (`revalidate = 3600`) zostaje poprzednia wersja |
| Varnish niedostępny | odczyty katalogu sklepu kończą się błędem (lista: `app/error.tsx`); koszyk działa |
| Purge albo rewalidacja nie powiodła się | ponowienia Messengera, potem `failed`; do tego czasu dane do 1 h stare |
| Wariant ze zdarzenia nieznany Catalogowi | wiadomość obsłużona bez unieważnienia |
| `POST /api/revalidate` bez sekretu lub ze złym | 401, bez rewalidacji |
| `PURGE` bez `X-Purge-Token`, ze złym albo spoza ACL | 403, nic nie jest usuwane |
| Wariant wykupiony po wyrenderowaniu strony | dodanie do koszyka: 409 jak dziś; strona odświeżona po zdarzeniu |

## Koszyk i checkout: weryfikacja ceny i dostępności

Bez zmian w tym epiku. Backend już weryfikuje cenę i dostępność przy każdej operacji, niezależnie od cache:
- dodanie do koszyka (`AddCartItemHandler` → `Cart::add` z ofertą wariantu z portów `CartPrices` i
  `StockAvailability`, czyli Pricing `GetCurrentPrices` i Inventory `GetStockLevels` z bazy): wariant bez aktualnej
  ceny albo z `available = 0` daje 409 `ProductNotPurchasable`;
- widok koszyka (`CartViewBuilder`) liczy kwoty i stany linii z bieżących cen i stanów;
- złożenie zamówienia: kodu jeszcze nie ma w `main` (CHECKOUT #90 w realizacji). Specyfikacja CHECKOUT
  (`docs/features/checkout/spec.md`) przewiduje bieżące ceny (`OrderPrices`), 409 `OrderTotalChanged`, gdy kwota różni
  się od widzianej przez klienta, i rezerwację z blokadą wiersza (`ReserveStock`, 409 `InsufficientStockForOrder`).
  Ten epik tego nie implementuje.
Koszyk i checkout idą do API bezpośrednio (`API_INTERNAL_URL`, A1), nigdy przez Varnish ani ISR.

## Kryteria akceptacji

1. `/p/<slug>` pokazuje nazwę, ścieżkę kategorii, zdjęcia, krótki opis, cenę, dostępność i wybór wariantu; gość
   i klient dodają wybrany wariant do koszyka.
2. Strona ma tytuł, opis, kanoniczny adres, Open Graph i JSON-LD `Product` (oferty wariantów z ceną) oraz
   `BreadcrumbList`; nieznany slug daje 404.
3. Strona produktu jest serwowana z cache ISR (na buildzie produkcyjnym drugie wejście: `x-nextjs-cache: HIT`).
4. Odczyty katalogu sklepu przechodzą przez Varnish; koszyk, logowanie i żądania z `Authorization` lub `Cookie`
   nigdy nie są cache'owane.
5. Zmiana ceny w panelu jest widoczna na stronie produktu i na liście w ciągu 20 s mimo `s-maxage=3600`
   (purge xkey i rewalidacja ISR). Na buildzie produkcyjnym pierwsze wejście po obsłudze zdarzenia na stronę produktu
   i na listę pokazuje już nową cenę.
6. Przyjęcie towaru zmieniające poziom dostępności jest widoczne na stronie produktu w ciągu 20 s.
7. Po `make seed` sklep nie pokazuje danych sprzed seeda (pełne unieważnienie).
8. Catalog nie zależy od Pricing ani Inventory; konsumenci są w contextach publikujących (Deptrac).
9. `PURGE` bez poprawnego tokenu albo spoza ACL nic nie usuwa; obraz Varnisha ma `vmod_xkey` (pokazane komendą).

## Testy

- Jednostkowe: `VariantPrice` rejestruje `VariantPriceChanged` przy każdej zmianie; `StockItem` rejestruje
  `StockLevelChanged` tylko przy zmianie poziomu (przejścia `out_of_stock`↔`low_stock`↔`in_stock`, operacje bez
  zmiany, zatwierdzenie); konsumenci z dublerem `StorefrontCache` (kolejność, wariant nieznany, źródło `seed` bez
  unieważnienia, błąd).
- Integracyjne zdarzeń: `ReconcileTax` z odrzuconym zapisem (konflikt) nie zostawia zdarzenia w outboxie.
- Integracyjne: `GetProductBySlug` (ścieżka kategorii, zdjęcia w kolejności, warianty i wartości, nieznany slug);
  zdarzenia trafiają do outboxu w transakcji i znikają przy rollbacku (także savepoint w Inventory); `StorefrontCache`
  wobec dublera HTTP (nagłówki, kolejność, błąd purge zatrzymuje rewalidację, błąd rewalidacji to wyjątek).
- Funkcjonalne API: `GET /api/v1/products/{slug}` (200, 404, zły format, 401 przy złym tokenie, nagłówki
  `Cache-Control` i `xkey`); nagłówki `xkey` listy, kategorii i ofert; `storefront:cache:purge-all` (0 i 2 przy
  niedostępnym Varnishu).
- VCL: `varnishtest` (sekcja „Varnish”).
- Vitest: strona produktu (cena „od”/„X zł”, wybór osi i wyłączone wartości według kolejności osi, oś późniejsza wyłączona przed wyborem wcześniejszej, czyszczenie niezgodnego wyboru późniejszej osi, przypadek „czarne, 42” i „białe, 44” bez blokady, brak
  odznaczania drugim kliknięciem, automatyczny wybór jedynego wariantu możliwego do kupienia, jeden wariant, brak
  wariantu możliwego do kupienia, wariant spoza oferty), metadane i JSON-LD (oferty tylko z ceną, mapowanie dostępności, ucieczka `<`), 404,
  błąd oferty; `/api/revalidate` (401, 400, 503, 200 z `revalidateTag`); kafelek z linkiem; odczyty katalogu idą pod
  `API_CATALOG_URL`, a koszyk i logowanie pod `API_INTERNAL_URL`.
- Funkcjonalne/integracyjne `StorefrontCache` i `purge-all`: sklep odpowiadający po kilku sekundach (mieści się
  w limicie), ponowienia `purge-all` i kod 2 po ostatniej próbie.
- E2E (`make e2e`; w CI na buildzie produkcyjnym):
  - `product-page.spec.ts`: z listy do strony „Buty szosowe Tarvik Asfalt 200”, ścieżka, zdjęcia, JSON-LD, wybór
    „czarne” i „42”, cena i poziom wariantu, dodanie do koszyka jako gość, linia z etykietą; to samo jako zalogowany
    klient; nieznany slug → 404; drugie wejście
    na stronę z `x-nextjs-cache: HIT` (tylko przy `STORE_MODE=production`);
  - `price-change.spec.ts`: nowa cena widoczna na stronie produktu i na liście w ciągu 20 s (zamiast obecnych 70 s);
  - `stock-receipt.spec.ts`: po przyjęciu strona produktu pokazuje „Dostępny” w ciągu 20 s;
  - `product-page-freshness.spec.ts` (tylko `STORE_MODE=production`, czyli w CI; w trybie dev pominięty z adnotacją)
    sprawdza P5: produkt z wariantem domyślnym („Hantle regulowane Lumora Para 10”); dwa wejścia na `/p/<slug>`,
    drugie z `x-nextjs-cache: HIT`; zmiana ceny w panelu; test odpytuje `/p/<slug>` żądaniem HTTP, dopóki odpowiedź
    jest `HIT` ze starą ceną (strona w cache przed obsługą zdarzenia), najwyżej 20 s; pierwsza odpowiedź inna niż
    `HIT` (obsłużone zdarzenie) ma już nową cenę w treści i w JSON-LD; zaraz potem pierwsze wejście na listę
    (kategoria produktu) pokazuje nową cenę na kafelku. Żadna odpowiedź po pierwszej nowej cenie nie wraca do starej.

## Ryzyka i ograniczenia

- Cache ISR jest na dysku jednej instancji sklepu (A6): przy kilku instancjach rewalidacja trafia tylko do jednej.
  Skalowanie poziome sklepu wymaga współdzielonego cache (np. `cacheHandler` na Redisie). Zgłoszone świadomie.
- ISR działa tylko na buildzie produkcyjnym; `next dev` renderuje przy każdym żądaniu, więc lokalne E2E w trybie dev
  nie sprawdzają cache ISR (sprawdza je CI).
- Między zapisem a unieważnieniem mijają sekundy (outbox → RabbitMQ → konsument); w tym czasie strona i Varnish
  pokazują poprzedni stan. Dodanie do koszyka i tak sprawdza cenę i stan w API.
- Rezerwacje w CHECKOUT (#90) zmieniają poziom dostępności i będą publikować `StockLevelChanged` (więcej purge
  przy dużym ruchu). CHECKOUT jest w realizacji i zmienia Inventory, Cart i sklep: możliwe konflikty przy merge'u.
- Dwa nowe sekrety w `init-env.sh`: istniejący lokalny `.env.local` bez nich zatrzymuje `make up` z komunikatem.
  Te dwa sekrety nie wymagają świeżych wolumenów: wystarczy dopisać je ręcznie (losowa wartość hex), mimo że komunikat
  skryptu proponuje odtworzenie stosu.
- Wyścig: odczyt API rozpoczęty przed commitem zmiany i zapisany w Varnishu już po purge zostaje jako stara wersja do
  `s-maxage` (1 h). Okno jest bardzo małe (dłuższe niż droga outbox → RabbitMQ → konsument); bez drugiego purge.
- Nieudany seed przerywa `make seed` przed `purge-all`: Varnish i ISR mogą trzymać dane sprzed seeda do 1 h (albo do
  ponownego `make seed` lub ręcznego `purge-all`).
- Sekret `PURGE` jest porównywany w VCL zwykłym porównaniem napisów (nie w stałym czasie); Varnish jest dostępny
  tylko w sieci compose, a ACL zawęża nadawców.
- Każdy nieznany slug zostawia wpis ISR 404 na dysku instancji; wpisy unieważnia tylko `catalog`, nic ich nie usuwa
  (nieograniczony przyrost przy losowych adresach, razem z A6).
- Seed publikuje zdarzenie dla każdej ceny i zmiany poziomu (ok. 2 tys. wiadomości); konsument potwierdza je bez
  wywołań HTTP.
- Produkty i kategorie tworzone poza seedem (CATALOG-ADMIN) będą wymagać własnych zdarzeń i unieważniania.
- Wydajność nie jest potwierdzona testami obciążeniowymi.
