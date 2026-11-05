# Pricing: ceny katalogowe (MVP)

Status: zaakceptowana do realizacji. Punkt 5 MVP z `docs/vision.md`.

## Cel i zakres

Cena katalogowa wariantu produktu w PLN: brutto wpisywane przez pracownika, netto i VAT wyliczane przez API, historia
każdej zmiany. Kluczem ceny i historii jest wariant (`variantId` z Catalog, `docs/features/variants/spec.md`); produkt
bez osi wariantów ma jeden wariant domyślny. Zarządzanie cenami w panelu, cena brutto na liście produktów w sklepie oraz
autorytatywny odczyt cen wielu wariantów dla koszyka i przyszłego checkoutu.

Obsługujemy wyłącznie Polskę i PLN. Poza zakresem: promocje, kody rabatowe, cena Omnibus w sklepie, koszyk,
checkout, wiele krajów i walut, harmonogramy zmian stawek, edycja klasy podatkowej produktu (kontrakt w sekcji
„Przyszła zmiana klasy podatkowej”).

## Bounded contexty i zależności

| Context | Odpowiada za |
|---|---|
| Catalog | przypisanie produktowi klasy podatkowej (`TaxClassCode`), nazwy, kategorie i warianty, zapytania dla Pricing |
| Pricing | stawka VAT dla klasy, obliczenia, cena wariantu, historia cen, autorytatywny odczyt cen |
| Sklep, panel | wyświetlanie i formatowanie kwot z API; żadnych obliczeń netto, VAT ani stawek |

- Zależność jest jednokierunkowa: `Pricing_Infrastructure → Catalog_Application` (adaptery portów Pricing wołają
  zapytania Catalog przez `query.bus`, jak `Backoffice\Infrastructure\QueryBusCatalogStatistics`).
- Catalog nie zależy od Pricing w żadnej warstwie. Deptrac nie ma żadnej reguły `Catalog_* → Pricing_*`.
- Pricing zależy od Backoffice wyłącznie przez kontrakt bezpieczeństwa `AuthenticatedStaffMember` z `Backoffice_Application`:
  `Pricing_Infrastructure` (`PriceVoter`, role pracownika) i `Pricing_UI` (aktor zmiany ceny w `ChangePriceController`).
  `Pricing_Domain` i `Pricing_Application` nie zależą od Backoffice, a żadna warstwa Pricing nie zależy od
  `Backoffice_Infrastructure`, `Backoffice_Domain` ani `Backoffice_UI`.
- Domena Pricing ma własne value objecty (`VariantId`, `TaxClass` jako kod tekstowy; `ProductId` tylko w liście cen
  panelu) i nie importuje kodu Catalog. Adapter mapuje kod klasy z Catalog na `TaxClass`
  Pricing. Klasa podatkowa wariantu to klasa jego produktu.
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
- Agregat `VariantPrice` (identyfikator = `VariantId`): `breakdown`, `taxClass`, `version`, niezapisane wpisy historii.
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
`(variant_id, changed_at)`.

## Persystencja i transakcje

- `pricing_variant_price`: `variant_id uuid` (PK), `currency`, `gross_amount`, `net_amount`, `vat_amount`,
  `vat_rate_bp`, `tax_class`, `version`, `updated_at`.
- `pricing_price_history`: `id uuid` (PK), `variant_id`, `currency`, `gross_amount`, `net_amount`, `vat_amount`,
  `vat_rate_bp`, `tax_class`, `changed_at`, `source`, `actor_id` (null), `actor_name` (null);
  indeks `(variant_id, changed_at DESC, id)`.
- Migracja `Version20261104130000KeyPricesByVariant` przemianowała `pricing_product_price` na `pricing_variant_price`
  i `product_id` na `variant_id` w obu tabelach, bez zmiany wartości: migracja Catalog nadała każdemu istniejącemu
  produktowi wariant domyślny o `id` produktu, więc każda cena i każdy wpis historii zachowały identyfikator i wskazują
  wariant domyślny swojego dawnego produktu.
