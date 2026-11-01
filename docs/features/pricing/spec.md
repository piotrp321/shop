# Pricing: ceny katalogowe (MVP)

Status: zaakceptowana do realizacji. Punkt 5 MVP z `docs/vision.md`.

## Cel i zakres

Cena katalogowa produktu w PLN: brutto wpisywane przez pracownika, netto i VAT wyliczane przez API, historia każdej
zmiany. Zarządzanie cenami w panelu, cena brutto na liście produktów w sklepie oraz autorytatywny odczyt cen wielu
produktów dla przyszłego koszyka i checkoutu.

Obsługujemy wyłącznie Polskę i PLN. Poza zakresem: promocje, kody rabatowe, cena Omnibus w sklepie, koszyk,
checkout, wiele krajów i walut, harmonogramy zmian stawek, edycja klasy podatkowej produktu (kontrakt w sekcji
„Przyszła zmiana klasy podatkowej”).

## Bounded contexty i zależności

| Context | Odpowiada za |
|---|---|
| Catalog | przypisanie produktowi klasy podatkowej (`TaxClassCode`), nazwy i kategorie, zapytania dla Pricing |
| Pricing | stawka VAT dla klasy, obliczenia, cena produktu, historia cen, autorytatywny odczyt cen |
| Sklep, panel | wyświetlanie i formatowanie kwot z API; żadnych obliczeń netto, VAT ani stawek |

- Zależność jest jednokierunkowa: `Pricing_Infrastructure → Catalog_Application` (adaptery portów Pricing wołają
  zapytania Catalog przez `query.bus`, jak `Backoffice\Infrastructure\QueryBusCatalogStatistics`).
- Catalog nie zależy od Pricing w żadnej warstwie. Deptrac nie ma żadnej reguły `Catalog_* → Pricing_*`.
- Domena Pricing ma własne value objecty (`ProductId`, `TaxClass` jako kod tekstowy) i nie importuje kodu Catalog.
  Adapter mapuje kod klasy z Catalog na `TaxClass` Pricing.
- Odczyt cen (`GetCurrentPrices`) czyta wyłącznie tabele Pricing, bez odczytu z Catalog.
- Pricing w MVP nie publikuje ani nie konsumuje zdarzeń domenowych: nie ma konsumenta, a integracje w modularnym
  monolicie są synchroniczne. Zdarzenie zmiany ceny (outbox) dochodzi razem z Varnishem i rewalidacją ISR.

## Klasa podatkowa produktu (Catalog)

- Produkt ma klasę podatkową `TaxClassCode` (enum z kodami tekstowymi), a nie stawkę. Klasa to jednorodna grupa
  towarów, nazwana po towarze, nie po stawce. W Polsce jedna klasa ma dokładnie jedną stawkę.
- Kolumna `catalog_product.tax_class`. Klasa nie występuje w publicznym API, w sklepie ani w indeksie ES.
- Nowa klasa: przypadek w `TaxClassCode` i stawka w `PolishVatRates` (Pricing). Test kontraktu wymaga obu.
- Klasy i stawki (stawki są **przykładowe dla danych demo, niezweryfikowane księgowo; przed uruchomieniem sprzedaży
  wymagają weryfikacji**, co jest też zapisane w komentarzu `PolishVatRates`):

| `TaxClassCode` | Towary demo | Stawka przykładowa |
|---|---|---|
| `standard` | sprzęt, odzież, obuwie, hantle | 23% |
| `printed_book` | przewodniki z ISBN | 5% |
| `printed_map` | mapy turystyczne | 5% |
| `freeze_dried_meal` | dania liofilizowane | 5% |
| `energy_bar` | batony energetyczne | 5% |
| `energy_gel` | żele energetyczne | 8% |
| `protein_powder` | odżywki białkowe | 8% |
| `electrolyte_tablet` | elektrolity w tabletkach | 23% |

