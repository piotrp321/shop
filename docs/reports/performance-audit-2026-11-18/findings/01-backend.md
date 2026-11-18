# Audyt wydajności i skalowalności backendu (`apps/api`): analiza statyczna

Zakres: `apps/api` (src, config, docker, public, bin), pomocniczo chart `infra/k8s/charts/shop` i `infra/docker-compose.yml`
tam, gdzie decydują o tym, jak API i worker faktycznie działają. Eksport nie zawiera `vendor/`, więc zachowanie
frameworka (Symfony Messenger Worker, FrankenPHP, OidcTokenHandler) opisuję według znanej semantyki wersji wskazanych
w `composer.json`; takie miejsca są oznaczone „semantyka frameworka”.

Skala docelowa (`docs/vision.md`, „Wymagania niefunkcjonalne”): 10 mln produktów (~30 mln wariantów), 1 mln klientów,
do 50 mln zamówień, 60 tys. jednocześnie aktywnych użytkowników.

Oznaczenia: **[K]** potwierdzone analizą kodu (wskazana ścieżka wykonania), **[H]** hipoteza do pomiaru.
Waga podana dwa razy: „cel” (skala docelowa) / „demo” (obecne małe dane, jedna replika).

---

## 0. Podsumowanie

Warstwa SQL jest napisana świadomie: odczyty sklepu i panelu to pojedyncze zapytania zbiorcze DBAL (bez N+1), listy panelu
mają keyset i ograniczony licznik, blokady mają ustaloną kolejność i są krótkie, migracje dużych tabel idą `CONCURRENTLY`.
Wąskie gardła przy wzroście ruchu i wolumenu leżą gdzie indziej:

1. **Jeden proces workera obsługuje 6 transportów po kolei, outbox ma pierwszeństwo** – przy stałym napływie zdarzeń
   reszta (reindeks, invalidacja cache, e-maile, wygasanie nieopłaconych zamówień, webhooki symulatora, granice promocji,
   partie reindeksu) czeka. Przepustowość całej asynchronicznej części systemu to jeden wątek PHP.
2. **Brak koalescencji zdarzeń „produkt się zmienił”** – każda zmiana ceny/poziomu stanu/promocji daje osobny reindeks
   jednego dokumentu ES i dwa synchroniczne żądania HTTP (PURGE Varnisha + rewalidacja Next.js z limitem 30 s). Masowe
   zmiany (VAT, import cen, przeniesienie dużej kategorii) liczą się w dobach.
3. **Elasticsearch: 1 shard, 0 replik, pełne agregacje nested + `reverse_nested` i `track_total_hits: true` przy każdym
   listingu** – przy 10 mln produktów i ~100 mln dokumentów Lucene (produkty + warianty + pary opcji) to najdroższe
   zapytanie systemu, bez równoległości.
4. **FrankenPHP w trybie klasycznym, bez puli połączeń** – każde żądanie bootuje kernel i otwiera nowe połączenie
   PostgreSQL (bez pgbouncera, `max_connections` domyślne). Przy skalowaniu poziomym liczba połączeń rośnie liniowo.
5. **Kod rabatowy z limitem** – licznik użyć to `count(*)` po indeksie (do 1 mln wierszy) przy każdej wycenie koszyka
   i przy złożeniu zamówienia pod blokadą wiersza kodu: popularny kod z limitem szereguje checkouty.
6. **Wywołania HTTP w transakcji** – start płatności (dostawca/symulator w tym samym kontenerze) i partia reindeksu
   kategorii (500 zapisów do ES) trzymają transakcję i połączenie przez czas wywołań zewnętrznych.

---

## 1. Ścieżka żądania HTTP

### 1.1 FrankenPHP w trybie klasycznym – boot kernela i nowe połączenie DB na każde żądanie
**[K] konfiguracja / [H] koszt. Waga: High (cel) / Low (demo). Złożoność: O(żądania) × stały koszt bootu.**

- `docker/frankenphp/Caddyfile:10` – `php_server` bez dyrektywy `worker`, w bloku globalnym (`:4`) `frankenphp` bez
  `num_threads`/`max_threads`. `public/index.php:7-9` to zwykły front controller runtime, a w `composer.json` jest tylko
  `symfony/runtime` (bez runtime FrankenPHP w trybie worker).
- Skutek (semantyka frameworka): każde żądanie tworzy nowy `Kernel`, ładuje skompilowany kontener (z opcache), buduje
  od nowa potrzebne serwisy (EntityManager, metadane z cache, klient ES, klient HTTP, limiter), a DBAL łączy się
  z PostgreSQL leniwie przy pierwszym zapytaniu – osobne połączenie TCP + uwierzytelnienie na żądanie.
- `config/packages/doctrine.yaml:2-9` – brak `persistent`, brak pgbouncera w chartcie i compose (grep `pgbouncer` –
  brak), Postgres bez strojenia (`infra/docker-compose.yml:12-24`, chart: `values.yaml:185` 1 GiB limitu, domyślne
  `max_connections=100`).
- Liczba wątków FrankenPHP domyślnie zależy od liczby CPU widzianych przez proces (2×CPU); pod `api` ma tylko
  `requests.cpu: 250m` bez limitu CPU (`infra/k8s/charts/shop/values.yaml:192`), więc liczba wątków wynika z rdzeni
  węzła, a nie z przydziału. `memory_limit = 256M` (`docker/php/conf.d/app.ini:2`) × wątki może przekroczyć limit
  poda 1 GiB przy uploadach 64 MB. **[H]**
- Brak `opcache.preload` (kontener generuje plik preload, ale `prod.ini` go nie włącza), brak JIT, brak APCu
  (`Dockerfile:12` – lista rozszerzeń), więc `cache.app` (`framework.yaml:11-14`, bez `app:`) to adapter plikowy
  w `var/cache` poda.
- Szacunek **[H]**: narzut bootu w trybie klasycznym zwykle 5–20 ms CPU + 1–5 ms na połączenie z PG. Dla katalogu za
  Varnishem to mniej istotne, ale koszyk, checkout, `/me/*`, panel i wszystkie MISS-y Varnisha płacą go zawsze.
  Przejście na worker mode wymaga serwisów bezstanowych albo `ResetInterface` – `CorrelationId` już go implementuje
  (`src/Shared/Infrastructure/Observability/CorrelationId.php:18`), co ułatwia zmianę.

### 1.2 Listenery wykonywane na każdym żądaniu
**[K]. Waga: Low.**

