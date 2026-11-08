# Pricing: ceny katalogowe (MVP)

Status: zaakceptowana do realizacji. Punkt 5 MVP z `docs/vision.md`.

## Cel i zakres

Cena katalogowa wariantu produktu w PLN: brutto wpisywane przez pracownika, netto i VAT wyliczane przez API, historia
każdej zmiany. Kluczem ceny i historii jest wariant (`variantId` z Catalog, `docs/features/variants/spec.md`); produkt
bez osi wariantów ma jeden wariant domyślny. Zarządzanie cenami w panelu, cena brutto na liście produktów w sklepie oraz
autorytatywny odczyt cen wielu wariantów dla koszyka i przyszłego checkoutu.

Obsługujemy wyłącznie Polskę i PLN. Promocje (obniżka procentowa wariantów na okres) planuje się w panelu
(sekcja „Promocje”). W aktywnej promocji cena skuteczna wariantu to cena promocyjna: odczyt cen (`GetCurrentPrices`),
a przez niego oferta listy, indeks wyszukiwania, koszyk i zamówienie, płacą ją bez własnych obliczeń; odczyt podaje też
cenę Omnibus (najniższa cena skuteczna z 30 dni przed startem promocji). Kody rabatowe (procent albo kwota na całe
zamówienie, sekcja „Kody rabatowe”) zakłada i zmienia się w API panelu; wycena koszyka (`QuoteBasket`, sekcja „Wycena
koszyka”) liczy ich rabat i rozkłada go na linie, a widok koszyka pokazuje ją z kodem w podglądzie. Poza zakresem:
wiele krajów i walut, harmonogramy zmian stawek. Klasę podatkową produktu zmienia edycja produktu w Catalog,
a Pricing przelicza po niej ceny wariantów (sekcja „Przeliczenie po zmianie klasy podatkowej”).

## Bounded contexty i zależności

| Context | Odpowiada za |
|---|---|
| Catalog | przypisanie produktowi klasy podatkowej (`TaxClassCode`), nazwy, kategorie i warianty, zapytania dla Pricing |
| Pricing | stawka VAT dla klasy, obliczenia, cena wariantu, historia cen, autorytatywny odczyt cen, promocje, kody rabatowe i ich użycia |
| Sklep, panel | wyświetlanie i formatowanie kwot z API; żadnych obliczeń netto, VAT ani stawek |

- Zależność jest jednokierunkowa: `Pricing_Infrastructure → Catalog_Application`. Adaptery portów Pricing wołają
  zapytania Catalog przez `query.bus` (jak `Backoffice\Infrastructure\QueryBusCatalogStatistics`), a Pricing
  subskrybuje integracyjne zdarzenie Catalog `ProductChanged` (konsument `RepriceOnTaxClassChange`, niżej).
- Catalog nie zależy od Pricing w żadnej warstwie. Deptrac nie ma żadnej reguły `Catalog_* → Pricing_*`.
- Pricing zależy od Backoffice wyłącznie przez kontrakt bezpieczeństwa `AuthenticatedStaffMember` z `Backoffice_Application`:
  `Pricing_Infrastructure` (`PriceVoter`, `PromotionVoter` i `DiscountCodeVoter`, role pracownika) i `Pricing_UI` (aktor
  zmiany ceny w `ChangePriceController` i zapisu promocji i kodu w ich kontrolerach).
  `Pricing_Domain` i `Pricing_Application` nie zależą od Backoffice, a żadna warstwa Pricing nie zależy od
  `Backoffice_Infrastructure`, `Backoffice_Domain` ani `Backoffice_UI`.
- Domena Pricing ma własne value objecty (`VariantId`, `TaxClass` jako kod tekstowy; `ProductId` w liście cen
  panelu, w porcie `ProductDirectory::taxClassOf` (`ProductTaxClass`) i w `RepriceProductPrices`) i nie importuje kodu Catalog. Adapter mapuje kod klasy z Catalog na `TaxClass`
  Pricing. Klasa podatkowa wariantu to klasa jego produktu.