- Migracja (nowa, istniejących się nie edytuje) zmienia `tax_category` na `tax_class` i mapuje wartości:
  `standard` → `standard`, `printed_publication` → `printed_book`, `food` → `freeze_dried_meal`,
  `food_supplement` → `protein_powder`. Dokładne klasy nadają dane demo (`fixtures/catalog.yaml`, klucz `taxClass`),
  odtwarzane przez `make seed`.

## Model domenowy (Pricing)

- `Money`: liczba całkowita w groszach i `Currency` (tylko `PLN`). Moduł nie używa `float`.
- `GrossPrice`: `Money` w zakresie 1–99 999 999 groszy (0,01–999 999,99 zł). Poza zakresem: wyjątek `InvalidInput`.
- `VatRate`: stawka w punktach bazowych (`2300` = 23%), liczba całkowita.
- `PolishVatRates`: `rateFor(TaxClass): VatRate`, tabela w kodzie domeny. Klasa bez stawki: `TaxClassWithoutRate`
  (`InvalidInput`).
- `PriceBreakdown::fromGross(GrossPrice, VatRate)`: jedyne miejsce obliczeń.
  - `d = 10000 + bp`, netto = `intdiv(2 · brutto · 10000 + d, 2 · d)` (HALF_UP do grosza, tylko liczby całkowite).
  - VAT = brutto − netto, więc netto + VAT = brutto zawsze co do grosza.
  - Zakres `GrossPrice` wyklucza przepełnienie `int`.
- Agregat `ProductPrice` (identyfikator = `ProductId`): `breakdown`, `taxClass`, `version`, niezapisane wpisy historii.
  - `create(id, gross, taxClass, rates, origin)` – pierwsza cena, jeden wpis historii.
  - `changeGross(gross, rates, origin): bool` – nowa kwota, z bieżącą stawką dla klasy ceny.
  - `reprice(taxClass, rates, origin): bool` – to samo brutto, klasa i stawka od nowa (reconcile, przyszła zmiana klasy).
  - `false`, gdy komplet brutto, netto, VAT, stawka i klasa jest identyczny: bez wpisu historii i bez zmiany wersji.
  - `isCurrent(rates): bool` – zapisana stawka równa stawce z `PolishVatRates` dla zapisanej klasy.
- `PriceHistoryEntry`: snapshot netto, VAT, brutto, stawki, klasy, waluty, `changedAt`, źródło i aktor. Tylko
  dopisywany.
- `PriceChangeOrigin`: źródło (`staff`, `seed`, `tax_reconciliation`) i aktor (`sub` oraz nazwa pracownika z tokena;
  `null` dla źródeł systemowych).

Historia wystarcza do późniejszego Omnibusa: najniższe brutto z 30 dni przed datą to zapytanie po
`(product_id, changed_at)`.

## Persystencja i transakcje

- `pricing_product_price`: `product_id uuid` (PK), `currency`, `gross_amount`, `net_amount`, `vat_amount`,
  `vat_rate_bp`, `tax_class`, `version`, `updated_at`.
- `pricing_price_history`: `id uuid` (PK), `product_id`, `currency`, `gross_amount`, `net_amount`, `vat_amount`,
  `vat_rate_bp`, `tax_class`, `changed_at`, `source`, `actor_id` (null), `actor_name` (null);
  indeks `(product_id, changed_at DESC, id)`.
- Bez kluczy obcych do tabel Catalog (granica modułu, mimo wspólnej bazy).
- Rekordy Doctrine z atrybutami w `Pricing/Infrastructure/Persistence/Doctrine/Record`, mapowanie agregat ↔ rekord
  w repozytorium, `reconstitute()` bez wpisów. Optimistic locking przez `#[Version]` na cenie.