- `CorrelationIdSubscriber` (`src/Shared/Infrastructure/Observability/CorrelationIdSubscriber.php:31`, priorytet 256)
  – generuje identyfikator, koszt pomijalny.
- `RateLimitSubscriber` (`src/Shared/Infrastructure/Http/RateLimitSubscriber.php:45`) – działa tylko na trasach
  z `_rate_limit` (złożenie zamówienia, start płatności, webhook). Redis z `timeout=1&read_timeout=1`
  (`config/packages/rate_limiter.yaml:3`), fail-open: przy awarii Redisa każde złożenie zamówienia czeka do 1 s, zanim
  przejdzie **[H]**. Bez `symfony/lock` – świadomie (zero dodatkowych round tripów).
- `PublicCacheVarySubscriber`, `SecurityHeadersSubscriber`, `PublicReadErrorCacheSubscriber`, subscribery
  `*ResponseCacheSubscriber` – operacje na nagłówkach, pomijalne.
- Sentry: `traces_sample_rate` domyślnie `0` (`config/services.yaml:22`, `sentry.yaml:8`), w produkcji 0,1 według
  wizji – narzut instrumentacji DBAL/HTTP przy próbkowaniu 0,1 jest niewielki **[H]**.
- CORS (`nelmio_cors.yaml`) – tylko dopasowanie ścieżki, pomijalne.

### 1.3 JWT (Keycloak)
**[K] konfiguracja / [H] koszt. Waga: Low–Medium (cel).**

- `config/packages/security.yaml:14-19, 26-31, 48-66` – handler `oidc` z discovery i `cache: { id: cache.oidc }`.
  Pula `cache.oidc` (`framework.yaml:13`) dziedziczy `cache.app` = pliki w kontenerze, osobno w każdym podzie.
  JWKS nie jest pobierany na każde żądanie (dobrze).
- Weryfikacja RS256 na każde żądanie z tokenem; `Dockerfile:10-12` dodaje `gmp`, bo konwersja JWK→PEM w czystym PHP
  kosztowała ~0,2 s. W trybie klasycznym konwersja klucza odbywa się na każde żądanie (brak pamięci procesu) **[H]**:
  koszt z gmp prawdopodobnie 1–3 ms CPU.
- Providery użytkowników nie czytają bazy (`src/Customer/Infrastructure/Security/CustomerUserProvider.php` – użytkownik
  z claimów tokena) – dobrze, zero SQL na uwierzytelnienie.

### 1.4 Serializacja i walidacja
**[K]. Waga: Low.** Kontrolery zwracają `JsonResponse` z ręcznie budowanych DTO/widoków (np.
`src/Catalog/UI/Http/ListProductsController.php`), wejście przez `#[MapQueryString]`/`#[MapRequestPayload]` + Validator.
Brak ciężkiej serializacji Serializerem z normalizerami obiektów na gorących ścieżkach. Bez uwag.

---

## 2. Doctrine ORM/DBAL

### 2.1 Co jest zrobione dobrze **[K]**
- Odczyty sklepu i panelu przez DBAL jednym zapytaniem zbiorczym z `= ANY(CAST(:ids AS uuid[]))`:
  `DbalVariantSource` (`src/Catalog/Infrastructure/Persistence/Doctrine/DbalVariantSource.php:29-33, 83-87`),
  `DoctrineVariantPriceRepository::findMany` (`.../Pricing/Infrastructure/Persistence/Doctrine/DoctrineVariantPriceRepository.php:48-56`),
  `DbalActivePromotions::at` (`.../DbalActivePromotions.php`, jedno zapytanie), `DbalOmnibusHistory` (`LATERAL` per
  wariant w jednym zapytaniu), `DoctrineStockItemRepository::findMany/lockMany`. N+1 na ścieżkach sklepu nie znalazłem.
- Strona produktu: 3 zapytania (`DbalProductDetailsSource.php:26-70`).
- Brak `OFFSET` w SQL w całym `src` (poza DemoData) – listy panelu na keysecie, licznik `BoundedCounter`
  (`src/Shared/Infrastructure/Persistence/BoundedCounter.php:31-33`, `LIMIT 10001`) tylko na pierwszej stronie.
- Liczba produktów z `pg_class.reltuples`, nie `count(*)`.
- Strona reindeksu pełnego (`DbalProductSearchSource.php:16-58`) – CTE z `LIMIT` po kluczu i `json_agg` w `LATERAL`,
  jedno zapytanie na 500 produktów.
- Unit of Work jest mały: każda komenda dotyka jednego agregatu, `flush` w repozytoriach, brak `flush` w pętli
  na ścieżkach HTTP (grep `->flush()` poza DemoData: max 3 wywołania na repozytorium, wszystkie poza pętlami).
  W workerze DoctrineBundle czyści EntityManager po każdej wiadomości (semantyka frameworka).

### 2.2 Pełne drzewo kategorii hydratowane przez ORM przy każdym listingu
**[K]. Waga: Medium (cel) / Low (demo, ~100 kategorii). Złożoność: O(liczba kategorii) na żądanie listingu.**

- `src/Catalog/Application/Query/ListProductsHandler.php:41` (`categories->get`) i `:79`
  (`CategoryTreeBuilder::build($this->categories->all())`).
- `DoctrineCategoryRepository::all()` (`src/Catalog/Infrastructure/Persistence/Doctrine/DoctrineCategoryRepository.php:74-86`)
  – `SELECT c FROM CategoryRecord` z `Query::HINT_REFRESH` (linia 82) – pełna hydratacja encji (z proxy rodzica)
  i budowa drzewa w PHP, tylko po to, żeby wziąć dzieci jednej kategorii do facetu.
- Przy kilku tysiącach kategorii to kilkadziesiąt ms CPU na każdy MISS Varnisha listingu **[H]**. Listing ma
  `max-age=60` bez `s-maxage` (`ListProductsController.php:135-136`) i klucz cache zależy od `q`, filtrów i strony, więc
  trafień będzie mało przy wyszukiwaniu i filtrowaniu.
- Tanie rozwiązanie: odczyt DBAL tylko dzieci (`parent_id = :id` lub prefiks `path`) albo cache drzewa w pamięci/Redis
  z invalidacją po `CategoryTreeChanged` (zdarzenie już istnieje).

### 2.3 Transakcje: wywołania HTTP w środku
**[K]. Waga: High (cel, start płatności) / Medium (demo).**

