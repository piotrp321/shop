# CATALOG-ADMIN: zarządzanie katalogiem w panelu

Punkt 4 „Zakresu MVP” z `docs/vision.md`: zarządzanie katalogiem w panelu z uploadem zdjęć. Pracownik tworzy
i edytuje produkty (dane, kategorie, klasa podatkowa, zdjęcia, osie i warianty), publikuje je i wycofuje ze sklepu.
Każda zmiana trafia do indeksu wyszukiwania i unieważnia cache sklepu (Varnish, ISR).

Issues: #95

**Zależności.** Funkcja opiera się na SEARCH (`docs/features/search/spec.md`): usługa
`ProductIndexing::reindex(productId)` z wersją dokumentu `external_gte`, port `ProductOfferFacts`, `catalog:reindex`
w `make seed`, slug kategorii i reguła „nazwa osi bez `:`”. Opiera się też na VARIANTS (warianty w agregacie
`Product`) i PRODUCT-PAGE (Varnish, tagi xkey, `StorefrontCache`, ISR strony `/p/<slug>`).

## Cel i zakres

- Panel: lista produktów (wyszukiwanie po nazwie, filtr kategorii i statusu, stronicowanie), formularz nowego
  produktu i edycji: nazwa, slug, krótki opis, kategoria główna i dodatkowe, klasa podatkowa, zdjęcia (upload,
  tekst alternatywny, kolejność, usunięcie), osie i warianty (dodanie, zmiana wartości, usunięcie).
- Produkt ma status: szkic albo opublikowany. Sklep widzi wyłącznie produkty opublikowane. Nowy produkt z panelu
  jest szkicem; publikacja i wycofanie to osobne akcje.
- Upload oryginału zdjęcia do Garage przez API (walidacja typu po treści, rozmiaru i rozdzielczości), podgląd
  w panelu z podpisanego adresu imgproxy zbudowanego w API (rozmiar `thumbnail` na liście rozmiarów).
- Zapis, publikacja i wycofanie publikują zdarzenie domenowe; konsumenci aktualizują indeks Elasticsearch
  (szkic zostaje w nim jako dokument `draft`, którego zapytania sklepu nie widzą) i unieważniają Varnish oraz ISR strony produktu (także stronę 404 sluga, który właśnie
  został opublikowany).
- Zmiana klasy podatkowej produktu przelicza ceny jego wariantów w Pricing (to samo brutto, nowa stawka).
- Edycja z widoku z optimistic locking (wersja produktu).

Poza zakresem:
- tworzenie, edycja i usuwanie kategorii oraz przekierowania po zmianie sluga kategorii (kategorie zostają z danych
  demo; osobne issue);
- usuwanie produktu (zamiast niego wycofanie publikacji);
- przekierowania 301 po zmianie sluga produktu (slug jest stały od pierwszej publikacji, decyzja K4);
- import hurtowy, eksport, operacje masowe;
- długi opis, pola SEO (własny tytuł i opis), marka jako pole, kody SKU i EAN, zdjęcia per wariant;
- generator kombinacji wariantów (wiersze wariantów dodaje się pojedynczo);
- kadrowanie i edycja zdjęć w panelu, sprzątanie nieużywanych oryginałów w Garage (K7);
- ustawianie ceny i stanu z formularza produktu (robią to istniejące listy cen i stanów);
- historia zmian produktu (kto i kiedy) poza identyfikatorem pracownika ostatniej zmiany;
- podgląd szkicu w sklepie; oznaczenie szkiców na listach cen i stanów (listy pokazują szkice bez oznaczenia).

## Decyzje

