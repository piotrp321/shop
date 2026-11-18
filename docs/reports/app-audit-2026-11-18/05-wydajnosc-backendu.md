# 05. Wydajność i skalowalność backendu

Audyt statyczny migawki `main` (`ef204620`). Obszar: `apps/api`, PostgreSQL, Elasticsearch, cache (Redis, Varnish),
Messenger i RabbitMQ, konfiguracja wykonania, skrypty `load/`.

## Zakres i metoda

Punkt odniesienia: `docs/vision.md`, „Wymagania niefunkcjonalne”: katalog do 10 mln produktów, do 60 tys. jednocześnie
aktywnych użytkowników; wizja zastrzega, że to cel architektury, a nie deklaracja obecnej wydajności, i że pomiary
opisują mały katalog demo.

Przeczytane (ok. 110 plików):

- wszystkie 41 migracji `apps/api/migrations` (każda instrukcja `addSql`), zestawione z zapytaniami;
- ok. 55 plików PHP z `*/Infrastructure` i `*/Application`: wszystkie modele odczytu DBAL Catalog, Ordering, Inventory,
  Pricing i Cart, repozytoria Doctrine (produkt, kategoria, koszyk, zamówienie, cena, promocja, kod rabatowy, stan),
  cały katalog `Catalog/Infrastructure/Search`, przebudowa i reindeksacja indeksu, handlery `PlaceOrder`,
  `StartPayment`, `RedeemDiscountCode`, `QuoteBasket`, `GetProductOffers`, `ListProducts`, `GetDashboard`, uzgadnianie
  VAT, unieważnianie cache sklepu, relay outboxa;
- konfiguracja: `config/services.yaml`, `packages/{doctrine,messenger,framework,rate_limiter}.yaml`, `Dockerfile`,
  `docker/frankenphp/Caddyfile`, `docker/php/conf.d/*.ini`, `infra/varnish/default.vcl`, `infra/docker-compose.yml`
  (usługi postgres, varnish, worker), chart `infra/k8s/charts/shop` (`api.yaml`, `postgres.yaml`, `varnish.yaml`,
  `ingress.yaml`, `middlewares.yaml`, `_helpers.tpl`, `jobs.yaml`, `values.yaml`, `files/scripts/initialize.sh`),
  profile `environments/prod` i `environments/load`;
- `load/` w całości (`lib/config.js`, `lib/data.js`, `steady.js`, `saturate.js`, `README.md`);
- specyfikacje: `search`, `demo-data` (cel, decyzje, ryzyka H1–H5), `admin-features` (skala, ryzyka),
  `discount-codes` (decyzje A1, A3, D7), `hardening` (D15, D18), `deploy` (jedna replika); `apps/api/CLAUDE.md`
  w części o symulatorze płatności;
- testy przypinające plany zapytań (`SearchBackofficeProductsTest`, `BackofficeProductViewsTest`,
  `BoundedCounterTest`, `DoctrineDiscountCodeUsageRepositoryTest`) w zakresie tego, co przypinają.

Metoda: dla każdej ścieżki odczytu i zapisu sprawdzałem, który indeks ją obsługuje, czy koszt zależy od rozmiaru
tabeli, co dzieje się w żądaniu HTTP, a co w workerze, i jak warstwa cache zachowuje się przy chybieniu i przy awarii.
Niczego nie uruchamiałem. Szacunki czasu i przepustowości są rzędami wielkości wyprowadzonymi z liczby zapytań
i żądań na operację, nie pomiarami.

Ważny kontekst: specyfikacja `docs/features/demo-data/spec.md` (sekcja „Ryzyka”) sama wymienia pięć hipotez H1–H5
i kilka braków „do sprawdzenia pomiarem”. Generatora danych w skali (`apps/api/src/DemoData`) w tej migawce nie ma,
więc żadna z nich nie jest zweryfikowana. Znaleziska pokrywające się z H1–H5 oznaczam; nie traktuję ich jako nowych,
ale oceniam, bo pozostają otwarte.

## Ocena ogólna obszaru

**7 / 10.**

Warstwa dostępu do danych jest napisana z wyraźnie ponadprzeciętną świadomością skali: stronicowanie keyset we
wszystkich listach, liczniki ograniczone limitem, odczyty przez projekcje DBAL zamiast hydratacji encji, modele
odczytu dla sprzedaży i klientów, indeksy częściowe z testami planów, indeksy budowane `CONCURRENTLY`, operacje masowe
w partiach z czyszczeniem sesji. Na ścieżce klienta (strona produktu, oferty, koszyk, złożenie zamówienia) nie
znalazłem ani jednego N+1, pełnego skanu ani ładowania zbioru do pamięci. Dokumentacja uczciwie nazywa kompromisy.

Punkty odjąłem za to, co leży poza pojedynczym zapytaniem:

- topologię przetwarzania asynchronicznego (jeden proces, ścisły priorytet transportów, jedna kolejka FIFO na e-maile
  i reindeksację), która przy pierwszej operacji masowej zatrzymuje e-maile zamówień i wygaszanie rezerwacji;
- listing i wyszukiwarkę, których koszt przy chybieniu cache rośnie liniowo z katalogiem, z limitem 2 s i jednym
  shardem zaszytym w założeniach faset;
- warstwę cache, która przy degradacji Elasticsearcha przestaje chronić backend, a publiczny host API w ogóle ją
  omija;
- konfigurację wykonania pozostawioną na wartościach domyślnych (FrankenPHP, PostgreSQL, brak puli połączeń);
- brak jakiegokolwiek pomiaru przy skali docelowej.

Dziś, na katalogu demo (ok. 2 tys. zdarzeń seeda, jedna replika każdej usługi), żadne z poniższych znalezisk nie jest
awarią. Wszystkie znaleziska o wadze High i Medium to ryzyka skali, z podanym progiem.

## Znaleziska

### 1. Jeden worker, ścisły priorytet transportów i jedna kolejka `events`: operacja masowa zatrzymuje e-maile zamówień, wygaszanie nieopłaconych zamówień i unieważnianie cache