- Bez kluczy obcych do tabel Catalog (granica modułu, mimo wspólnej bazy).
- Rekordy Doctrine z atrybutami w `Pricing/Infrastructure/Persistence/Doctrine/Record`, mapowanie agregat ↔ rekord
  w repozytorium, `reconstitute()` bez wpisów. Optimistic locking przez `#[Version]` na cenie.
- Cena i jej nowe wpisy historii zapisują się w jednej transakcji. Nie ma zmiany ceny bez wpisu ani wpisu bez zmiany.
- Kod tylko dopisuje historię: repozytorium nie ma operacji zmiany ani usuwania wpisów. Tabele Pricing czyści tylko
  reset danych demo: `pricing:seed-demo` (`DbalPricingReset`), który `make seed` uruchamia po seedzie katalogu.

## Warstwa aplikacji

Pricing:
- `SetVariantPrice(variantId, gross, expectedVersion|null, actorId, actorName)`
  - Klasa produktu wariantu z portu `VariantTaxClasses` (Catalog `GetVariant`). Nieznany wariant (także identyfikator
    produktu): `VariantNotFound` (404, „Wariant produktu nie istnieje.”).
  - Brak ceny i `expectedVersion` różne od `null`: 409. Cena istnieje, a `expectedVersion` jest inne (także `null`): 409
    (`PriceModifiedConcurrently`, `Conflict`). Wersję sprawdza handler, a przy zapisie dodatkowo `#[Version]` i PK,
    więc równoległe zapisy tej samej wersji lub dwa równoległe pierwsze zapisy kończą się jednym sukcesem i 409.
  - Istniejąca cena: `changeGross` z bieżącą stawką klasy ceny (zapis naprawia też nieaktualną stawkę, jednym wpisem).
- `GetCurrentPrices(variantIds)` (1–4800 id, czyli 48 produktów po 100 wariantów)
  - Jedno zapytanie `WHERE variant_id = ANY(:ids)` po tabelach Pricing.
  - Zwraca `PriceSnapshot {variantId, taxClass, vatRateBp, net, vat, gross, currency, version}` wyłącznie dla cen
    z `isCurrent`, w kolejności żądania (powtórzone id raz). Wariant bez ceny lub z nieaktualną stawką nie ma ceny
    i nie może zostać kupiony.
  - Autorytatywny odczyt dla koszyka i przyszłego checkoutu: wywoływany w procesie przez port Cart/Ordering, z bazy,
    bez cache HTTP. Ordering kopiuje te wartości do pozycji zamówienia, a `version` pozwala wykryć zmianę ceny.
- `ListBackofficePrices(q, categoryId, after, perPage)`: strona produktów z portu `ProductDirectory`
  (Catalog `SearchBackofficeProducts`, kursor po produktach), warianty strony z etykietami jednym wywołaniem
  (Catalog `ListProductVariants`) i ich ceny jednym zapytaniem. Wynik: produkty strony, a w każdym jego warianty
  w kolejności pozycji z ceną albo `null`. `applicableVatRate` z `PolishVatRates` dla klasy produktu,
  `vatRateOutdated` = nie `isCurrent`.
- `GetPriceHistory(variantId, after, perPage)`: od najnowszych, stronicowanie keyset. Nieznany wariant: 404; wariant
  bez ceny: pusta lista.
- `ReconcileTax(apply)`: opis w sekcji „Reconcile”.
- Dane demo: `pricing:seed-demo`. `fixtures/prices.yaml` przypisuje brutto do wzorców kluczy wariantów demo
  (`key` = `<slug produktu>/<slug etykiety>`, wariant domyślny `<slug produktu>/standard`; `*` dopasowuje dowolny
  ciąg znaków, także `/`; np. `przewodnik-*-tatry-zachodnie-*: 5490`). Warianty z portu `VariantsForPricing`
  (Catalog `ListVariantIdentities`), zapis przez `SetVariantPrice` ze źródłem `seed`. Wariant demo bez dopasowanego
  wzorca kończy seed błędem bez zmian w danych. `make seed` uruchamia go po danych katalogu, więc każdy wariant demo
  ma cenę.