| # | Decyzja |
|---|---|
| K1 | Status produktu: `draft` (szkic) albo `published` (opublikowany). Nowy produkt z panelu jest szkicem. Publikacja i wycofanie (powrót do szkicu) to osobne akcje. Produktów się nie usuwa. Istniejące produkty i dane demo są opublikowane. |
| K2 | Publikacja szkicu wymaga co najmniej jednego zdjęcia. Cena i stan nie są wymagane: opublikowany produkt bez ceny albo stanu jest w sklepie „Niedostępny” (E3 VARIANTS). Zapis opublikowanego produktu, który miał zdjęcia, nie może usunąć ostatniego. Opublikowany produkt bez zdjęć (dane demo `imagelessEvery: 50`, `PhotoPlaceholder` w sklepie) jest edytowalny bez dodawania zdjęcia. |
| K3 | Uprawnienia: tworzenie, edycja, upload zdjęć, publikacja i wycofanie: role `admin` i `marketing`. Lista i podgląd produktu: każdy pracownik. |
| K4 | Slug: na tworzeniu opcjonalny (pusty: API wylicza go z nazwy); zmienny, dopóki produkt nie był nigdy opublikowany; od pierwszej publikacji stały (także po wycofaniu). Bez przekierowań 301. |
| K5 | Warianty: osie, wartości i lista wariantów zmieniają się dowolnie, dopóki produkt nie był nigdy opublikowany. Po pierwszej publikacji: wariant zachowuje tożsamość, więc jego wartości mogą zmienić się tylko bez zmiany klucza porównania (`VariantValues::comparisonKey()`: wielkość liter i spacje na brzegach, np. „czarne” → „Czarne”); osi nie da się dodać, usunąć ani przestawić (zmiana nazwy osi tylko bez zmiany klucza porównania); nowy wariant można dodać; inna zmiana znaczenia to usunięcie starego wariantu i dodanie nowego (nowy identyfikator). Usunięcie wariantu jest zablokowane, gdy ma stan fizyczny > 0 albo zarezerwowany > 0. Cena, stan i historie usuniętego wariantu zostają w Pricing i Inventory, niewidoczne w panelu; linie koszyka z nim mają stan `unavailable_product`. |
| K6 | Zmiana klasy podatkowej przelicza ceny wariantów produktu: to samo brutto, nowa stawka i netto, nowy wpis historii cen (Omnibus) ze źródłem systemowym `tax_reconciliation`, bez pracownika (pracownika zmiany klasy wskazuje `updated_by` produktu). |
| K7 | Oryginały zdjęć w Garage nie są usuwane (także po usunięciu zdjęcia z produktu albo uploadzie bez zapisu produktu). Klucz wynika z treści, więc ten sam plik nie zajmuje miejsca dwa razy. |
| K8 | Limity uploadu: 64 MB i 50 MP na plik, typy JPEG, PNG, WebP, AVIF, HEIC. Jeden plik na żądanie, panel wysyła kilka po kolei. |
| A1 | Status jest częścią agregatu `Product` (Catalog): metody `edit(...)`, `publish()`, `unpublish()` pilnują K2 i K4. |
| A2 | Jedno zdarzenie `ProductChanged` (zapis, publikacja, wycofanie) publikowane przez handler komendy (jedyna droga zapisu to komendy `command.bus`). Konsumenci: reindeksacja (Catalog Application), unieważnienie sklepu (Catalog Infrastructure), przeliczenie cen przy zmianie klasy (Pricing Infrastructure). Pricing nie może zależeć od `Catalog_Domain` (Deptrac), więc `ProductChanged` jest zdarzeniem integracyjnym w `Catalog_Application` (publiczny kontrakt modułu, jak zapytania; implementuje `DomainEvent`, idzie przez outbox); agregat zapisuje je jako wewnętrzne zdarzenie, a handler komendy tłumaczy je na kontrakt przed publikacją. Jedyny przypadek, w którym context subskrybuje zdarzenie innego contextu. |
| A3 | Szkic jest ukryty w odczytach sklepu w Catalog: indeks wyszukiwania (dokument szkicu zostaje jako trwały tombstone ze statusem `draft` i wersją, a każde zapytanie sklepu filtruje `status: published`), `GET /api/v1/products/{slug}` (404), `GetVariantSummaries` (koszyk, zamówienie) i `ListProductVariants` w trybie domyślnym (oferta sklepu). Listy cen i stanów panelu pytają `ListProductVariants` z `includeDrafts: true`. Cart i Ordering nie mają osobnej obsługi szkiców: wariant szkicu jest dla nich wariantem nieznanym. |
| A4 | Nowy tag cache `product-slug-<slug>` na odpowiedzi 404 szczegółów produktu i na odczycie szczegółów w ISR, żeby publikacja produktu unieważniła zapamiętaną stronę 404 jego sluga bez pełnego unieważnienia katalogu. |
| A6 | Blokadę usunięcia wariantu ze stanem (K5) rozstrzyga domena na podstawie faktów z Inventory: port `VariantStockFacts` w `Catalog_Application`, adapter w `Inventory_Infrastructure` (istniejące zapytanie `GetStockLevels`), jak `ProductOfferFacts` z SEARCH. Catalog nie zależy od Inventory w żadnej warstwie. |
| A5 | Upload osobnym endpointem `multipart/form-data`, który zwraca klucz i podgląd; zapis produktu (JSON) wskazuje klucze. API sprawdza przy zapisie, że oryginał o kluczu istnieje w Garage. |

## Przepływ danych

```
panel → POST /api/v1/admin/product-images (multipart) → walidacja → Garage (originals/<sha256>.<ext>)
      ← {key, preview (thumbnail, imgproxy)}
panel → POST/PUT /api/v1/admin/products[/{id}], POST …/publish, POST …/unpublish
      → command.bus: Product (Catalog) + outbox ProductChanged
ProductCreated, ProductChanged → RabbitMQ (events) ─┬→ Catalog IndexProduct → ProductIndexing::reindex (szkic: dokument `draft`)
                                    ├→ Catalog InvalidateStorefrontOnProductChange
                                    │     1. PURGE xkey product-<id> product-slug-<slug> w Varnishu
                                    │     2. POST sklep /api/revalidate {tags: [product-<id>, product-slug-<slug>]}
                                    └→ Pricing RepriceOnTaxClassChange (ceny wariantów z inną klasą niż produkt)
```

## Catalog: domena

- `Product` dostaje `status` (`ProductStatus`: `Draft`, `Published`) i `firstPublishedAt` (`?DateTimeImmutable`,
  ustawiane przy pierwszej publikacji, nigdy nie zerowane).
- `Product::create(..., status)`: panel tworzy `Draft`, loader danych demo `Published` (z `firstPublishedAt`).
  `create` nie sprawdza reguły K2 (dane demo mają produkty bez zdjęć). Zapisuje `ProductCreated` (nie
  `ProductChanged`); `IndexProduct` obsługuje je, więc nowy szkic trafia do indeksu od utworzenia (dokument `draft`).
- `edit(name, slug, shortDescription, categories, taxClass, images, variants)`: zastępuje dane produktu. Zmiana
  sluga produktu z `firstPublishedAt ≠ null` → `SlugLocked` (`Conflict`). Opublikowany produkt, który miał zdjęcia,
  a nowa lista jest pusta → `ProductNotPublishable` (`Conflict`); opublikowany produkt bez zdjęć zapisuje się bez
  nich (K2). Zapisuje `ProductChanged`, także gdy dane się nie zmieniły (zapis idempotentny dla konsumentów).
- `publish(now)`: szkic bez zdjęć → `ProductNotPublishable`; opublikowany → bez zmian i bez zdarzenia;
  inaczej `Published`, `firstPublishedAt ??= now`, `ProductChanged`.