- `src/Payment/Application/Command/StartPaymentHandler.php:97` – `createSession()` (HTTP do dostawcy) w transakcji
  `command.bus`, po `payments->add()`; `:121, :128` – blokada oczekującej płatności (`lockPendingOfOrder`) i
  `cancelSession()` (drugie HTTP) też w transakcji. Limit klienta: `timeout: 2`, `max_duration: 5`
  (`config/packages/framework.yaml:7-10`), więc transakcja może trwać do ~10 s z blokadą wiersza płatności
  i zajętym połączeniem DB. Decyzja jest udokumentowana (`apps/api/CLAUDE.md`, „Wywołania HTTP do dostawcy płatności są
  w transakcji”), ale przy skali to zasób krytyczny: połączenia DB × czas odpowiedzi dostawcy.
- Symulator płatności działa w tym samym kontenerze (`CLAUDE.md` – „ryzyko samoblokady”): żądanie startu płatności
  trzyma wątek FrankenPHP i czeka na drugi wątek tego samego procesu. Przy nasyceniu wątków – zakleszczenie do
  timeoutu **[K] konfiguracja / [H] próg**.
- `src/Catalog/Application/Command/ReindexCategoryBatchHandler.php:67-70` – partia 500 produktów, każdy osobno
  `ProductIndexing::reindex` (zapis do ES) w jednej transakcji `command.bus` z `SELECT … FOR UPDATE` na wierszu
  przebiegu. Patrz 4.4.
- `src/Catalog/Application/Command/StoreProductImageHandler.php:30` – zapis do Garage (do 64 MB) jako komenda
  `command.bus` z middleware `doctrine_transaction` (`messenger.yaml:7-11`): otwiera transakcję i połączenie z bazą,
  której handler nie używa. Waga Low.

### 2.4 Kod rabatowy z limitem: `count(*)` po użyciach pod blokadą
**[K]. Waga: High (cel, kampanie) / Low (demo). Złożoność: O(aktywne użycia kodu) na wycenę i na złożenie; szeregowanie
checkoutów z tym kodem.**

- Licznik: `DoctrineDiscountCodeUsageRepository.php:24`
  `SELECT count(*) FROM pricing_discount_code_usage WHERE code_id = … AND released_at IS NULL`. Limit domeny do
  `1_000_000` (`src/Pricing/Domain/DiscountCode/UsageLimit.php:13`).
- Wywoływany przy każdej wycenie z kodem (`QuoteBasketHandler.php:62`) – czyli przy każdym widoku koszyka z kodem
  i w `PlaceOrder` (`PlaceOrderHandler.php:119`) – oraz w `RedeemDiscountCodeHandler.php:45-51` **po** blokadzie
  `FOR NO KEY UPDATE` wiersza kodu (`DoctrineDiscountCodeRepository.php:82`).
- Blokada kodu jest trzymana do końca transakcji złożenia: po niej idą numer, czyszczenie koszyka, rezerwacja stanów,
  zapis zamówienia, outbox, harmonogram, upsert klienta (`PlaceOrderHandler.php:130-142`). Wszystkie zamówienia z tym
  samym limitowanym kodem idą jedno po drugim, a każde liczy coraz więcej wierszy.
- Szacunek **[H]**: przy 200 tys. użyć `count(*)` po indeksie częściowym to dziesiątki ms; z transakcją złożenia
  ~20–40 ms → kilkanaście–kilkadziesiąt zamówień/s z jednym kodem. Dla „kodu z newslettera” przy 60 tys. aktywnych
  to realny limit.
- Panel: `DbalDiscountCodeReader.php:43` – skorelowany `count(*)` użyć dla każdego kodu na stronie listy, O(suma użyć
  kodów strony).
- Tanie rozwiązanie: licznik `active_uses` na wierszu kodu aktualizowany w tej samej transakcji (wiersz i tak jest
  blokowany), `count(*)` tylko w narzędziu naprawczym.

### 2.5 Blokady wierszy stanów magazynowych przy popularnym wariancie
**[K] kolejność / [H] próg. Waga: Medium (cel, flash sale) / Low.**

- `DoctrineStockItemRepository.php:54` – `SELECT … ORDER BY variant_id FOR UPDATE`, kolejność stała (brak
  zakleszczeń). Rezerwacja jest prawie na końcu transakcji (`PlaceOrderHandler.php:134`, potem tylko zapis zamówienia),
  więc blokada trwa krótko – dobrze zaprojektowane.
- Mimo to wszystkie zamówienia z jednym wariantem szeregują się na jego wierszu: przepustowość ≈ 1 / (czas od
  `lockMany` do `COMMIT`). Przy ~5 ms to ~200 zamówień/s na wariant – wystarczy dla celu, ale warto zmierzyć.

### 2.6 Liczba indeksów `ordering_order`
**[K] / [H] koszt. Waga: Medium (cel). Złożoność: O(indeksy) na INSERT i na UPDATE kolumn indeksowanych.**

- `src/Ordering/Infrastructure/Persistence/Doctrine/Record/OrderRecord.php:26-42` – 2 unikalne + 7 zwykłych/częściowych,
  do tego `lower(email)` (`migrations/Version20261107150000AddBackofficeOrderListIndexes.php:24`) i GIN trigramowy na
  `lower(number||email||full_name)` (`migrations/Version20261115140000IndexOrderSearch.php`) + PK ≈ 12 indeksów.
- Każde złożenie i każda zmiana `status`/`paid_at`/`refund_*` (nie-HOT update) aktualizuje wiele drzew; GIN z
  `fastupdate` odkłada koszt na listę oczekującą. Przy 50 mln zamówień i szczytach zakupowych to głównie I/O i WAL
  **[H]**. Każdy indeks ma uzasadnienie w sortach panelu, więc to świadomy kompromis – do zmierzenia, nie do usuwania na ślepo.

### 2.7 Wyszukiwanie fragmentu w panelu (zamówienia, klienci, produkty)
**[K] zapytanie / [H] plan. Waga: Medium (cel) / Low.**

- `DbalBackofficeOrderReader.php:40` – `LIKE '%…%'` po wyrażeniu z indeksem GIN, ale wynik jest sortowany po
  `placed_at`/`total_gross` z `LIMIT`: dla częstego fragmentu (np. „gmail”, „kowal”) planner musi zebrać bitmapę
  milionów wierszy, sprawdzić je w tabeli i posortować, zanim zwróci 50. Analogicznie `DbalCustomerReader.php:32`
  (OR dwóch warunków `LIKE`) i `DbalBackofficeProductSource.php:29` (`ILIKE`).