- Odczyt cen (`GetCurrentPrices`) czyta wyłącznie tabele Pricing (ceny, promocje, historia), bez odczytu z Catalog.
- Każdy zapis zmiany ceny (panel, seed, `pricing:reconcile-tax`, przeliczenie po zmianie klasy) publikuje `VariantPriceChanged {variantId, source,
  occurredAt}`: `DoctrineVariantPriceRepository::save` wpisuje je do outboxu w transakcji zapisu ceny i historii, więc
  zapis odrzucony albo wycofany nie zostawia zdarzenia. Jedyny konsument (`Pricing_Infrastructure`) unieważnia cache
  sklepu produktu wariantu (`docs/features/product-page/spec.md`, „Unieważnianie”); zdarzenie ze źródła `seed`
  potwierdza bez unieważnienia.
- Granice okresów promocji publikują `PromotionPricesChanged {productId, source, occurredAt}`, jedno na produkt
  promocji (niżej, „Granice promocji”). Konsumenci w `Pricing_Infrastructure` (transport `events`):
  `ReindexProductOnPromotionChange` (reindeksacja produktu) i `InvalidateStorefrontOnPromotionChange` (unieważnienie
  cache sklepu produktu); źródło `seed` potwierdzają bez skutku.
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
- `PriceBreakdown`: jedyne miejsce obliczeń netto i VAT (jedna formuła dla ceny i dla wartości linii).
  - `d = 10000 + bp`, netto = `intdiv(2 · brutto · 10000 + d, 2 · d)` (HALF_UP do grosza, tylko liczby całkowite).
  - VAT = brutto − netto, więc netto + VAT = brutto zawsze co do grosza.
  - `fromGross(GrossPrice, VatRate)`: cena; zakres `GrossPrice` wyklucza przepełnienie `int`.
  - `ofLineGross(Money, VatRate)`: `LineBreakdown {gross, net, vat, rate}` wartości linii koszyka po rabacie, 0 –
    `MAX_LINE_GROSS` (99 × `GrossPrice::MAX`) groszy; poza zakresem `\InvalidArgumentException`. W zakresie
    `GrossPrice` wynik jest równy `fromGross`.
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

Historia jest wejściem ceny Omnibus (sekcja „Cena skuteczna i cena Omnibus”); jej odczyt idzie po indeksie
`(variant_id, changed_at DESC, id)`.

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
  `now`, jawne `announceStart`: `false` tylko dla seeda), który wysyła wiadomości granic na transport `promotions` (niżej).
- Odczyty (`query.bus`): `ListPromotions(status?, after, perPage)` przez port `PromotionReader` (DBAL, keyset
  `(starts_at DESC, id)`, kursor `PromotionCursor`, status liczony z `now`) i `GetPromotion(id)`: warianty z Catalog
  (produkt, nazwa, etykieta; wariant nieznany Catalogowi ma je `null`), `regularGross` = aktualna cena katalogowa,
  `promotionalGross` = brutto ceny skutecznej w tej promocji (`EffectivePrice`, dla zaplanowanej: cena, jaką będzie
  miał), `null` bez aktualnej ceny i dla zakończonej promocji.

### Cena skuteczna i cena Omnibus

Decyzje A2 i A3 w `docs/features/promotions/spec.md`: obie są liczone przy odczycie, bez zapisu w tabeli.
- `EffectivePrice::of(cena katalogowa, stawki, procent promocji aktywnej | null)`: wariant bez aktualnej ceny
  katalogowej (`isCurrent`) nie ma ceny skutecznej, także w promocji. W promocji aktywnej w chwili odczytu
  (`startsAt ≤ now < endsAt`) brutto to `PromotionalPrice`, a netto i VAT liczy `PriceBreakdown::fromGross` ze stawką
  ceny katalogowej; poza nią to cena katalogowa. `regularGross` to brutto ceny katalogowej.
- `LowestPriorPrice::of(startsAt, historia, promocje)` (czysta funkcja domeny): okno `W = [startsAt − 30 dni, startsAt)`
  (30 dób po 24 h w UTC, `LowestPriorPrice::windowStart`). Cena skuteczna w chwili `t ∈ W` to brutto wpisu historii
  obowiązującego w `t` (ostatni z `changed_at ≤ t`; z wpisów tej samej chwili późniejszy), obniżone przez inną
  promocję wariantu aktywną w `t`. Wynik to minimum po odcinkach `W` wyznaczonych przez wpisy i granice promocji;
  odcinki sprzed pierwszej ceny wariantu się nie liczą, a wariant bez ceny przed `startsAt` dostaje `null`. Wpisy
  z nieaktualną stawką też się liczą (sklep pokazywał tę cenę). Wpis zapisany w sekundzie przed startem „od teraz”
  (`changed_at` ucięte do sekundy) jest w `W`, wpis z chwili `startsAt` już nie.
