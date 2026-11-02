# Inventory: stany magazynowe (MVP)

Status: zaakceptowana do realizacji. Punkt 9 MVP z `docs/vision.md` (bez Ordering).

## Cel i zakres

Stan magazynowy produktu w jednym magazynie, w całych sztukach: fizyczny, zarezerwowany i dostępny
(fizyczny − zarezerwowany). Przyjęcia i korekty w panelu, historia każdej operacji, rezerwacja, zwolnienie
i zatwierdzenie z kluczem idempotencji przez port aplikacyjny dla przyszłego Ordering, poziom dostępności w sklepie.
Sprzedaż ponad stan jest niemożliwa także przy równoległych operacjach.

Poza zakresem: Ordering, Cart, Checkout, wiele magazynów, lokalizacje, kompletacja, warianty, zdarzenia domenowe,
Varnish i ISR. Nie powstają endpointy ani ekrany rezerwacji: port jest testowany na poziomie aplikacji.

## Bounded contexty i zależności

| Context | Odpowiada za |
|---|---|
| Inventory (nowy) | stany, przyjęcia, korekty, rezerwacje, historia, poziom dostępności |
| Catalog | zapytania dla Inventory: `GetProductExists`, `ListProductIdentities` (nowe), `SearchBackofficeProducts` (istniejące) |
| Sklep, panel | wyświetlanie wartości z API; żadnego liczenia dostępności ani poziomów |

- `Inventory_Infrastructure → Catalog_Application`: adaptery portów Inventory wołają zapytania Catalog przez
  `query.bus`, jak `Pricing\Infrastructure\Catalog\QueryBusProductDirectory`.
- `Inventory_Infrastructure → Backoffice_Application` (`StockVoter`) i `Inventory_UI → Backoffice_Application`
  (aktor operacji): wyłącznie kontrakt `AuthenticatedStaffMember`. `Inventory_Domain` i `Inventory_Application`
  nie zależą od Backoffice.
- Catalog i Pricing nie zależą od Inventory w żadnej warstwie. Inventory nie zależy od Pricing.
- Domena Inventory ma własne value objecty (`ProductId` itd.) i nie importuje kodu innych contextów.
- Inventory w MVP nie publikuje ani nie konsumuje zdarzeń domenowych (jak Pricing). Zdarzenie zmiany dostępności
  dochodzi razem z Varnishem i rewalidacją ISR.

Nowe zapytania Catalog (`query.bus`, DBAL):
- `GetProductExists(productId): bool`.
- `ListProductIdentities(after, limit)`: `{id, slug}` partiami po `id`, dla seeda Inventory.

## Model domenowy

### Agregat `StockItem`

Identyfikator = `ProductId`. Pola: `physical`, `reserved`, `version`, niezapisane wpisy historii (`StockMovement`).
`available() = physical − reserved` jest liczone, nie przechowywane.

Niezmiennik: `0 ≤ reserved ≤ physical ≤ 1 000 000`.

| Metoda | Działanie | Błędy |
|---|---|---|
| `receive(quantity, receiptKey, note, origin)` | `physical += quantity`, quantity 1–100 000 | quantity poza zakresem: `InvalidStockQuantity` (422); wynik ponad 1 000 000: `PhysicalStockLimitExceeded` (422) |
| `correct(counted, reason, note, origin): bool` | `physical = counted`, counted 0–1 000 000 | poniżej `reserved`: `CorrectionBelowReserved` (409, komunikat „Stan fizyczny nie może być niższy niż zarezerwowany (N szt.).”); poza zakresem: 422 |
| `reserve(quantity, reservationKey)` | `reserved += quantity` | `available < quantity`: niedobór (zbierany przez handler do `InsufficientStock`) |
| `release(quantity, reservationKey)` | `reserved −= quantity` | |
| `commit(quantity, reservationKey)` | `physical −= quantity`, `reserved −= quantity` | |