- `DbalBackofficeProductSource.php:35-43, 71` – filtr kategorii jako `primary_category_id IN (…) OR EXISTS (…)` przy
  sortowaniu po `(name, id)`: dla małego poddrzewa planner przechodzi indeks `(name, id)` i filtruje wiersz po wierszu,
  co przy 10 mln produktów może oznaczać skan dużej części indeksu, zanim znajdzie 50 pasujących **[H]**.
- To ścieżki panelu (mały ruch), ale z ryzykiem pojedynczych wielosekundowych zapytań.

### 2.8 Hydratacja ORM tam, gdzie wystarczy odczyt
**[K]. Waga: Low.**
- `DoctrineOrderRepository::findByIdempotencyKey` (`DoctrineOrderRepository.php:184`) – `findOneBy` encji
  z leniwymi kolekcjami linii i historii; wołane dwa razy w `PlaceOrder` (`PlaceOrderHandler.php:99, 106`), ale zwykle
  zwraca `null` (1 zapytanie po unikalnym indeksie). Bez znaczenia.
- Koszyk: `DoctrineCartRepository` blokuje wiersz przez ORM i dociąga linie leniwie (`:158, :175-177`), `flush` zapisuje
  linie pojedynczymi `INSERT/UPDATE/DELETE` – O(linie ≤ 50). Bez znaczenia przy limicie linii.

---

## 3. Ścieżki biznesowe

### 3.1 Listing i wyszukiwanie (Elasticsearch)
**[K] zapytanie i konfiguracja / [H] czasy. Waga: Critical (cel) / Low (demo).
Złożoność: O(dokumenty pasujące, w tym zagnieżdżone) na każde żądanie listingu, bez równoległości shardów.**

- `config/services.yaml:11-12` – `app.search.shards: 1`, `app.search.replicas: 0`. Komentarz w zapytaniu zakłada to
  wprost: `ProductSearchQuery.php:140` („The index has one shard, so the sorted terms are exact; with more shards they
  would need a shard_size…”).
- Każde zapytanie listingu (`ProductSearchQuery.php:33-43`) ma:
  - `track_total_hits: true` (`:36`) – dokładne liczenie wszystkich trafień,
  - agregacje (`:39`, `:117-184`): `available` i `price` (nested po `variants` + filtr), `free` (nested po
    `variants` → nested po `variants.pairs` → `terms` osi (10) × `terms` wartości (30), każdy kubełek z
    `reverse_nested`), dla każdej wybranej osi osobna podobna agregacja, plus `categories`.
  - Fraza: dwa `multi_match`, w tym `fuzziness: AUTO` po trzech polach (`:84-93`).
- Dokument produktu ma warianty `nested`, a w nich pary `nested` (`ProductIndexDefinition.php:210-219`): przy 10 mln
  produktów, 30 mln wariantów i ~2–3 parach na wariant to ~100–130 mln dokumentów Lucene w jednym shardzie **[H]**.
  Agregacje `reverse_nested` po całym katalogu (listing bez kategorii, wyszukiwanie częstej frazy) kosztują sekundy
  i dużo heapu przy 2 GiB limitu ES (`values.yaml:187`) **[H]**. Klient ma `request_timeout 2.0` s
  (`services.yaml:19`), więc takie zapytania skończą się 503 (`ListProductsController.php` → `SearchUnavailable`).
- Varnish łagodzi to tylko dla powtarzalnych URL-i (listing kategorii bez filtrów); wyszukiwanie i kombinacje filtrów
  mają mało trafień przy `max-age=60`.
- Dodatkowo każdy MISS czyta drzewo kategorii z bazy (2.2) – 1–2 zapytania SQL + 1 zapytanie ES na listing.
- Kierunki: kilka shardów (np. 4–8 przy 10 mln) z `shard_size` w `terms`, replika do odczytu, `track_total_hits`
  ograniczone (np. 10 000, wizja i tak nie potrzebuje dokładnych milionów), agregacje opcji tylko z kategorią lub
  frazą, osobne zapytanie dla facetów z cache.

### 3.2 Strona produktu i oferty
**[K]. Waga: Low.** `DbalProductDetailsSource` – 3 zapytania; `GetProductOffersHandler`
(`src/Cart/Application/Query/GetProductOffersHandler.php`) – 4–6 zapytań zbiorczych (warianty, ceny, promocje, Omnibus
×2 tylko przy promocjach, stany). Obie odpowiedzi `s-maxage=3600` z tagami xkey (`ProductDetailsController.php:86-88`,
`ProductOffersController.php:101-104`). Dobre.

### 3.3 Koszyk
**[K]. Waga: Low–Medium.**
- Odczyt (`GetCartHandler`): czytnik koszyka (1), `ProductDataLoader.php:38` – podsumowania wariantów (1), ceny
  bieżące (2), stany (1), a potem `CartViewBuilder.php:53` – wycena (`QuoteBasket`: ceny ponownie (2), kod (1), licznik
  użyć (0–1)). Ceny i promocje czytane są dwa razy w jednym żądaniu – ~8–10 zapytań, z czego 2 zbędne.
- Zmiana (`AddCartItemHandler`): transakcja z `FOR UPDATE` na koszyku, ładowanie linii, dane produktów (4), `flush`
  (UPDATE koszyka + INSERT/UPDATE linii), wycena (3–4) – ~12–14 instrukcji w transakcji, blokada wiersza koszyka przez
  cały czas wyceny. Koszyk klienta dodaje `INSERT … ON CONFLICT DO NOTHING` + `SELECT id` (`DoctrineCartRepository.php`
  `insertAndLockForCustomer`). Blokada jest per koszyk, więc nie ma rywalizacji między użytkownikami.
- Podsumowanie (`GetCartSummaryHandler`) – 1 zapytanie. Dobre.

### 3.4 Checkout (`POST /api/v1/orders`, `PlaceOrderHandler`)
**[K]. Waga: Medium (cel). Złożoność: ~25 + 5·N instrukcji SQL w jednej transakcji (N = linie ≤ 50).**

Sekwencja (`src/Ordering/Application/Command/PlaceOrderHandler.php`):
1. Redis: limiter (sliding window, 1–2 komendy) – `RateLimitSubscriber`.
2. `BEGIN` (middleware `doctrine_transaction`).
3. `findByIdempotencyKey` (`:99`) – 1 SELECT po unikalnym indeksie.
4. `lockForOrder` (`:104`) – zagnieżdżona komenda Cart (SAVEPOINT), `SELECT … FOR UPDATE` koszyka, linie – 2–3.
5. `findByIdempotencyKey` ponownie (`:106`) – 1.
6. Wycena (`:119`) – ceny 1, promocje 1, kod 1, licznik użyć 0–1; nazwy z Catalog – 1.
7. `redeem` (`:130`, tylko z kodem) – kod 1, `FOR NO KEY UPDATE` 1, licznik 1, INSERT użycia 1.
8. `nextval` numeru (`:132`) – 1.
9. `clearAfterOrder` (`:133`) – zagnieżdżona komenda: ponowna blokada koszyka (rekord jest w identity map → `SELECT …
   FOR UPDATE` + `refresh` + `refresh` każdej linii: 2 + N), DELETE linii (N), UPDATE koszyka 1.