- Porty (`Pricing_Application`) i adaptery DBAL (`Pricing_Infrastructure`, po jednym zapytaniu na metodę dla
  wszystkich wariantów): `ActivePromotions::at(variantIds, moment)` (`DbalActivePromotions`:
  `pricing_promotion_variant` po `variant_id` z okresem promocji) oraz `OmnibusHistory` (`DbalOmnibusHistory`, okna
  wariantów jako `unnest` tablic id, początku okna i startu): `prices` – wpisy historii z `W` i ostatni wpis sprzed
  `W` (po `changed_at, id`), `promotions` – promocje wariantu, których okres przecina `W`
  (`starts_at < startsAt` i `ends_at > ` początek `W`, więc bez promocji bieżącej).

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

### Granice promocji (A2)

- Transport Messengera `promotions` (Doctrine, `queue_name=promotions`, opcje jak `scheduled`). Port
  `PromotionBoundaries` (adapter `MessengerPromotionBoundaries`, `DelayStamp`) wysyła w transakcji zapisu promocji
  `PromotionBoundaryReached(promotionId, at)` na każdą granicę `≥ now` (start „od teraz” i „Zakończ teraz” to
  `startOfNow`; seed (`announceStart = false`) wysyła tylko koniec), także tę, którą miał poprzedni okres: jej wcześniejsza wiadomość mogła trafić do `failed`, a powtórzona
  jest nieszkodliwa, bo handler i konsumenci `PromotionPricesChanged` są idempotentni. Przy przesunięciu końca aktywnej
  promocji na chwilę po `startOfNow` zapis wysyła też `RefreshPromotionProducts(promotionId)` bez opóźnienia.
  Usunięcie zaplanowanej promocji nic nie wysyła.
- Handler granicy: promocja nieznana albo `at` różne od jej bieżącego startu i końca to wiadomość bez skutku;
  wiadomość za wczesna wraca na `at` + 1 s; inaczej produkty wariantów promocji z Catalog (`VariantSummaries`,
  paczkami po 100, wariant nieznany pominięty) i jedno `PromotionPricesChanged` (źródło `promotion`) na produkt
  w outboxie, w jednej transakcji. `RefreshPromotionProducts` nieznanej albo zakończonej promocji nic nie robi,
  inaczej działa jak granica.

## Kody rabatowe

Model i decyzje: `docs/features/discount-codes/spec.md` (D1, Q3, Q4, Q7, A1). Pricing jest jedynym miejscem reguł
kodów i jedynym miejscem tekstów ich odrzucenia.

- Value objecty (`Pricing\Domain\DiscountCode`): `DiscountCodeValue` (tekst po przycięciu białych znaków Unicode
  i zamianie liter ASCII na wielkie, 3–32 znaki `[A-Z0-9-]`; inny znak, także NUL, U+2028 i polska litera, to odmowa),
  `DiscountValue` (`percent(PercentOff)` 1–90 albo `amount(Money)` 100–1 000 000 groszy; niejawne minimum procentu
  `ceil(100 / p)` groszy, Q7), `MinimumOrder` (1–100 000 000 groszy), `ValidityPeriod {startsAt, endsAt | null}` (pełne
  sekundy w UTC, `startsAt < endsAt`, bez maksymalnej długości; `isValidAt(t)` = `startsAt ≤ t` i bez końca albo
  `t < endsAt`), `UsageLimit` (1–1 000 000). Odmowa wartości to `InvalidDiscountCode` (422) z polem i polskim
  komunikatem (sekcja „API”).