- **Waga:** High
- **Klasa:** RYZYKO
- **Dowód:**
  - `infra/k8s/charts/shop/templates/api.yaml:136` (to samo w `infra/docker-compose.yml:350`):
    ```
    command: ["php", "bin/console", "messenger:consume", "outbox", "events", "scheduled", "payment_simulator", "promotions", "jobs"]
    ```
    Jeden proces, sześć transportów w kolejności priorytetu, bez `--time-limit` i `--memory-limit`.
    `infra/k8s/charts/shop/templates/_helpers.tpl:244`: `replicas: 1` wpisane w szablon Deploymentu (bez wartości).
  - `apps/api/config/packages/messenger.yaml:40-41`: jeden transport AMQP `events` (jedna kolejka) dla wszystkich
    zdarzeń domenowych.
  - Na tej samej kolejce stoją handlery o zupełnie różnej wadze biznesowej:
    `Ordering/Application/EventHandler/SendOrderConfirmation.php:23`, `SendPaymentReceived`, `SendOrderCancelled`
    (e-maile, SMTP synchronicznie, limit 10 s) obok `Pricing/Infrastructure/Search/ReindexProductOnPriceChange.php:29`,
    `Inventory/Infrastructure/Search/ReindexProductOnStockLevelChange.php:29`
    i `*/Infrastructure/Storefront/InvalidateStorefrontOn*.php` (reindeksacja ES, PURGE Varnisha, rewalidacja sklepu
    z limitem 30 s: `Shared/Infrastructure/Http/Cache/HttpStorefrontCache.php:25`).
  - Źródło masowych zdarzeń istnieje w kodzie: `Pricing/Application/TaxReconciliation/ReconcileTax.php:48-65`
    przechodzi wszystkie ceny, a każdy zapis publikuje `VariantPriceChanged`
    (`Pricing/Infrastructure/Persistence/Doctrine/DoctrineVariantPriceRepository.php:104-107`). Handlery pomijają tylko
    źródło `Seed`:
    ```php
    // ReindexProductOnPriceChange.php:47, InvalidateStorefrontOnPriceChange.php:43
    if (PriceChangeSource::Seed === $event->source) {
        return;
    }
    ```
    Uzgodnienie VAT nie zmienia kwoty brutto (`ReconcileTax.php:17-18`: „the same gross”), a publiczne odpowiedzi
    ofert i dokument indeksu zawierają wyłącznie brutto (`Cart/UI/Http/ProductOfferResponse.php:14`,
    `Catalog/Infrastructure/Search/ProductIndexDefinition.php:67-75`). Reindeksacja i unieważnienie po takim zdarzeniu
    niczego więc nie zmieniają.
- **Konsekwencja:** Worker Symfony obsługuje transporty ze ścisłym priorytetem: dopóki `outbox` nie jest pusty, nie
  czyta `events`; dopóki `events` nie jest pusty, nie czyta `scheduled`, `promotions` ani `jobs`. Scenariusz: zmiana
  stawki VAT i `pricing:reconcile-tax --apply`. Jedno zdarzenie ceny to dwa handlery, razem ok. 7 zapytań SQL, jeden
  zapis do ES i dwa żądania HTTP, czyli rząd 20–60 zdarzeń na sekundę na jeden proces (szacunek). Przy 100 tys. cen
  to 0,5–1,5 godziny, przy 1 mln 5–14 godzin, przy pełnym katalogu (ok. 30 mln wariantów według P3 z DEMO-DATA) dni.
  W tym czasie:
  - klient nie dostaje potwierdzenia zamówienia ani potwierdzenia płatności (e-maile stoją w tej samej kolejce FIFO
    za reindeksacją);
  - `ExpireUnpaidOrder` (`scheduled`) nie jest czytany, więc nieopłacone zamówienia trzymają rezerwacje towaru długo
    po 30-minutowym terminie, a towar znika ze sprzedaży;
  - zmiany cen i dostępności wprowadzone przez sprzedawcę nie docierają do sklepu inaczej niż po TTL (oferty mają
    `s-maxage=3600`, `Cart/UI/Http/ProductOffersController.php:104`).

  Ten sam mechanizm zadziała przy każdej przyszłej operacji masowej (import cen, akcje masowe z ADMIN-TOOLS).
  Prawdopodobieństwo: przy skali demo znikome (2 tys. cen to minuta lub dwie opóźnienia); od ok. 100 tys. wariantów
  opóźnienie liczone w godzinach przy każdej zmianie stawki VAT.
- **Rekomendacja:** (1) Rozdzielić worker na osobne Deploymenty według transportów: relay outboxa, powiadomienia,
  indeksacja z unieważnianiem, komendy opóźnione i zadania. (2) Rozdzielić kolejkę `events` na co najmniej dwie
  (powiadomienia klienta, pochodne katalogu) kluczem routingu. (3) W handlerach reindeksacji i unieważniania pomijać
  `PriceChangeSource::TaxReconciliation`, bo brutto się nie zmienia; to usuwa znane dziś źródło masowego ruchu.
  (4) Liczbę replik workera wynieść do `values.yaml`. (5) Dodać `--time-limit` i `--memory-limit`.
- **Pewność:** wysoka co do topologii i ścieżki zdarzeń (przeczytane: konfiguracja Messengera, komenda workera, lista
  wszystkich handlerów `fromTransport: 'events'`, repozytorium cen, oba handlery ceny). Średnia co do liczb
  (niezmierzone). Kolejność obsługi transportów przez `Worker` znam z kodu Symfony, katalogu `vendor` w eksporcie nie
  ma; potwierdza ją komentarz w `infra/docker-compose.yml:327` („Consumes the outbox … before events”). Sprawdziłem,
  że promocje mają limit 1000 wariantów (`Pricing/Domain/Promotion/Promotion.php:29`), więc one tego nie wywołają,
  a zdarzenia seeda są pomijane.

### 2. Listing i wyszukiwarka: dokładna liczba wyników i 4–5 agregacji `nested` z `reverse_nested` przy każdym chybieniu cache, na jednym shardzie i z limitem 2 s (hipoteza H1 specyfikacji, nadal otwarta)

- **Waga:** High
- **Klasa:** RYZYKO
- **Dowód:**
  - `apps/api/src/Catalog/Infrastructure/Search/ProductSearchQuery.php:33-40`:
    ```php
    'query' => self::query($criteria),
    'sort' => self::sort($criteria->sort()),
    'track_total_hits' => true,
    'from' => $from,
    'size' => $size,
    'aggs' => self::aggregations($criteria),
    ```
    `aggregations()` (linie 117-184) zawsze dokłada `available`, `price` i `free` (`terms` po osi, w nim `terms` po
    wartości, oba sortowane po `reverse_nested`), a dla każdej wybranej osi kolejną agregację.
  - `apps/api/config/services.yaml:8-9,23`: `app.search.shards: 1`, `app.search.replicas: 0`,
    `app.search.request_timeout: 2.0` (parametry kontenera, nie zmienne środowiska).
  - Dokładność faset zależy od jednego sharda (`ProductSearchQuery.php:140-141`): „The index has one shard, so the
    sorted terms are exact; with more shards they would need a shard_size…”.
  - `docs/features/search/spec.md:424`: „Fasety na 10 mln produktów … nie są potwierdzone testami obciążeniowymi”;
    `docs/features/demo-data/spec.md` H1.
- **Konsekwencja:** Koszt zapytania rośnie liniowo z liczbą produktów pasujących do `query`, bo dokładny `total`
  wyłącza wczesne zakończenie, a agregacje przechodzą po wszystkich wariantach i parach oś–wartość dopasowanych
  produktów. Przy 10 mln produktów indeks ma rząd 50–100 mln dokumentów Lucene w jednym shardzie (spec: w danych demo
  2,4 raza więcej dokumentów niż produktów; średnio ok. 3 warianty). Listing bez kategorii (`/`) i działy główne
  agregują miliony produktów. Jedno zapytanie na jednym shardzie wykonuje jeden wątek, więc czas liczony w sekundach
  jest realny już od kilkuset tysięcy dopasowanych produktów, a limit klienta to 2 s. Skutek dla klienta: strona
  główna listingu i duże działy odpowiadają 503 przy każdym chybieniu cache, a Varnish nie ma czego trzymać, bo
  udanej odpowiedzi nigdy nie było. Założenie jednego sharda zamyka też najprostszą drogę skalowania ES: dodanie
  shardów zmienia wyniki faset, więc wymaga zmiany kodu, nie konfiguracji. Sterta ES w `prod` to 2 GB
  (`infra/k8s/environments/prod/values.yaml`, `elasticsearch.javaOpts`).

  Dziś: katalog demo, brak problemu. Próg: nieznany bez pomiaru; spodziewam się go między 100 tys. a 1 mln produktów
  w jednym dziale.