- `correct` z wartością równą bieżącemu `physical` zwraca `false`: bez wpisu historii i bez zmiany wersji.
- Każda faktyczna zmiana dopisuje jeden `StockMovement` ze stanem po operacji i podnosi `version` przy zapisie.
- Produkt bez rekordu stanu ma `physical = reserved = 0`. Rekord powstaje przy pierwszym przyjęciu lub korekcie
  albo jako pusty rekord danych demo (wersja 0, bez wpisu historii).

### Agregat `Reservation`

Identyfikator = `ReservationKey`. Linie `[{productId, quantity}]`: 1–100 linii, quantity 1–1000, bez powtórzonego
produktu (`InvalidReservation`, 422). Status: `active → released | committed`.

- `release(): bool` i `commit(): bool`: przejście z `active`; powtórzenie tej samej operacji na rezerwacji w jej
  stanie końcowym zwraca `false` (bez zmian). `commit` zwolnionej i `release` zatwierdzonej: `ReservationStateConflict`
  (409).
- Rezerwacja nie wygasa. Kończy ją wyłącznie zwolnienie albo zatwierdzenie.

Dlaczego dwa agregaty: `StockItem` per produkt ogranicza blokadę i rywalizację do jednego produktu; rezerwacja
obejmuje wiele produktów, ma własny cykl życia i jest rekordem idempotencji. Rezerwację „wszystko albo nic”
koordynuje handler aplikacji w jednej transakcji bazy (moduły dzielą bazę; saga byłaby zbędną złożonością).
Świadomy kompromis: jedna transakcja zmienia wiele agregatów, a niezmienniki każdego pilnuje domena.

### Value objecty

- `ProductId` (UUID).
- `ReceiptKey`, `ReservationKey`: 1–100 znaków `[A-Za-z0-9._:-]`. Zły format: 422 w porcie, 400 w nagłówku HTTP.
- `CorrectionReason`: `inventory_count` (inwentaryzacja), `damage` (uszkodzenie), `loss` (zaginięcie), `other` (inne).
  Przy `other` notatka jest wymagana.
- `StockNote`: do 500 znaków, przycięta; pusta = brak notatki.
- `StockChangeOrigin`: źródło (`staff`, `seed`, `system`) i aktor (`sub` i nazwa pracownika z tokena; `null` dla
  `seed` i `system`). Operacje portu rezerwacji mają źródło `system`.
- `StockMovement` (wpis historii, tylko dopisywany): `type` (`receipt`, `correction`, `reservation`, `release`,
  `commit`), `physicalDelta`, `reservedDelta`, `physicalAfter`, `reservedAfter`, `reason` (tylko korekta), `note`,
  `reference` (klucz przyjęcia albo rezerwacji), origin, `occurredAt`.
- `AvailabilityLevel::of(available)`: 0 → `out_of_stock`, 1–5 → `low_stock`, ≥ 6 → `in_stock`. Próg 5 jest stałą
  w domenie Inventory, jedynym miejscem tej reguły.

## Współbieżność i blokowanie

Wybrane: blokada wiersza `SELECT … FOR UPDATE` i decyzja w agregacie (zamiast atomowego warunkowego `UPDATE … WHERE
physical - reserved >= :q`). Reguła zostaje w domenie, rezerwacja wielu linii jest naturalnie „wszystko albo nic”,
wpis historii ze stanem po operacji powstaje z agregatu, a wynik jest deterministyczny (dokładnie min(N, M) sukcesów,
bez fałszywych odmów i ponawiania). Krótsza blokada warunkowego `UPDATE` jest pomijalna przy krótkiej transakcji.

- Repozytorium: `lockMany(productIds)` blokuje wiersze `inventory_stock_item` w kolejności rosnącego `product_id`
  (bez zakleszczeń przy liniach w dowolnej kolejności). Wiersz dla przyjęcia lub korekty produktu bez rekordu
  zakłada `INSERT … ON CONFLICT DO NOTHING` przed blokadą.
- Model mieszany: operacje atomowe (przyjęcie, rezerwacja, zwolnienie, zatwierdzenie) nie sprawdzają wersji z widoku,
  szereguje je blokada wiersza. Korekta z panelu dodatkowo sprawdza `expectedVersion` (nieaktualny widok: 409,
  `StockModifiedConcurrently`). Każda zmiana podnosi `version`.