- Agregat `DiscountCode` (UUID v7): tekst, wartość, minimum, okres, limit, `enabled`, `createdBy`/`updatedBy` (aktor
  z tokena, `null` dla systemu), `createdAt`, `updatedAt`, wersja. Kod kwotowy ma minimum większe od kwoty. Status
  z czasu i flagi: `disabled` (pierwszeństwo), `planned` (`now < startsAt`), `active`, `expired` (`now ≥ endsAt`).
  - `create(…, enabled, actor, now)`: początek i koniec w przeszłości są dozwolone.
  - `revise(wartość, minimum, okres, limit, enabled, aktywne użycia, aktor, now)`: przed startem (także kod wyłączony)
    zmienia wszystko poza tekstem. Po starcie (`active`, `expired`, także wyłączony) zmieniają się tylko koniec,
    `enabled` i istniejący limit (także zdjęty); inny rodzaj, wartość, minimum albo początek oraz limit dodany kodowi
    bez limitu to `DiscountCodeNotEditable` (409). Zmieniony koniec musi być po `now`, a limit nie mniejszy od liczby
    aktywnych użyć (422). Kolejność: reguły wartości (422), reguły edycji (409), koniec i limit (422). Każda zmiana
    podnosi wersję.
  - `rejectionFor(suma, aktywne użycia, now)`: `null` albo `DiscountCodeRejection {reason, minimumOrderGross}`, pierwszy
    spełniony powód w kolejności `invalid` (wyłączony, przed początkiem, od końca), `used_up` (aktywne użycia ≥ limit),
    `below_minimum` z minimum skutecznym `max(minimum kodu, ceil(100 / p))` (kod kwotowy: minimum kodu). Odrzucenie
    daje typ problemu (`…/discount-code-invalid`, `…/discount-code-used-up`, `…/discount-code-below-minimum`) i polski
    `detail` („Ten kod jest nieprawidłowy albo nieaktywny.”, „Ten kod został już wykorzystany.”, „Ten kod działa przy
    zamówieniu od 300,00 zł.”).
  - Tekst kodu się nie zmienia, kodu nie da się usunąć. Unikalność tekstu (bez rozróżniania wielkości liter, bo zapis
    jest znormalizowany) pilnuje unikalny indeks; repozytorium tłumaczy naruszenie na `DiscountCodeTaken` (409).
- `DiscountCodeUsage {id, codeId, orderId, usedAt, releasedAt | null}`: osobny agregat z repozytorium
  `DiscountCodeUsageRepository` (`add`, `activeUsesOf`), nie kolekcja kodu; aktywne użycie ma `releasedAt = null`,
  zamówienie ma najwyżej jedno użycie. Liczba aktywnych użyć jest liczona zapytaniem, kod nie ma licznika. Użycia
  nie zapisuje jeszcze żadna komenda.
- Komendy (`command.bus`, jedna transakcja): `CreateDiscountCode(id, code, kind, value, minimumOrderGross, startsAt,
  endsAt, usageLimit, enabled, actorId, actorName)` (aktor `null` to system) i `ReviseDiscountCode(id, expectedVersion,
  …)`. `ReviseDiscountCode` zaczyna od blokady wiersza kodu (`DiscountCodeRepository::lock`, `PESSIMISTIC_WRITE` przez
  ORM; rekord już w identity map jest odświeżany pod blokadą), potem czyta zegar i liczbę aktywnych użyć, buduje
  wartości (422), porównuje wersję (`DiscountCodeModifiedConcurrently`, 409) i woła `revise`. Nieznany kod i id spoza
  UUID: `DiscountCodeNotFound` (404).
- `DiscountCodeRepository::findByCode(DiscountCodeValue)`: kod po tekście znormalizowanym (unikalny indeks), odczyt
  z bazy z odświeżeniem rekordu z identity map.
- Odczyty (`query.bus`): `ListDiscountCodes(status?, q?, after, perPage)` przez port `DiscountCodeReader` (DBAL
  `DbalDiscountCodeReader`, keyset `(created_at DESC, id)`, kursor `DiscountCodeCursor`, status liczony z `now`, `q`
  przycięty i wielkimi literami jako prefiks `LIKE` tekstu; `q` ze znakiem spoza `[A-Z0-9-]` daje pustą stronę bez
  zapytania) i `GetDiscountCode(id)` (repozytorium i `activeUsesOf`). Oba dają `DiscountCodeView` z liczbą aktywnych
  użyć i statusem.

## Wycena koszyka

Reguły i decyzje: `docs/features/discount-codes/spec.md` („Wycena koszyka i rozkład rabatu”, D2, D8, D9, Q2, Q7).