- Cena i jej nowe wpisy historii zapisują się w jednej transakcji. Nie ma zmiany ceny bez wpisu ani wpisu bez zmiany.
- Kod tylko dopisuje historię: repozytorium nie ma operacji zmiany ani usuwania wpisów. Reset danych demo czyści
  tabele Pricing razem z katalogiem.

## Warstwa aplikacji

Pricing:
- `SetProductPrice(productId, gross, expectedVersion|null, actorId, actorName)`
  - Klasa produktu z portu `ProductTaxClasses` (Catalog `GetProductTaxClass`). Nieznany produkt: `ProductNotFound` (404).
  - Brak ceny i `expectedVersion` różne od `null`: 409. Cena istnieje, a `expectedVersion` jest inne (także `null`): 409
    (`PriceModifiedConcurrently`, `Conflict`). Wersję sprawdza handler, a przy zapisie dodatkowo `#[Version]` i PK,
    więc równoległe zapisy tej samej wersji lub dwa równoległe pierwsze zapisy kończą się jednym sukcesem i 409.
  - Istniejąca cena: `changeGross` z bieżącą stawką klasy ceny (zapis naprawia też nieaktualną stawkę, jednym wpisem).
- `GetCurrentPrices(productIds)` (1–100 id)
  - Jedno zapytanie `WHERE product_id = ANY(:ids)` po tabelach Pricing.
  - Zwraca `PriceSnapshot {productId, taxClass, vatRateBp, net, vat, gross, currency, version}` wyłącznie dla cen
    z `isCurrent`. Produkt bez ceny lub z nieaktualną stawką nie ma ceny i nie może zostać kupiony.
  - Autorytatywny odczyt dla przyszłego koszyka i checkoutu: wywoływany w procesie przez port Cart/Ordering, z bazy,
    bez cache HTTP. Ordering kopiuje te wartości do pozycji zamówienia, a `version` pozwala wykryć zmianę ceny.
- `ListBackofficePrices(q, categoryId, after, perPage)`: strona produktów z portu `ProductDirectory`
  (Catalog `SearchBackofficeProducts`), ceny strony jednym zapytaniem, `applicableVatRate` z `PolishVatRates`,
  `vatRateOutdated` = nie `isCurrent`.
- `GetPriceHistory(productId, after, perPage)`: od najnowszych, stronicowanie keyset. Nieznany produkt: 404.
- `ReconcileTax(apply)`: opis w sekcji „Reconcile”.
- Dane demo: `pricing:seed-demo`. `fixtures/prices.yaml` przypisuje brutto do wzorców slugów produktów demo
  (np. `przewodnik-*-tatry-zachodnie-*: 5490`). Produkty z portu `ListProductsForPricing`, zapis przez
  `SetProductPrice` ze źródłem `seed`. Produkt demo bez dopasowanego wzorca kończy seed błędem. `make seed`
  uruchamia go po danych katalogu, więc każdy produkt demo ma cenę.

Catalog:
- `GetProductTaxClass(productId)`.
- `SearchBackofficeProducts(q?, categoryId?, after?, perPage)`: zapytanie do PostgreSQL (DBAL), wynik
  `{id, name, taxClass}`. Nazwa przez `ILIKE` z indeksem trigramowym (`pg_trgm`, GIN na `catalog_product.name`),
  `q` od 2 do 100 znaków. Kategoria z podkategoriami po ścieżce zmaterializowanej, jako kategoria główna lub dodatkowa.
  Sortowanie `(name, id)`, kursor keyset, bez liczenia wszystkich wyników.
- `ListProductsForPricing(after, limit)`: `{id, slug, taxClass}` partiami po `id`, dla seeda i reconcile.

## Reconcile

`pricing:reconcile-tax [--apply]` to świadomie uruchamiane narzędzie kontrolno-naprawcze. Nie wywołuje go
`make init`, `make up` ani `make seed`.

- Przechodzi wszystkie ceny partiami (keyset) i wykrywa: stawkę różną od `PolishVatRates` dla klasy ceny oraz klasę
  ceny różną od bieżącej klasy produktu w Catalog (zbiorczo przez `ListProductsForPricing`).