- `unpublish()`: szkic → bez zmian i bez zdarzenia; inaczej `Draft`, `ProductChanged`.
- Zdarzenie agregatu (`Catalog_Domain`) `ProductChanged {productId, slug, occurredAt}`; handler komendy publikuje
  jego odpowiednik integracyjny `Catalog\Application\Event\ProductChanged` z tymi samymi polami (A2). `slug` po
  zmianie (konsument unieważnia tag tego sluga).
- Warianty (K5): `edit` przyjmuje gotowe `ProductVariants` (reguły VARIANTS: 1–100 wariantów, 0–2 osie,
  unikalne kombinacje, produkt bez osi ma jeden wariant domyślny). Handler buduje je z listy wierszy: wiersz
  z `variantId` tego produktu zachowuje identyfikator, wiersz bez `variantId` dostaje nowy UUIDv7, `variantId`
  spoza produktu → `UnknownVariant` (`InvalidInput`, 422). Produkt bez osi, któremu dochodzą osie (tylko przed
  pierwszą publikacją), zachowuje identyfikator wariantu domyślnego w pierwszym wierszu, jeśli panel go odeśle.
- Tożsamość wariantu po publikacji (K5), w `edit` dla `firstPublishedAt ≠ null`:
  - osie: ta sama liczba i kolejność, każda nazwa z tym samym kluczem porównania co w `VariantAxes`
    (`mb_strtolower` po przycięciu);
  - wariant zachowany (ten sam `variantId`): `VariantValues::comparisonKey()` taki sam jak zapisanego wariantu;
  - naruszenie → `VariantIdentityLocked` (`Conflict`, typ `variant-locked`) z `detail` wskazującym etykietę
    wariantu albo nazwę osi; nowe warianty bez ograniczeń.
- Usunięcie wariantu (każdy wariant produktu, którego nie ma w nowej liście; także przed publikacją): handler
  pobiera z portu `VariantStockFacts::inUse(variantIds)` identyfikatory wariantów ze stanem fizycznym > 0 albo
  zarezerwowanym > 0 i przekazuje je do `edit`; usunięcie któregoś z nich → `VariantInUse` (`Conflict`, typ
  `variant-in-use`) z `detail` z etykietą pierwszego takiego wariantu. Odczyt faktów bez blokady (patrz „Ryzyka”).
- Identyfikator pracownika ostatniej zmiany (`sub` z tokena; zasada „Klienci i pracownicy” w `docs/vision.md`)
  i czas zmiany są metadanymi zapisu w rekordzie produktu (`updated_by`, `updated_at`), nie częścią agregatu
  (inaczej niż `PriceChangeOrigin` w Pricing, bo produkt nie ma historii zmian). Repozytorium dostaje je w
  `save(Product, ?int expectedVersion, ?string actorId, \DateTimeImmutable $at)`; seed zapisuje `actorId = null`.
  Zmieniają się tylko przy zapisie, który podnosi wersję.
  `updated_at` pokazuje lista panelu; autora panel nie pokazuje.

## Catalog: komendy i wersja

- `CreateProduct` dostaje `status`, `actorId` (null w seedzie) i opcjonalny slug (pusty → `Slug::slugify(name)`;
  wynik pusty → 422 „Podaj slug.”). Id produktu i wariantów nadaje API (UUIDv7).
- `EditProduct {productId, expectedVersion, actorId, …dane}`, `PublishProduct {productId, expectedVersion, actorId}`,
  `UnpublishProduct {productId, expectedVersion, actorId}` na `command.bus`. Pusty slug w `EditProduct`: produkt bez
  blokady sluga → `Slug::slugify(name)`; z blokadą → slug bez zmian.
- Wersja z widoku: `EditProduct`, `PublishProduct` i `UnpublishProduct` porównują `expectedVersion` z wersją
  wczytanego produktu przed budową wariantów, odczytem faktów stanów i jakąkolwiek regułą domeny; niezgodność →
  `ProductModifiedConcurrently` (`Conflict`, 409 `product-modified`), także gdy akcja niczego by nie zmieniła.
  Repozytorium dostaje tę samą wersję w `save` i porównuje ją z rekordem pod istniejącym `#[ORM\Version]`
  (ochrona przed wyścigiem między odczytem a zapisem; jeden mechanizm, bez `LockMode` przy odczycie).
- Każdy udany `EditProduct` podnosi wersję i ustawia `updated_at` i `updated_by`, także gdy dane się nie zmieniły
  (przez `incrementVersion` w repozytorium, jak przy zmianie samych wariantów albo kategorii). Publikacja szkicu
  i wycofanie opublikowanego robią to samo; publikacja opublikowanego i wycofanie szkicu (przy zgodnej wersji) to 200 bez zmian,
  bez nowej wersji i bez zdarzenia. Odpowiedzi panelu zwracają `version`.
- Błędy typowane (`TypedProblem`, panel rozróżnia 409 po `type`): `slug-taken` (`SlugAlreadyTaken`), `slug-locked`,
  `product-modified` (`ProductModifiedConcurrently`), `product-not-publishable`, `variant-locked`, `variant-in-use`.
  Schemat OpenAPI
  `ProductConflictProblem` wylicza te wartości w `enum`.
- Nieistniejący oryginał zdjęcia (port `ProductImageStorage::exists(key)`) → `ImageNotFound` (`InvalidInput`, 422
  ze ścieżką `images[i].key`). Garage niedostępny przy `exists` → 503.
- Komunikaty wyjątków Catalog, które panel pokazuje (`detail`: `InvalidImage`, `ImageNotFound`, `SlugAlreadyTaken`,
  `SlugLocked`, `ProductNotPublishable`, `ProductModifiedConcurrently`, `UnknownVariant`, `VariantIdentityLocked`,
  `VariantInUse`), i komunikaty naruszeń DTO
  są po polsku (jak w Ordering i Cart).

## Catalog: odczyty sklepu (A3)