Catalog (zapytania o warianty opisuje `docs/features/variants/spec.md`):
- `GetVariant(variantId)` → `{variantId, productId, taxClass}` albo `null`.
- `ListProductVariants(productIds)` (1–100 id) → warianty produktów z etykietami w kolejności pozycji.
- `ListVariantIdentities(after, limit)` → `{variantId, key, taxClass}` partiami po `variantId`, dla seeda i reconcile.
- `SearchBackofficeProducts(q?, categoryId?, after?, perPage)`: zapytanie do PostgreSQL (DBAL), wynik
  `{id, name, taxClass}`. Nazwa przez `ILIKE` z indeksem trigramowym (`pg_trgm`, GIN na `catalog_product.name`),
  `q` od 2 do 100 znaków. Kategoria z podkategoriami po ścieżce zmaterializowanej, jako kategoria główna lub dodatkowa.
  Sortowanie `(name, id)`, kursor keyset, bez liczenia wszystkich wyników.

## Reconcile

`pricing:reconcile-tax [--apply]` to świadomie uruchamiane narzędzie kontrolno-naprawcze. Nie wywołuje go
`make init`, `make up` ani `make seed`.

- Przechodzi wszystkie ceny wariantów partiami (keyset po `variant_id`) i wykrywa: stawkę różną od `PolishVatRates`
  dla klasy ceny oraz klasę ceny różną od bieżącej klasy produktu wariantu w Catalog (zbiorczo przez
  `ListVariantIdentities`, partiami po `variantId` obok cen).
- Bez `--apply` tylko raportuje. Z `--apply` przelicza `reprice` (brutto bez zmian) ze źródłem `tax_reconciliation`,
  każdą cenę w osobnej transakcji.
- Idempotentne: kolejne uruchomienie po naprawie nic nie znajduje.
- Konflikt wersji z równoległą edycją: ponowny odczyt tej ceny i jedna kolejna próba, potem pozycja w raporcie.
- Cena, dla której klasa z Catalog nie ma stawki, zostaje bez zmian i trafia do raportu. Cena wariantu, którego nie
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
  `VariantPrice::reprice` wszystkich cen wariantów produktu z nowej klasy (źródło `staff`).
- Błąd lub konflikt wersji produktu albo ceny wycofuje oba kroki. Bez zdarzeń i bez okna niespójności.
- Catalog nie udostępnia innej drogi zmiany klasy niż ten przypadek użycia.
- `pricing:reconcile-tax` wykrywa rozjazd klasy, gdyby jednak do niego doszło.

## API

Błędy w formacie RFC 9457 (problem details).

Sklep (`apps/api/openapi.json`):
- Publicznego endpointu cen nie ma: cenę „od” i ceny wariantów kafelka zwraca oferta produktów listy
  (`GET /api/v1/product-offers`, Cart, `docs/features/cart/spec.md`), która czyta ceny przez `GetCurrentPrices`.
- `GET /api/v1/products` bez zmian.

Panel (`apps/api/openapi-admin.json`, firewall `backoffice`, `Cache-Control: private, no-store`; bez tokena albo
z tokenem realmu `shop`: 401):

| Endpoint | Uprawnienie | Odpowiedzi |
|---|---|---|
| `GET /api/v1/admin/prices?q&category&after&perPage` | każdy pracownik | `200 {items: [{productId, name, taxClass, applicableVatRate, variants: [{variantId, label, price: {gross, net, vat, vatRate, currency, version, updatedAt, vatRateOutdated} \| null}]}], next}`; kursor i `perPage` po produktach; 400 złe parametry; 404 nieznana kategoria |
| `PUT /api/v1/admin/variants/{variantId}/price`, body `{gross: int, version: int \| null}` | rola `admin` | 200 cena (ta sama kwota: bez zmiany wersji i bez wpisu); 403; 404 nieznany wariant; 409 konflikt wersji; 422 kwota poza zakresem lub zły typ |
| `GET /api/v1/admin/variants/{variantId}/price-history?after&perPage` | każdy pracownik | `200 {items: [{changedAt, currency, gross, net, vat, vatRate, taxClass, source, actorName}], next}`; 404 nieznany wariant |
| `GET /api/v1/admin/categories` | każdy pracownik | 200 drzewo kategorii (`GetCategoryTree`) |