- Ostatnia linia obrony w bazie: `CHECK (reserved >= 0 AND physical >= reserved AND physical <= 1000000)`.
- Poziom izolacji: READ COMMITTED (domyślny PostgreSQL). Po uzyskaniu blokady handler czyta stan od nowa.

## Idempotencja

### Rezerwacja (`ReserveStock`)

1. `INSERT … ON CONFLICT DO NOTHING` nagłówka `inventory_reservation`. Równoległa transakcja z tym samym kluczem
   czeka na unikalnym kluczu do zatwierdzenia lub wycofania pierwszej.
2. Klucz już istnieje: te same linie (ten sam zbiór produktów i ilości) → zwracany jest `ReservationView`
   z **aktualnym statusem** rezerwacji (`active`, `released` albo `committed`), bez ponownej zmiany stanów i bez
   wpisu historii, także po zwolnieniu lub zatwierdzeniu. Inne linie → `ReservationKeyReused` (409).
3. Klucz nowy: `lockMany` po posortowanych id, `reserve` na każdej linii.
4. Niedobór na dowolnej linii: `InsufficientStock` (409) z listą `shortages: [{productId, requested, available}]`
   dla wszystkich brakujących linii. Cała transakcja się wycofuje, więc klucz nie zostaje zapisany i ponowienie
   później może się udać. Produkt bez rekordu ma 0 dostępnych (Inventory nie sprawdza Catalog przy rezerwacji).

### Zwolnienie i zatwierdzenie

`ReleaseReservation(key)`, `CommitReservation(key)`: blokada wiersza rezerwacji (`FOR UPDATE`, szereguje zwolnienie
i zatwierdzenie tej samej rezerwacji), przejście statusu w domenie, `lockMany` produktów rezerwacji i zmiana stanów,
po jednym wpisie historii na linię. Powtórzenie tej samej operacji: sukces bez zmian. Nieznany klucz:
`ReservationNotFound` (404).

### Przyjęcie (`ReceiveStock`)

- Klucz przyjęcia jest wymagany (z panelu nagłówek `Idempotency-Key`).
- Handler zakłada (jeśli trzeba) i blokuje wiersz `StockItem`, potem szuka klucza w historii (`type = 'receipt'`).
  Równoległe przyjęcia tego samego produktu szereguje blokada wiersza.
- Klucz istnieje z tym samym produktem, ilością i notatką: zwracany jest bieżący stan bez zmiany (HTTP 200 zamiast
  201). Klucz istnieje z inną ilością lub notatką albo dla innego produktu: `ReceiptKeyReused` (409). Przypadek
  innego produktu wykrywa także unikalny indeks częściowy `(reference) WHERE type = 'receipt'`.
- Klucze przyjęć i rezerwacji są przechowywane na zawsze.

Korekta jest wartością bezwzględną z wersją: powtórzenie po sukcesie daje 409 (wersja się zmieniła), panel odświeża
wiersz, a stan nie zmienia się dwa razy.

## Persystencja i transakcje

- `inventory_stock_item`: `product_id uuid` (PK), `physical int`, `reserved int`, `version int`, `updated_at`;
  `CHECK (reserved >= 0 AND physical >= reserved AND physical <= 1000000)`.
- `inventory_reservation`: `reservation_key varchar(100)` (PK), `status`, `created_at`, `updated_at`.
- `inventory_reservation_line`: `reservation_key`, `product_id`, `quantity`; PK `(reservation_key, product_id)`;
  klucz obcy `reservation_key` → `inventory_reservation` (`inventory_reservation_line_reservation_fk`, bez
  `ON DELETE CASCADE`: rezerwacje są przechowywane na zawsze, więc nagłówek z liniami nie daje się usunąć).