- `GET /api/v1/products/{slug}`: szkic → 404 „Produkt nie istnieje.” (ten sam kształt co nieznany slug).
- `GetVariantSummaries` i `ListProductVariants` (domyślnie): warianty szkiców pominięte (jak warianty nieznane).
  Skutki w Cart i Ordering: linia koszyka z wariantem szkicu ma stan `unavailable_product`, dodanie
  do koszyka takiego wariantu → 404, złożenie zamówienia z taką linią → `CartNotOrderable` (409), oferta produktu
  szkicu jak produktu nieznanego.
- `ListProductVariants(productIds, includeDrafts = false)`: adaptery list panelu (`QueryBusProductDirectory` w
  `Pricing_Infrastructure` i `Inventory_Infrastructure`) podają `includeDrafts: true`, więc szkic ma na listach cen
  i stanów swoje warianty (cenę i stan szkicu ustawia się przed publikacją). `ProductVariantList` dostaje `taxClass`
  produktu (dla przeliczenia cen, K6).
- `GetVariant`, `ListVariantIdentities` i `SearchBackofficeProducts` widzą szkice.
- Indeks (tombstone, A3): dokument dostaje pole `status` (`keyword`, `published`/`draft`) w `ProductIndexDefinition`.
  `ProductSearchSource` zwraca status, a `ProductIndexing::reindex` zapisuje dokument każdego produktu, także szkicu
  (ze statusem `draft`), z wersją `external_gte` jak w SEARCH. Dokumentów się nie usuwa, więc wersja szkicu chroni
  przed spóźnionym zapisem starszego stanu bez limitu czasu (bez zależności od `index.gc_deletes`). `catalog:reindex`
  indeksuje wszystkie produkty. `IndexProduct` obsługuje oba zdarzenia: `ProductCreated` i integracyjne
  `ProductChanged`; oba prowadzą do `ProductIndexing::reindex`. Każde zapytanie sklepu (`ProductSearch`: wyniki, `total`, fasety, `availableCount`,
  `price`) ma filtr `term status: published` w `query`, więc działa też w agregacjach.
- Statystyki katalogu (dashboard): indeks ma dokument każdego produktu, także szkicu, więc zgodność
  indeksu (`inSync`: liczba dokumentów = liczba produktów) zostaje poprawna, a liczba produktów obejmuje szkice.

## Catalog: unieważnienie sklepu (A2, A4)

- `CacheTags::productSlug(slug)` buduje `product-slug-<slug>` (slug w formacie `Slug`, inny napis → wyjątek).
- `GET /api/v1/products/{slug}`: 200 z tagami `catalog`, `product-<id>` (slug zmienia się tylko w szkicu, który
  nie ma zapamiętanej odpowiedzi 200); 404 z tagami `catalog`, `product-slug-<slug>` (dla sluga poprawnego formatu;
  slug niezgodny z formatem: tylko `catalog`).
- Sklep: `loadProduct` dodaje tag `product-slug-<slug>` do odczytu szczegółów (obok `catalog`) tylko dla sluga
  w formacie `Slug` (ten sam wzorzec co w `/api/revalidate`; inny napis tylko z `catalog`); `POST /api/revalidate`
  przyjmuje tag `product-slug-<slug>` (format sluga produktu).
- `StorefrontCache::invalidateTags(tags)` (purge xkey wszystkich tagów, potem rewalidacja wszystkich) obok
  `invalidateProduct(id)`.
- Konsument `InvalidateStorefrontOnProductChange` (`Catalog_Infrastructure`, transport `events`, `ProductChanged`):
  `invalidateTags([product-<id>, product-slug-<slug>])`. Bez odczytu bazy; ponowienia transportu jak u konsumentów
  PRODUCT-PAGE; obsługa idempotentna.
- Listy sklepu: produkt opublikowany pojawia się na listach po reindeksacji i najpóźniej po TTL list (60 s, S11
  SEARCH); listy, na których produkt był, czyści purge `product-<id>`.

## Pricing: przeliczenie przy zmianie klasy (K6)

- Konsument `RepriceOnTaxClassChange` (`Pricing_Infrastructure`, transport `events`, integracyjne `ProductChanged`
  z `Catalog_Application`): warianty produktu i jego klasa z Catalog `ListProductVariants([productId],
  includeDrafts: true)` przez port Pricing (także szkic, bo jego ceny też mogą istnieć). Każda cena z klasą inną niż
  klasa produktu jest przeliczana tą samą logiką co `pricing:reconcile-tax --apply`: pętla „reprice, zapis, jedno
  ponowienie przy konflikcie wersji” jest w `PriceReconciler`, używanym przez `ReconcileTax` i `RepriceProductPrices`
  (`reprice`: to samo brutto, źródło `tax_reconciliation`, zdarzenie `VariantPriceChanged`). Klasa bez stawki →
  wiadomość do `failed` z komunikatem. Drugi konflikt wersji albo zmiana klasy produktu w trakcie przeliczania
  (`TaxClassChangedWhileRepricing`) → wyjątek i ponowienie wiadomości. Powtórzone zdarzenie nic nie zmienia.
  Produkt nieznany Catalogowi → wiadomość obsłużona.
- Idempotencja i kolejność: konsument nie bierze klasy ze zdarzenia, tylko czyta bieżącą klasę produktu z Catalog
  i porównuje ją z klasą każdej ceny. Kilka szybkich zmian klasy (A → B → A) i dostarczenie zdarzeń w dowolnej
  kolejności lub po wielokrotnym powtórzeniu kończy się cenami w klasie bieżącej; obsługa zdarzenia, gdy ceny już
  mają bieżącą klasę, nic nie zapisuje (bez wpisu historii).

## API panelu

Wszystkie pod `/api/v1/admin`, `Cache-Control: private, no-store`, firewall `backoffice`, voter `ProductVoter`
(K3: `VIEW_PRODUCTS` każdy pracownik, `MANAGE_PRODUCTS` `admin` i `marketing`).

