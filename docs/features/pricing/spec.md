# Pricing: ceny katalogowe (MVP)

Status: zaakceptowana do realizacji. Punkt 5 MVP z `docs/vision.md`.

## Cel i zakres

Cena katalogowa wariantu produktu w PLN: brutto wpisywane przez pracownika, netto i VAT wyliczane przez API, historia
każdej zmiany. Kluczem ceny i historii jest wariant (`variantId` z Catalog, `docs/features/variants/spec.md`); produkt
bez osi wariantów ma jeden wariant domyślny. Zarządzanie cenami w panelu, cena brutto na liście produktów w sklepie oraz
autorytatywny odczyt cen wielu wariantów dla koszyka i przyszłego checkoutu.

Obsługujemy wyłącznie Polskę i PLN. Promocje (obniżka procentowa wariantów na okres) planuje się w panelu
(sekcja „Promocje”); ich cena promocyjna nie jest jeszcze ceną skuteczną: odczyt cen (`GetCurrentPrices`), koszyk,
zamówienie i sklep używają ceny katalogowej. Poza zakresem: kody rabatowe, cena Omnibus w sklepie, koszyk,
checkout, wiele krajów i walut, harmonogramy zmian stawek. Klasę podatkową produktu zmienia edycja produktu w Catalog,
a Pricing przelicza po niej ceny wariantów (sekcja „Przeliczenie po zmianie klasy podatkowej”).

## Bounded contexty i zależności

| Context | Odpowiada za |
|---|---|
| Catalog | przypisanie produktowi klasy podatkowej (`TaxClassCode`), nazwy, kategorie i warianty, zapytania dla Pricing |
| Pricing | stawka VAT dla klasy, obliczenia, cena wariantu, historia cen, autorytatywny odczyt cen |
| Sklep, panel | wyświetlanie i formatowanie kwot z API; żadnych obliczeń netto, VAT ani stawek |

- Zależność jest jednokierunkowa: `Pricing_Infrastructure → Catalog_Application`. Adaptery portów Pricing wołają
  zapytania Catalog przez `query.bus` (jak `Backoffice\Infrastructure\QueryBusCatalogStatistics`), a Pricing
  subskrybuje integracyjne zdarzenie Catalog `ProductChanged` (konsument `RepriceOnTaxClassChange`, niżej).
- Catalog nie zależy od Pricing w żadnej warstwie. Deptrac nie ma żadnej reguły `Catalog_* → Pricing_*`.
- Pricing zależy od Backoffice wyłącznie przez kontrakt bezpieczeństwa `AuthenticatedStaffMember` z `Backoffice_Application`:
  `Pricing_Infrastructure` (`PriceVoter` i `PromotionVoter`, role pracownika) i `Pricing_UI` (aktor zmiany ceny
  w `ChangePriceController` i zapisu promocji w kontrolerach promocji).
  `Pricing_Domain` i `Pricing_Application` nie zależą od Backoffice, a żadna warstwa Pricing nie zależy od
  `Backoffice_Infrastructure`, `Backoffice_Domain` ani `Backoffice_UI`.
- Domena Pricing ma własne value objecty (`VariantId`, `TaxClass` jako kod tekstowy; `ProductId` w liście cen
  panelu, w porcie `ProductDirectory::taxClassOf` (`ProductTaxClass`) i w `RepriceProductPrices`) i nie importuje kodu Catalog. Adapter mapuje kod klasy z Catalog na `TaxClass`
  Pricing. Klasa podatkowa wariantu to klasa jego produktu.
- Odczyt cen (`GetCurrentPrices`) czyta wyłącznie tabele Pricing, bez odczytu z Catalog.
- Każdy zapis zmiany ceny (panel, seed, `pricing:reconcile-tax`, przeliczenie po zmianie klasy) publikuje `VariantPriceChanged {variantId, source,
  occurredAt}`: `DoctrineVariantPriceRepository::save` wpisuje je do outboxu w transakcji zapisu ceny i historii, więc
  zapis odrzucony albo wycofany nie zostawia zdarzenia. Jedyny konsument (`Pricing_Infrastructure`) unieważnia cache
  sklepu produktu wariantu (`docs/features/product-page/spec.md`, „Unieważnianie”); zdarzenie ze źródła `seed`
  potwierdza bez unieważnienia.