- Bez `--apply` tylko raportuje. Z `--apply` przelicza `reprice` (brutto bez zmian) ze źródłem `tax_reconciliation`,
  każdą cenę w osobnej transakcji.
- Idempotentne: kolejne uruchomienie po naprawie nic nie znajduje.
- Konflikt wersji z równoległą edycją: ponowny odczyt tej ceny i jedna kolejna próba, potem pozycja w raporcie.
- Cena, dla której klasa z Catalog nie ma stawki, zostaje bez zmian i trafia do raportu. Cena produktu, którego nie
  ma w Catalog, zachowuje swoją klasę.
- Kod wyjścia: 0 – spójne albo naprawione, 1 – bez `--apply` znaleziono rozbieżności albo z `--apply` zostały
  nienaprawione (konflikt po ponownej próbie, klasa bez stawki), 2 – błąd (np. niedostępna baza).

Procedura zmiany stawki: zmiana w `PolishVatRates` i wdrożenie, potem `pricing:reconcile-tax --apply`. Do tego
czasu ceny z nieaktualną stawką są niedostępne w sklepie (bezpieczne zachowanie), a panel oznacza je
`vatRateOutdated`.

## Przyszła zmiana klasy podatkowej (kontrakt)

Dziś nic nie zmienia klasy podatkowej produktu i ten mechanizm nie jest implementowany. Gdy powstanie:

- Przypadek użycia `ChangeProductTaxClass(productId, taxClass, expectedProductVersion, actor)` należy do
  Pricing_Application, bo konsekwencją zmiany klasy jest cena; kierunek zależności Pricing → Catalog zostaje.
- W jednej transakcji szyny komend: komenda Catalog `AssignTaxClass` przez port (z wersją produktu), potem
  `ProductPrice::reprice` z nowej klasy (źródło `staff`).
- Błąd lub konflikt wersji produktu albo ceny wycofuje oba kroki. Bez zdarzeń i bez okna niespójności.
- Catalog nie udostępnia innej drogi zmiany klasy niż ten przypadek użycia.
- `pricing:reconcile-tax` wykrywa rozjazd klasy, gdyby jednak do niego doszło.

## API

Błędy w formacie RFC 9457 (problem details).

Sklep (`apps/api/openapi.json`):
- `GET /api/v1/prices?productId=<uuid>&productId=…` – publiczny (`PUBLIC_ACCESS` dla `GET` w `security.yaml`),
  `Cache-Control: public, max-age=60`.
  - 1–48 id. Brak, za dużo lub niepoprawny UUID: 400.
  - `200 {items: [{productId, gross, currency}]}` – `gross` w groszach. Produkty bez aktualnej ceny i nieznane id
    są pominięte.
- `GET /api/v1/products` bez zmian.

Panel (`apps/api/openapi-admin.json`, firewall `backoffice`, `Cache-Control: private, no-store`; bez tokena albo
z tokenem realmu `shop`: 401):

| Endpoint | Uprawnienie | Odpowiedzi |
|---|---|---|
| `GET /api/v1/admin/prices?q&category&after&perPage` | każdy pracownik | `200 {items: [{productId, name, taxClass, applicableVatRate, price: {gross, net, vat, vatRate, currency, version, updatedAt, vatRateOutdated} \| null}], next}`; 400 złe parametry; 404 nieznana kategoria |
| `PUT /api/v1/admin/products/{productId}/price`, body `{gross: int, version: int \| null}` | rola `admin` | 200 cena (ta sama kwota: bez zmiany wersji i bez wpisu); 403; 404 nieznany produkt; 409 konflikt wersji; 422 kwota poza zakresem lub zły typ |
| `GET /api/v1/admin/products/{productId}/price-history?after&perPage` | każdy pracownik | `200 {items: [{changedAt, gross, net, vat, vatRate, taxClass, source, actorName}], next}`; 404 nieznany produkt |
| `GET /api/v1/admin/categories` | każdy pracownik | 200 drzewo kategorii (`GetCategoryTree`) |