10. `reserve` (`:134`) – INSERT rezerwacji, `lockMany` (1, `FOR UPDATE` po `variant_id`), dla każdej linii UPDATE stanu
    z wersją + INSERT ruchu (2N), ewentualnie outbox `StockLevelChanged` przy zmianie poziomu.
11. `orders->add` (`:137`) – INSERT zamówienia + N linii + historia statusu.
12. Outbox `OrderPlaced` (INSERT do `messenger_messages`), `ExpireUnpaidOrder` (`:139`, INSERT do `messenger_messages`),
    upsert `ordering_customer` (`:142`).
13. `COMMIT`.

Kolejność blokad (koszyk → kod → stany) jest stała, stany blokowane krótko. Zastrzeżenia: krok 9 odświeża koszyk
i linie, które transakcja już trzyma (N zbędnych SELECT-ów, O(N) – Low); blokada kodu z limitem (2.4).

### 3.5 Płatność i webhook
**[K]. Waga: High (start płatności, 2.3) / Low (webhook).**
- Start: patrz 2.3.
- Webhook (`HandleProviderWebhookHandler.php:50-90`): blokada płatności, `INSERT … ON CONFLICT` zdarzenia, `MarkOrderPaid`
  (odczyt zamówienia, UPDATE z wersją, historia, `DbalSalesHours::add` – upsert w jednym z 16 kubełków godziny
  (`SalesSql.php:24-26`, `DbalSalesHours.php:35-41`), upsert klienta, outbox). ~10 instrukcji. Dobre.

### 3.6 Panel: listy, pulpit
**[K]. Waga: Low–Medium.**
- Listy: keyset + `BoundedCounter` – dobre; ryzyka planów w 2.7.
- `DbalBackofficeOrderReader.php:31` – skorelowane `SUM(quantity)` linii dla każdego zamówienia strony (po indeksie
  `order_id`, O(strona × linie)) – akceptowalne.
- Pulpit (`GetDashboardHandler.php:45-49`): sondy HTTP 8 usług (równolegle), API zarządzania RabbitMQ, a
  `MessagingStatisticsAdapter.php:35` – `SELECT count(*) FROM messenger_messages WHERE queue_name = 'outbox'` bez
  ograniczenia. Przy zaległości outboxu (np. 1 mln wierszy po masowej zmianie, 4.2) to skan indeksu przy każdym
  odświeżeniu pulpitu, akurat wtedy, gdy baza jest najbardziej obciążona. Waga Medium (cel) / Low. Rozwiązanie:
  `BoundedCounter` jak w innych licznikach.

### 3.7 Magazyn
**[K]. Waga: Low.** Rezerwacje i ruchy przez DBAL (`DoctrineStockItemRepository.php`), zdarzenie tylko przy zmianie
poziomu dostępności (`src/Inventory/Domain/Stock/StockItem.php:234`), nie przy każdej rezerwacji – to bardzo dobra
decyzja, inaczej każde zamówienie unieważniałoby cache strony produktu. Historia ruchów rośnie liniowo z zamówieniami
(2 wiersze na linię zamówienia na cykl rezerwacja/zatwierdzenie) – do monitorowania rozmiaru, nie wąskie gardło.

---

## 4. Przetwarzanie asynchroniczne

### 4.1 Jeden proces workera, 6 transportów po kolei, outbox ma pierwszeństwo
**[K] konfiguracja + semantyka frameworka. Waga: Critical (cel) / Medium (demo przy seedzie i testach obciążeniowych).
Złożoność: przepustowość całej części asynchronicznej = 1 wątek PHP, niezależnie od liczby podów API.**

- `infra/k8s/charts/shop/templates/api.yaml:136` (i `infra/docker-compose.yml:350`):
  `messenger:consume outbox events scheduled payment_simulator promotions jobs`, deployment z `replicas: 1`
  (`infra/k8s/charts/shop/templates/_helpers.tpl:244`), bez `--limit/--time-limit/--memory-limit`.
- Semantyka `Symfony\Component\Messenger\Worker::run`: w każdej iteracji odbiorniki są pytane w kolejności z linii
  komend, a po obsłużeniu wiadomości z jednego odbiornika pętla zaczyna od pierwszego. Dopóki `outbox` ma wiadomości,
  `events` (reindeks, invalidacja, e-maile), `scheduled` (`ExpireUnpaidOrder` – zwolnienie rezerwacji), `payment_simulator`
  (webhooki), `promotions` (granice promocji) i `jobs` nie są konsumowane.
- Przykład: zmiana 100 tys. cen → 100 tys. wierszy outboxu → worker najpierw przekazuje wszystkie do RabbitMQ, a dopiero
  potem zaczyna je konsumować; w tym czasie nie wygasają nieopłacone zamówienia (stan wygląda na zarezerwowany), nie idą
  e-maile potwierdzeń, a na demo nie przychodzą webhooki symulatora (płatność wisi).
- Jedna kolejka `events` (`messenger.yaml:32-40`, brak `queues` w opcjach – jedna kolejka o nazwie exchange) obsługuje
  wszystkie typy zdarzeń: e-mail potwierdzenia zamówienia czeka za tysiącami reindeksów (head-of-line blocking).
- Skalowanie poziome workera jest możliwe (Doctrine transport bierze wiersze `FOR UPDATE SKIP LOCKED`, AMQP rozdziela
  wiadomości), ale chart ma `replicas: 1` na sztywno i jeden proces na wszystko. Kierunki: osobne deploymenty/procesy
  na transport (relay outboxu osobno, `events` w kilku replikach, osobne kolejki dla e-maili i dla reindeksu).

### 4.2 Przepustowość relaya outboxu
**[K] konfiguracja / [H] liczby. Waga: High (cel).**