- `BasketQuote::of(lines, prices, code | null, activeUses, now)` (`Pricing\Domain\Basket`, czysta funkcja): linie
  `BasketLine {variantId, quantity 1–99}` (1–50, różne warianty, w kolejności koszyka) i ceny skuteczne `BasketPrice
  {EffectivePrice, taxClass, promotion {id, name, percentOff} | null}` po `variantId`. Linia bez ceny nie wchodzi do
  wyceny. `g_i` = brutto jednostkowe × ilość, `S` = suma `g_i`. Kod: `rejectionFor(S, activeUses, now)`; odrzucony daje
  wycenę bez rabatu z odrzuceniem. Rabat `D`: procentowy `floor(S × p / 100)`, kwotowy kwota kodu (zawsze
  `1 ≤ D < S`). Rozkład metodą największych reszt: `d_i = floor(g_i × D / S)`, brakujące grosze po jednym liniom
  o największej reszcie `g_i × D mod S`, przy remisie wcześniejszej; iloczyny przez `ext-gmp` (`gmp_mul`,
  `gmp_div_qr`), wyniki w `int`. Linia po rabacie `L_i = g_i − d_i`, netto i VAT z `PriceBreakdown::ofLineGross`;
  bez kodu `d_i = 0` i netto liczone z brutto linii, nie jako netto jednostkowe × ilość. Sumy (`net`, `vat`, `gross`)
  to sumy linii. `BasketQuote::withUnknownCode(lines, prices)`: kod nieznany, odrzucenie `invalid`.
- `QuoteBasket(lines {variantId, quantity}, discountCode | null)` (`query.bus`): najpierw sprawdza zapytanie (1–50
  linii, UUID, ilość 1–99, różne warianty, kod do 64 znaków; inaczej `InvalidBasketQuery`, `InvalidInput`), potem
  czyta ceny skuteczne przez `CurrentEffectivePrices` (ten sam odczyt co `GetCurrentPrices`, bez Omnibusa: ceny
  i promocje aktywne o `now` z zegara), kod przez `findByCode` i liczbę aktywnych użyć tylko dla kodu z limitem
  (indeks częściowy). Kod pusty albo z samych białych znaków Unicode to brak kodu; tekst, który nie jest kodem (zły
  format, znak sterujący, bajty spoza UTF-8) albo którego nikt nie ma, to kod nieznany (`invalid`), nie błąd zapytania.
  Wynik `QuotedBasket {lines, subtotalGross, discountGross, net, vat, gross, currency, discount {codeId, code, kind,
  value, amountGross} | null, rejection {code, reason, type, detail, minimumOrderGross | null} | null}`, linia
  `{variantId, quantity, unit {net, vat, gross, vatRateBp, taxClass}, regularUnitGross, promotion {promotionId, name,
  percentOff} | null, grossBeforeDiscount, discountGross, net, vat, gross}`. `rejection.code` to tekst po przycięciu
  i zamianie liter ASCII na wielkie (bajty spoza UTF-8 zastąpione), `type` i `detail` z `DiscountCodeRejection`.
  Wołający: Cart (`QueryBusCartQuote`, widok koszyka).

## Persystencja i transakcje

- `pricing_promotion`: `id uuid` (PK), `name`, `percent_off smallint`, `starts_at`, `ends_at`, `created_at`,
  `created_by_id`, `created_by_name`, `updated_at`, `updated_by_id`, `updated_by_name` (aktor `null` bez pracownika),
  `version`; CHECK `starts_at < ends_at`, `percent_off BETWEEN 1 AND 90` i aktor z obiema wartościami albo bez;
  indeks `(starts_at DESC, id)`. `pricing_promotion_variant`: PK `(promotion_id, variant_id)`, FK do promocji
  z `ON DELETE CASCADE`, indeks `(variant_id)`, bez FK do Catalog. Zapis przez rekordy Doctrine
  (`DoctrinePromotionRepository`, `#[Version]`), warianty synchronizowane po identyfikatorze; nakładanie okresów
  i blokada ceny to zapytania na połączeniu. Promocje i ich warianty są przechowywane na zawsze (usuwa się tylko
  zaplanowane).
- `pricing_discount_code`: `id uuid` (PK), `code varchar(32)` (unikalny indeks `pricing_discount_code_code_key`,
  postać znormalizowana), `kind` (`percent`, `amount`), `percent_off smallint NULL`, `amount_gross integer NULL`,
  `minimum_order_gross bigint NULL`, `starts_at`, `ends_at NULL`, `usage_limit integer NULL`, `enabled`, `created_at`,
  `created_by_id`, `created_by_name`, `updated_at`, `updated_by_id`, `updated_by_name`, `version`; CHECK: wartość
  zgodna z rodzajem (tylko jedna z `percent_off`, `amount_gross`), `percent_off BETWEEN 1 AND 90`, minimum kodu
  kwotowego obecne i większe od kwoty, `starts_at < ends_at`, aktor z obiema wartościami albo bez; indeks
  `(created_at DESC, id)`. Zapis przez rekordy Doctrine (`DoctrineDiscountCodeRepository`, `#[Version]`); kody nie są
  usuwane.