- **Rekomendacja:** Zmierzyć przed jakąkolwiek zmianą (to właśnie cel DEMO-DATA). Kierunki o największym zwrocie:
  `track_total_hits` ograniczone do okna 10 000 dla listingu bez frazy (strony i tak kończą się na oknie,
  `ElasticsearchProductSearch.php:30-31`); fasety liczone osobnym, dłużej cachowanym żądaniem albo tylko wtedy, gdy
  jest kategoria lub fraza; dla listingu bez filtrów fasety z góry policzone na kategorię. Liczbę shardów zrobić
  parametrem środowiska dopiero razem z `shard_size`.
- **Pewność:** wysoka co do kształtu zapytania i konfiguracji; niska co do progu (statycznie nie do ustalenia).
  Sprawdziłem, że warunek wariantu idzie do `post_filter`, więc nie zawęża agregacji, i że handler nie ma własnego
  cache po stronie API.

### 3. Warstwa cache nie chroni backendu przy degradacji: 503 wyłącza łączenie żądań na 120 s, `grace` 10 s, twarde czyszczenie całego katalogu, a publiczny host API omija Varnisha i nie ma limitu żądań

- **Waga:** High
- **Klasa:** RYZYKO
- **Dowód:**
  - `apps/api/src/Shared/Infrastructure/Http/Cache/PublicReadErrorCacheSubscriber.php:21,41-42`:
    ```php
    private const array STATUSES = [400, 401, 503];
    …
    $response->headers->remove('Cache-Control');
    $response->headers->set('Cache-Control', 'private, no-store');
    ```
  - `infra/varnish/default.vcl:61-69`: `vcl_backend_response` woła tylko reguły wbudowane; w całym pliku nie ma
    `beresp.grace`, `beresp.keep` ani obsługi błędu backendu. Varnish startuje z `-t 0`
    (`infra/k8s/charts/shop/templates/varnish.yaml:4`), bez `default_grace` i bez rozmiaru pamięci (domyślne 100 MB
    obrazu przy limicie poda 384–512 Mi).
  - `infra/varnish/default.vcl:45-47`: „A hard purge (not a soft one)”.
  - Czyszczenie tagu `catalog` (wszystkie odpowiedzi katalogu) następuje po każdej zmianie drzewa kategorii
    (`Catalog/Infrastructure/Storefront/InvalidateStorefrontOnCategoryTreeChange.php:29-32`, bez rozróżnienia rodzaju
    zmiany), na końcu łańcucha reindeksacji (`Catalog/Application/Command/ReindexCategoryBatchHandler.php:78`)
    i przy każdym wdrożeniu (`infra/k8s/charts/shop/templates/jobs.yaml:195`, `storefront:cache:purge-all`).
  - `infra/k8s/charts/shop/templates/ingress.yaml:12`: host `api.<domena>` wskazuje wprost na usługę `api`, nie na
    `varnish`. Limity żądań mają tylko zamówienia, płatności i webhooki (`config/packages/rate_limiter.yaml:15-33`;
    `RateLimit::ROUTE_DEFAULT` występuje w pięciu kontrolerach Ordering i Payment). `middlewares.yaml` ma wyłącznie
    `ipallowlist`, `noindex` i `hsts`.
- **Konsekwencja:**
  1. Odpowiedź `private, no-store` Varnish zapamiętuje jako hit-for-miss na 120 s: przez ten czas żądania tego
     samego adresu nie czekają na jedno pobranie, tylko wszystkie idą do API równolegle. Gdy ES zwalnia i listing
     przekracza 2 s, pierwsze 503 zdejmuje ochronę dokładnie z tych adresów, które są najdroższe. Im wolniejszy ES,
     tym więcej równoległych zapytań dostaje.
  2. Obiekt po TTL żyje jeszcze domyślne 10 s. Nie ma serwowania nieświeżej odpowiedzi przy błędzie backendu, więc
     60 s po ostatnim udanym odświeżeniu klient widzi 503, mimo że poprawna odpowiedź istniała minutę wcześniej.
  3. Twarde czyszczenie tagu `catalog` pod ruchem zeruje cały cache naraz. Przy docelowym ruchu każde wdrożenie
     i każda zmiana nazwy kategorii kończy się falą chybień na najdroższe zapytania (znalezisko 2).
  4. Każdy może wołać `https://api.<domena>/api/v1/products?q=…&page=…` z dowolnymi parametrami. Każde takie żądanie
     to zapytanie do ES z pełnymi agregacjami i hydratacja drzewa kategorii (znalezisko 10), bez cache i bez limitu,
     na puli wątków wspólnej z koszykiem i checkoutem (znalezisko 7).

  Dziś: punkt 4 działa na serwerze demo już teraz, ale przy małym katalogu jedno zapytanie kosztuje milisekundy.
  Punkty 1–3 bolą od chwili, gdy listing zbliża się do limitu 2 s.
- **Rekomendacja:** W VCL ustawić `beresp.grace` (np. 1 h) i przy 5xx oddawać nieświeży obiekt (`return (abandon)`
  w `vcl_backend_response`, gdy istnieje obiekt w `grace`); 503 publicznego odczytu cachować krótko (2–5 s) zamiast
  `no-store`, żeby łączenie żądań zostało; czyszczenie całego katalogu robić `xkey.softpurge`, a twarde zostawić dla
  tagu pojedynczego produktu; odczyty katalogu na hoście `api.` kierować przez Varnisha albo objąć limitem żądań.
- **Pewność:** wysoka co do konfiguracji (przeczytany cały VCL, subscriber, ingress, lista tras z limitem); średnia co
  do zachowania wbudowanego VCL Varnisha 9 (hit-for-miss 120 s, `default_grace` 10 s znam z dokumentacji, nie
  z uruchomienia). Świadoma decyzja o twardym purge jest uzasadniona dla pojedynczego produktu; dla całego katalogu
  uzasadnienia w specyfikacji nie znalazłem poza „zmiany drzewa są rzadkie”.

### 4. Filtr kategorii w listach panelu (produkty, cennik, stany) nie jest warunkiem indeksu, a „ograniczony” licznik skanuje wtedy całą tabelę (hipoteza H4, nadal otwarta)

- **Waga:** Medium
- **Klasa:** RYZYKO
- **Dowód:**
  - `apps/api/src/Catalog/Infrastructure/Persistence/Doctrine/DbalBackofficeProductSource.php:35-43`:
    ```sql
    (
        p.primary_category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE :prefix)
        OR EXISTS (
            SELECT 1 FROM catalog_product_category pc
            WHERE pc.product_id = p.id AND pc.category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE :prefix)
        )
    )
    ```
    Ten sam warunek trafia do strony (linie 69-71, `ORDER BY p.name, p.id LIMIT`) i do licznika (linie 80-85).
  - `apps/api/src/Shared/Infrastructure/Persistence/BoundedCounter.php:14-16`: „The subquery stops after limit + 1
    rows, so the cost depends on the limit, never on the size of the table”.
  - Te same zapytania obsługują cennik i listę stanów: `Pricing/Infrastructure/Catalog/QueryBusProductDirectory.php:48`,
    `Inventory/Infrastructure/Catalog/QueryBusProductDirectory.php:46`.
  - Testy planów przypinają tylko warianty bez kategorii
    (`tests/Integration/Catalog/Query/SearchBackofficeProductsTest.php:379-384`).