- Konsument `RepriceOnTaxClassChange` (`Pricing_Infrastructure`, transport `events`) przelicza ceny po zmianie klasy
  podatkowej produktu. Poza tym integracje w modularnym monolicie są synchroniczne.

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
  - `reprice(taxClass, rates, origin): bool` – to samo brutto, klasa i stawka od nowa (reconcile, przeliczenie po zmianie klasy).
  - `false`, gdy komplet brutto, netto, VAT, stawka i klasa jest identyczny: bez wpisu historii i bez zmiany wersji.
  - `isCurrent(rates): bool` – zapisana stawka równa stawce z `PolishVatRates` dla zapisanej klasy.
- `PriceHistoryEntry`: snapshot netto, VAT, brutto, stawki, klasy, waluty, `changedAt`, źródło i aktor. Tylko
  dopisywany.
- `PriceChangeOrigin`: źródło (`staff`, `seed`, `tax_reconciliation`) i aktor (`sub` oraz nazwa pracownika z tokena;
  `null` dla źródeł systemowych).

Historia wystarcza do późniejszego Omnibusa: najniższe brutto z 30 dni przed datą to zapytanie po
`(variant_id, changed_at)`.

## Promocje

Model i decyzje: `docs/features/promotions/spec.md` (Q1–Q5, Q13, A1). Pricing jest jedynym miejscem reguł promocji.

- `PercentOff`: liczba całkowita 1–90. `PromotionPeriod {startsAt, endsAt}`: pełne sekundy w UTC (ułamek jest
  ucinany), `startsAt < endsAt`, najwyżej 90 dni; obowiązuje w `[startsAt, endsAt)`.
- Agregat `Promotion` (UUID v7): nazwa (1–100 znaków po przycięciu, bez znaków sterujących), procent, okres, warianty
  (1–1000, bez powtórzeń), `createdBy`/`updatedBy` (aktor z tokena: `sub` i nazwa; `null` bez pracownika),
  `createdAt`, `updatedAt`, wersja. Status z czasu: `planned` (przed startem), `active`, `ended` (od końca).
- `startOfNow` = `now` ucięte do sekundy + 1 s. `plan` przesuwa start `null` albo ≤ `now` na `startOfNow` (start „od
  teraz”); koniec ≤ `now` to `InvalidPromotion` (422).
- `revise` to jedyna zmiana: zaplanowana zmienia wszystko; aktywna tylko nazwę i koniec (inny procent, start albo zbiór
  wariantów to `PromotionNotEditable`, 409), a koniec ≤ `now` kończy ją w `startOfNow` („Zakończ teraz”); zakończonej
  nie można zmienić. Każda zmiana podnosi wersję i zapisuje aktora oraz `updatedAt`; wynik mówi, czy zmienił się okres.
  Usunąć można tylko zaplanowaną (`assertDeletable`).
- `PromotionalPrice::apply(brutto, procent)` = brutto − `floor(brutto × procent / 100)` w liczbach całkowitych, co
  najmniej 1 grosz.
- Komendy (`command.bus`, jedna transakcja): `PlanPromotion`, `RevisePromotion(id, expectedVersion, …)` i
  `DeletePromotion(id, expectedVersion)`. Każda zaczyna od blokady zapisów promocji (niżej) i dopiero po niej czyta
  zegar; wersja inna niż zapisana to `PromotionModifiedConcurrently` (409). Wariant dodawany do promocji (przy `plan`
  wszystkie, przy `revise` tylko nowe) musi być znany Catalogowi (`GetVariantSummaries` przez port `VariantSummaries`,
  paczkami po 100; warianty szkiców są nieznane) i mieć aktualną cenę (`isCurrent`); inaczej `InvalidPromotionVariants`
  (422). Wariant usunięty z Catalog, który już jest w promocji, nie blokuje zmiany. Niezmiennik Q4: promocja, której
  okres nakłada się na okres innej ze wspólnym wariantem, to `PromotionOverlap` (409) z listą
  `conflictingVariantIds`. Po zapisie handler woła port `PromotionBoundaries` (promocja, poprzedni okres albo `null`,
  `now`); dziś adapter nic nie robi.