- Uprawnienie do zmiany ceny sprawdza `PriceVoter` w `Pricing_Infrastructure` na podstawie ról
  `AuthenticatedStaffMember`. `marketing`, `support` i `warehouse` dostają 403.
- Aktor (`sub` i nazwa) pochodzi z tokena, nigdy z treści żądania.
- Kwoty zawsze w groszach (`integer`), stawki w punktach bazowych.

## Sklep (`apps/store`)

- `loadCatalog` po pobraniu strony produktów pobiera ich ceny jednym wywołaniem `GET /api/v1/prices`.
- `ProductTile` pokazuje brutto przez `formatPrice(gross, currency)` (`Intl.NumberFormat("pl-PL")`) albo
  „Cena niedostępna”.
- Błąd endpointu cen nie psuje listy: produkty renderują się z „Cena niedostępna”, a błąd trafia do logu serwera.
- Nowa cena jest widoczna w sklepie najpóźniej po czasie cache odpowiedzi (60 s).

## Panel (`apps/admin`)

- Pozycja menu „Ceny” i trasa `/prices`.
- Lista: wyszukiwanie po nazwie (od 2 znaków, z opóźnieniem), filtr kategorii (drzewo), kolumny nazwa, klasa,
  brutto, netto, VAT, stawka, oznaczenie nieaktualnej stawki; stronicowanie „następna/poprzednia” po kursorach.
- „Zmień cenę” (tylko dla roli `admin`, role z `/api/v1/admin/me`; API sprawdza niezależnie):
  - pole brutto w PLN, zamiana tekstu na grosze bez `float` w osobnym helperze;
  - podgląd stawki z `applicableVatRate`, bez liczenia netto na froncie;
  - 409: „Cena została w międzyczasie zmieniona – odśwież” i odświeżenie wiersza;
  - 422: komunikat przy polu.
- „Historia”: wpisy od najnowszych, „Pokaż więcej”, etykiety źródeł (Pracownik, Dane demo, Uzgodnienie podatkowe).

## Scenariusze błędów

| Sytuacja | Wynik |
|---|---|
| Zmiana ceny przez pracownika bez roli `admin` | 403; w panelu brak przycisku |
| Zapis z nieaktualnego widoku | 409, bez nadpisania |
| Dwa równoległe pierwsze zapisy ceny | jeden sukces, drugi 409 |
| Nieznany produkt | 404 |
| Kwota 0, ujemna, ponad 999 999,99 zł, nie liczba całkowita groszy | 422 |
| Stawka zmieniona w kodzie przed `reconcile --apply` | sklep: „Cena niedostępna”; `GetCurrentPrices` pomija; panel: `vatRateOutdated` |
| Błąd zapisu historii | cała transakcja wycofana, cena bez zmian |
| Błąd endpointu cen w sklepie | lista bez cen („Cena niedostępna”) |

## Kryteria akceptacji

1. Pracownik wpisuje brutto, API wylicza netto i VAT. Kwoty w groszach bez `float`, HALF_UP, VAT = brutto − netto.
2. Cenę zmienia tylko `admin`, każdy pracownik ją widzi. Limity 0,01–999 999,99 zł.
3. Każda faktyczna zmiana kwoty lub stawki tworzy w tej samej transakcji jeden wpis historii z pełnym snapshotem,
   źródłem i aktorem. Identyczny komplet nie tworzy wpisu ani nowej wersji.
4. Zapis z nieaktualną wersją kończy się 409 bez nadpisania.
5. Sklep pokazuje brutto albo „Cena niedostępna”. `GetCurrentPrices` nie zwraca ceny z nieaktualną stawką.
6. Catalog nie zależy od Pricing; Deptrac odrzuca próby obejścia.
7. `pricing:reconcile-tax` domyślnie raportuje, z `--apply` naprawia, jest idempotentne i nie jest uruchamiane
   automatycznie.