| Endpoint | Uprawnienie | Odpowiedzi |
|---|---|---|
| `GET /products?q&category&status&after&perPage` | widok | 200 `{items: [{id, name, slug, status, mainCategory {id, name}, variantCount, image: ImageView(thumbnail) \| null, updatedAt}], next}`; `q` 2–100 znaków (`SearchBackofficeProducts`, `pg_trgm`), `status` `draft`/`published` (indeks częściowy `(name, id) WHERE status = 'draft'`, bo szkiców jest mało), kursor keyset; 400 złe parametry; 404 nieznana kategoria |
| `GET /products/{id}` | widok | 200 `ProductEditView {id, version, status, firstPublishedAt, name, slug, slugLocked, shortDescription, mainCategoryId, additionalCategoryIds, taxClass, images: [{key, alt, preview: ImageView(thumbnail)}], variantAxes, variants: [{variantId, values}], updatedAt}`; 404 |
| `POST /products` | zarządzanie | 201 `ProductEditView`; 409 `slug-taken`; 422 walidacja (pola, `UnknownVariant`, `ImageNotFound`, nieznana kategoria); 503 Garage niedostępny |
| `PUT /products/{id}` (body z `version`) | zarządzanie | 200 `ProductEditView`; 404; 409 `product-modified`, `slug-taken`, `slug-locked`, `product-not-publishable`, `variant-locked`, `variant-in-use`; 422; 503 Garage niedostępny |
| `POST /products/{id}/publish` `{version}` | zarządzanie | 200 `ProductEditView`; 404; 409 `product-modified`, `product-not-publishable` |
| `POST /products/{id}/unpublish` `{version}` | zarządzanie | 200 `ProductEditView`; 404; 409 `product-modified` |
| `POST /product-images` (`multipart/form-data`, pole `file`) | zarządzanie | 201 `{key, preview: ImagePreview}` (`ImagePreview`: `size`, `aspectRatio`, `variants` rozmiaru `thumbnail`, bez `alt`); 422 brak pliku, za duży, nieobsługiwany typ, nieczytelne wymiary, za duża rozdzielczość (`detail` po polsku); 503 Garage niedostępny |
| `GET /tax-classes` | widok | 200 `[{code, name}]` (nazwy po polsku z jednego miejsca w Catalog) |

- Ciało `POST`/`PUT`: `{name, slug, shortDescription, mainCategoryId, additionalCategoryIds, taxClass,
  images: [{key, alt}], variantAxes, variants: [{variantId: string|null, values}]}` (+ `version` w `PUT`).
  Walidacja DTO z limitami domeny (nazwa 1–200, slug ≤ 200 i format, opis ≤ 500, do 10 kategorii dodatkowych,
  do 10 zdjęć, alt 1–200, 0–2 osie po 1–30 znaków bez `:`, wartości 1–40, 1–100 wariantów); 422 z `violations`
  ze ścieżkami pól (`variants[3].values[1]`).
- Reguły tekstu (jedna klasa `ProductText` w `Catalog\Domain\Product`, używają jej value objecty `ProductName`,
  `ShortDescription`, `ProductImage`, `VariantAxes`, `VariantValues` i walidator DTO): znaki sterujące C0 i DEL
  (U+0000–U+001F, U+007F) oraz U+0085, U+2028 i U+2029 są niedozwolone, a tekst, który nie jest poprawnym UTF-8, też.
  Jedynym wyjątkiem jest LF w krótkim opisie (pozostałe teksty są jednoliniowe). Znaki niedozwolone są szukane
  w wartości przed przycięciem (przycięcie ucięłoby np. końcowy NUL po cichu). Naruszenie to 422 przy polu
  (`name`, `shortDescription`, `images[i].alt`, `variantAxes[i]`, `variants[i].values[j]`): „Nazwa produktu zawiera
  niedozwolone znaki.”, „Krótki opis …”, „Tekst alternatywny …”, „Nazwa osi …”, „Wartość wariantu …”. Końce tekstu są
  przycinane z białych znaków Unicode (`\s` i `\p{Z}`, także NBSP), a długość liczy się po przycięciu. Nazwa, tekst
  alternatywny, nazwa osi i wartość wariantu z samych białych znaków to jak puste pole (422 „wymagane” przy tym polu,
  nie przy `slug`); krótki opis z samych białych znaków jest zapisany jako pusty. Zapisywane są teksty przycięte.
  Niepoprawny UTF-8 w treści JSON nie dociera do walidatora: dekoder odpowiada 400 (bajt poza UTF-8 albo samotny
  surogat `\udc80` w ucieczce).
- DTO sprawdza też reguły domeny, które bez tego dałyby 500 albo 422 bez ścieżki pola: format klucza zdjęcia
  (`images[i].key`), powtórzony klucz zdjęcia (`images[i].key`, „To zdjęcie już jest w produkcie.”), kategoria
  główna powtórzona w dodatkowych albo powtórzona dodatkowa (`additionalCategoryIds[i]`), powtórzona nazwa osi
  (`variantAxes[i]`), liczba wartości wiersza różna od liczby osi (`variants[i].values`), powtórzona kombinacja
  wartości według `VariantValues::comparisonKey()` (`variants[i].values`; constraint w `Catalog_UI` używa klasy
  domeny, więc reguła jest jedna), więcej niż jeden wiersz bez osi (`variants`),
  powtórzony `variantId` (`variants[i].variantId`). Domena dalej pilnuje swoich niezmienników.