- `pricing_discount_code_usage`: `id uuid` (PK), `code_id` (FK do kodu, bez `ON DELETE`, indeks `(code_id)`),
  `order_id uuid` (unikalny, bez FK do Ordering), `used_at`, `released_at NULL`; indeks częściowy
  `pricing_discount_code_usage_active_idx (code_id) WHERE released_at IS NULL` liczy aktywne użycia. Zapis przez rekord
  Doctrine (`DoctrineDiscountCodeUsageRepository`).
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
  reset danych demo: `pricing:seed-demo` (`DbalPricingReset`, które czyści też promocje i kolejkę `promotions`), który
  `make seed` uruchamia po seedzie katalogu.

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
- `GetCurrentPrices(variantIds, withLowestPriorPrice = false)` (1–4800 id, czyli 48 produktów po 100 wariantów)
  - Zapytanie o ceny `WHERE variant_id = ANY(:ids)` i, gdy jest aktualna cena, zapytanie o promocje aktywne teraz
    (`ActivePromotions`, zegar `Clock`); oba w `CurrentEffectivePrices`, wspólnym z `QuoteBasket`. Z flagą `withLowestPriorPrice` i wariantem w promocji jeszcze dwa zapytania
    (`OmnibusHistory`: historia i promocje okien); bez flagi żadnego z nich. Flagę podaje tylko oferta listy dla
    endpointu sklepu; koszyk, zamówienie i indeks wyszukiwania jej nie podają.
  - Zwraca `PriceSnapshot {variantId, taxClass, vatRateBp, net, vat, gross, currency, version, regularGross,
    promotion}` wyłącznie dla wariantów z ceną skuteczną (`EffectivePrice`), w kolejności żądania (powtórzone id raz).
    `net`, `vat` i `gross` to cena skuteczna (w promocji: promocyjna), `regularGross` cena katalogowa brutto, `version`
    wersja ceny katalogowej, a `promotion {promotionId, name, percentOff, endsAt, lowestPriorGross | null}` jest
    `null` poza promocją; `lowestPriorGross` (`LowestPriorPrice`) jest liczone tylko z flagą, bez niej i dla wariantu
    bez ceny przed startem jest `null`. Wariant bez ceny lub z nieaktualną stawką nie ma ceny i nie może zostać kupiony.
  - Autorytatywny odczyt dla koszyka i zamówienia: wywoływany w procesie przez port Cart/Ordering, z bazy, bez cache
    HTTP. Ordering kopiuje cenę skuteczną do pozycji zamówienia, a `version` (wersja ceny katalogowej) wykrywa zmianę ceny katalogowej, nie ceny skutecznej: start lub koniec promocji zmienia netto, VAT i brutto przy tej samej wersji.
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
  ma cenę. Po cenach planuje promocje z `fixtures/promotions.yaml` (`name`, `percentOff`, `days`, `variants` jako wzorce
  kluczy tej samej składni): komendą `PlanPromotion` bez aktora i ze źródłem `seed`, od chwili seeda na `days` dni (planuje tylko granicę końca,
  bez wiadomości startu); wariant należy do
  pierwszej promocji, która go dopasuje. Plik promocji jest sprawdzany przed resetem: promocja bez wariantu kończy
  seed błędem bez zmian w danych.

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
  (`GET /api/v1/product-offers`, Cart, `docs/features/cart/spec.md`), która czyta ceny skuteczne z ceną Omnibus przez
  `GetCurrentPrices` z flagą `withLowestPriorPrice`.
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
| `GET /api/v1/admin/discount-codes?status&q&after&perPage` | każdy pracownik | `200 {items: [DiscountCode], next}`; `status` `disabled`, `planned`, `active`, `expired`; `q` początek kodu (przycięty, bez rozróżniania wielkości liter, 1–32 znaki); `perPage` 1–100 (domyślnie 25); 400 zły parametr albo kursor |
| `POST /api/v1/admin/discount-codes`, body `{code, kind, value, minimumOrderGross \| null, startsAt, endsAt \| null, usageLimit \| null, enabled}` | `admin`, `marketing` | 201 kod (`Location`); 403; 409 `discount-code-taken`; 422 |
| `GET /api/v1/admin/discount-codes/{id}` | każdy pracownik | 200 `DiscountCode`; 404 |
| `PUT /api/v1/admin/discount-codes/{id}`, body `{kind, value, minimumOrderGross, startsAt, endsAt, usageLimit, enabled, version}` | `admin`, `marketing` | 200 kod; 403; 404; 409 wersja (`about:blank`), `discount-code-not-editable`; 422 |
| `GET /api/v1/admin/variants/{variantId}/price-history?after&perPage` | każdy pracownik | `200 {items: [{changedAt, currency, gross, net, vat, vatRate, taxClass, source, actorName}], next}`; 404 nieznany wariant |
| `GET /api/v1/admin/categories` | każdy pracownik | 200 drzewo kategorii (`GetCategoryTree`) |