8. Po `make seed` każdy produkt demo ma cenę. Stawki są oznaczone jako przykładowe.
9. Przechodzą `make -C apps/api check`, `make -C apps/api test`, `check` i `test` sklepu i panelu oraz `make e2e`.

## Testy

- Architektura: Deptrac z regułą `Pricing_Infrastructure → Catalog_Application` i próbami obejścia (pliki
  tymczasowe muszą dać błąd): `Catalog_* → Pricing_*`, `Pricing_Domain → Catalog_*`,
  `Pricing_Application → Catalog_Domain`. Test kontraktu: każdy przypadek `TaxClassCode` ma stawkę
  w `PolishVatRates` i każda stawka ma przypadek.
- Obliczenia (TDD): przypadki graniczne HALF_UP dla każdej stawki; test własności dla każdego brutto
  1–100 000 groszy i dla maksimum (netto + VAT = brutto, VAT ≥ 0, wynik równy obliczeniu referencyjnemu); stawka 0 bp;
  limity `GrossPrice`.
- Agregat i handlery (adaptery in-memory): brak wpisu i wersji przy identycznym komplecie, jeden wpis przy zmianie
  kwoty lub stawki, 409 przy złej wersji i przy `null` dla istniejącej ceny, pomijanie nieaktualnych cen
  w `GetCurrentPrices`, reconcile (raport, `--apply`, idempotencja, kody wyjścia).
- Integracyjne (PostgreSQL): wymuszony błąd zapisu historii wycofuje zmianę ceny; dwie zmiany z tą samą wersją
  dają jeden sukces i 409, liczba wpisów równa liczbie sukcesów; dwa równoległe pierwsze zapisy; `GetCurrentPrices`
  dla 100 id jednym zapytaniem; `SearchBackofficeProducts` (nazwa, podkategorie, kategoria dodatkowa, keyset);
  migracja klas; reconcile na poprawnej ścieżce i przy niedostępnej bazie.
- Funkcjonalne API: każdy endpoint z przypadkami 200, 400 lub 422, 401 (bez tokena i z tokenem `shop`), 403
  (`marketing`, `support`, `warehouse` przy `PUT`), 404 i 409.
- Vitest: sklep – `formatPrice`, kafelek z ceną i bez, `loadCatalog` przy błędzie cen; panel – zamiana kwoty
  na grosze, widok cen (403, 409, pusty wynik), historia.
- E2E: admin zmienia cenę w panelu, nowa cena jest widoczna w sklepie (oczekiwanie do 70 s).

## Ryzyka i ograniczenia

- Cena jest per produkt. Warianty w Catalog wymuszą klucz ceny per wariant.
- Ceny nie ma w Elasticsearch. Sortowanie i filtrowanie po cenie wymaga później zdarzenia i reindeksu.
- Sklep wykonuje dwa kolejne wywołania API na stronę listy. Cache Varnisha dla zestawów id będzie słabo trafiał.
- Wyszukiwanie trigramowe z filtrem kategorii przy 10 mln produktów jest przeznaczone dla ruchu panelu. Wydajność
  nie jest potwierdzona testami obciążeniowymi.

## Dokumentacja do aktualizacji razem z implementacją

- `docs/vision.md`: „kategoria podatkowa (`taxCategory`)” → „klasa podatkowa (`taxClass`)” w regułach domenowych
  i na liście decyzji; stawki dla PL w kodzie Pricing.
- `apps/api/CLAUDE.md`: `pricing:seed-demo` w `make seed` i opis `pricing:reconcile-tax`.
- `apps/api/deptrac.yaml`: warstwy Pricing i komentarz do reguły Pricing → Catalog.