- **Konsekwencja:** Podzapytania pod `OR` PostgreSQL wykonuje jako filtr wiersza, nie jako złączenie ani warunek
  indeksu. Strona idzie więc po indeksie `(name, id)` i odrzuca wiersze, aż zbierze 26 pasujących; licznik czyta
  wiersze, aż zbierze 10 001. Zdanie z komentarza `BoundedCounter` jest prawdziwe tylko wtedy, gdy pasujących wierszy
  jest więcej niż limit albo warunek obsługuje indeks. Dla kategorii mającej mniej niż 10 001 produktów (przy 10 mln
  produktów i kilku tysiącach kategorii to prawie każdy liść) licznik czyta całą tabelę `catalog_product`, a dla
  kategorii z mniej niż 26 produktami robi to także sama strona. Pracownik wybiera kategorię w liście produktów,
  cenniku albo stanach i czeka kilkanaście sekund lub dłużej na każde przeładowanie; kilku pracowników naraz zajmuje
  wątki API i połączenia bazy, bo `statement_timeout` nie jest ustawiony. To samo dotyczy frazy dwuznakowej
  (`SearchBackofficeProducts::MIN_TERM_LENGTH = 2`; komentarz w liniach 21-22 przyznaje skan z filtrem).

  Dziś: brak problemu. Próg: zauważalne od ok. 1 mln produktów, uciążliwe przy 10 mln.
- **Rekomendacja:** Zapisać przypisanie produktu do kategorii w jednej tabeli (kategoria główna także
  w `catalog_product_category` albo osobna tabela `(category_id, product_id)` z kategoriami przodków), tak żeby
  filtr był jednym `EXISTS` po indeksie zaczynającym się od kategorii, a nie `OR`. Do czasu zmiany: licznik dla
  filtra kategorii pominąć (panel umie pokazać listę bez sumy) i ustawić `statement_timeout` dla odczytów panelu.
- **Pewność:** wysoka co do tego, że warunek nie jest indeksowalny w tej postaci (potwierdzają to obie specyfikacje:
  H4 i ryzyko „Filtr kategorii z sortowaniem” w `admin-features/spec.md:514`); średnia co do czasów (bez `EXPLAIN`
  na danych w skali).

### 5. Liczba aktywnych użyć kodu rabatowego: nieograniczone `count(*)` w podglądzie koszyka, pod blokadą wiersza przy złożeniu zamówienia i w liście panelu

- **Waga:** Medium
- **Klasa:** RYZYKO
- **Dowód:**
  - `apps/api/src/Pricing/Infrastructure/Persistence/Doctrine/DoctrineDiscountCodeUsageRepository.php:24`:
    ```php
    public const string ACTIVE_USES = 'SELECT count(*) FROM pricing_discount_code_usage WHERE code_id = CAST(:codeId AS uuid) AND released_at IS NULL';
    ```
  - Wołane przy każdej wycenie koszyka z kodem z limitem (`Pricing/Application/Query/QuoteBasketHandler.php:62`) i przy
    złożeniu zamówienia, po zablokowaniu wiersza kodu
    (`Pricing/Application/Command/RedeemDiscountCodeHandler.php:44-51`). Blokada trwa do końca transakcji
    `PlaceOrder`, czyli obejmuje też rezerwację stanów i zapis zamówienia
    (`Ordering/Application/Command/PlaceOrderHandler.php:129-143`).
  - `Pricing/Domain/DiscountCode/UsageLimit.php:13`: `MAX = 1_000_000`.
  - Lista kodów w panelu liczy użycia każdego kodu strony bez ograniczenia, także kodów bez limitu
    (`Pricing/Infrastructure/Persistence/Doctrine/DbalDiscountCodeReader.php:43`).
- **Konsekwencja:** Koszt licznika rośnie liniowo z liczbą użyć kodu. Scenariusz: kampania „kod X dla pierwszych
  200 tys. zamówień”. Po 150 tys. użyć każdy podgląd koszyka z tym kodem liczy 150 tys. pozycji indeksu (świeżo
  wstawione wiersze nie są w mapie widoczności, więc dochodzą odczyty sterty), a każde złożenie zamówienia robi to
  samo, trzymając blokadę kodu. Zamówienia z tym kodem wykonują się jedno po drugim, każde o czas licznika plus
  resztę transakcji; przepustowość checkoutu z kodem spada do kilku–kilkunastu na sekundę dokładnie w szczycie
  kampanii. W panelu jeden popularny kod bez limitu (miliony użyć) spowalnia każde otwarcie listy kodów. Zasada
  z `admin-features/spec.md:25-27` („żadne zapytanie żądania nie liczy `count(*)` po całej tabeli”) jest tu
  w praktyce złamana dla kodu, do którego należy większość tabeli.

  Dziś: brak problemu. Próg: od ok. 100 tys. aktywnych użyć jednego kodu.
- **Rekomendacja:** Trzymać licznik aktywnych użyć w wierszu kodu i zmieniać go pod blokadą, którą `Redeem` i tak już
  bierze dla kodu z limitem; zwolnienie użycia (`ExpireUnpaidOrder`) wziąć pod krótką blokadę kodu. Decyzja A1
  odrzuciła licznik z powodu blokady przy zwolnieniu, ale zwolnienie jest rzadkie i krótkie, a obecny koszt płaci
  każdy koszyk. W liście panelu użyć licznika ograniczonego jak w innych listach.
- **Pewność:** wysoka (przeczytane oba miejsca wywołania, handler złożenia, specyfikacja A1, D7; test planu potwierdza
  tylko użycie indeksu częściowego, nie koszt).

### 6. Przebudowa indeksu trwa godzinami przy skali docelowej i nie jest online; reindeksacja poddrzewa zapisuje dokumenty pojedynczo w jednej transakcji (H5, nadal otwarta)

- **Waga:** Medium
- **Klasa:** RYZYKO
- **Dowód:**
  - `apps/api/src/Catalog/Application/Search/ProductSearchIndexRebuilder.php:126-142`: pętla po partiach 500
    produktów; na partię jedno zapytanie migawki, fakty ofert i jeden `bulk`.
  - `apps/api/src/Cart/Infrastructure/Catalog/QueryBusProductOfferFacts.php:37`: oferty w paczkach po 48 produktów,
    każda to trzy–cztery zapytania przez szynę (Catalog, Pricing, Inventory).
  - `ProductSearchIndexRebuilder.php:16-17`: „Not an online rebuild: writes made through the alias while it runs go
    to the previous index”.
  - `apps/api/src/Catalog/Application/Command/ReindexCategoryBatchHandler.php:67-71`:
    ```php
    $productIds = $this->subtree->after(CategoryId::fromString($run->categoryId), $run->cursor, $this->batchSize);
    foreach ($productIds as $productId) {
        $this->indexing->reindex($productId);
    }
    ```
    500 wywołań `reindex()` (każde: migawka, oferta, jedno żądanie HTTP do ES z limitem 2 s) w jednej transakcji
    `command.bus`.