- Uprawnienie do zmiany ceny sprawdza `PriceVoter` w `Pricing_Infrastructure` na podstawie ról
  `AuthenticatedStaffMember`. `marketing`, `support` i `warehouse` dostają 403. Zmiany promocji: `PromotionVoter`
  (`MANAGE_PROMOTIONS`: `admin`, `marketing`), sprawdzany przed treścią; odczyt promocji ma każdy pracownik.
- 409 cen, promocji i kodów mają schemat `PricingConflictProblem`: `type` `…/price-locked-by-promotion`,
  `…/promotion-overlap` (z rozszerzeniem `conflictingVariantIds`, posortowanym), `…/promotion-not-editable`,
  `…/discount-code-taken` („Taki kod już istnieje.”), `…/discount-code-not-editable` („Tej zmiany nie można wprowadzić
  w kodzie, który już obowiązuje albo był użyty.”) albo `about:blank` (wersja). Panel rozróżnia je po `type`.
- Kody rabatowe: `DiscountCode` = `{id, code, kind, value, minimumOrderGross | null, startsAt, endsAt | null, usageLimit
  | null, activeUses, enabled, status, version}` (`value` to procent dla `percent`, grosze dla `amount`; czasy w UTC).
  Zmiany sprawdza `DiscountCodeVoter` (`MANAGE_DISCOUNT_CODES`: `admin`, `marketing`) przed treścią; odczyt ma każdy
  pracownik. Kolejność sprawdzeń: 401, 403, 400 (parametry listy), 404 (także przed treścią `PUT`), 415/400/422
  treści, 409. Treść czyta `DiscountCodeBodyReader` (zamiast `#[MapRequestPayload]`, żeby `PUT` sprawdzał kod przed
  treścią): tylko JSON (inny format 415, nieczytelny JSON 400), typy ścisłe bez rzutowania, wszystkie klucze wymagane
  (brak klucza: „To pole jest wymagane.” przy polu), klucze spoza treści są pomijane, a wartość JSON inna niż obiekt nie
  ma żadnego klucza. Komunikaty 422 przy polu: `code` „Kod ma 3–32 znaki: litery, cyfry i myślnik.”; `kind` „Rodzaj
  rabatu to percent albo amount.”; `value` „Wartość rabatu musi być liczbą całkowitą.”, „Rabat procentowy to 1–90%.”
  albo „Kwota rabatu to 1–10 000 zł.”; `minimumOrderGross` „Kod kwotowy wymaga minimum większego od kwoty rabatu.”
  albo „Minimum to 0,01–1 000 000 zł.”; `startsAt`, `endsAt` „Podaj czas w formacie RFC 3339 ze strefą, np.
  2026-11-10T12:00:00Z.”; `endsAt` „Koniec musi być po początku.” albo „Koniec musi być w przyszłości.”; `usageLimit`
  „Limit to 1–1 000 000 użyć.” albo „Limit nie może być mniejszy od liczby użyć (N).”; `enabled` „Włączenie kodu to
  true albo false.”; `version` „Wersja musi być dodatnią liczbą całkowitą.”. Odpowiedzi `private, no-store`.
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
  w `GetCurrentPrices`, cena skuteczna w promocji i poza jej okresem, Omnibus tylko z flagą (bez flagi żadnego odczytu
  historii), `EffectivePrice`, `LowestPriorPrice` (przypadki ze specyfikacji promocji), reconcile (raport, `--apply`, idempotencja, kody wyjścia), przeliczenie po zmianie klasy
  (A → B → A w dowolnej kolejności, powtórzone zdarzenie, brak zapisu przy bieżącej klasie, klasa bez stawki, konflikty,
  zmiana klasy w trakcie, nieznany produkt).