- Odczyty (`query.bus`): `ListPromotions(status?, after, perPage)` przez port `PromotionReader` (DBAL, keyset
  `(starts_at DESC, id)`, kursor `PromotionCursor`, status liczony z `now`) i `GetPromotion(id)`: warianty z Catalog
  (produkt, nazwa, etykieta; wariant nieznany Catalogowi ma je `null`), `regularGross` = aktualna cena katalogowa,
  `promotionalGross` = `PromotionalPrice` z niej, `null` bez ceny i dla zakończonej promocji.

### Blokada zapisów promocji i ceny (Q4, Q13)

- Port `PromotionWriteLock` z adapterem `PostgresPromotionWriteLock`: `pg_advisory_xact_lock` na stałym kluczu Pricing,
  trzymana do końca transakcji zapisu (poza transakcją adapter odmawia). Zapisy promocji biorą ją na wyłączność, więc
  idą po kolei i niezmiennik Q4 jest sprawdzany na zatwierdzonych danych.
- `SetVariantPrice` po sprawdzeniu wariantu w Catalog bierze tę samą blokadę współdzieloną, czyta zegar, a wariant
  w promocji aktywnej w tej chwili kończy się `PriceLockedByPromotion` (409) przed sprawdzeniem wersji. Cena i wpis
  historii mają czas odczytany po blokadzie, więc cena zapisana równolegle z promocją „od teraz” ma `changed_at` przed
  jej startem albo jest odrzucona, nigdy zmieniona w aktywnej promocji. Przy promocji zaplanowanej i zakończonej cena
  się zmienia.
- `ReconcileTax` i przeliczenie po zmianie klasy (`reprice`, brutto bez zmian) nie biorą blokady. Seed cen idzie
  przez `SetVariantPrice` (`bySeed`), więc bierze blokadę współdzieloną i ma tę samą odmowę dla wariantu w aktywnej
  promocji.

## Persystencja i transakcje

- `pricing_promotion`: `id uuid` (PK), `name`, `percent_off smallint`, `starts_at`, `ends_at`, `created_at`,
  `created_by_id`, `created_by_name`, `updated_at`, `updated_by_id`, `updated_by_name` (aktor `null` bez pracownika),
  `version`; CHECK `starts_at < ends_at`, `percent_off BETWEEN 1 AND 90` i aktor z obiema wartościami albo bez;
  indeks `(starts_at DESC, id)`. `pricing_promotion_variant`: PK `(promotion_id, variant_id)`, FK do promocji
  z `ON DELETE CASCADE`, indeks `(variant_id)`, bez FK do Catalog. Zapis przez rekordy Doctrine
  (`DoctrinePromotionRepository`, `#[Version]`), warianty synchronizowane po identyfikatorze; nakładanie okresów
  i blokada ceny to zapytania na połączeniu. Promocje i ich warianty są przechowywane na zawsze (usuwa się tylko
  zaplanowane).
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
  - Wariant w aktywnej promocji: `PriceLockedByPromotion` (409) przed sprawdzeniem wersji (sekcja „Blokada zapisów
    promocji i ceny”).
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
  (Catalog `ListProductVariants` z `includeDrafts: true`) i ich ceny jednym zapytaniem. Wynik: produkty strony, także
  szkice (cenę szkicu ustawia się przed publikacją), a w każdym jego warianty w kolejności pozycji z ceną albo `null`. `applicableVatRate` z `PolishVatRates` dla klasy produktu,
  `vatRateOutdated` = nie `isCurrent`.
- `GetPriceHistory(variantId, after, perPage)`: od najnowszych, stronicowanie keyset. Nieznany wariant: 404; wariant
  bez ceny: pusta lista.