- **Konsekwencja:** 10 mln produktów to 20 tys. partii i ok. 208 tys. wywołań `GetProductOffers`; przy 0,25–0,5 s na
  partię przebudowa trwa 1,5–3 godziny jednym wątkiem. Przez cały ten czas zmiany cen i dostępności trafiają do
  starego indeksu i giną po przepięciu aliasu „aż do następnej zmiany produktu”. Przy katalogu demo to okno sekund,
  przy 10 mln okno godzin: produkty wyprzedane w tym czasie zostają w filtrze „dostępne” i w sortowaniu po cenie ze
  starymi danymi na czas nieokreślony. Zmiana definicji indeksu wymaga pełnej przebudowy, więc każde wdrożenie ze
  zmianą mapowania ma kilkugodzinny krok ręczny (`catalog:search-index:setup` w Jobie `migrate` kończy się błędem
  do czasu przebudowy, `jobs.yaml:76-77`). Zmiana nazwy działu z 3 mln produktów to 6 tys. partii po kilka–kilkanaście
  sekund, czyli kilkanaście godzin pracy jedynego workera, z transakcją i blokadą wiersza przebiegu otwartą na czas
  500 żądań HTTP.

  Dziś: przebudowa trwa sekundy. Próg: od ok. 1 mln produktów okno utraty zmian liczone w dziesiątkach minut.
- **Rekomendacja:** Zapisywać w czasie przebudowy identyfikatory produktów reindeksowanych przez zdarzenia (tabela
  albo zbiór w Redisie) i po przepięciu aliasu przepisać je jeszcze raz; alternatywnie zapis podwójny do obu
  indeksów w czasie przebudowy. Oferty dla przebudowy czytać paczką wielkości partii (osobny port zamiast publicznego
  limitu 48). W reindeksacji poddrzewa użyć zapisu `bulk` i ofert dla całej partii.
- **Pewność:** wysoka co do mechanizmu (spec `search`, „Ryzyka”, opisuje utratę zapisów; H5 podaje te same 208 tys.
  wywołań); średnia co do czasu.

### 7. Konfiguracja wykonania na wartościach domyślnych: FrankenPHP w trybie klasycznym bez limitu wątków, brak puli połączeń i `statement_timeout`, PostgreSQL bez strojenia

- **Waga:** Medium
- **Klasa:** RYZYKO
- **Dowód:**
  - `apps/api/docker/frankenphp/Caddyfile` (całość):
    ```
    {
    	admin off
    	auto_https off
    	frankenphp
    }
    :80 {
    	root * /app/public
    	encode zstd gzip
    	php_server
    }
    ```
    Bez `worker`, `num_threads` i `max_threads`; w `composer.json` nie ma `runtime/frankenphp-symfony`.
  - `apps/api/docker/php/conf.d/prod.ini`: `opcache.validate_timestamps = 0`, pamięć 192 MB, 20 000 plików; bez
    `opcache.preload`, JIT, `opcache.interned_strings_buffer`, `realpath_cache_*`.
  - `apps/api/config/packages/doctrine.yaml:2-9`: połączenie `pdo_pgsql` bez `persistent`, bez opcji sesji.
    W repozytorium nie ma PgBouncera, `statement_timeout`, `shared_buffers` ani `max_connections` (przeszukane
    `apps/api/config`, `apps/api/src`, `apps/api/docker`, `infra/`).
  - `infra/k8s/charts/shop/templates/postgres.yaml:1-18` i `infra/docker-compose.yml:12-19`: obraz `postgres:18.6`
    bez argumentów i pliku konfiguracji, więc `shared_buffers` 128 MB, `max_connections` 100, `random_page_cost` 4;
    ta sama instancja obsługuje bazę Keycloaka.
  - Limity czasu zależności: ES 2 s (`services.yaml:23`), dostawca płatności 5 s
    (`Payment/Infrastructure/Provider/ProviderHttp.php:20`), klient HTTP 2–5 s (`framework.yaml:9-12`).
- **Konsekwencja:**
  - Tryb klasyczny startuje kernel Symfony i otwiera nowe połączenie do bazy przy każdym żądaniu. Przy tysiącach
    żądań na sekundę to tysiące uwierzytelnień i procesów PostgreSQL na sekundę; to zwykle pierwsze wąskie gardło
    bazy, wcześniej niż same zapytania.
  - Domyślna pula to dwa wątki PHP na rdzeń, wspólna dla wszystkich tras. Wolna zależność (ES na limicie 2 s, dostawca
    płatności 5 s) zajmuje wątki, których potrzebują koszyk, checkout i sonda `/health`. 16 wątków przy 2 s na
    żądanie to 8 żądań na sekundę; potem stoi całe API, nie tylko wyszukiwarka.
  - Po dodaniu replik API suma wątków szybko przekracza `max_connections` 100 (7 replik po 16 wątków plus worker,
    joby i Keycloak).
  - `shared_buffers` 128 MB przy tabelach i indeksach liczonych w dziesiątkach GB oznacza pracę wyłącznie na cache
    systemu; `random_page_cost` 4 na SSD przesuwa planistę od skanów indeksu, na których opiera się projekt zapytań.
  - Bez `statement_timeout` jedno patologiczne zapytanie (znalezisko 4) trzyma połączenie i wątek bez końca.

  Dziś: serwer demo ma jeden węzeł i mały ruch, konfiguracja wystarcza. Próg: rząd kilkuset żądań na sekundę do API
  albo pierwsza wolna zależność pod ruchem.
- **Rekomendacja:** Ustawić `num_threads` jawnie i sprawdzić tryb worker FrankenPHP (kod już resetuje usługi między
  wiadomościami, więc jest do tego przygotowany); dodać PgBouncer w trybie transakcyjnym albo połączenia trwałe
  w trybie worker; `statement_timeout` i `idle_in_transaction_session_timeout` na roli aplikacji; parametry
  PostgreSQL w wartościach chartu (`shared_buffers`, `effective_cache_size`, `random_page_cost`, `work_mem`);
  osobna instancja bazy dla Keycloaka przy skali.
- **Pewność:** wysoka co do braku ustawień (przeczytane wszystkie pliki konfiguracji wykonania); średnia co do
  domyślnych wartości FrankenPHP 1.13 i PostgreSQL 18 (z dokumentacji, nie z uruchomienia). Wdrożenie jednowęzłowe
  jest świadomą decyzją (`docs/features/deploy/spec.md:41,182`), więc to ryzyko skali, nie wada demo.

### 8. Skalowanie poziome zablokowane przez założenie jednej repliki w warstwie cache i w chartcie

- **Waga:** Medium
- **Klasa:** RYZYKO
- **Dowód:**
  - `apps/api/src/Shared/Infrastructure/Http/Cache/HttpStorefrontCache.php:62-82`: jedno żądanie `PURGE` na
    `VARNISH_URL` i jedno `POST /api/revalidate` na `STORE_INTERNAL_URL`; w chartcie to adresy usług
    (`api.yaml:76-79`: `http://varnish`, `http://store:3000`).
  - `infra/k8s/charts/shop/templates/_helpers.tpl:244,300`: `replicas: 1` w szablonie Deploymentu i StatefulSetu.
  - `apps/api/config/packages/framework.yaml:16-18`: pule `cache.oidc` i `cache.sitemap` bez adaptera, czyli na dysku
    poda. Redis służy wyłącznie limitom żądań (`rate_limiter.yaml:11-14`).