- `messenger.yaml:23-31` – outbox na transporcie Doctrine, `use_notify: false` (polling), `redeliver_timeout=300`.
  Jedna wiadomość = transakcja `SELECT … FOR UPDATE SKIP LOCKED LIMIT 1` + `UPDATE delivered_at` + `COMMIT`, publikacja
  AMQP z potwierdzeniem (`confirm_timeout: 5`, `messenger.yaml:36`; `RoutedAmqpTransport.php` dodaje `mandatory`
  i czeka na potwierdzenie synchronicznie), potem `DELETE` (semantyka frameworka).
- Szacunek **[H]**: 4–5 round tripów do PG + 1 do RabbitMQ na zdarzenie → ~200–500 zdarzeń/s w najlepszym razie,
  a tylko gdy worker nie obsługuje akurat innego transportu.
- Tabela `messenger_messages` jest wspólna dla outboxu, opóźnionych komend (`ExpireUnpaidOrder` dla każdego zamówienia),
  webhooków symulatora, promocji, partii i `failed` (`messenger.yaml:41-76`). Każda wiadomość to INSERT + UPDATE +
  DELETE – duża rotacja martwych krotek, zależność od autovacuum **[H]**. Indeks jest standardowy
  (`migrations/Version20261029142852CreateMessengerTables.php`, `(queue_name, available_at, delivered_at, id)`).

### 4.3 Brak koalescencji zdarzeń zmiany produktu
**[K]. Waga: Critical (cel, masowe zmiany) / Low (demo; seed pomija invalidację).
Złożoność: O(zmienione warianty) × (reindeks dokumentu + 2 żądania HTTP).**

- Zmiana ceny wariantu → `VariantPriceChanged` → dwóch konsumentów:
  `ReindexProductOnPriceChange` (GetVariant + `ProductIndexing::reindex`: odczyt produktu 1 zapytanie
  (`ProductIndexing.php:46`), fakty oferty 4–5 zapytań (`:50`, przez `GetProductOffers`), zapis jednego dokumentu
  ES (`:52`)) oraz `InvalidateStorefrontOnPriceChange.php:46-53` (GetVariant + `HttpStorefrontCache::invalidateTags`:
  PURGE Varnisha i POST `/api/revalidate` do sklepu, `HttpStorefrontCache.php:45-46`, limit sklepu 30 s – `:25`).
  To samo dla `StockLevelChanged` i `PromotionPricesChanged`.
- Produkt z 20 wariantami i zmianą wszystkich cen = 20 reindeksów tego samego dokumentu i 40 żądań HTTP. Zmiana VAT
  (`pricing:reconcile-tax --apply`) albo import cennika 30 mln wariantów = 30 mln zdarzeń × ~6 SQL + 1 ES + 2 HTTP,
  obsługiwanych przez jeden wątek (4.1) – przy ~10–20 ms na zdarzenie to 4–7 dób **[H]**.
- Promocja: do 1000 wariantów (`Promotion.php:29`), zdarzenie raz na produkt (`PromotionPriceEvents.php:38`) – dobrze,
  ale też bez łączenia z innymi zmianami.
- Rewalidacja sklepu synchronicznie w workerze z limitem 30 s: wolny lub restartujący się Next.js blokuje jedyny wątek
  workera do 30 s na wiadomość, z ponowieniami (`messenger.yaml:37-40`, 3 próby).
- Kierunki: debounce/dedup po `productId` (np. tabela „brudnych” produktów + partia co N sekund, reindeks `_bulk`
  i jeden PURGE z wieloma tagami xkey), rewalidacja sklepu krótkim timeoutem albo asynchronicznie.

### 4.4 Reindeks poddrzewa kategorii produkt po produkcie
**[K]. Waga: High (cel) / Low (demo). Złożoność: O(produkty poddrzewa) × (~5 SQL + 1 żądanie ES), w transakcjach po 500.**

- `ReindexCategoryBatchHandler.php:41, 67-70` – partia 500 identyfikatorów z ES (`search_after`, dobrze), potem 500 ×
  `ProductIndexing::reindex` (pojedyncze odczyty i pojedyncze `index`), a nie strona `DbalProductSearchSource::page`
  + `_bulk`, z których korzysta pełna przebudowa (`ProductSearchIndexRebuilder.php:133-137`).
- Na partię ~2500 zapytań SQL i 500 żądań HTTP do ES w jednej transakcji z blokadą wiersza przebiegu. Przeniesienie
  kategorii z 2 mln produktów = 4000 partii; przy ~5 s na partię ~5,5 h **[H]**, a każda partia blokuje jedyny wątek
  workera na kilka sekund (jobs jest ostatni w kolejności, ale rozpoczętej partii nic nie przerwie).
- `CLAUDE.md` mówi „Partia trwa sekundy” – przy skali celu będzie to kilka–kilkanaście sekund. `redeliver_timeout=300`
  ma zapas.
- Kierunek: użyć tej samej ścieżki co rebuild (jedno zapytanie na stronę + fakty ofert zbiorczo + `_bulk`) – zysk
  rzędu 10–50× **[H]**.

### 4.5 E-maile synchronicznie w konsumencie
**[K] konfiguracja / [H] czasy. Waga: Medium (cel).**
- `framework.mailer.message_bus: false` (`CLAUDE.md`, „Infrastruktura”) i `SendOrderConfirmation`, `SendPaymentReceived`
  itd. na transporcie `events` – każde zamówienie to co najmniej 2 wysyłki SMTP w jedynym wątku workera. Przy
  prawdziwym SMTP (100–500 ms) i szczycie kilku zamówień/s sam e-mail zajmuje dużą część przepustowości workera.
  Lease i `sendOnce` (`NotificationSender.php`) chronią przed duplikatami – dobrze.

### 4.6 Pełna przebudowa indeksu
**[K]. Waga: Medium (cel).** `ProductSearchIndexRebuilder` – keyset po `id`, partie 500, `refresh_interval=-1` na czas
budowy (`ElasticsearchSearchIndexMaintenance.php:286`), przełączenie aliasu. Poprawnie, ale jednowątkowo:
10 mln / 500 = 20 tys. partii; czas zależy od faktów ofert (4–5 zapytań na partię) i `_bulk` **[H]** – prawdopodobnie
kilka godzin.

---

## 5. Cache aplikacyjny

**[K].**
- HTTP: Varnish z xkey przed publicznymi `GET` katalogu; produkt i oferty `s-maxage=3600` z invalidacją zdarzeniami,
  listingi i drzewo kategorii `max-age=60` bez `s-maxage`. Brak `stale-while-revalidate`/grace w odpowiedziach API
  (VCL bez `beresp.grace`), więc po wygaśnięciu żądania czekają na backend (Varnish łączy równoległe MISS-y) **[H]**.