- `ReconcileTax(apply)`: opis w sekcji „Reconcile”.
- Dane demo: `pricing:seed-demo`. `fixtures/prices.yaml` przypisuje brutto do wzorców kluczy wariantów demo
  (`key` = `<slug produktu>/<slug etykiety>`, wariant domyślny `<slug produktu>/standard`; `*` dopasowuje dowolny
  ciąg znaków, także `/`; np. `przewodnik-*/oprawa-twarda: 5990`; ceny różnią się między wariantami przewodników,
  map i dań liofilizowanych). Warianty z portu `VariantsForPricing` (Catalog `ListVariantIdentities`), zapis przez
  `SetVariantPrice` ze źródłem `seed`. Wariant demo bez dopasowanego
  wzorca kończy seed błędem bez zmian w danych. `make seed` uruchamia go po danych katalogu, więc każdy wariant demo
  ma cenę.

Catalog (zapytania o warianty opisuje `docs/features/variants/spec.md`):
- `GetVariant(variantId)` → `{variantId, productId, taxClass}` albo `null`.
- `ListProductVariants(productIds, includeDrafts)` (1–100 id) → warianty produktów z etykietami w kolejności pozycji
  i klasa podatkowa produktu; lista cen i przeliczenie po zmianie klasy (jeden produkt, port `ProductDirectory::taxClassOf`)
  podają `includeDrafts: true`.
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
  każdą cenę w osobnej transakcji. Pętlę „reprice, zapis, jedno ponowienie przy konflikcie wersji” robi `PriceReconciler`
  (`Pricing_Application`), wspólny z przeliczeniem po zmianie klasy.
- Idempotentne: kolejne uruchomienie po naprawie nic nie znajduje.
- Konflikt wersji z równoległą edycją: ponowny odczyt tej ceny i jedna kolejna próba, potem pozycja w raporcie.
- Cena, dla której klasa z Catalog nie ma stawki, zostaje bez zmian i trafia do raportu. Cena wariantu, którego nie
  ma w Catalog, zachowuje swoją klasę.
- Kod wyjścia: 0 – spójne albo naprawione, 1 – bez `--apply` znaleziono rozbieżności albo z `--apply` zostały
  nienaprawione (konflikt po ponownej próbie, klasa bez stawki), 2 – błąd (np. niedostępna baza).

Procedura zmiany stawki: zmiana w `PolishVatRates` i wdrożenie, potem `pricing:reconcile-tax --apply`. Do tego
czasu ceny z nieaktualną stawką są niedostępne w sklepie (bezpieczne zachowanie), a panel oznacza je
`vatRateOutdated`.

## Przeliczenie po zmianie klasy podatkowej

Klasę zmienia edycja produktu w Catalog (`EditProduct`), która publikuje integracyjne `ProductChanged {productId, slug,
occurredAt}` przez outbox. Ceny przelicza konsument `RepriceOnTaxClassChange` (`Pricing_Infrastructure`, transport
`events`) przez `RepriceProductPrices` (`Pricing_Application`):

- Klasa i warianty produktu z Catalog `ListProductVariants([productId], includeDrafts: true)` przez port
  `ProductDirectory::taxClassOf` (także szkic, bo jego ceny też mogą istnieć). Ceny wariantów jednym odczytem
  (`findMany`); wariant bez ceny jest pomijany.
- Każda cena z klasą inną niż klasa produktu przechodzi przez `PriceReconciler`, jak w `pricing:reconcile-tax --apply`:
  `reprice` (to samo brutto, klasa produktu i jej bieżąca stawka, źródło `tax_reconciliation`, bez pracownika;
  pracownika zmiany klasy wskazuje `updated_by` produktu), wpis historii i `VariantPriceChanged`, każda cena w osobnej
  transakcji, konflikt wersji: ponowny odczyt i jedna kolejna próba.
- Cena w klasie produktu nie jest zapisywana (bez wpisu historii), także z nieaktualną stawką: tę naprawia
  `pricing:reconcile-tax`.