- **Konsekwencja:** Przy dwóch replikach Varnisha albo sklepu unieważnienie trafia do jednej, losowej instancji.
  Pozostałe trzymają starą cenę i dostępność do końca TTL, a oferty mają `s-maxage=3600`: klient przez godzinę może
  widzieć starą cenę na jednej z replik. Wizja każe preferować rozwiązania, które pozwalają skalować poziomo; tu
  dodanie repliki wymaga zmiany kodu unieważniania i szablonu chartu, a błąd byłby cichy (żądanie zwraca 200).

  Dziś: jedna replika, brak problemu. Próg: pierwsza druga replika Varnisha lub sklepu.
- **Rekomendacja:** Unieważnianie rozsyłać do wszystkich instancji (lista podów z usługi headless albo wspólna
  kolejka, którą czyta każda replika); cache ISR sklepu przenieść do wspólnego magazynu; liczbę replik wynieść do
  wartości chartu; pule `cache.sitemap` i `cache.oidc` oprzeć o Redis, który już jest w stosie.
- **Pewność:** wysoka (przeczytany adapter, szablon, wartości). Specyfikacja DEPLOY świadomie wyklucza wiele węzłów
  i autoskalowanie, więc to nie wada wdrożenia demo, tylko niezałatwiony warunek celu z wizji.

### 9. Wywołania HTTP dostawcy płatności w transakcji bazy; symulator w tym samym kontenerze grozi samoblokadą wątków (znane, D18)

- **Waga:** Medium
- **Klasa:** RYZYKO
- **Dowód:**
  - `apps/api/src/Payment/Application/Command/StartPaymentHandler.php:97,128`: `createSession()` i `cancelSession()`
    wewnątrz handlera `command.bus` (middleware `doctrine_transaction`), po `lockPendingOfOrder()`; komentarz klasy:
    „The calls of the providers run in the transaction (5 s each)”.
  - `infra/k8s/charts/shop/templates/api.yaml:81-82`: `PAYMENT_PROVIDER_BASE_URL: http://api`, czyli żądanie do tego
    samego serwera, który na nie czeka.
  - `apps/api/CLAUDE.md:536-538` i `docs/features/hardening/spec.md` D18 opisują samoblokadę; test obciążeniowy
    ogranicza równoległość checkoutu do ćwierci wątków.
- **Konsekwencja:** Każde rozpoczęcie płatności zajmuje dwa wątki FrankenPHP i połączenie bazy z otwartą transakcją
  na czas żądania HTTP. Gdy równoległych rozpoczęć jest tyle, ile wątków, wszystkie czekają na siebie do limitu 5 s
  i przez ten czas API nie odpowiada na nic. Ograniczenie równoległości istnieje tylko w skrypcie k6, nie
  w aplikacji: limit `payment_start` liczy żądania na adres (20 na 15 minut), nie równoległość. Przy prawdziwym
  dostawcy samoblokada znika, ale zostaje transakcja i blokada wiersza trzymane do 10 s przy wolnym dostawcy, co
  przy puli 100 połączeń szybko wyczerpuje bazę.

  Dziś: realne na serwerze demo przy kilkunastu jednoczesnych płatnościach (mało prawdopodobne przy obecnym ruchu,
  łatwe do wywołania celowo).
- **Rekomendacja:** Wynieść symulator do osobnej usługi (zapowiedziane w `apps/api/CLAUDE.md`); wywołanie dostawcy
  przenieść poza transakcję (zapis płatności `pending`, zatwierdzenie, wywołanie, drugi krótki zapis sesji).
- **Pewność:** wysoka; ryzyko jest udokumentowane jako decyzja użytkownika, zgłaszam je, bo zabezpieczenie istnieje
  wyłącznie w teście.

### 10. Testy obciążeniowe nie mierzą tego, co deklaruje wizja, i nie mogą wykryć regresji

- **Waga:** Medium
- **Klasa:** RYZYKO
- **Dowód:**
  - `load/lib/data.js:47-60`: dane wejściowe to najwyżej `DISCOVER_MAX_PAGES` (100) stron po 48 produktów z listingu
    domyślnego, czyli pierwsze 4800 produktów w kolejności nazw.
  - `load/steady.js:27-32`: progi to puste tablice (`thresholds[...] = []`), tylko raportowanie;
    `docs/features/hardening/spec.md:189`: kod wyjścia 0 to „pomiar wykonany (bez progów zaliczenia)”.
  - `load/saturate.js:36`: kryterium nasycenia to p95 powyżej 2000 ms.
  - `load/README.md`, „Ograniczenia”: checkout „nie jest ani benchmarkiem checkoutu, ani przepustowością systemu”.
  - `docs/vision.md:233-234`: wynik „opisuje tę maszynę i to demo (mały katalog), nie skalę docelową”;
    `docs/features/hardening/spec.md:29`: skala docelowa poza zakresem.
- **Konsekwencja:** Żaden istniejący przebieg nie mówi nic o 10 mln produktów ani 60 tys. użytkowników. Na dużym
  katalogu skrypty próbkowałyby 0,05% produktów, zawsze te same, więc trafienia w cache byłyby nierealistycznie
  wysokie, a długi ogon (chybienia Varnisha, ISR, zimne strony ES) niewidoczny. Scenariusze nie obejmują
  zalogowanego klienta, list panelu ani opóźnienia potoku zdarzeń (znalezisko 1 nie wyszłoby w żadnym przebiegu).
  Pomiar nie ma progów, więc nie zatrzyma zmiany, która pogorszy wydajność. Operator nie ma liczby, do której mógłby
  porównać kolejne wydanie. Projekt jest tu uczciwy: wizja i `CLAUDE.md` zabraniają twierdzić, że wymagania są
  spełnione, i nigdzie tego nie twierdzą. Ryzyko polega na tym, że decyzje architektoniczne pod skalę (jeden shard,
  fasety, jedna kolejka) zapadły bez danych, a raportu pomiaru (`docs/reports/`) w migawce nie ma.
- **Rekomendacja:** Dokończyć DEMO-DATA (generator i losowanie produktów z całego katalogu są w jego specyfikacji)
  i przebieg `full` przed dalszymi funkcjami opartymi o listing; do przebiegu stałego dodać progi regresji względem
  ostatniego raportu; dodać scenariusz mierzący opóźnienie zdarzenie → indeks i e-mail pod zapisem masowym.
- **Pewność:** wysoka (przeczytany cały katalog `load/` i opis w specyfikacji).

### 11. Drzewo kategorii hydratowane w całości przez ORM przy każdym chybieniu listingu i odczycie drzewa

- **Waga:** Low
- **Klasa:** RYZYKO
- **Dowód:** `apps/api/src/Catalog/Application/Query/ListProductsHandler.php:41,79`:
  ```php
  $this->categories->get($category);
  …
  $nodes = CategoryTreeBuilder::build($this->categories->all());
  ```
  `Catalog/Infrastructure/Persistence/Doctrine/DoctrineCategoryRepository.php:74-86`: `all()` to zapytanie ORM po
  wszystkich kategoriach z `HINT_REFRESH`, sortowane po `path` (indeks ścieżki jest `text_pattern_ops`, więc sortowanie
  odbywa się w pamięci), mapowane na agregaty domeny. To samo w `GetCategoryTreeHandler.php:22`.