- `inventory_stock_movement`: `id uuid` (v7, PK), `product_id`, `type`, `physical_delta`, `reserved_delta`,
  `physical_after`, `reserved_after`, `reason` (null), `note` (null), `reference` (null), `source`, `actor_id` (null),
  `actor_name` (null), `occurred_at`; indeks `(product_id, occurred_at DESC, id)`; unikalny indeks częściowy
  `(reference) WHERE type = 'receipt'`.
- Bez kluczy obcych do tabel Catalog.
- Repozytoria (`Inventory/Infrastructure/Persistence/Doctrine`) zapisują i czytają przez DBAL na połączeniu Doctrine,
  w transakcji `command.bus`: `SELECT … FOR UPDATE`, `INSERT … ON CONFLICT DO NOTHING`, `UPDATE` z wersją. Agregat jest
  odtwarzany przez `reconstitute()` bez wpisów historii. Rekordy Doctrine (`…/Doctrine/Record`) są wyłącznie definicją
  schematu dla `doctrine:schema` i migracji (`doctrine:schema:update --dump-sql` nie pokazuje różnic dla tabel
  Inventory), repozytoria ich nie używają. To świadomy wyjątek od reguły „agregat ↔ rekord” (`apps/api/CLAUDE.md`):
  rollback do savepointu przy zagnieżdżonym dispatchu nie czyści `UnitOfWork`, więc `flush` wywołującego zapisałby
  wycofane zmiany; błąd bazy zamyka wspólny `EntityManager`; `find()` z `PESSIMISTIC_WRITE` na encji już obecnej
  w identity map nie odświeża jej stanu (ryzyko sprzedaży ponad stan); `ON CONFLICT` i tak wymaga SQL.
- Stan, rezerwacja i wpisy historii zapisują się w jednej transakcji `command.bus` (`doctrine_transaction`).
  Nie ma zmiany stanu bez wpisu ani wpisu bez zmiany.
- Repozytoria nie mają operacji zmiany ani usuwania wpisów historii. Tabele Inventory czyści wyłącznie
  `inventory:seed-demo` (`DbalInventoryReset`), w kolejności zgodnej z kluczem obcym (linie przed nagłówkami
  rezerwacji) albo jednym `TRUNCATE` wszystkich tabel.

## Warstwa aplikacji

Komendy (`command.bus`):
- `ReceiveStock(productId, quantity, receiptKey, note, actorId, actorName)` → `StockView` i znacznik, czy to
  powtórzenie. Nieznany produkt (port `ProductCatalog`, Catalog `GetProductExists`): `ProductNotFound` (404).
- `CorrectStock(productId, countedPhysical, expectedVersion|null, reason, note, actorId, actorName)` → `StockView`.
  Nieznany produkt: 404. Produkt bez rekordu wymaga `expectedVersion = null`; rekord istnieje, a wersja jest inna
  (także `null`): 409.
- `CreateEmptyStock(productId)`: pusty rekord (0/0) w wersji 0 bez wpisu historii; idempotentna (istniejący rekord
  zostaje bez zmian). Nieznany produkt: `ProductNotFound` (404). Używa jej tylko seed.
- Port dla Ordering:
  - `ReserveStock(reservationKey, lines)` → `ReservationView {key, status, lines}`;
  - `ReleaseReservation(reservationKey)` → `ReservationView`;
  - `CommitReservation(reservationKey)` → `ReservationView`;
  - wyjątki: `InsufficientStock` (z `shortages`), `ReservationKeyReused`, `ReservationNotFound`,
    `ReservationStateConflict`, `InvalidReservation`.

Zapytania (`query.bus`):
- `GetStockLevels(productIds)` (1–100 id) → dla każdego id `StockLevel {productId, physical, reserved, available,
  level, version|null}`; produkt bez rekordu: zera i `version = null`. Jedno zapytanie `WHERE product_id = ANY(:ids)`,
  z bazy, bez cache. Autorytatywny odczyt dla przyszłych Cart i Ordering i źródło endpointu sklepu.
- `ListBackofficeStock(q, categoryId, after, perPage)`: strona produktów z portu `ProductDirectory` (Catalog
  `SearchBackofficeProducts`), stany strony jednym zapytaniem.