- Nieznany `taxClass` → 422. Kategoria nieistniejąca → 422 (pole kategorii), nie 404.
- Upload: PHP `upload_max_filesize = 64M`, `post_max_size = 70M` (`apps/api/docker/php/conf.d/app.ini`). Plik ponad
  `upload_max_filesize` (błąd uploadu PHP) albo żądanie z `Content-Length` ponad `post_max_size` (PHP odrzuca wtedy
  całe ciało, więc kontroler sprawdza nagłówek przed szukaniem pliku) → 422 „Plik jest za duży.”. Caddy bez własnego
  limitu ciała poniżej 70 MB. Limity PHP nie działają w kernelu testów funkcjonalnych, więc te dwie ścieżki sprawdza
  test HTTP na działającym stosie (E2E `catalog-admin.spec.ts`).
- `ImageSize::Thumbnail` (1:1, szerokości 96 i 192, WebP) na liście rozmiarów; jedyny rozmiar odpowiedzi panelu.
- OpenAPI w `openapi-admin.json`, klient panelu wygenerowany; `openapi.json` sklepu nie zawiera endpointów panelu.

## Panel (`apps/admin`)

- Menu „Produkty” (`/products`), trasy `/products/new` i `/products/:id`. Widoki tras w `src/views/`
  (`ProductsView`, `ProductFormView`), logika i komponenty w `src/products/`. `/products/new` bez roli `admin` albo
  `marketing` przekierowuje na listę.
- Lista (`useProductList` na `useListUrlState` i `useCursorPager`, standard listy z `docs/features/admin-design/spec.md`):
  wyszukiwanie od 2 znaków z opóźnieniem, filtr kategorii (`CategoryCombobox` na `useCategoryTree`), filtr statusu
  (Select: Wszystkie, Szkice, Opublikowane), rozmiar strony 10/25/50, stan listy w adresie (`q`, `category`, `status`,
  `perPage`), stronicowanie kursorem ze stosem kursorów i „Pokazano X–Y”. Kolumny: produkt (miniatura albo placeholder,
  nazwa jako link do edycji, slug), kategoria główna, liczba wariantów, status (etykieta: „Szkic”, „Opublikowany”),
  ostatnia zmiana, akcje („Edytuj”, „Podgląd w sklepie” dla opublikowanego). Przycisk „Dodaj produkt” tylko dla
  `admin` i `marketing` (role z `/me`).
- Formularz (`useProductForm`): sekcje „Dane” (nazwa, slug z podpowiedzią „pusty: wyliczony z nazwy”, zablokowany
  przy `slugLocked` z wyjaśnieniem, krótki opis z licznikiem znaków, kategoria główna i dodatkowe z drzewa, klasa
  podatkowa z `GET /tax-classes`), „Zdjęcia”, „Warianty”. Pracownik bez `MANAGE_PRODUCTS` widzi formularz tylko
  do odczytu, bez akcji.
- Wynik akcji (zapis, publikacja, wycofanie): formularz jest długi, więc po akcji panel przewija i ustawia fokus na
  pierwszym błędzie w kolejności dokumentu (pole z błędem albo komunikat) albo, po sukcesie, na komunikacie sukcesu.
- Zdjęcia: wybór wielu plików; każdy wysyłany osobno, po kolei, ze statusem przy pliku (oczekuje, wysyłanie,
  gotowe, błąd z `detail` 422 albo ogólnym komunikatem i możliwością ponowienia); plik, którego klucz już jest
  w formularzu, nie jest dodawany drugi raz („To zdjęcie już jest w produkcie.”);
  po sukcesie miniatura i pole alt (wstępnie nazwa produktu, edytowalne); przesuwanie w górę i w dół, usunięcie;
  pierwsze oznaczone „Główne”; najwyżej 10 (przycisk wyłączony przy 10; z wybranych plików wysyłane są tylko
  te, które mieszczą się w wolnych miejscach, reszta ma błąd „Produkt może mieć najwyżej 10 zdjęć.”). Usunięcie zdjęcia z formularza nie usuwa
  oryginału (K7).
- Warianty: osie (0–2, dodanie, zmiana nazwy, usunięcie) i tabela wierszy: wartość dla każdej osi, dodanie
  i usunięcie wiersza. Produkt bez osi pokazuje jeden wiersz „Wariant domyślny” bez pól. Usunięcie wiersza
  istniejącego wariantu (także usunięcie ostatniej osi, które usuwa pozostałe istniejące warianty) pyta o
  potwierdzenie („Cena i stan wariantu przestaną być widoczne w panelu.”). Reguły
  (unikalność kombinacji, liczba wartości) sprawdza API; 422 wskazuje pole wiersza. Przed pierwszą publikacją osie
  można też przestawić (wartości w wierszach przestawiają się razem z osią).
- Po pierwszej publikacji (`firstPublishedAt` z API) dodanie, usunięcie i przestawienie osi są niedostępne, a pod
  tabelą jest wyjaśnienie: „Wartości istniejących wariantów można poprawić tylko w wielkości liter. Inną zmianę
  zrób przez dodanie nowego wariantu i usunięcie starego.” Pola wartości zostają edytowalne; regułę klucza
  porównania sprawdza API (409 `variant-locked`), panel jej nie liczy.
- Akcje: „Zapisz” (nowy: `POST`, potem przejście na `/products/:id`; edycja: `PUT` z wersją), „Opublikuj”
  / „Wycofaj z publikacji” (wyłączone przy niezapisanych zmianach; wysyłają wersję). Wszystkie akcje są wyłączone
  w trakcie żądania. Po sukcesie widok z odpowiedzi
  API (nowa wersja) i komunikat. Opuszczenie formularza z niezapisanymi zmianami pyta o potwierdzenie.