- **Konsekwencja:** Koszt stały każdego niecachowanego listingu rośnie z liczbą kategorii, a liczba kategorii nie ma
  limitu w domenie (tylko głębokość 4). Przy kilkuset kategoriach to pojedyncze milisekundy; przy kilku tysiącach
  dziesiątki milisekund procesora i kilka MB pamięci na żądanie, dokładane do najdroższej ścieżki (znalezisko 2)
  i dostępne bez cache z publicznego hosta API (znalezisko 3).
- **Rekomendacja:** Odczyt drzewa dla sklepu zrobić projekcją DBAL (`id, parent_id, name, slug, path, position`)
  i trzymać w cache aplikacji unieważnianym zdarzeniem `CategoryTreeChanged`; do faset wystarczą dzieci jednej
  kategorii (indeks `parent_id` istnieje).
- **Pewność:** wysoka co do ścieżki; próg szacunkowy.

### 12. Zdublowane indeksy na tabelach rosnących z zamówieniami; indeksy częściowe stanów wyłączają aktualizacje HOT

- **Waga:** Low
- **Klasa:** BŁĄD
- **Dowód:** Indeks jednokolumnowy będący prefiksem innego indeksu tej samej tabeli:
  - `ordering_order_line_order_id_idx (order_id)` obok unikalnych `(order_id, position)` i `(order_id, variant_id)`
    (`migrations/Version20261105130000CreateOrderingTables.php`);
  - `ordering_order_status_change_order_id_idx (order_id)` obok klucza głównego `(order_id, position)`
    (`Version20261107140000AddOrderLifecycle.php`);
  - `ordering_order_notification_order_id_idx (order_id)` obok klucza `(order_id, kind)`
    (`Version20261107120000CreateOrderNotifications.php`);
  - `cart_line_cart_id_idx (cart_id)` obok unikalnego `(cart_id, variant_id)`
    (`Version20261103120000CreateCartTables.php`);
  - `pricing_promotion_variant_promotion_id_idx (promotion_id)` obok klucza `(promotion_id, variant_id)`
    (`Version20261108090000CreatePromotions.php`);
  - `IDX_8BEBEF264584665A (product_id)` na `catalog_product_category` obok klucza `(product_id, category_id)`
    (`Version20261029101125CreateCatalogProduct.php`);
  - `pricing_discount_code_usage_code_id_idx (code_id)` obok częściowego `(code_id) WHERE released_at IS NULL`
    (`Version20261108190000CreateDiscountCodes.php`): pełny służy tylko kluczowi obcemu, a kodów się nie usuwa.

  Osobno: `inventory_stock_item_out_of_stock_idx` i `…_low_stock_idx`
  (`Version20261115120100IndexStockLevels.php`) mają w predykacie `physical - reserved`, czyli kolumny zmieniane przy
  każdej rezerwacji.
- **Konsekwencja:** Każdy zdublowany indeks to dodatkowy zapis przy każdym zamówieniu i miejsce na dysku. Przy profilu
  `full` z DEMO-DATA (50 mln zamówień, ponad 100 mln linii i zmian statusu) trzy zbędne indeksy na tabelach Ordering
  to rząd kilku–kilkunastu GB i dłuższe `PlaceOrder`. Tabela `ordering_order` ma 12 indeksów, a `status` jest
  kolumną dwóch z nich, więc zmiana statusu nie może być aktualizacją HOT i dopisuje wpisy we wszystkich, łącznie
  z GIN trigramów
  (specyfikacja ADMIN-FEATURES przyznaje koszt zapisu i odsyła do pomiaru). Kolumny z predykatu indeksu częściowego
  liczą się jako indeksowane, więc aktualizacja `reserved` nigdy nie jest HOT: najgorętsza tabela checkoutu puchnie
  szybciej i mocniej zależy od autovacuum. Dziś bez znaczenia.
- **Rekomendacja:** Usunąć sześć indeksów-prefiksów (w mapowaniu `Record` i migracją `DROP INDEX CONCURRENTLY`);
  wpływ indeksów częściowych stanów i 12 indeksów zamówienia zmierzyć w teście checkoutu na danych w skali, zanim
  dojdą kolejne.
- **Pewność:** wysoka co do duplikatów (zestawione wszystkie migracje z atrybutami `ORM\Index`; żadna późniejsza
  migracja ich nie usuwa). Średnia co do HOT (zachowanie PostgreSQL znane z dokumentacji, niezmierzone).

### 13. Pozostałe skany zależne od rozmiaru tabeli i brak retencji

- **Waga:** Low
- **Klasa:** RYZYKO
- **Dowód i konsekwencja:**
  - `apps/api/src/Catalog/Infrastructure/Persistence/Doctrine/DbalSitemapProductSource.php:29-33`:
    `row_number() OVER (ORDER BY id)` po całym `catalog_product`, raz na godzinę na instancję (cache na dysku poda).
    Przy 10 mln wierszy to kilka sekund w żądaniu HTTP; N replik robi to N razy.
  - `infra/k8s/charts/shop/files/scripts/initialize.sh:13`: cztery `count(*)` po pełnych tabelach przy każdym
    wdrożeniu, choć decyzja potrzebuje tylko „pusta czy nie” (wymienione w ryzykach DEMO-DATA).
  - `apps/api/src/Backoffice/Infrastructure/MessagingStatisticsAdapter.php:35`: `count(*)` po outboxie bez limitu
    przy każdym odświeżeniu pulpitu; w scenariuszu ze znaleziska 1 liczy miliony wierszy co 30 s.
  - Tabele dopisywane bez końca (`ordering_order` z liniami i historią, `inventory_stock_movement`,
    `inventory_reservation`, `pricing_price_history`, `pricing_discount_code_usage`, `payment_webhook_event`) nie mają
    retencji, archiwizacji ani partycjonowania; `messenger_messages` łączy sześć kolejek w jednej tabeli o dużej
    rotacji wierszy.
- **Rekomendacja:** Granice mapy witryny liczyć w tle i trzymać w Redisie; w `initialize.sh` użyć
  `SELECT EXISTS (SELECT 1 FROM … LIMIT 1)`; licznik outboxa ograniczyć jak inne liczniki pulpitu; politykę retencji
  i partycjonowanie po dacie zaplanować dla `ordering_order` i ruchów stanów przed profilem `full`.
- **Pewność:** wysoka co do zapytań; progi szacunkowe.

## Mocne strony

- **Stronicowanie keyset we wszystkich listach, z kursorem jako warunkiem indeksu.** Porównanie wierszowe w kierunku
  sortowania (`DbalBackofficeOrderReader.php:73`), dwie osobne instrukcje zamiast `IS NULL OR`
  (`DbalVariantSource.php:113-129`, `DoctrineVariantPriceRepository.php:68-81`), komentarz wyjaśniający plan
  generyczny (`DbalProductSearchSource.php:19-21`). Nigdzie nie ma `OFFSET`.
- **Liczniki ograniczone limitem zamiast `COUNT(*)`** (`BoundedCounter.php`, pulpit „1000+”), liczba produktów
  z `pg_class.reltuples` (`PostgresProductTableStatistics.php:20`). Z zastrzeżeniem ze znaleziska 4.