- `GetStockHistory(productId, after, perPage)`: od najnowszych, keyset po `(occurred_at, id)` malejąco. Nieznany
  produkt: 404. Produkt bez rekordu: pusta lista.

Kontrakt dla przyszłego Ordering (nie jest implementowany):
- Ordering wywołuje komendy w procesie, przez własny port, w swojej transakcji (zagnieżdżony dispatch na tym samym
  połączeniu), więc zamówienie i rezerwacja zapisują się razem.
- Kluczem rezerwacji jest identyfikator zamówienia.
- Zatwierdzenie przy wysyłce, zwolnienie przy anulowaniu lub nieopłaceniu. Czas na opłacenie ustala Ordering.

Dane demo: `inventory:seed-demo`
- Czyści tabele Inventory i przechodzi produkty z portu `ProductsForInventory` (Catalog `ListProductIdentities`).
- `fixtures/stock.yaml`: wzorce slugów → stan fizyczny (składnia i zasada „pierwszy pasujący” jak `prices.yaml`).
  Większość produktów 20–50 sztuk, kilka 2–3 (`low_stock`), kilka 0 (`out_of_stock`); co najmniej jeden produkt
  demo ma 0 (używa go E2E).
- Stan > 0: `ReceiveStock` ze źródłem `seed` i kluczem `seed:<productId>`. Stan 0: pusty rekord przez
  `CreateEmptyStock`, bez przyjęcia i wpisu historii.
- Produkt demo bez dopasowanego wzorca kończy seed błędem bez zmian w danych.
- `make seed` uruchamia go po `pricing:seed-demo`. W `prod` wymaga `--force-reset`.

## API

Błędy w formacie RFC 9457 (problem details).

Sklep (`apps/api/openapi.json`):
- `GET /api/v1/availability?productId=<uuid>&productId=…`: publiczny (`PUBLIC_ACCESS` dla `GET`),
  `Cache-Control: public, max-age=60`.
  - 1–48 id. Brak, za dużo lub niepoprawny UUID: 400.
  - `200 {items: [{productId, level}]}` dla każdego żądanego id (`level`: `in_stock`, `low_stock`, `out_of_stock`).
    Produkt bez rekordu i nieznany Catalogowi: `out_of_stock`. Liczba sztuk nigdy nie jest zwracana.

Panel (`apps/api/openapi-admin.json`, firewall `backoffice`, `Cache-Control: private, no-store`; bez tokena albo
z tokenem realmu `shop`: 401):

| Endpoint | Uprawnienie | Odpowiedzi |
|---|---|---|
| `GET /api/v1/admin/stock?q&category&after&perPage` | każdy pracownik | `200 {items: [{productId, name, stock: {physical, reserved, available, level, version, updatedAt} \| null}], next}`; 400 złe parametry; 404 nieznana kategoria |
| `POST /api/v1/admin/products/{productId}/stock/receipts`, nagłówek `Idempotency-Key`, body `{quantity: int, note: string \| null}` | `admin`, `warehouse` | 201 stan po przyjęciu; 200 powtórzenie z tym samym kluczem i treścią (stan bieżący); 400 brak lub zły klucz; 403; 404 nieznany produkt; 409 klucz użyty z inną treścią; 422 ilość, limit 1 000 000, notatka |
| `POST /api/v1/admin/products/{productId}/stock/corrections`, body `{physical: int, version: int \| null, reason: string, note: string \| null}` | `admin`, `warehouse` | 200 stan (ta sama wartość: bez zmiany wersji i bez wpisu); 403; 404; 409 konflikt wersji albo poniżej zarezerwowanego (różne `detail`); 422 zakres, powód, brak notatki przy `other` |
| `GET /api/v1/admin/products/{productId}/stock-history?after&perPage` | każdy pracownik | `200 {items: [{occurredAt, type, physicalDelta, reservedDelta, physicalAfter, reservedAfter, reason, note, reference, source, actorName}], next}`; 400 zły kursor lub parametr; 404 nieznany produkt |