- Uprawnienie do zmiany ceny sprawdza `PriceVoter` w `Pricing_Infrastructure` na podstawie ról
  `AuthenticatedStaffMember`. `marketing`, `support` i `warehouse` dostają 403.
- Aktor (`sub` i nazwa) pochodzi z tokena, nigdy z treści żądania.
- `PUT` ceny: oba klucze są wymagane, a typy ścisłe (liczba w napisie, ułamek, także `100.0`, to 422, bez
  rzutowania). Uprawnienie jest sprawdzane przed treścią i wariantem. Identyfikator, który nie jest UUID: 404;
  treść, która nie jest poprawnym JSON-em: 400; treść inna niż JSON: 415. Odpowiedź 200 to cena zwrócona przez
  `SetVariantPrice` po zapisie (z wersją zapisu), w kształcie `price` wariantu z listy.
- Historia (`price-history`): `perPage` 1–100 (domyślnie 25), `after` to nieprzezroczysty kursor z `next`. Kolejność
  `(changed_at, id)` malejąco: przy zmianach w tej samej sekundzie pierwsza jest późniejsza (identyfikatory wpisów są
  uporządkowane w czasie). Zły kursor albo parametr: 400. Wariant istniejący w Catalog bez ceny: 200 z pustą listą;
  „nieznany” znaczy nieznany Catalogowi, jak przy `PUT` ceny. `changedAt` w UTC (RFC 3339), a identyfikator aktora
  nie jest zwracany, tylko `actorName`.
- Kwoty zawsze w groszach (`integer`), stawki w punktach bazowych.

## Sklep (`apps/store`)

- Ceny listy pochodzą z oferty produktów (`GET /api/v1/product-offers`, `docs/features/cart/spec.md`): `ProductTile`
  pokazuje brutto przez `formatPrice(gross, currency)` (`Intl.NumberFormat("pl-PL")`), „od X zł” przy różnych cenach
  wariantów możliwych do kupienia, albo „Cena niedostępna” przy błędzie ofert.
- Nowa cena jest widoczna w sklepie najpóźniej po czasie cache odpowiedzi (60 s).

## Panel (`apps/admin`)

- Pozycja menu „Ceny” i trasa `/prices`.
- Lista: wyszukiwanie po nazwie (od 2 znaków, z opóźnieniem), filtr kategorii (drzewo), stronicowanie
  „następna/poprzednia” po kursorach (strona to produkty). Wiersz na wariant, warianty pod nazwą swojego produktu
  (nazwa i klasa w pierwszym wierszu produktu); kolumny nazwa, wariant (etykieta, pusta dla wariantu domyślnego),
  klasa, brutto, netto, VAT, stawka, oznaczenie nieaktualnej stawki. „Zmień cenę” i „Historia” dotyczą wariantu
  wiersza.
- „Zmień cenę” (tylko dla roli `admin`, role z `/api/v1/admin/me`; API sprawdza niezależnie):
  - pole brutto w PLN, zamiana tekstu na grosze bez `float` w osobnym helperze;
  - podgląd stawki z `applicableVatRate`, bez liczenia netto na froncie;
  - 409: „Cena została w międzyczasie zmieniona – odśwież” i odświeżenie wiersza;
  - 422: komunikat naruszenia z API przy polu; kwota poza zakresem daje „Cena brutto musi wynosić od 0,01 zł do
    999 999,99 zł.” (zakres zdefiniowany tylko w API).
- „Historia”: wpisy od najnowszych, „Pokaż więcej”, etykiety źródeł (Pracownik, Dane demo, Uzgodnienie podatkowe).

## Scenariusze błędów

| Sytuacja | Wynik |
|---|---|
| Zmiana ceny przez pracownika bez roli `admin` | 403; w panelu brak przycisku |
| Zapis z nieaktualnego widoku | 409, bez nadpisania |
| Dwa równoległe pierwsze zapisy ceny | jeden sukces, drugi 409 |
| Nieznany wariant (także identyfikator produktu) | 404 |
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
8. Po `make seed` każdy wariant demo ma cenę. Stawki są oznaczone jako przykładowe.
9. Przechodzą `make -C apps/api check`, `make -C apps/api test`, `check` i `test` sklepu i panelu oraz `make e2e`.

