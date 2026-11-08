# PROMOTIONS: promocje i cena Omnibus

Punkt 10 „Zakresu MVP” z `docs/vision.md`, część 1: promocje z ceną Omnibus (najniższa cena z 30 dni przed obniżką)
w sklepie. Kody rabatowe to część 2, epik DISCOUNT-CODES (#225, zależny od tego epiku). Reguły
cen liczy wyłącznie API (Pricing), fronty tylko wyświetlają.

Issues: #96
Zakres: `apps/api/src/Pricing`, `apps/api/src/Cart`, `apps/api/src/Shared`, `apps/api/tests`, `apps/api/config`, `apps/api/fixtures/prices.yaml`, `apps/api/deptrac.yaml`, `apps/api/openapi.json`, `apps/api/openapi-admin.json`, `apps/store/src/api/schema.d.ts`, `apps/admin/src/api/schema.d.ts`, `apps/store/src/catalog`, `apps/store/app/p`, `apps/admin/src/promotions`, `apps/admin/src/prices`, `apps/admin/src/views/PricesView.vue`, `apps/admin/tests/PricesView.test.ts`, `apps/admin/src/router/index.ts`, `apps/admin/src/layout/AppMenu.vue`, `apps/admin/tests/AppMenu.test.ts`, `infra/docker-compose.yml`, `e2e/support`, `apps/api/CLAUDE.md`, `apps/store/CLAUDE.md`, `apps/admin/CLAUDE.md`, `docs/features/pricing/spec.md`, `docs/features/cart/spec.md`, `docs/features/checkout/spec.md`, `docs/features/product-page/spec.md`

**Zależności.** Pricing z historią cen (`docs/features/pricing/spec.md`), oferta produktów listy w Cart
(`docs/features/cart/spec.md`), unieważnianie cache sklepu (`docs/features/product-page/spec.md`) i CHECKOUT (#90,
zamówienie z kopią cen) są na `main`.

## Cel i zakres

- **Promocja**: pracownik planuje obniżkę procentową wybranych wariantów na okres `[startsAt, endsAt)`. W tym okresie
  cena skuteczna wariantu (oferta, kafelek, strona produktu, indeks wyszukiwania, koszyk, zamówienie) to cena
  katalogowa pomniejszona o procent promocji.
- **Cena Omnibus**: przy wariancie w aktywnej promocji sklep pokazuje najniższą cenę skuteczną wariantu z 30 dni przed
  rozpoczęciem promocji. Liczy ją Pricing z historii cen i z historii promocji.
- Panel: zarządzanie promocjami; blokada zmiany ceny wariantu w aktywnej promocji. Sklep: cena promocyjna i Omnibus na
  kafelku i stronie produktu (z JSON-LD).
- Dane demo: kilka aktywnych promocji po `make seed`.

Poza zakresem:
- kody rabatowe (część 2, DISCOUNT-CODES #225);
- programy lojalnościowe (issue);
- promocje na całe kategorie, marki albo „cały sklep” (cel promocji to lista wariantów, Q3);
- promocje kwotowe i cena promocyjna wpisywana per wariant (tylko procent, Q2);
- filtr „tylko promocje” i sortowanie po wysokości obniżki w wyszukiwarce;
- kopia danych promocji (nazwa, procent, cena katalogowa) w pozycji zamówienia: zamówienie kopiuje cenę skuteczną jak
  dziś (`GetCurrentPrices`), a dane promocji dopisze DISCOUNT-CODES (#225), które i tak zmienia pozycje zamówienia.

## Decyzje

Decyzje użytkownika:

| # | Decyzja |
|---|---|
| Q1 | Promocje zmienia rola `admin` albo `marketing`; każdy pracownik je widzi. |
| Q2 | Promocja to jeden procent obniżki (1–90%) dla wszystkich jej wariantów. Cena promocyjna = cena katalogowa − `floor(cena × procent / 100)`. |
| Q3 | Cel promocji: lista wariantów (1–1000), wybierana w panelu przez produkty i ich warianty. |
| Q4 | Wariant jest najwyżej w jednej promocji w danej chwili: okresy promocji o wspólnym wariancie nie mogą się nakładać (409). |
| Q5 | Promocja ma obowiązkowy koniec, najwyżej 90 dni po początku. Po rozpoczęciu można zmienić tylko nazwę i koniec (wcześniej albo później, nie przed „teraz”); zaplanowaną można zmieniać całą i usunąć; zakończonej nie można zmienić ani usunąć. |
| Q11 | Sklep przy wariancie w promocji: cena promocyjna, oznaczenie „Promocja” i linia „Najniższa cena z 30 dni przed obniżką: X zł”; bez przekreślonej ceny katalogowej. Oznaczenie zawsze z linią Omnibus (kafelek: wariant ceny „od” albo wybrany; strona produktu: wybrany wariant). Koszyk bez oznaczenia. Wariant, który przed startem promocji nie miał ceny, pokazuje cenę promocyjną bez oznaczenia i bez linii Omnibus (nie było obniżki). |
| Q13 | Ceny katalogowej wariantu w aktywnej promocji nie można zmienić (409 `price-locked-by-promotion`); zmiana wymaga zakończenia promocji. `pricing:reconcile-tax` działa bez zmian (brutto zostaje). Przy zaplanowanej promocji cena się zmienia. Wariant bez ceny nie może trafić do promocji (422), więc blokada nie zostawia wariantu bez ceny na czas promocji. |

Decyzje architektoniczne:

| # | Decyzja |
|---|---|
| A1 | Promocje należą do Pricing (`docs/vision.md`, tabela contextów). Nowy agregat `Promotion`, zapis przez rekordy Doctrine według ogólnej zasady. Pricing jest jedynym miejscem obliczeń ceny skutecznej i Omnibusa. |
| A2 | Cena skuteczna jest liczona przy odczycie z ceny katalogowej i promocji aktywnej w chwili odczytu (`GetCurrentPrices`), bez zapisywania jej w tabeli. Granice okresów promocji (opóźnione wiadomości na nowym transporcie `promotions`) publikują jedno zdarzenie `PromotionPricesChanged` na produkt promocji, a dwa konsumenty reindeksują produkt i unieważniają jego cache sklepu. |
| A3 | Cena Omnibus liczona przy odczycie z historii cen i historii promocji (promocje po rozpoczęciu są niezmienne poza nazwą i końcem w przyszłości; ceny wariantów w aktywnej promocji też, Q13), bez osobnej tabeli. |

## Bounded contexty i zależności

| Context | Odpowiada za |
|---|---|
| Pricing | promocje, cena skuteczna, cena Omnibus, blokada ceny w aktywnej promocji |
| Catalog | `GetVariantSummaries(variantIds)` (istnienie wariantu, produkt, nazwa, etykieta) dla promocji i jej produktów |
| Cart | oferta produktów listy z ceną skuteczną i Omnibusem; koszyk z ceną skuteczną (bez zmian w kodzie widoku) |
| Ordering | bez zmian: kopiuje cenę skuteczną z `GetCurrentPrices` |
| Sklep, panel | wyświetlanie wartości z API; żadnych obliczeń cen ani Omnibusa |

- Nowych kierunków zależności nie ma: `Pricing_Infrastructure → Catalog_Application` i
  `Cart_Infrastructure → Pricing_Application` już są (`apps/api/deptrac.yaml`). Pricing nie zależy od Cart ani Ordering.
- Uprawnienia: `PromotionVoter` w `Pricing_Infrastructure` na rolach `AuthenticatedStaffMember` (jak `PriceVoter`).

## Model domenowy (Pricing)

### Promocja

- `PercentOff`: liczba całkowita 1–90.
- `PromotionPeriod {startsAt, endsAt}`: `startsAt < endsAt`, `endsAt − startsAt ≤ 90 dni`, czasy z dokładnością do
  sekundy (UTC). `isActiveAt(t)` = `startsAt ≤ t < endsAt`.
- `startOfNow` = `now` ucięte do sekundy + 1 s. Historia cen ma `changed_at` z dokładnością do sekundy (ucięte), więc
  cena zapisana przed startem „od teraz” jest zawsze przed `startsAt`.
- Agregat `Promotion` (identyfikator UUID v7): `name` (1–100 znaków po przycięciu, bez znaków sterujących),
  `percentOff`, `period`, `variantIds` (1–1000, bez powtórzeń), `createdBy` i `updatedBy` (aktor z tokena: `sub`
  i nazwa; seed: `null`), `updatedAt`, `version`. Status wyliczany z czasu: `planned` (`now < startsAt`), `active`,
  `ended` (`now ≥ endsAt`).

| Metoda | Działanie | Błędy |
|---|---|---|
| `plan(id, name, percent, startsAt \| null, endsAt, variantIds, actor, now)` | nowa promocja; brak `startsAt` albo `startsAt ≤ now` daje `startOfNow` (start „od teraz”) | `endsAt ≤ now`, okres, procent, nazwa, liczba wariantów: `InvalidPromotion` (`InvalidInput`, 422) |
| `revise(name, percent, startsAt \| null, endsAt, variantIds, actor, now)` | jedyna metoda zmiany (Q5). `planned`: zmienia wszystko (brak `startsAt` albo `startsAt ≤ now` daje `startOfNow`). `active`: tylko nazwa i koniec; `endsAt ≤ now` jest przesuwane na `startOfNow` (zakończenie od razu, „Zakończ teraz”), okres ≤ 90 dni. Zapisuje aktora i `updatedAt`; zwraca, czy zmienił się okres | `active` ze zmianą procentu, startu albo wariantów oraz każda zmiana `ended`: `PromotionNotEditable` (409, `…/promotion-not-editable`); `InvalidPromotion` |
| `assertDeletable(now)` | tylko `planned` | `PromotionNotEditable` |

- Nowo dodany wariant (przy `plan` i `revise`) musi być znany Catalogowi i mieć aktualną cenę katalogową; nieznany:
  422 z naruszeniem `variantIds`, bez ceny: 422 „Wariant bez ceny nie może być w promocji.”. Ceny nie da się usunąć,
  więc każdy wariant promocji ma cenę przed startem.
- Wariant usunięty z Catalog po dodaniu do promocji (np. przez CATALOG-ADMIN) nie blokuje zmiany nazwy ani końca;
  `GetPromotion` pokazuje go jako usunięty (`productName` i `label` `null`).
- Niezmiennik między agregatami (Q4): dwie promocje o wspólnym wariancie nie mają nakładających się okresów.
  Sprawdza go handler zapisu (`plan`, `revise`) pod globalną blokadą zapisów promocji (`pg_advisory_xact_lock`
  z kluczem stałym dla Pricing, DBAL, jawny wyjątek od zapisu przez ORM: tylko blokada, dane idą przez rekordy). Zapisy
  promocji to rzadkie operacje panelu, więc szeregowanie ich wszystkich jest tańsze niż blokowanie wierszy wariantów.
  Naruszenie: `PromotionOverlap` (409, `type` `…/promotion-overlap`, „Część wariantów jest już w innej promocji w tym
  okresie.”) z rozszerzeniem problemu `conflictingVariantIds` (lista, do 1000), żeby panel wskazał kolidujące
  warianty.
- Promocja po rozpoczęciu nie zmienia przeszłości: `revise` nigdy nie ustawia końca przed `now` ani nie zmienia
  procentu, startu i wariantów aktywnej, a usunąć można tylko zaplanowaną. Dlatego historia promocji (A3) jest
  wiarygodna. Promocje i ich warianty są przechowywane na zawsze.
- `PromotionalPrice::apply(GrossPrice catalog, PercentOff p)`: `catalog − floor(catalog × p / 100)`, liczby całkowite;
  wynik ≥ 1 grosz (dla 1 grosza obniżka 0). Netto i VAT ceny promocyjnej liczy `PriceBreakdown::fromGross` ze stawką
  ceny katalogowej (jedyne miejsce obliczeń VAT).

### Blokada ceny (Q13)

- `SetVariantPrice` dla wariantu w promocji aktywnej w chwili zapisu kończy się `PriceLockedByPromotion` (`Conflict`,
  409, `type` `…/price-locked-by-promotion`, „Cena wariantu w aktywnej promocji nie może się zmienić. Zakończ promocję,
  żeby zmienić cenę.”), przed sprawdzeniem wersji.
- Handler bierze tę samą blokadę doradczą co zapisy promocji, w trybie współdzielonym (`pg_advisory_xact_lock_shared`),
  więc zapis ceny i równoległy zapis promocji „od teraz” idą po kolei. Handlery zapisu promocji i `SetVariantPrice`
  pobierają `now` dopiero po uzyskaniu blokady, więc `changed_at` ceny < `startsAt` promocji zapisanej po niej.
- `ReconcileTax` (`reprice`, brutto bez zmian) i seed cen (przed seedem promocji) nie są blokowane.

### Cena skuteczna

`EffectivePrice` wariantu w chwili `t`: cena katalogowa aktualna (`isCurrent`), a gdy wariant jest w promocji
aktywnej w `t`: jej cena promocyjna. Wariant bez aktualnej ceny katalogowej nie ma ceny skutecznej (promocja tego nie
zmienia).

### Cena Omnibus

`LowestPriorPrice::of(promotionStart, history, promotions)` (domena, czysta funkcja, dla jednego wariantu):
- okno `W = [startsAt − 30 dni, startsAt)`;
- cena skuteczna w chwili `t ∈ W` = brutto wpisu historii cen obowiązującego w `t` (ostatni wpis z `changed_at ≤ t`),
  obniżone przez inną promocję tego wariantu aktywną w `t` (Q4 wyklucza dwie naraz);
- wynik: minimum ceny skutecznej po wszystkich odcinkach `W` wyznaczonych przez wpisy historii i granice promocji;
  odcinki sprzed pierwszej ceny wariantu się nie liczą, więc wariant sprzedawany krócej niż 30 dni dostaje najniższą
  cenę od początku sprzedaży;
- wariant bez żadnej ceny przed `startsAt`: wynik `null` (nie było ceny, od której jest obniżka); sklep pokazuje wtedy
  cenę promocyjną bez oznaczenia i bez linii Omnibus (Q11). Wariant bez ceny nie trafia do promocji, więc to przypadek
  obronny;
- wpisy historii z nieaktualną stawką też się liczą (to cena, którą sklep pokazywał).

Wejście: wpisy historii z `W` i ostatni wpis sprzed `W` (jedno zapytanie dla wszystkich wariantów w promocji,
`(variant_id, changed_at)` ma indeks) oraz promocje tych wariantów, których okres przecina `W` (jedno zapytanie).

## Warstwa aplikacji (Pricing)

### Odczyty

- `GetCurrentPrices(variantIds)` (bez zmiany limitów i kolejności): `PriceSnapshot` niesie cenę skuteczną (`net`,
  `vat`, `gross` jak dziś, więc koszyk i zamówienie bez zmian płacą cenę promocyjną) oraz nowe pola `regularGross`
  (cena katalogowa brutto) i `promotion: {promotionId, name, percentOff, endsAt, lowestPriorGross | null} | null`. Dwa
  zapytania: ceny (jak dziś) i promocje aktywne dla id (`pricing_promotion_variant` po `variant_id` z okresem).
  `lowestPriorGross` jest liczone tylko z flagą zapytania `withLowestPriorPrice` (endpoint ofert listy): wtedy, gdy są warianty w promocji, jeszcze dwa zapytania (historia i promocje do Omnibusa). Koszyk,
  zamówienie i indeks go nie potrzebują. `version` to wersja ceny katalogowej.
- Panel: `ListPromotions(status?, after, perPage)` (keyset po `(starts_at DESC, id)`; `perPage` 1–100, domyślnie 25;
  nieprzezroczysty kursor `after`; zły parametr albo kursor: 400) i `GetPromotion(id)` (warianty z etykietami
  i nazwami produktów z Catalog, ceny katalogowe i promocyjne; zakończona promocja ma `promotionalGross = null`, bo
  cena katalogowa mogła się zmienić, a cena płacona jest w zamówieniach).

### Komendy

- `PlanPromotion`, `RevisePromotion(id, expectedVersion, name, percent, startsAt, endsAt, variantIds)` (jedna komenda
  dla `PUT`; co wolno zmienić, rozstrzyga `Promotion::revise`) i `DeletePromotion(id, expectedVersion)`: wersja z widoku
  (409 `PromotionModifiedConcurrently`); nowo dodane warianty sprawdzane w Catalog (`GetVariantSummaries`; adapter
  Pricing dzieli id na paczki po `GetVariantSummaries::MAX_VARIANTS` = 100, kontrakt Catalog bez zmian) i w cenach
  Pricing; blokada i niezmiennik Q4 jak wyżej; po zapisie planowanie granic przez port `PromotionBoundaries` (niżej).
  `GetPromotion` czyta warianty tymi samymi paczkami.
- Seed zapisuje promocje tą samą komendą `PlanPromotion` z aktorem `null` i źródłem `seed`, więc komenda jest jedyną
  ścieżką zapisu.

### Granice promocji i unieważnianie (A2)

- Nowy transport Messengera `promotions` (Doctrine, `queue_name=promotions`, te same opcje co `scheduled`; w `test`
  w pamięci, w `test_messaging` prawdziwy), worker konsumuje `outbox events scheduled payment_simulator promotions`.
  Osobna kolejka, bo `ordering:seed-demo` czyści całą kolejkę `scheduled`, a `pricing:seed-demo` czyści swoją.
- Jedna ścieżka publikacji: wiadomości na transporcie `promotions`. Zapis promocji wysyła w swojej transakcji (port
  `PromotionBoundaries`, adapter z `DelayStamp`, jak `UnpaidOrderExpiry`) `PromotionBoundaryReached(promotionId, at)`
  na każdą granicę `≥ now` (`startsAt`, `endsAt`; start „od teraz” i „Zakończ teraz” to `startOfNow`, więc też
  wiadomość), a `revise` aktywnej promocji, który przesuwa `endsAt` na inną przyszłą chwilę, wysyła dodatkowo
  `RefreshPromotionProducts(promotionId)` bez opóźnienia (zmienia się `endsAt` w ofercie i `priceValidUntil`). Handler
  komendy nie czyta Catalog ani nie publikuje zdarzeń; robi to handler wiadomości, poza transakcją panelu.
- Handler `PromotionBoundaryReached`: promocja nieznana albo jej bieżące `startsAt`/`endsAt` różne od `at` (zmieniona,
  usunięta): wiadomość obsłużona bez skutku. Wiadomość za wczesna (zegar, zaokrąglenie `available_at`) wraca na
  `at` + 1 s. Inaczej wyznacza produkty wariantów promocji (port `VariantSummaries`, Catalog `GetVariantSummaries`
  paczkami po 100; wariant nieznany Catalogowi pomija) i publikuje jedno `PromotionPricesChanged {productId, source:
  promotion, occurredAt}` na produkt (outbox, jedna transakcja). Produkt z 20 wariantami w promocji to jeden reindeks
  i jedno unieważnienie.
- `RefreshPromotionProducts`: promocja nieznana albo `ended`: bez skutku; inaczej jak granica (publikacja na produkt).
  Usunięcie zaplanowanej promocji nic nie publikuje (cena nie była skuteczna). Seed planuje tylko granice końca (start
  seeda nie potrzebuje zdarzeń: `make seed` kończy się `catalog:reindex` i `storefront:cache:purge-all`).
- Konsumenci w `Pricing_Infrastructure` (transport `events`): `ReindexProductOnPromotionChange` (`ProductIndexing`
  Catalog, jak `ReindexProductOnPriceChange`, ale bez `GetVariant`) i `InvalidateStorefrontOnPromotionChange`
  (`StorefrontCache::invalidateProduct`). Źródło `seed`: potwierdzenie bez skutku. Błędy jak w istniejących
  konsumentach (ponowienia, potem `failed`). `VariantPriceChanged` i jego konsumenci bez zmian.
- Przestój workera opóźnia tylko zmianę ceny w indeksie i cache sklepu. Koszyk i zamówienie zawsze liczą cenę w chwili
  odczytu; różnica z widokiem klienta kończy się 409 `OrderTotalChanged` (decyzja P2 CHECKOUT).

## Cart

- `CartPrices` niesie `regularGross` i `promotion` z `PriceSnapshot`. `ProductOffer` i `VariantOffer` mają cenę
  skuteczną (reguła zakupu bez zmian).
- Oferta produktów listy (`GetProductOffers`, z flagą `withLowestPriorPrice`: `true` dla endpointu ofert, `false`
  w `QueryBusProductOfferFacts` dla indeksu): wariant ma też `regularGross` i `promotion: {endsAt, lowestPriorGross:
  int | null} | null`; `ProductOfferSummary` dostaje `fromPromotion: {lowestPriorGross: int | null} | null` =
  promocja wariantu, który daje `fromGross` (remis cen: pierwszy w kolejności pozycji). Indeks wyszukiwania dostaje
  cenę skuteczną automatycznie (`ProductOfferFacts`), bez zmiany mapowania.
- Widok koszyka bez zmian w kodzie: `unitPrice` to cena skuteczna z `CartPrices`; bez oznaczenia promocji (Q11).

## Persystencja

- `pricing_promotion`: `id uuid` (PK), `name`, `percent_off smallint`, `starts_at`, `ends_at` (`timestamptz`),
  `created_at`, `created_by_id`, `created_by_name`, `updated_at`, `updated_by_id`, `updated_by_name` (aktor `null`
  dla seeda), `version`; CHECK `starts_at < ends_at`, `percent_off BETWEEN 1 AND 90`; indeks `(starts_at DESC, id)`.
- `pricing_promotion_variant`: `promotion_id` (FK do `pricing_promotion`, `ON DELETE CASCADE`), `variant_id`; PK
  `(promotion_id, variant_id)`, indeks `(variant_id)`. Bez FK do Catalog.
- Tabele promocji czyści `pricing:seed-demo` (`DbalPricingReset` rozszerzony o promocje i kolejkę `promotions`).

## API

Błędy w RFC 9457. Nowy schemat `PricingConflictProblem` w panelu (`price-locked-by-promotion`, `promotion-overlap`
z `conflictingVariantIds`, `promotion-not-editable`), który `PricesView` i widok promocji rozróżniają po `type`
(`apps/api/CLAUDE.md`, „API”).

Sklep (`apps/api/openapi.json`):
- `GET /api/v1/product-offers`: wariant `regularGross`, `promotion {endsAt, lowestPriorGross: int | null} | null`;
  oferta `fromPromotion {lowestPriorGross: int | null} | null`. Nagłówki cache bez zmian.

Panel (`apps/api/openapi-admin.json`, firewall `backoffice`, `private, no-store`; zmiany: `admin` i `marketing`,
pozostali 403; odczyt: każdy pracownik):

| Endpoint | Odpowiedzi |
|---|---|
| `GET /api/v1/admin/promotions?status&after&perPage` | 200 `{items: [{id, name, percentOff, startsAt, endsAt, status, variantCount, version}], next}`; 400 |
| `POST /api/v1/admin/promotions` `{name, percentOff, startsAt \| null, endsAt, variantIds}` | 201 promocja; 400 (treść nie jest JSON-em); 403; 409 `promotion-overlap`; 422 |
| `GET /api/v1/admin/promotions/{id}` | 200 `{…, variants: [{variantId, productId, productName, label, regularGross \| null, promotionalGross \| null}]}`; 404 |
| `PUT /api/v1/admin/promotions/{id}` `{name, percentOff, startsAt, endsAt, variantIds, version}` | 200; 400 (treść nie jest JSON-em); 403; 404; 409 (wersja, `promotion-not-editable`, `promotion-overlap`); 422. Dla `active` dozwolona zmiana wyłącznie `name` i `endsAt` (inne pola różne od zapisanych: 409 `promotion-not-editable`); `endsAt ≤` czas serwera kończy promocję od razu („Zakończ teraz” wysyła czas przeglądarki) |
| `DELETE /api/v1/admin/promotions/{id}?version=` | 204; 400 (brak albo zła `version`); 403; 404; 409 (wersja, `promotion-not-editable`) |
| `PUT /api/v1/admin/variants/{variantId}/price` (istniejący) | nowe 409 `price-locked-by-promotion` |

- Czasy w RFC 3339 ze strefą (zapis w UTC, odpowiedź w UTC). Kwoty w groszach, procenty jako liczby całkowite.
- Aktor z tokena. Typy ścisłe jak `PUT` ceny (`docs/features/pricing/spec.md`).

## Sklep (`apps/store`)

- Oznaczenie „Promocja” pojawia się zawsze razem z linią „Najniższa cena z 30 dni przed obniżką: X zł” (Q11);
  `lowestPriorGross = null` pokazuje cenę promocyjną bez obu.
- Kafelek (`ProductTile`/`TileOffer`): cena „od” skuteczna; przy `fromPromotion` oznaczenie i linia Omnibus; po
  wyborze wariantu w kafelku jego cena, a przy `promotion` oznaczenie i jego linia Omnibus.
- Strona produktu: przed wyborem jak kafelek; po wyborze wariantu jego cena, a przy `promotion` oznaczenie i linia
  Omnibus z jego `lowestPriorGross`. JSON-LD `Offer`: `price` skuteczna, przy promocji `priceValidUntil` = data chwili
  `endsAt − 1 s` w `Europe/Warsaw` (`YYYY-MM-DD`; okres jest prawostronnie otwarty, więc promocja do 00:00 obowiązuje
  do poprzedniego dnia). Strona jest odświeżana przez istniejącą rewalidację po zdarzeniu.
- Koszyk i kasa: cena skuteczna bez oznaczenia promocji, bez zmian w kodzie.

## Panel (`apps/admin`)

- Menu „Promocje” (`/promotions`). Przyciski zmian widzą tylko role `admin` i `marketing` (role z `/me`; API sprawdza
  niezależnie).
- Promocje: lista z filtrem statusu i stronicowaniem kursorami; formularz (nazwa, procent, początek z opcją „od teraz”,
  koniec; czasy wpisywane i pokazywane w `Europe/Warsaw`); wybór wariantów: wyszukiwanie produktów z listy cen
  (`GET /api/v1/admin/prices`) i zaznaczanie wariantów albo całego produktu, lista wybranych z możliwością usunięcia;
  widok szczegółów z cenami katalogowymi i promocyjnymi z API (wariant usunięty z katalogu oznaczony „Wariant
  usunięty”); „Zakończ teraz” (aktywna: `PUT` z `endsAt` = teraz), „Usuń” (zaplanowana). Pola niedostępne dla
  aktywnej promocji są zablokowane. 409 `promotion-overlap` oznacza kolidujące warianty, inne 409: komunikat
  z `detail` i odświeżenie.
- Ceny (`PricesView`): 409 `price-locked-by-promotion` pokazuje `detail` przy polu ceny bez komunikatu o zmianie
  w międzyczasie i bez odświeżania wiersza (pozostałe 409 jak dziś); rozróżnienie po `type`.

## Dane demo (`make seed`)

- `fixtures/promotions.yaml`: 3–4 promocje na wzorcach kluczy wariantów (składnia `fixtures/prices.yaml`), np. 20% na
  namioty i 15% na żele energetyczne, start w chwili seeda, koniec za 30 dni. Ładuje `pricing:seed-demo` po cenach
  (źródło `seed`, więc zdarzenia nie unieważniają cache; robi to końcowe `storefront:cache:purge-all`), z wiadomościami
  końca promocji na kolejce `promotions`.
- Wzorzec bez wariantu kończy seed błędem bez zmian w danych: `promotions.yaml` jest sprawdzany przed resetem i seedem
  cen (jak dziś warianty bez wzorca ceny).
- Promocje demo omijają produkty i kategorie używane przez E2E: produkty „Hantle regulowane Lumora Para 10”, „Buty
  szosowe Tarvik Asfalt 200”, produkt testu `promotions.spec.ts` i całą kategorię Bieganie > Buty biegowe
  (`listing-filters.spec.ts` sprawdza jej zakres cen); test kontraktu fixtures sprawdza tę listę.
- Omnibus po seedzie: historia ma tylko wpis seeda, więc cena Omnibus = cena katalogowa.

## Scenariusze błędów

| Sytuacja | Wynik |
|---|---|
| Promocja nakłada się na inną dla wspólnego wariantu (także dwa równoległe zapisy) | 409 `promotion-overlap` z `conflictingVariantIds`, jeden zapis wygrywa |
| Zmiana procentu, startu albo wariantów aktywnej promocji | 409 `promotion-not-editable` |
| Przesunięcie końca aktywnej promocji | natychmiastowe odświeżenie ofert i stron produktów promocji (`endsAt`, `priceValidUntil`) |
| Dodanie do promocji wariantu bez ceny albo nieznanego | 422 |
| Promocja z końcem w przeszłości, procent spoza 1–90, okres > 90 dni, 0 albo > 1000 wariantów | 422 |
| Pracownik `support`/`warehouse` zmienia promocję | 403 |
| Zmiana ceny wariantu w aktywnej promocji (panel) | 409 `price-locked-by-promotion`; panel: komunikat z `detail` bez odświeżania wiersza |
| Worker stoi w chwili startu promocji | sklep i indeks pokazują starą cenę do obsłużenia wiadomości; koszyk i zamówienie liczą cenę promocyjną; kasa z nieaktualną sumą: 409 `OrderTotalChanged` |
| Wariant w promocji bez aktualnej ceny katalogowej (nieaktualna stawka VAT) | brak ceny („Cena niedostępna”), jak dziś |
| Wariant bez ceny przed startem promocji | cena promocyjna bez oznaczenia i bez linii Omnibus |

## Kryteria akceptacji

1. Wariant w aktywnej promocji ma cenę promocyjną w ofercie, na kafelku, stronie produktu (z JSON-LD), w indeksie
   (filtr i sortowanie po cenie), w koszyku i w zamówieniu; po końcu promocji wraca cena katalogowa. Netto i VAT ceny
   promocyjnej liczy `PriceBreakdown`.
2. Przy wariancie w promocji sklep pokazuje oznaczenie i cenę Omnibus równą najniższej cenie skutecznej z 30 dni przed
   startem, także gdy w oknie była inna promocja albo zmiana ceny katalogowej.
3. Start i koniec promocji są widoczne w sklepie bez ręcznego unieważniania (purge i rewalidacja po zdarzeniu ze źródła
   `promotion`); dla promocji na jeden produkt w ciągu 70 s od granicy przy działającym workerze.
4. Okresy promocji o wspólnym wariancie się nie nakładają; aktywnej promocji nie da się zmienić poza nazwą i końcem
   w przyszłości.
5. Cena katalogowa wariantu w aktywnej promocji się nie zmienia (409); reconcile podatkowy działa.
6. Promocje zmieniają tylko `admin` i `marketing`; każdy pracownik je widzi; Deptrac odrzuca próby obejścia.
7. Po `make seed` sklep ma aktywne promocje z ceną Omnibus.
8. Przechodzą `make -C apps/api check`, `make -C apps/api test`, `check` i `test` sklepu i panelu oraz `make e2e`.

## Testy

- Jednostkowe (TDD, `apps/api/tests/Unit/Pricing`, `Unit/Cart`): `PromotionalPrice` (granice procentu, 1 grosz,
  maksimum), `Promotion` (statusy, `plan` od teraz ze `startOfNow`, `revise` i usunięcie w każdym statusie, zakończenie
  z `endsAt ≤ now`, limit 90 dni, aktor zmiany), `LowestPriorPrice` (brak zmian, podwyżka przed promocją, wcześniejsza
  promocja w oknie, promocja zaczęta przed oknem i kończąca się w nim, zmiana ceny w oknie, pierwsza cena w środku
  okna, brak ceny przed startem (`null`), wpis dokładnie na granicy okna, wpis w tej samej sekundzie co start „od
  teraz”, wpis z nieaktualną stawką), `SetVariantPrice` w aktywnej, zaplanowanej i zakończonej promocji, `reprice`
  w aktywnej, `GetCurrentPrices` z promocją i bez flagi Omnibusa, oferta z `fromPromotion`, handler granicy (nieaktualna
  wiadomość, za wczesna, jedno zdarzenie na produkt przy wielu wariantach, wariant nieznany Catalogowi),
  `RefreshPromotionProducts`, konsumenci `PromotionPricesChanged` (źródło `seed` bez skutku).
- Integracyjne (PostgreSQL): repozytorium promocji, zapytania Omnibusa dla wielu wariantów, opóźniona wiadomość na
  transporcie `promotions` (`test_messaging`), reset demo, seed z wzorcem bez wariantu.
- Współbieżność (5 rund): dwa równoległe zapisy nakładających się promocji dają jeden sukces i 409; zmiana ceny
  i równoległe utworzenie promocji „od teraz” na ten wariant: cena zmieniona przed startem (`changed_at < startsAt`)
  albo 409, nigdy zmiana w aktywnej promocji.
- Funkcjonalne API: każdy endpoint promocji (200/201/204, 400, 401 bez tokena i z tokenem `shop`, 403 `support`
  i `warehouse`, 404, 409, 422), oferta z promocją, `PUT` ceny wariantu w aktywnej promocji (409 nowego typu).
- Architektura: Deptrac bez nowych reguł; próby obejścia (pliki tymczasowe muszą dać błąd): `Pricing_* → Cart_*`,
  `Pricing_* → Ordering_*`, `Cart_Domain → Pricing_*`.
- Vitest: sklep – kafelek i strona produktu z promocją i bez, `lowestPriorGross = null`, wybór wariantu w promocji
  w kafelku, JSON-LD z `priceValidUntil` (także koniec o 00:00); panel – lista, formularz (blokada pól aktywnej
  promocji, czasy w strefie `Europe/Warsaw`, „Zakończ teraz”), wybór wariantów, widoczność przycisków według ról, 409
  (także `conflictingVariantIds` i `price-locked-by-promotion` w `PricesView`).
- E2E (Playwright): `promotions.spec.ts` na własnym produkcie demo, spoza innych testów i spoza promocji demo; da się
  go powtarzać na tym samym stosie: na początku kończy aktywną promocję tego produktu z poprzedniego uruchomienia
  (helper w `e2e/support` przez API panelu), a w `finally` kończy swoją. `marketing` tworzy w panelu promocję „od
  teraz” na ten produkt; strona produktu i lista pokazują cenę promocyjną i cenę Omnibus równą `lowestPriorGross` z `GET
  /api/v1/product-offers`, nie stałej (poprzednie uruchomienie zostawia promocję w oknie 30 dni), do 70 s.

## Ryzyka i ograniczenia

- Promocja na 1000 wariantów publikuje na każdej granicy zdarzenie na każdy produkt (do 1000), czyli tyle reindeksów
  i unieważnień, ile produktów. Wystarcza dla MVP; promocje na kategorie albo sklep wymagałyby innego celu promocji.
- `GetCurrentPrices` robi dodatkowe zapytanie o promocje przy każdym odczycie, a z flagą Omnibusa dla wariantów
  w promocji dwa kolejne; oferty są w Varnishu (`s-maxage` 1 h), koszyk i kasa nie liczą Omnibusa.
- Blokada doradcza szereguje wszystkie zapisy promocji i (współdzielona) zapisy cen z panelu; zapisy promocji są rzadkie.
  Wydajność nie jest potwierdzona testami obciążeniowymi.