- Uprawnienie `MANAGE_STOCK` sprawdza `StockVoter` w `Inventory_Infrastructure` na podstawie ról
  `AuthenticatedStaffMember`. `support` i `marketing` dostają 403.
- Aktor (`sub` i nazwa) pochodzi z tokena, nigdy z treści żądania.
- Uprawnienie jest sprawdzane przed treścią i produktem. Identyfikator, który nie jest UUID: 404; treść, która nie
  jest poprawnym JSON-em: 400; treść inna niż JSON: 415. Typy ścisłe, bez rzutowania (liczba w napisie, ułamek: 422).
  Oba klucze treści są wymagane (`note` może być `null`).
- Stronicowanie list i historii: `perPage` 1–100 (domyślnie 25), `after` to nieprzezroczysty kursor z `next`.
  Zły kursor: 400. `occurredAt` i `updatedAt` w UTC (RFC 3339). Identyfikator aktora nie jest zwracany.

## Sklep (`apps/store`)

- `loadCatalog` pobiera dostępność strony produktów jednym wywołaniem `GET /api/v1/availability`, równolegle z cenami.
- `ProductTile` pokazuje etykietę „Dostępny”, „Ostatnie sztuki” albo „Niedostępny”.
- Błąd endpointu dostępności nie psuje listy: kafelki bez etykiety dostępności, błąd w logu serwera.
- Produkty niedostępne zostają na liście, bez zmian w Elasticsearch.
- Nowa dostępność jest widoczna najpóźniej po czasie cache odpowiedzi (60 s). Rozstrzyga rezerwacja, nie widok sklepu.

## Panel (`apps/admin`)

- Pozycja menu „Stany magazynowe” i trasa `/inventory`.
- Lista: wyszukiwanie po nazwie (od 2 znaków, z opóźnieniem), filtr kategorii (drzewo), kolumny nazwa, fizyczny,
  zarezerwowany, dostępny, poziom; stronicowanie „następna/poprzednia” po kursorach. Produkt bez rekordu: zera.
- „Przyjęcie” i „Korekta” tylko dla ról `admin` i `warehouse` (role z `/api/v1/admin/me`; API sprawdza niezależnie).
  - Przyjęcie: ilość i notatka. Klucz (UUID) powstaje przy otwarciu dialogu i jest używany ponownie przy ponowieniu
    tego samego zapisu po błędzie sieci; nowy dialog to nowy klucz.
  - Korekta: stan policzony, powód z listy (Inwentaryzacja, Uszkodzenie, Zaginięcie, Inne), notatka (wymagana przy
    „Inne”), wersja z wiersza.
  - 409 wersji: „Stan został w międzyczasie zmieniony – odśwież” i odświeżenie wiersza; 409 poniżej zarezerwowanego
    i 409 klucza: `detail` z API; 422: komunikat naruszenia przy polu.
- „Historia” (przycisk w wierszu, dla każdego pracownika): wpisy od najnowszych, „Pokaż więcej”, etykiety typów (Przyjęcie, Korekta, Rezerwacja, Zwolnienie,
  Zatwierdzenie), powodów i źródeł (Pracownik, Dane demo, System).

## Scenariusze błędów

| Sytuacja | Wynik |
|---|---|
| Przyjęcie lub korekta przez `support` albo `marketing` | 403; w panelu brak przycisków |
| Ponowione przyjęcie z tym samym kluczem i treścią | 200, stan zwiększony raz |
| Ten sam klucz przyjęcia z inną treścią lub dla innego produktu | 409 |
| Korekta z nieaktualnego widoku | 409, bez nadpisania |
| Korekta poniżej stanu zarezerwowanego | 409 z liczbą zarezerwowanych |
| Przyjęcie ponad 1 000 000 sztuk stanu | 422 |
| Rezerwacja przy niedoborze dowolnej linii | `InsufficientStock` z listą braków, nic nie zarezerwowane |
| Ponowione `ReserveStock` z tym samym kluczem i liniami | aktualny status rezerwacji, bez zmiany stanów |
| Ten sam klucz rezerwacji z innymi liniami | `ReservationKeyReused` |
| Zatwierdzenie zwolnionej albo zwolnienie zatwierdzonej | `ReservationStateConflict` |
| Błąd zapisu historii | cała transakcja wycofana, stan bez zmian |
| Błąd endpointu dostępności w sklepie | lista bez etykiet dostępności |