## Testy

- Architektura: Deptrac z regułami `Pricing_Infrastructure → Catalog_Application`,
  `Pricing_Infrastructure → Backoffice_Application` i `Pricing_UI → Backoffice_Application` oraz próbami obejścia
  (pliki tymczasowe muszą dać błąd): `Catalog_* → Pricing_*`, `Pricing_Domain → Catalog_*`,
  `Pricing_Application → Catalog_Domain`, `Pricing_Domain → Backoffice_Application`,
  `Pricing_Application → Backoffice_Application`, `Pricing_Infrastructure → Backoffice_Infrastructure`. Test kontraktu: każdy przypadek `TaxClassCode` ma stawkę
  w `PolishVatRates` i każda stawka ma przypadek.
- Obliczenia (TDD): przypadki graniczne HALF_UP dla każdej stawki; test własności dla każdego brutto
  1–100 000 groszy i dla maksimum (netto + VAT = brutto, VAT ≥ 0, wynik równy obliczeniu referencyjnemu); stawka 0 bp;
  limity `GrossPrice`.
- Agregat i handlery (adaptery in-memory): brak wpisu i wersji przy identycznym komplecie, jeden wpis przy zmianie
  kwoty lub stawki, 409 przy złej wersji i przy `null` dla istniejącej ceny, pomijanie nieaktualnych cen
  w `GetCurrentPrices`, reconcile (raport, `--apply`, idempotencja, kody wyjścia).
- Integracyjne (PostgreSQL): wymuszony błąd zapisu historii wycofuje zmianę ceny; dwie zmiany z tą samą wersją
  dają jeden sukces i 409, liczba wpisów równa liczbie sukcesów; dwa równoległe pierwsze zapisy; `GetCurrentPrices`
  dla 4800 id jednym zapytaniem; `SearchBackofficeProducts` (nazwa, podkategorie, kategoria dodatkowa, keyset);
  migracja klas; migracja na klucz wariantu na danych sprzed zmiany; lista panelu z wariantami na wielu stronach;
  reconcile na poprawnej ścieżce i przy niedostępnej bazie; seed z wariantem bez wzorca. Testy używają wariantów
  o `id` różnym od `id` produktu (wyjątek: test migracji).
- Funkcjonalne API: każdy endpoint z przypadkami 200, 400 lub 422, 401 (bez tokena i z tokenem `shop`), 403
  (`marketing`, `support`, `warehouse` przy `PUT`), 404 (także identyfikator produktu zamiast wariantu) i 409.
- Vitest: sklep – `formatPrice` (kafelek z ofertą opisuje `docs/features/cart/spec.md`); panel – zamiana kwoty
  na grosze, widok cen (wiersz na wariant, kolumna „Wariant”, akcje na wierszu wariantu, 403, 409, pusty wynik),
  historia.
- E2E: admin zmienia cenę w panelu, nowa cena jest widoczna w sklepie (oczekiwanie do 70 s).

## Ryzyka i ograniczenia

- Ceny nie ma w Elasticsearch. Sortowanie i filtrowanie po cenie wymaga później zdarzenia i reindeksu.
- Sklep wykonuje dwa kolejne wywołania API na stronę listy. Cache Varnisha dla zestawów id będzie słabo trafiał.
- Wyszukiwanie trigramowe z filtrem kategorii przy 10 mln produktów jest przeznaczone dla ruchu panelu. Wydajność
  nie jest potwierdzona testami obciążeniowymi.

## Dokumentacja do aktualizacji razem z implementacją

- `docs/vision.md`: „kategoria podatkowa (`taxCategory`)” → „klasa podatkowa (`taxClass`)” w regułach domenowych
  i na liście decyzji; stawki dla PL w kodzie Pricing.
- `apps/api/CLAUDE.md`: `pricing:seed-demo` w `make seed` i opis `pricing:reconcile-tax`.
- `apps/api/deptrac.yaml`: warstwy Pricing oraz komentarze do reguł Pricing → Catalog i Pricing → Backoffice_Application.