- Symfony Cache: tylko `cache.oidc` (JWKS) i `cache.sitemap` (granice porcji sitemap,
  `CachingSitemapProductSource.php:20`, 1 h), obie na adapterze plikowym w każdym podzie. Doctrine: metadane i zapytania
  DQL w `cache.system` (pliki PHP/opcache), `result_cache` skonfigurowany (`doctrine.yaml:64-80`), ale nieużywany.
- Brak cache tam, gdzie byłby tani:
  - drzewo kategorii (2.2) – zmienia się rzadko, ma zdarzenie `CategoryTreeChanged`, czytane przy każdym listingu
    i przy `GET /categories`;
  - licznik użyć kodu rabatowego (2.4) – lepiej jako kolumna niż cache;
  - stawki VAT/klasy podatkowe są w kodzie (`PolishVatRates`), więc bez potrzeby.
- `CurrentEffectivePrices` celowo „Never cached” (`src/Pricing/Application/Query/CurrentEffectivePrices.php:10`) –
  słusznie, bo to podstawa wyceny; zapytania są tanie.

---

## 6. Zbędna albo przedwczesna złożoność „dla wydajności”

**[K] kod / [H] ocena korzyści. Waga: Low.**

- **16 kubełków na godzinę sprzedaży** (`SalesSql.php:24-26`, `DbalSalesHours.php:35-41`, przebudowa z `FOR UPDATE`
  kubełków `:92-114`). Upsert dzieje się raz na opłacone zamówienie; przy 50 mln zamówień/rok średnio ~1,6/s, w szczycie
  kilkadziesiąt/s, a blokada wiersza trwa do końca transakcji webhooka (milisekundy). Jeden wiersz na godzinę
  prawdopodobnie by wystarczył; podział komplikuje przebudowę i testy współbieżności. Koszt utrzymania niewielki, więc
  nie zalecam cofania – raczej pomiar, czy kiedykolwiek był potrzebny.
- **`CachingSitemapProductSource`** – cache granic porcji w plikach poda na 1 h, choć odpowiedź
  `/sitemap/product-chunks` i tak ma `s-maxage=3600` w Varnishu (`SitemapProductChunksController.php:63-65`).
  Zapytanie (`DbalSitemapProductSource.php:30`, `row_number()` po PK) to skan indeksu raz na MISS. Druga warstwa daje
  niewiele.
- **Podwójny odczyt cen w widoku koszyka** (3.3) – nie przedwczesna optymalizacja, raczej jej brak; wymieniam dla
  kompletności.
- Nie znalazłem przedwczesnych mechanizmów typu własny cache w pamięci procesu, sharding tabel czy ręczne batchowanie
  bez potrzeby – `BoundedCounter`, keyset i `reltuples` są uzasadnione skalą.

---

## 7. Co jest zrobione dobrze (krótko)

- Odczyty zbiorcze bez N+1, modele odczytu DBAL dla sklepu i panelu, ORM tylko do zapisu agregatów.
- Keyset wszędzie w panelu, ograniczone liczniki, testy planów (`RecordingConnection`, wyłączone `enable_seqscan`).
- Stała kolejność blokad, krótkie blokady stanów (rezerwacja na końcu), `ON CONFLICT` zamiast łapania naruszeń.
- Zdarzenie magazynowe tylko przy zmianie poziomu dostępności, nie przy każdej rezerwacji.
- Promocje: zdarzenie raz na produkt, limit 1000 wariantów.
- Indeksy dużych tabel `CONCURRENTLY` z obsługą `INVALID`, wypełnianie danych krokami `app:backfill` w partiach.
- Reindeks z wersją `external_gte` – idempotentny i odporny na kolejność.
- Obraz produkcyjny: `classmap-authoritative`, rozgrzany cache, `opcache.validate_timestamps=0`, `gmp` do RSA.
- Cache HTTP z tagami i invalidacją zdarzeniami; odpowiedzi prywatne zawsze `private, no-store`.

---

## 8. Tabela: endpoint → szacowana liczba zapytań SQL i wywołań zewnętrznych

Szacunek z czytania handlerów i repozytoriów (MISS Varnisha, bez SQL frameworka). N = linie koszyka/zamówienia.
Każde żądanie w trybie klasycznym dokłada boot kernela i nowe połączenie PG (1.1); żądanie z tokenem – weryfikację RS256.

| Endpoint | SQL | Zewnętrzne | Uwagi |
|---|---|---|---|
| `GET /api/v1/products` (listing/wyszukiwanie) | 1–2 (kategoria + całe drzewo ORM) | 1 ES `_search` z agregacjami | koszt dominuje ES (3.1); `max-age=60` |
| `GET /api/v1/products/{slug}` | 3 | – | `s-maxage=3600` |
| `GET /api/v1/product-offers?productId=…` | 4–6 | – | Omnibus tylko przy promocji; `s-maxage=3600` |
| `GET /api/v1/categories` | 1 (całe drzewo) | – | `max-age=60` |
| `GET /api/v1/guest-carts/{id}` | 8–10 | – | ceny czytane 2× (3.3) |
| `GET /api/v1/guest-carts/{id}/summary` | 1 | – | |
| `POST /api/v1/guest-carts/{id}/items` | 12–14 w transakcji (+2 dla koszyka klienta) | – | blokada wiersza koszyka |
| `POST /api/v1/orders` (checkout gościa) | ~25 + 5·N w jednej transakcji, + savepointy | Redis 1–2 (limiter) | z kodem z limitem: +4 i blokada kodu (2.4) |
| `POST /api/v1/orders/{id}/payments` | ~6–8 w transakcji | 1–2 HTTP do dostawcy w transakcji; Redis 1–2 | symulator = drugi wątek tego samego API (2.3) |
| `POST /api/v1/payments/webhooks/{provider}` | ~10 w transakcji | Redis 1–2 | |
| `GET /api/v1/me/orders` | 1–2 | – | keyset, indeks częściowy klienta |
| `GET /api/v1/admin/orders` | 1 (+1 licznik na 1. stronie) | – | ryzyko planu przy fragmencie (2.7) |
| `GET /api/v1/admin/products` | 1 (+1 licznik) | – | ryzyko planu przy kategorii (2.7) |
| `GET /api/v1/admin/discount-codes` | 1 (+1) z `count(*)` użyć na wiersz | – | O(suma użyć strony) |
| `GET /api/v1/admin/dashboard` (z sekcją systemu) | ~8–10 (liczniki, `count(*)` outboxu) | 8 sond HTTP + RabbitMQ mgmt + ES `_count` | 3.6 |
| Konsument `VariantPriceChanged` / `StockLevelChanged` | 1 + 1 + 4–5 (×2 konsumentów GetVariant) | 1 ES `index` + Varnish PURGE + POST rewalidacji (30 s) | bez koalescencji (4.3) |
| Partia `ReindexCategoryBatch` (500) | ~2500 w jednej transakcji | 1 ES `_search` + 500 ES `index` | 4.4 |
| Relay outboxu (1 zdarzenie) | 3–4 (SELECT FOR UPDATE SKIP LOCKED, UPDATE, DELETE) | 1 publish AMQP z potwierdzeniem | 4.2 |