- Błędy: 422 przy polach (`violations[].propertyPath`), 422 bez `violations` jako komunikat formularza;
  409 po `type`: `product-modified` „Produkt został w międzyczasie zmieniony – odśwież” z przyciskiem odświeżenia
  (odrzuca zmiany formularza), `slug-taken` przy polu slug („Ten slug ma już inny produkt. Podaj własny slug.”, także gdy slug wyliczyło API
  z nazwy; bez automatycznego sufiksu), `slug-locked` przy polu slug,
  `product-not-publishable` „Dodaj co najmniej jedno zdjęcie przed publikacją.” (przy zapisie: „Opublikowany produkt
  musi mieć co najmniej jedno zdjęcie.”), `variant-locked` i `variant-in-use` z `detail` API przy sekcji
  „Warianty”; 403 i inne błędy jako komunikat
  bez utraty danych formularza.
- Panel niczego nie liczy: slug wylicza API, stan `slugLocked` i status pochodzą z odpowiedzi.

## Dane demo

- Loader demo tworzy produkty jako `Published` (`firstPublishedAt` = czas seeda) komendą `CreateProduct`; zdarzenia
  `ProductChanged` nie powstają. `make seed` kończy się `catalog:reindex`, który przebudowuje indeks.
- Migracja: kolumny `status` (`published` dla istniejących), `first_published_at` (czas migracji dla istniejących),
  `updated_by` (null), `updated_at` (czas migracji).

## Błędy widoczne dla użytkownika

| Sytuacja | Zachowanie |
|---|---|
| Równoległa edycja (nieaktualna wersja) | 409 `product-modified`, komunikat z odświeżeniem |
| Slug zajęty przez inny produkt | 409 `slug-taken` przy polu slug |
| Zmiana sluga po pierwszej publikacji | 409 `slug-locked` (panel i tak blokuje pole) |
| Publikacja szkicu bez zdjęć albo usunięcie ostatniego zdjęcia opublikowanego produktu | 409 `product-not-publishable` |
| Zmiana znaczenia wariantu albo osi po publikacji | 409 `variant-locked` |
| Usunięcie wariantu ze stanem albo rezerwacją | 409 `variant-in-use` („Wariant ma stan magazynowy albo rezerwacje. Najpierw skoryguj stan do zera.”) |
| Plik za duży, zły typ, za duża rozdzielczość, nieczytelny | 422 przy pliku, pozostałe pliki wysyłane dalej |
| Klucz zdjęcia bez oryginału w Garage | 422 przy zdjęciu |
| Garage niedostępny przy uploadzie albo zapisie produktu | 503 ogólny komunikat, plik albo zapis można ponowić |
| Pracownik bez roli | 403; panel ukrywa akcje |
| Unieważnienie sklepu nie powiodło się | ponowienia transportu `events`, potem `failed`; sklep pokazuje stary stan do `s-maxage`/`revalidate` (1 h) |
| Reindeksacja nie powiodła się | ponowienia, potem `failed`; listy pokazują stary stan do kolejnej reindeksacji |

## Kryteria akceptacji

1. Pracownik `marketing` tworzy produkt z dwoma zdjęciami i dwoma wariantami; produkt jest szkicem, nie ma go
   w sklepie (lista, wyszukiwarka, `/p/<slug>` 404, oferta jak nieznany produkt).
2. Publikacja szkicu bez zdjęć → 409 `product-not-publishable`; ze zdjęciem → produkt na `/p/<slug>` od razu (także
   gdy slug był wcześniej odwiedzony jako 404 na buildzie produkcyjnym) i na liście po reindeksacji (≤ 60 s + czas
   reindeksacji).
3. Edycja nazwy, opisu i zdjęć opublikowanego produktu jest widoczna na `/p/<slug>` od razu po obsłużeniu zdarzenia.
   Opublikowany produkt demo bez zdjęć zapisuje się ze zmienioną nazwą bez dodawania zdjęcia; usunięcie ostatniego
   zdjęcia opublikowanego produktu → 409 `product-not-publishable`.
4. Wycofanie z publikacji: `/p/<slug>` 404 od razu po obsłużeniu zdarzenia, produkt znika z wyszukiwarki po
   reindeksacji (≤ 60 s + czas reindeksacji), linia koszyka z jego wariantem ma stan
   `unavailable_product`, złożenie zamówienia z nią → 409 `CartNotOrderable`.
5. Warianty opublikowanego produktu: „czarne” → „Czarne” przyjęte (cena i stan zostają); „M” → „XL” albo zmiana osi
   → 409 `variant-locked`; nowy wariant dodany bez ceny i stanu; usunięcie wariantu ze stanem albo rezerwacją → 409
   `variant-in-use`, bez stanu → wariant znika z oferty i list cen i stanów, linia koszyka z nim → `unavailable_product`.
   W szkicu, który nie był publikowany, zmiana wartości i osi jest przyjęta.
6. Zmiana klasy podatkowej: ceny wariantów mają nową stawkę i to samo brutto, wpis historii ze źródłem
   `tax_reconciliation`. Dwie szybkie zmiany (A → B → A) z dowolną kolejnością obsługi zdarzeń kończą się cenami
   w klasie A.
7. Zmiana sluga szkicu, który nie był publikowany: przyjęta; po pierwszej publikacji: 409 `slug-locked`.
8. Dwie karty edytują ten sam produkt: druga dostaje 409 `product-modified`.
9. `support` i `warehouse`: lista i podgląd, 403 na zapis, upload, publikację; panel bez akcji.
10. Upload: JPEG 5 MB przyjęty z miniaturą; plik SVG, tekst z rozszerzeniem `.jpg`, plik > 64 MB i obraz > 50 MP
    odrzucone 422 z komunikatem.

## Testy