- Idempotencja i kolejność: klasa nie pochodzi ze zdarzenia, tylko z bieżącego stanu Catalog, więc kilka zmian
  (A → B → A) obsłużonych w dowolnej kolejności, także wielokrotnie, kończy się cenami w klasie bieżącej. Gdy cokolwiek
  zapisano, konsument czyta klasę ponownie: inna klasa (zmiana zapisana w trakcie, której zdarzenie mogło już zostać
  obsłużone przed tymi zapisami) to `TaxClassChangedWhileRepricing` i ponowienie wiadomości.
- Błędy: klasa bez stawki → `UnrecoverableMessageHandlingException` z komunikatem (klasa i produkt), wiadomość trafia do
  `failed`, ceny bez zmian; drugi konflikt wersji → `PriceModifiedConcurrently` po zapisie pozostałych cen i ponowienie
  wiadomości (ponowienia transportu `events`, potem `failed`); produkt nieznany Catalogowi → wiadomość obsłużona bez
  zmian; identyfikator produktu w złym formacie → `failed`.
- `pricing:reconcile-tax` wykrywa rozjazd klasy, gdyby jednak do niego doszło (np. wiadomość w `failed`).
- Znany przypadek: pierwsza cena wariantu (`SetVariantPriceHandler` bierze klasę z Catalog przed zapisem) zapisana
  równolegle ze zmianą klasy produktu może powstać w starej klasie, gdy konsument przeliczenia odczyta ceny wcześniej.
  Brutto jest poprawne, rozbicie VAT nie; rozjazd wykrywa i naprawia `pricing:reconcile-tax`.

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
| `PUT /api/v1/admin/variants/{variantId}/price`, body `{gross: int, version: int \| null}` | rola `admin` | 200 cena (ta sama kwota: bez zmiany wersji i bez wpisu); 403; 404 nieznany wariant; 409 konflikt wersji albo `price-locked-by-promotion`; 422 kwota poza zakresem lub zły typ |
| `GET /api/v1/admin/promotions?status&after&perPage` | każdy pracownik | `200 {items: [{id, name, percentOff, startsAt, endsAt, status, variantCount, version}], next}`; `status` `planned`, `active`, `ended`; `perPage` 1–100 (domyślnie 25); 400 zły parametr albo kursor |
| `POST /api/v1/admin/promotions`, body `{name, percentOff, startsAt \| null, endsAt, variantIds}` | `admin`, `marketing` | 201 promocja (`Location`); 403; 409 `promotion-overlap`; 422 |
| `GET /api/v1/admin/promotions/{id}` | każdy pracownik | 200 `{id, name, percentOff, startsAt, endsAt, status, variantCount, version, updatedAt, updatedByName, variants: [{variantId, productId, productName, label, regularGross, promotionalGross}]}`; 404 |
| `PUT /api/v1/admin/promotions/{id}`, body `{name, percentOff, startsAt \| null, endsAt, variantIds, version}` | `admin`, `marketing` | 200 promocja; 403; 404; 409 wersja (`about:blank`), `promotion-not-editable`, `promotion-overlap`; 422 |
| `DELETE /api/v1/admin/promotions/{id}?version=` | `admin`, `marketing` | 204; 400 brak albo zła wersja; 403; 404; 409 wersja, `promotion-not-editable` |
| `GET /api/v1/admin/variants/{variantId}/price-history?after&perPage` | każdy pracownik | `200 {items: [{changedAt, currency, gross, net, vat, vatRate, taxClass, source, actorName}], next}`; 404 nieznany wariant |
| `GET /api/v1/admin/categories` | każdy pracownik | 200 drzewo kategorii (`GetCategoryTree`) |

- Uprawnienie do zmiany ceny sprawdza `PriceVoter` w `Pricing_Infrastructure` na podstawie ról
  `AuthenticatedStaffMember`. `marketing`, `support` i `warehouse` dostają 403. Zmiany promocji: `PromotionVoter`
  (`MANAGE_PROMOTIONS`: `admin`, `marketing`), sprawdzany przed treścią; odczyt promocji ma każdy pracownik.
- 409 cen i promocji mają schemat `PricingConflictProblem`: `type` `…/price-locked-by-promotion`,
  `…/promotion-overlap` (z rozszerzeniem `conflictingVariantIds`, posortowanym), `…/promotion-not-editable` albo
  `about:blank` (wersja). Panel rozróżnia je po `type`.