## Kryteria akceptacji

1. Dostępny = fizyczny − zarezerwowany. Niezmiennik `0 ≤ reserved ≤ physical ≤ 1 000 000` pilnuje domena i `CHECK`
   w bazie.
2. Przyjęcie zwiększa stan fizyczny. To samo `Idempotency-Key` zwiększa go dokładnie raz, także przy równoległych
   żądaniach; inna treść z tym kluczem to 409.
3. Korekta ustawia stan policzony z obowiązkowym powodem (notatka przy `other`). Poniżej zarezerwowanego: 409.
   Nieaktualna wersja: 409.
4. Rezerwacja jest „wszystko albo nic”, nie wygasa i jest idempotentna po kluczu; ponowienie zwraca aktualny status
   bez zmiany stanów. Zwolnienie i zatwierdzenie są idempotentne, niedozwolone przejścia dają 409.
5. Każda zmiana stanu tworzy w tej samej transakcji wpis historii ze stanem po operacji, źródłem i aktorem.
6. Przyjęcia i korekty wykonują tylko `admin` i `warehouse`; stany i historię widzi każdy pracownik.
7. Sklep pokazuje poziom dostępności (próg „Ostatnie sztuki” 1–5 w API), nigdy liczby sztuk.
8. Kryteria współbieżności C1–C6 przechodzą na prawdziwym PostgreSQL w CI.
9. Po `make seed` każdy produkt demo ma rekord stanu.
10. Catalog i Pricing nie zależą od Inventory; Deptrac odrzuca próby obejścia.
11. Przechodzą `make -C apps/api check`, `make -C apps/api test`, `check` i `test` sklepu i panelu oraz `make e2e`.

## Testy

### Współbieżność (integracyjne, PostgreSQL)

Część `make -C apps/api test`, więc działają w CI. Każdy uczestnik to osobny proces (`pcntl_fork`, rozszerzenie jest
w obrazie API) z własnym jądrem i połączeniem do bazy, otwieranym po forku. Wspólny start przez barierę na advisory
locku PostgreSQL. Uczestnicy wywołują handlery aplikacji (komendy na `command.bus`), nie endpointy. Każdy scenariusz
w 5 rundach; po każdej rundzie niezmiennik: `reserved` = suma linii aktywnych rezerwacji, `physical` = suma
`physical_delta` historii, `available ≥ 0`.

| ID | Scenariusz | Oczekiwany wynik |
|---|---|---|
| C1 | 20 rezerwacji po 1 szt. (różne klucze), stan M = 7 | dokładnie 7 sukcesów i 13 `InsufficientStock`, żadnych innych błędów; `reserved` = 7, `available` = 0; 7 wpisów `reservation` |
| C2 | 10 rezerwacji `[A:1, B:1]`, połowa z liniami w odwrotnej kolejności; A = 5, B = 3 | dokładnie 3 sukcesy; A i B mają `reserved` = 3; zero zakleszczeń i błędów innych niż `InsufficientStock` |
| C3 | 10 procesów, ten sam klucz i linie rezerwacji | 1 rezerwacja, stan zmieniony raz, 10 identycznych wyników, 1 wpis na linię |
| C4 | 10 procesów, ten sam klucz przyjęcia | `physical` += q raz, 1 wpis, wszystkie wyniki to sukces |
| C5 | 10 procesów naraz zwalnia albo zatwierdza tę samą rezerwację | wygrywa jedno przejście; powtórzenia tego przejścia: sukces bez zmian; przeciwne: `ReservationStateConflict`; stan spójny z wygranym przejściem |
| C6 | korekty w dół równolegle z rezerwacjami | `physical ≥ reserved ≥ 0` po każdej operacji; bezpośredni SQL łamiący `CHECK` kończy się błędem |