- **Odczyty przez projekcje DBAL, bez hydratacji encji.** Strona produktu to trzy zapytania po indeksach
  (`DbalProductDetailsSource.php`), koszyk jedno (`DbalCartReader.php:23-30`), oferty listingu trzy zapytania na 48
  produktów (`GetProductOffersHandler.php:63-79`), Omnibus jedno zapytanie `LATERAL` na wszystkie warianty
  (`DbalOmnibusHistory.php:43-52`). Na ścieżce klienta nie znalazłem N+1.
- **Modele odczytu zamiast agregacji w żądaniu.** Sprzedaż godzinowa w 16 shardach z upsertem
  (`DbalSalesHours.php:37-47`), podsumowania klientów (`DbalCustomerSummaries.php`), obie z przebudową poprawną pod
  ruchem i opisanym uzasadnieniem współbieżności.
- **Indeksy dopasowane do zapytań i przypięte testami planów.** Indeksy częściowe z predykatem wpisanym jako literał,
  żeby planista mógł go dowieść (`DbalBackofficeProductSource.php:144-147`, `DbalStockLevelProducts.php:16-18`);
  trigramy GIN na dokładnie tym wyrażeniu, którego używa zapytanie (`DbalBackofficeOrderReader.php:39-40`).
- **Migracje bezpieczne dla dużych tabel.** `CREATE INDEX CONCURRENTLY IF NOT EXISTS` poprzedzone usunięciem indeksu
  `INVALID` (`Version20261115130100IndexOrderTotals.php` i sześć kolejnych); wypełnianie danych poza migracjami,
  komendą `app:backfill` z własnym limitem czasu (21 600 s).
- **Operacje masowe w partiach, bez trzymania zbioru w pamięci.** `ReconcileTax.php:53-65` (partie 500, reset sesji),
  `StockRecordSync.php:52-65`, `DbalExpiredCarts.php:27-31` (`FOR UPDATE SKIP LOCKED` w zmaterializowanym CTE),
  przebudowa indeksu kursorem po kluczu głównym.
- **Spójność zapisu bez podwójnego zapisu.** Outbox w transakcji komendy (`messenger.yaml:26-30`), wersjonowanie
  zewnętrzne dokumentów ES (`ElasticsearchProductIndexer.php:32-34`), przepięcie aliasu z weryfikacją liczby
  dokumentów i definicji (`ElasticsearchSearchIndexMaintenance.php:122-146,148-189`).
- **Współbieżność na krótkich blokadach w stałej kolejności.** Stany blokowane w kolejności identyfikatorów jednym
  zapytaniem (`DoctrineStockItemRepository.php:53-56`), opisana kolejność blokad koszyk → kod → stany, kod bez limitu
  nie jest blokowany wcale.
- **Limity czasu na każdym wywołaniu zewnętrznym** (ES 1 s połączenie i 2 s żądanie, klient HTTP 2–5 s, SMTP 10 s,
  PURGE 5 s) i świadoma reakcja na awarię (503 zamiast błędu, limit żądań otwarty przy awarii Redisa).
- **Dokumentacja nazywa kompromisy i progi.** `search/spec.md` („Ryzyka i ograniczenia”), `demo-data/spec.md` (H1–H5),
  `admin-features/spec.md` („Ryzyka”). Wizja nie twierdzi, że skala docelowa jest osiągnięta.

## Zbędna złożoność

- **Cykl Catalog → Cart → Catalog przez szyny przy każdej reindeksacji** (`QueryBusProductOfferFacts.php:37-39`).
  Specyfikacja przyjmuje go świadomie dla jednej reguły „możliwy do kupienia”, ale kosztuje trzy przejścia przez szynę
  na 48 produktów i wymusza publiczny limit 48 w przebudowie pracującej partiami po 500.
- **Dwa handlery jednego zdarzenia, każdy z własnym `GetVariant`** (`ReindexProductOn*Change`,
  `InvalidateStorefrontOn*Change`): to samo zapytanie dwa razy na zdarzenie. Uzasadnione niezależnym ponawianiem;
  identyfikator produktu w zdarzeniu (jak w `PromotionPricesChanged`) usunąłby oba odczyty.
- **`ProductRepository::all()`** (`DoctrineProductRepository.php:78-92`) ładuje cały katalog z wariantami
  i kategoriami przez ORM. W `src` nie znalazłem wywołania; metoda, która przy 10 mln produktów nie może działać, nie
  powinna być w porcie.

Poza tym nie widzę nadmiaru: złożoność modeli odczytu, liczników i migracji jest proporcjonalna do celu skali.

## Braki

- Pomiar przy skali docelowej: brak generatora danych (`DemoData`) i raportu w migawce; hipotezy H1–H5 otwarte.
- `statement_timeout`, pula połączeń, parametry PostgreSQL, `pg_stat_statements` albo log wolnych zapytań.
- Rozdzielenie kolejek i workerów, skalowanie workera, metryka opóźnienia potoku zdarzeń (jest tylko liczba
  wiadomości w kolejkach na pulpicie).
- Ochrona przed stampedem i serwowanie nieświeżych odpowiedzi w Varnishu; limit żądań dla publicznych odczytów.
- Cache drzewa kategorii po stronie API; Redis nie jest używany do niczego poza limitami żądań.
- Przebudowa indeksu online albo dogrywanie zmian z okna przebudowy; okresowe uzgodnienie indeksu z bazą (pulpit
  porównuje tylko liczbę dokumentów z szacunkiem, z tolerancją 1%).
- Retencja, archiwizacja i partycjonowanie tabel dopisywanych; repliki do odczytu.
- Rozsyłanie unieważnień do wielu instancji cache; liczba replik w wartościach chartu.

## Czego nie zweryfikowano

- Żadnego planu zapytania ani czasu nie zmierzyłem (`EXPLAIN`, k6 i testy są poza zakresem audytu statycznego).
  Wszystkie progi („od ok. 1 mln wierszy”) i przepustowości to szacunki.
- Katalogu `docs/reports/` w eksporcie nie ma, więc nie widziałem raportu pomiaru obciążenia, na który powołuje się
  wizja.
- Katalogu `vendor` nie ma w eksporcie: kolejność obsługi transportów przez `Worker`, pobieranie jednej wiadomości
  przez transport Doctrine, wbudowany VCL Varnisha 9 (hit-for-miss 120 s, `default_grace` 10 s, pamięć 100 MB),
  domyślna liczba wątków FrankenPHP i domyślne parametry PostgreSQL 18 podaję z wiedzy o tych narzędziach.
- Nie czytałem warstw `Domain` ani większości kontrolerów `UI` poza nagłówkami cache; modułów `Customer`
  i `PaymentSimulator` tylko w zakresie mapowań. Z ok. 1290 plików PHP przeczytałem ok. 55, wybranych jako ścieżki
  danych.
- Strony sklepu Next.js (ISR, liczba wywołań API na stronę) nie badałem; wpływ na API oceniałem tylko od strony
  nagłówków i Varnisha.
- Zachowania Elasticsearcha przy agregacjach `nested` na dziesiątkach milionów dokumentów nie da się ocenić bez
  danych; znalezisko 2 opisuje kształt kosztu, nie liczbę.
- Nie sprawdzałem, czy obraz `varnish:9.1.0` ma moduł `xkey` ani jak zachowuje się przy limicie pamięci poda.
- Koszt Sentry (próbkowanie 0,1 ze spanami bazy) i logów JSON pod obciążeniem pominąłem.