---

## Do zmierzenia

Każdy pomiar na klastrze k3d z obrazem `prod`, najlepiej na profilu `medium`/`server` z `make seed-scale`
(SPX do profilowania pojedynczych żądań w obrazie `dev`; `pg_stat_statements` i `EXPLAIN (ANALYZE, BUFFERS)` w PG).

1. **Narzut trybu klasycznego** – k6 `steady.js` na `GET /api/v1/products/{slug}` z pominięciem Varnisha (bezpośrednio
   do serwisu `api`). Obserwować p50/p95, CPU poda, liczbę połączeń w `pg_stat_activity` i `pg_stat_database`
   (`numbackends`, nowe sesje/s). Oczekuję: p50 zdominowane przez boot (5–20 ms), liczba sesji PG/s ≈ RPS. Powtórzyć
   z worker mode FrankenPHP (gałąź eksperymentalna) – spodziewam się 2–4× wyższej przepustowości na rdzeń.
2. **Liczba wątków i pamięć** – odczytać faktyczne `num_threads` FrankenPHP w podzie (logi startu / metryki
   `frankenphp_*`), potem `saturate.js` na checkout. Oczekuję: liczba wątków zależna od rdzeni węzła, nie od 250m CPU;
   sprawdzić, czy nasycenie wątków przy starcie płatności z symulatorem daje timeouty 5 s (samoblokada).
3. **Listing ES na pełnym katalogu** – `GET /api/v1/products` bez kategorii, z częstą frazą (np. „buty”) i z 2 wybranymi
   osiami opcji, przy 10 mln produktów; zmierzyć `took` z ES, heap i GC ES, oraz odsetek 503 przy timeoucie klienta 2 s.
   Oczekuję: sekundy dla zapytań bez kategorii, część kończy się 503. Porównać z wariantem `track_total_hits: 10000`,
   bez agregacji `free` i z 4 shardami.
4. **Drzewo kategorii** – SPX na MISS listingu kategorii przy 1 tys. i 10 tys. kategorii: czas `DoctrineCategoryRepository::all`
   + `CategoryTreeBuilder::build`. Oczekuję: liniowy wzrost, >20 ms przy 10 tys.
5. **Głodzenie transportów workera** – wygenerować 50 tys. zmian cen (np. skrypt panelu albo `pricing:reconcile-tax --apply`
   po zmianie stawki w środowisku testowym) i równolegle składać zamówienia z płatnością symulatora. Obserwować:
   długość kolejek (`messenger_messages` per `queue_name`, RabbitMQ `events`), opóźnienie e-maila potwierdzenia,
   opóźnienie `ExpireUnpaidOrder` względem `available_at`, czas od webhooka do statusu „opłacone”. Oczekuję: kolejki
   `events`/`scheduled`/`payment_simulator` stoją, dopóki outbox nie zostanie opróżniony.
6. **Przepustowość relaya outboxu** – wstawić 100 tys. wierszy outboxu i zmierzyć tempo opróżniania przy pustych
   pozostałych kolejkach. Oczekuję 200–500/s; sprawdzić rozdęcie `messenger_messages` (`pg_stat_user_tables.n_dead_tup`,
   rozmiar tabeli) po przebiegu.
7. **Koszt zdarzenia zmiany produktu** – średni czas obsługi `VariantPriceChanged` (oba konsumenty) z prawdziwym Next.js
   w trybie `production`; czas POST `/api/revalidate`. Oczekuję 10–30 ms na zdarzenie; wyliczyć czas dla 30 mln wariantów.
8. **Reindeks poddrzewa** – przenieść kategorię z 200 tys. produktów; zmierzyć czas partii (log `search_rebuild`),
   łączny czas przebiegu i długość transakcji (`pg_stat_activity.xact_start`). Oczekuję 3–10 s na partię; porównać
   z `catalog:reindex` (ścieżka `_bulk`) na tej samej liczbie produktów.
9. **Kod rabatowy z limitem** – kod z limitem 1 mln i 200 tys. aktywnych użyć; k6 checkout gościa z tym kodem przy
   50 VU. Obserwować `pg_locks`/`wait_event` (`Lock:tuple`/`transactionid` na `pricing_discount_code`), p95 checkoutu,
   czas zapytania `ACTIVE_USES` w `pg_stat_statements`. Oczekuję: liniowy wzrost czasu licznika z liczbą użyć
   i szeregowanie checkoutów (przepustowość kilkanaście–kilkadziesiąt/s).
10. **Gorący wariant** – checkout 100 VU z tym samym wariantem w koszyku; czas oczekiwania na `FOR UPDATE`
    `inventory_stock_item`. Oczekuję: >100 zamówień/s, p95 rośnie liniowo z VU.
11. **Plany panelu przy 50 mln zamówień** – `EXPLAIN (ANALYZE, BUFFERS)` dla `GET /api/v1/admin/orders?q=gmail` (sort
    domyślny), `GET /api/v1/admin/customers?q=kowal`, `GET /api/v1/admin/products?category=<mała podkategoria>`
    przy 10 mln produktów. Oczekuję: sekundy dla częstych fragmentów i rzadkich kategorii.
12. **Zapis zamówienia a indeksy** – `pg_stat_statements` dla INSERT/UPDATE `ordering_order` w teście checkoutu
    i WAL/s (`pg_stat_wal`); porównać z tabelą bez indeksów sortów panelu. Oczekuję zauważalnego, ale nie dominującego
    udziału (do potwierdzenia, czy GIN trigramowy nie powoduje skoków przy opróżnianiu listy oczekującej).
13. **Pulpit przy zaległości outboxu** – po pomiarze 6 (outbox z 100 tys.+ wierszy) odświeżyć `GET /api/v1/admin/dashboard`;
    czas `SELECT count(*) FROM messenger_messages WHERE queue_name='outbox'`. Oczekuję setek ms przy milionie wierszy.