### Pozostałe

- Architektura: Deptrac z regułami `Inventory_Infrastructure → Catalog_Application`,
  `Inventory_Infrastructure → Backoffice_Application`, `Inventory_UI → Backoffice_Application` oraz próbami obejścia
  (pliki tymczasowe muszą dać błąd): `Catalog_* → Inventory_*`, `Pricing_* → Inventory_*`, `Inventory_* → Pricing_*`,
  `Inventory_Domain → Catalog_*`, `Inventory_Application → Catalog_Domain`,
  `Inventory_Domain → Backoffice_Application`, `Inventory_Application → Backoffice_Application`,
  `Inventory_Infrastructure → Backoffice_Infrastructure`.
- Domena i aplikacja (TDD, adaptery in-memory): metody `StockItem` i limity, `AvailabilityLevel` na granicach 0/1/5/6,
  korekta bez zmiany, poniżej zarezerwowanego, wersja; `Reservation` (walidacja linii, przejścia, powtórzenia);
  handlery: idempotencja przyjęcia i rezerwacji (także ponowienie po zwolnieniu i zatwierdzeniu zwraca aktualny
  status), `InsufficientStock` z pełną listą braków, „wszystko albo nic”.
- Integracyjne (PostgreSQL): blokady i `ON CONFLICT`, wymuszony błąd zapisu historii wycofuje zmianę stanu,
  `GetStockLevels` dla 100 id jednym zapytaniem, indeks unikalny klucza przyjęcia, nowe zapytania Catalog, seed
  i reset (także produkt bez wzorca).
- Funkcjonalne API: każdy endpoint z przypadkami 200/201, 400, 401 (bez tokena i z tokenem `shop`), 403 (`support`,
  `marketing` przy POST), 404, 409, 415, 422.
- Vitest: sklep – etykiety poziomów, kafelek bez etykiety, `loadCatalog` przy błędzie dostępności; panel – widok
  stanów, dialogi przyjęcia (ponowne użycie klucza) i korekty (notatka przy „Inne”), 403, 409, 422, historia.
- E2E: pracownik `warehouse` przyjmuje towar dla produktu demo ze stanem 0, sklep pokazuje „Dostępny” (oczekiwanie
  do 70 s).

## Ryzyka i ograniczenia

- Stan jest per produkt. Warianty w Catalog wymuszą klucz stanu per wariant.
- Popularny produkt szereguje rezerwacje na jednym wierszu; przepustowość zależy od długości transakcji, a wywołanie
  w transakcji Ordering wydłuża blokadę. Wydajność nie jest potwierdzona testami obciążeniowymi.
- Wersja rośnie przy każdej rezerwacji, więc korekta w trakcie ruchu częściej kończy się 409.
- Historia rośnie o 2–3 wpisy na linię zamówienia, bez partycjonowania w MVP.
- Sklep pokazuje dostępność z opóźnieniem do 60 s i wykonuje trzecie wywołanie API na stronę listy.

## Dokumentacja do aktualizacji razem z implementacją

- `docs/vision.md`: w „Spójność i współbieżność” – optimistic locking (wersja) dla edycji z widoku, krótka blokada
  wiersza (`SELECT … FOR UPDATE`) dla atomowych operacji na stanie magazynowym; `Idempotency-Key` także dla przyjęcia
  magazynowego; na liście decyzji pozycja „Optimistic locking i klucze idempotencji zamiast blokad pesymistycznych”
  zastąpiona opisem modelu mieszanego.
- `apps/api/CLAUDE.md`: reguła optimistic locking z wyjątkiem dla operacji atomowych na stanie magazynowym;
  `inventory:seed-demo` w opisie `make seed`; `Idempotency-Key` przy przyjęciu magazynowym.
- `apps/api/deptrac.yaml`: warstwy Inventory oraz komentarze do reguł Inventory → Catalog i
  Inventory → Backoffice_Application.