- Jednostkowe (domena i handlery, adaptery in-memory): `Product::edit/publish/unpublish` (K2 z produktem bez zdjęć
  i z usunięciem ostatniego, K4, zdarzenia, brak zdarzenia bez zmiany statusu), tożsamość wariantów po publikacji
  (zmiana wielkości liter, zmiana wartości, zmiana osi, nowy wariant, wszystko dozwolone w szkicu bez publikacji),
  blokada usunięcia wariantu w użyciu, budowa wariantów z wierszy (zachowanie id, nowe id, obcy id, dodanie osi do
  produktu bez osi), wersja oczekiwana sprawdzana przed regułami (nieaktualna wersja z usuniętym wariantem
  i z zablokowanym slugiem → `product-modified`), slug z nazwy, `RepriceOnTaxClassChange` (przeliczenie, powtórzenie, A → B → A
  z obsługą zdarzeń w obu kolejnościach), `InvalidateStorefrontOnProductChange`, `ProductIndexing` dla szkicu.
- Integracyjne: repozytorium (status, `firstPublishedAt`, wersja przy zmianie samych wariantów, konflikt wersji),
  migracja (istniejące produkty opublikowane), `exists` w Garage, zapytania sklepu pomijające szkice, adapter
  `VariantStockFacts` (stan fizyczny, rezerwacja, brak rekordu).
- Integracyjne ES (kolejność zdarzeń): zapis szkicu w nowszej wersji, potem spóźniony zapis starszej wersji
  opublikowanej → wynik „przestarzały”, dokument zostaje `draft` i nie ma go w wynikach ani fasetach; to samo
  w odwrotnym kierunku (publikacja po wycofaniu); powtórzony zapis tej samej wersji jest przyjęty
  (`external_gte`) i nie zmienia dokumentu. Brak usuwania
  dokumentów, więc test nie zależy od czasu (`gc_deletes`).
- Funkcjonalne: każdy endpoint panelu z przypadkami bez tokena, token klienta, rola bez uprawnień, złe dane (422
  z dokładną listą `violations`), 404, każdy typ 409; upload z prawdziwym plikiem i każdym rodzajem odrzucenia;
  `GET /api/v1/products/{slug}` szkicu 404 i tagi `product-slug-<slug>`.
- Panel (Vitest): `useProductList`, `useProductForm` (zapis, 409 po `type`, 422 przy polach, niezapisane zmiany,
  blokada akcji), upload po kolei z błędem jednego pliku, edytor wariantów.
- Sklep (Vitest): `revalidate` przyjmuje `product-slug-<slug>`, `loadProduct` taguje szczegóły.
- E2E `catalog-admin.spec.ts`: `marketing` tworzy produkt ze zdjęciem i wariantem, publikuje, `admin` ustawia cenę
  i przyjęcie; strona produktu pokazuje nazwę, zdjęcie i cenę; zmiana nazwy widoczna na stronie; wycofanie → 404.
  Na buildzie produkcyjnym: wejście na slug przed publikacją (404), po publikacji strona produktu, po wycofaniu 404.
  Upload przez API: obraz przyjęty, plik ponad `upload_max_filesize` i żądanie ponad `post_max_size` → 422 „Plik jest
  za duży.”. `support` widzi listę i formularz tylko do odczytu, bez `/products/new`. Produktów nie da
  się usunąć, więc każde uruchomienie tworzy produkt o unikalnej nazwie i slugu (sufiks uruchomienia, nazwa
  sortowana za danymi demo); żaden test nie zakłada stałej liczby produktów w katalogu.

## Ryzyka i ograniczenia

- Wydajność nie jest potwierdzona testami obciążeniowymi. Edycja jednego produktu czyści tylko jego tagi; nic nie
  unieważnia całego katalogu.
- Oryginały zdjęć nie są usuwane (K7): przyrost w Garage przy częstych uploadach bez zapisu.
- Upload do 64 MB trzyma plik w pamięci procesu PHP (`memory_limit` 256 MB); kilka równoległych uploadów na jednym
  workerze zwiększa zużycie pamięci.
- Między zapisem a unieważnieniem mijają sekundy (outbox → RabbitMQ → konsument); listy sklepu czekają do 60 s.
- Usunięty wariant zostawia cenę i historie w Pricing i Inventory (K5).
- Blokada usunięcia wariantu (A6) czyta stan bez blokady wierszy Inventory: przyjęcie albo rezerwacja zatwierdzona
  równolegle z usunięciem może zostawić stan usuniętego wariantu. Okno jest krótkie; po usunięciu przyjęcie
  i korekta wariantu kończą się 404, a rezerwacja nowego zamówienia nie powstanie (wariant nieznany Catalogowi przy
  składaniu). Stan zostaje w Inventory do ręcznego wyjaśnienia; bez mechanizmu naprawczego w MVP.
- Wycofanie produktu z publikacji nie zmienia złożonych zamówień (kopie danych) ani nie zwalnia rezerwacji.
- Dokumenty szkiców zostają w indeksie (tombstone): indeks rośnie o szkice, a każde zapytanie sklepu musi mieć
  filtr `published` (nowe zapytanie bez filtra pokazałoby szkice; pilnują tego testy).
- Wyścig ustawienia ceny ze zmianą klasy: `SetVariantPrice`, który przeczytał starą klasę i zapisał cenę po
  obsłużeniu `ProductChanged`, zostawia cenę w starej klasie do następnej zmiany produktu albo
  `pricing:reconcile-tax --apply`.
- Nowe pole `status` zmienia `definition_hash` indeksu: na istniejącym stosie `catalog:search-index:setup` kończy
  się błędem z instrukcją `catalog:reindex` (jak w SEARCH); `make seed` przebudowuje indeks.
- Po publikacji literówki w wartości wariantu nie da się poprawić w miejscu (K5): trzeba dodać nowy wariant,
  skorygować stan starego do zera i go usunąć.
- Jedyna subskrypcja zdarzenia innego contextu (A2): zdarzenie integracyjne w `Catalog_Application`
  staje się publicznym kontraktem, zmiana jego pól dotyka Pricing.