- Integracyjne (PostgreSQL): wymuszony błąd zapisu historii wycofuje zmianę ceny; dwie zmiany z tą samą wersją
  dają jeden sukces i 409, liczba wpisów równa liczbie sukcesów; dwa równoległe pierwsze zapisy; `GetCurrentPrices`
  dla 4800 id dwoma zapytaniami (ceny i promocje aktywne), a z flagą i wariantami w promocji czterema; zapytania
  promocji aktywnych i Omnibusa dla wielu wariantów naraz (`DbalPromotionPricesTest`); `SearchBackofficeProducts` (nazwa, podkategorie, kategoria dodatkowa, keyset);
  migracja klas; migracja na klucz wariantu na danych sprzed zmiany; lista panelu z wariantami na wielu stronach;
  reconcile na poprawnej ścieżce i przy niedostępnej bazie; przeliczenie po zmianie klasy na pełnej trasie (outbox,
  RabbitMQ, konsument) i powtórzone zdarzenie; seed z wariantem bez wzorca. Testy używają wariantów
  o `id` różnym od `id` produktu (wyjątek: test migracji).
- Funkcjonalne API: każdy endpoint z przypadkami 200, 400 lub 422, 401 (bez tokena i z tokenem `shop`), 403
  (`marketing`, `support`, `warehouse` przy `PUT`), 404 (także identyfikator produktu zamiast wariantu) i 409.
- Kody rabatowe: jednostkowe VO i `DiscountCode` (statusy, `rejectionFor` z kolejnością powodów i minimum skutecznym,
  reguły edycji), handlery z adapterami in-memory (blokada przed zegarem i liczbą użyć, wersja), integracyjne
  repozytoriów kodów i użyć (unikalny tekst, unikalne `order_id`, aktywne użycia po indeksie częściowym, CHECK-i,
  blokada wiersza, `findByCode` z odświeżeniem), czytnika listy i funkcjonalne endpointów panelu
  (`DiscountCodesEndpointTest`).
- Wycena koszyka (TDD): `PriceBreakdown::ofLineGross` (0 groszy, maksimum linii, równość z `fromGross` w zakresie
  `GrossPrice`), `BasketQuoteTest` (netto z brutto linii, kod procentowy i kwotowy, reszty i remis, linia 1 grosz,
  maksymalny koszyk 50 × 99 × `GrossPrice::MAX` przy 90% liczony dokładnie, kopia promocji, każde odrzucenie, Q7),
  `QuoteBasketHandlerTest` (kod po tekście znormalizowanym, użycia tylko dla kodu z limitem, kod pusty, zły format,
  bajty spoza UTF-8, 65 znaków, granice linii).
- Vitest: sklep – `formatPrice` (kafelek z ofertą opisuje `docs/features/cart/spec.md`); panel – zamiana kwoty
  na grosze, widok cen (wiersz na wariant, kolumna „Wariant”, akcje na wierszu wariantu, 403, 409, pusty wynik),
  historia.
- E2E: admin zmienia cenę w panelu, nowa cena jest widoczna w sklepie (oczekiwanie do 70 s).

## Ryzyka i ograniczenia

- Ceny nie ma w Elasticsearch. Sortowanie i filtrowanie po cenie wymaga później zdarzenia i reindeksu.
- `GetCurrentPrices` robi przy każdym odczycie zapytanie o promocje aktywne, a z flagą Omnibusa dla wariantów
  w promocji dwa kolejne; oferty są w Varnishu (`s-maxage` 1 h), koszyk, kasa i indeks Omnibusa nie liczą.
- Sklep wykonuje dwa kolejne wywołania API na stronę listy. Cache Varnisha dla zestawów id będzie słabo trafiał.
- Wyszukiwanie trigramowe z filtrem kategorii przy 10 mln produktów jest przeznaczone dla ruchu panelu. Wydajność
  nie jest potwierdzona testami obciążeniowymi.

## Dokumentacja do aktualizacji razem z implementacją

- `docs/vision.md`: „kategoria podatkowa (`taxCategory`)” → „klasa podatkowa (`taxClass`)” w regułach domenowych
  i na liście decyzji; stawki dla PL w kodzie Pricing.
- `apps/api/CLAUDE.md`: `pricing:seed-demo` w `make seed` i opis `pricing:reconcile-tax`.
- `apps/api/deptrac.yaml`: warstwy Pricing oraz komentarze do reguł Pricing → Catalog i Pricing → Backoffice_Application.