- Promocje: typy ścisłe jak `PUT` ceny, wszystkie klucze wymagane (`startsAt` może być `null`), czasy w RFC 3339 ze
  strefą (data i godzina muszą istnieć), w odpowiedzi w UTC. Reguły promocji (nazwa, procent, okres, liczba
  i powtórzenia wariantów) sprawdza domena, a odmowa ma naruszenie przy swoim polu; wariant nieznany albo bez ceny ma
  naruszenie `variantIds[<indeks>]` („Wariant produktu nie istnieje.”, „Wariant bez ceny nie może być w promocji.”).
  Identyfikator spoza UUID: 404. Odpowiedzi `private, no-store`.
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
- Nowa cena jest widoczna w sklepie po obsłużeniu zdarzenia zmiany ceny (purge w Varnishu i rewalidacja strony
  produktu), w sekundach; bez zdarzenia (np. koniec okresu stawki VAT) najpóźniej po `s-maxage` oferty (1 h).

## Panel (`apps/admin`)

- Pozycja menu „Ceny” i trasa `/prices`. Promocje mają własną pozycję „Promocje” (`docs/features/promotions/spec.md`).
- Lista: wyszukiwanie po nazwie (od 2 znaków, z opóźnieniem), filtr kategorii (drzewo), stronicowanie
  „następna/poprzednia” po kursorach (strona to produkty). Wiersz na wariant, warianty pod nazwą swojego produktu
  (nazwa i klasa w pierwszym wierszu produktu); kolumny nazwa, wariant (etykieta, pusta dla wariantu domyślnego),
  klasa, brutto, netto, VAT, stawka, oznaczenie nieaktualnej stawki. „Zmień cenę” i „Historia” dotyczą wariantu
  wiersza.
- „Zmień cenę” (tylko dla roli `admin`, role z `/api/v1/admin/me`; API sprawdza niezależnie):
  - pole brutto w PLN, zamiana tekstu na grosze bez `float` w osobnym helperze;
  - podgląd stawki z `applicableVatRate`, bez liczenia netto na froncie;
  - 409 po `type`: `price-locked-by-promotion` (wariant w aktywnej promocji) pokazuje `detail` przy polu ceny, bez
    komunikatu o zmianie w międzyczasie i bez odświeżania wiersza; każdy inny 409: „Cena została w międzyczasie zmieniona
    – odśwież” i odświeżenie wiersza;
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
| Zmiana klasy produktu na klasę bez stawki | wiadomość `ProductChanged` w `failed` z komunikatem, ceny w starej klasie |
| Równoległa zmiana ceny przy przeliczeniu po zmianie klasy (dwa razy) | ponowienie wiadomości, pozostałe ceny przeliczone |
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
  w `GetCurrentPrices`, reconcile (raport, `--apply`, idempotencja, kody wyjścia), przeliczenie po zmianie klasy
  (A → B → A w dowolnej kolejności, powtórzone zdarzenie, brak zapisu przy bieżącej klasie, klasa bez stawki, konflikty,
  zmiana klasy w trakcie, nieznany produkt).
- Integracyjne (PostgreSQL): wymuszony błąd zapisu historii wycofuje zmianę ceny; dwie zmiany z tą samą wersją
  dają jeden sukces i 409, liczba wpisów równa liczbie sukcesów; dwa równoległe pierwsze zapisy; `GetCurrentPrices`
  dla 4800 id jednym zapytaniem; `SearchBackofficeProducts` (nazwa, podkategorie, kategoria dodatkowa, keyset);
  migracja klas; migracja na klucz wariantu na danych sprzed zmiany; lista panelu z wariantami na wielu stronach;
  reconcile na poprawnej ścieżce i przy niedostępnej bazie; przeliczenie po zmianie klasy na pełnej trasie (outbox,
  RabbitMQ, konsument) i powtórzone zdarzenie; seed z wariantem bez wzorca. Testy używają wariantów
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
