# Audyt wydajności: warstwa API (apps/api) – PHP 8.5 / Symfony 8 / Doctrine / FrankenPHP

Rewizja: main 2b4258c7. Analiza statyczna kodu i konfiguracji, bez pomiarów (pomiary: lista na końcu).
Klasy dowodu: [KOD], [POMIAR], [HIPOTEZA], [SZACUNEK].

## 0. Uruchomienie (stan faktyczny)

- [KOD] `apps/api/docker/frankenphp/Caddyfile:1-15` – blok globalny `frankenphp` bez `worker` i bez `num_threads`/`max_threads`:
  **tryb klasyczny**, każde żądanie bootuje kernel Symfony od zera (kontener z cache `prod`, ale nowe obiekty, nowe
  połączenia PDO, Redis, ES, AMQP). Liczba wątków PHP = domyślna FrankenPHP: 2 × liczba CPU widzianych przez Go.
- [KOD] Chart: `api` ma `replicas: 1` (`infra/k8s/charts/shop/templates/_helpers.tpl:245`), prod
  `requests: {cpu: "1", memory: 512Mi}, limits: {memory: 2Gi}`, **bez limitu CPU** (`infra/k8s/environments/prod/values.yaml:121`).
  Bez limitu CPU Go widzi 8 CPU węzła → 16 wątków PHP; `GOMAXPROCS`/`GOMEMLIMIT` nieustawione.
- [KOD] `docker/php/conf.d/app.ini`: `memory_limit=256M`, `opcache.enable=1`, `zend.assertions=1` (także w prod).
  `prod.ini`: `opcache.validate_timestamps=0`, `memory_consumption=192`, `max_accelerated_files=20000`.
  **Brak** `opcache.preload`, `opcache.jit`, `realpath_cache_size/ttl`, `opcache.interned_strings_buffer`; brak
  `max_execution_time` (domyślnie 30 s w trybie HTTP).
- [KOD] Dockerfile `prod`: `APP_ENV=prod`, `APP_DEBUG=0`, `composer dump-autoload --classmap-authoritative`, `cache:warmup`
  w obrazie – dobrze.
- [KOD] Połączenia: Doctrine `pdo_pgsql` bez `persistent` (`config/packages/doctrine.yaml`), Redis limiterów z DSN bez
  `persistent` (`rate_limiter.yaml`, osobne połączenie dla blokad w `lock.yaml`), ES przez `HttpClient::create()` tworzony
  w fabryce (`ElasticsearchClientFactory.php:40-47`). W trybie klasycznym kontener i wszystkie te obiekty żyją jedno
  żądanie, więc **każde żądanie, które dotyka bazy, robi nowe połączenie TCP + uwierzytelnienie SCRAM do Postgresa**,
  a każde wyszukiwanie nowe TCP do ES (bez keep-alive między żądaniami). Sesji PHP brak (firewalle `stateless: true`) – dobrze.
- [KOD] Uwierzytelnianie: OIDC z discovery i cache `cache.oidc` (`security.yaml`, `when@prod: *discovery`), weryfikacja
  podpisu lokalna (RS256, `gmp` zainstalowane – Dockerfile:12-14). Klucze JWKS w puli `cache.oidc` → `cache.app` =
  domyślny adapter plikowy w `var/cache/prod/pools` w warstwie zapisywalnej kontenera: osobny na pod, znika przy
  restarcie poda. Trasy katalogu bez tokena nie uruchamiają weryfikacji.
- [KOD] Sentry: `traces_sample_rate` w prod 0.1 (`infra/k8s/environments/prod/values.yaml:37`), `http_timeout: 2`,
  `http_connect_timeout: 2` (`config/packages/sentry.yaml`); `ServerErrorReporter` zgłasza każdy wyjątek kończący się
  statusem ≥ 500, także 503 z niedostępnego wyszukiwania (`src/Shared/Infrastructure/Observability/ServerErrorReporter.php:38-48`).

## 1. Znaleziska

### F1. Brak backpressure: nieograniczona kolejka na wątek PHP, pojedyncza replika, readiness przez tę samą pulę – **Krytyczna**
- Dowód: [KOD] Caddyfile bez `max_wait_time` (domyślnie wyłączone – https://frankenphp.dev/docs/config/), 16 wątków
  (2 × 8 CPU węzła, brak limitu CPU), `replicas: 1`; readiness `GET /health` co 5 s, `timeoutSeconds: 5`,
  `failureThreshold: 20` (`infra/k8s/charts/shop/templates/api.yaml`, `shop.api.container`) obsługuje ten sam wątek PHP.
  Klient ES czeka do 3 s (1 s połączenie + 2 s bezczynności, `max_duration` 3 s; `config/services.yaml:41-45`), sklep
  (Next) nie ma limitu czasu na wywołania API ([KOD] brak `AbortSignal.timeout` w `apps/store/src` poza odświeżaniem sesji).
- Przyczyna: konfiguracja + architektura. Gdy ES odpowiada wolno, każdy wątek jest zajęty ≤ 3 s, kolejne żądania stoją
  w kolejce Go bez limitu, klient (sklep/Varnish) czeka, a PHP i tak liczy żądania, których nikt już nie odbierze. Po
  ~100 s nasycenia readiness wyłącza jedyny pod → wszystkie żądania API kończą się błędem upstreamu.
- Skalowanie: przepustowość listingu ≤ 16 wątków / czas zapytania ES; przy czasie 2-3 s ≈ 5-8 żądań/s na cały sklep.
  Rośnie liniowo z czasem zapytania (a ten rośnie z liczbą dokumentów), kolejka rośnie bez ograniczenia ze współbieżnością.
- Rekomendacja (S): `max_wait_time` (np. 2-5 s) w bloku `frankenphp` → szybkie 503 zamiast kolejki; jawne `num_threads`
  dobrane do `resources.limits.memory` (16 × `memory_limit` 256M = 4 GiB > limit 2 GiB); osobny limit współbieżności dla
  wyszukiwania (np. semafor w Redisie albo osobny `php_server`/worker z mniejszą pulą dla `/api/v1/products`), żeby
  wolny ES nie zajmował wszystkich wątków, z których korzysta koszyk i checkout; readiness, który nie zależy od wolnego
  wątku PHP, albo wyższy próg – przy jednej replice wyłączenie poda jest gorsze niż wolne odpowiedzi. Druga replika `api`
  (M) daje odporność, nie przepustowość ES.
- Efekt: brak kaskady 500/503 w całym API przy przeciążeniu ES; checkout nie umiera razem z wyszukiwarką.
- Pomiar: na medium 16, 32, 64 równoczesnych żądań `GET /api/v1/products?q=<fraza>&page=<losowa>` bezpośrednio do `api`
  (z pominięciem Varnisha), rejestrując: rozkład czasów, odsetek 503, czas `GET /health` w tym samym czasie, czas
  `GET /api/v1/products/<slug>` (żądanie bez ES) w tym samym czasie. Oczekiwanie wg hipotezy: odczyt produktu, który sam
  trwa < 20 ms, rośnie do sekund (kolejka na wątek).

### F2. Zapytanie ES przerwane przez klienta prawdopodobnie liczy się dalej albo zajmuje kolejkę – **Wysoka** [HIPOTEZA]
- Dowód: [KOD] treść zapytania bez `timeout` (`ProductSearchQuery.php:31-46`), w repo brak `search.default_search_timeout`
  i `search.cancel_after_time_interval` (grep `infra/`). Klient zamyka połączenie po upływie limitu (curl usuwa uchwyt);
  ES ≥ 7.4 anuluje zadanie wyszukiwania po zamknięciu kanału HTTP, ale anulowanie jest kooperacyjne: agregacje
  `nested`/`reverse_nested` po ~1 mln dokumentów nadrzędnych i wielokrotnie więcej zagnieżdżonych mogą działać dalej do
  najbliższego punktu kontroli, a żądania w kolejce puli `search` wykonują się, zanim ktoś zauważy anulowanie.
- Przyczyna: konfiguracja (brak górnej granicy po stronie ES) + koszt zapytania (obszar ES).
- Skutek: przy 8 zimnych wejściach każde przekroczenie 2 s zwalnia wątek PHP, ale nie CPU ES; ponowienia (Varnish/sklep/
  użytkownik) dokładają kolejne zapytania → CPU ES 100% dłużej niż ruch.
- Rekomendacja (S): ustawić w klastrze `search.cancel_after_time_interval` ≈ limit klienta (3 s) albo
  `search.default_search_timeout`, sprawdzić pomiarem, że po timeoucie PHP zadanie znika z `_tasks`. Nie zwiększać
  limitu klienta – to przesuwa problem.
- Pomiar: w trakcie testu z F1 co 0,5 s `GET _tasks?actions=*search*&detailed=true&group_by=parents` i
  `GET _cat/thread_pool/search?v&h=active,queue,rejected,completed`; porównać liczbę aktywnych zadań z liczbą żądań
  PHP w toku. Oczekiwanie wg hipotezy: po 503 w PHP zadania z `cancelled: true` albo bez anulowania żyją jeszcze sekundy.

### F3. Każdy listing (także strona 2+, bez filtrów) liczy pełny zestaw agregacji i dokładny `total` – **Wysoka** (koszt po stronie ES, przyczyna w kodzie API)
- Dowód: [KOD] `ProductSearchQuery::body()` zawsze dodaje `track_total_hits: true` i agregacje `available`, `price`,
  `free` (nested → nested pairs → terms 10 osi × terms 30 wartości, każda z `reverse_nested`), `categories`, i po jednej
  agregacji na wybraną oś (`ProductSearchQuery.php:31-46, 118-190`). `ListProductsHandler` niczego nie pomija dla
  `page > 1` ani dla `perPage=1` (`ListProductsHandler.php:44-55`).
- Skalowanie: liniowo z liczbą pasujących produktów × wariantów × par opcji; brak listingu „taniego”: strona główna
  i kategoria najwyższego poziomu to agregacja po całym katalogu (10 mln przy docelowej skali).
- Rekomendacja (M): rozdzielić wyniki i facety (parametr typu `facets=false` dla stron 2+, gdy sklep ma już facety
  z pierwszej strony, albo osobny endpoint facetów cache'owany dłużej niż lista); `track_total_hits` z górną granicą
  (np. 10 000, i tak `pageCount` ucina na `max_result_window`); rozważyć licznik dostępnych/ceny tylko na żądanie. To
  zmiana kontraktu API i sklepu – szczegóły zapytania należą do audytu ES.
- Pomiar: `GET products/_search` z ciałem jak dla `GET /api/v1/products` (bez parametrów) z `"profile": true`
  i ten sam bez `aggs` i z `track_total_hits: 10000`; porównać `took`. Oczekiwanie: agregacje to większość czasu.

### F4. Tryb klasyczny FrankenPHP: bootstrap Symfony i nowe połączenia na każde żądanie – **Średnia** (Wysoka przy docelowym RPS)
- Dowód: [KOD] Caddyfile bez `worker`; brak `opcache.preload`, brak `realpath_cache_*`, brak JIT; Doctrine bez
  `persistent`. Każde żądanie: dołączenie kontenera z cache, utworzenie kernela, firewall, routing, ArgumentResolver
  z `MapQueryString` (serializer + validator), Messenger (HandleTrait, middleware), nowe PDO z SCRAM-SHA-256 (PostgreSQL 18:
  domyślnie scram; PBKDF2 4096 iteracji po obu stronach), nowe TCP do ES.
- Skalowanie: koszt stały na żądanie, liniowo z RPS; przy 1000+ RPS bootstrap i SCRAM to CPU całych rdzeni API i Postgresa.
  Przy obecnym obciążeniu (ES dominuje) to nie jest przyczyna 500/503.
- Rekomendacja: najpierw zmierzyć (S): narzut stały = czas `GET /health` i czas `GET /api/v1/products/<slug>` przy
  współbieżności 1 i 16. Jeśli narzut > ~5 ms: (S) `opcache.preload` z `var/cache/prod/App_KernelProdContainer.preload.php`
  (Symfony go generuje) + `realpath_cache_size=4096K`, `realpath_cache_ttl=600`, `opcache.interned_strings_buffer=32`;
  (M) worker mode FrankenPHP z `symfony/runtime` (`frankenphp/symfony` runtime): kernel i połączenia żyją między
  żądaniami; wymaga przeglądu serwisów ze stanem (`ResetInterface`), `EntityManager::clear()` po żądaniu (robi to
  `kernel.reset` DoctrineBundle), `max_requests` jako bezpiecznik wycieków. Worker mode zmienia też koszt F7 (keep-alive do
  Sentry i ES). Nie zaczynać od worker mode bez pomiaru z SPX.
- Pomiar: SPX w obrazie dev (`SPX_KEY=dev SPX_ENABLED=1` w nagłówkach/cookie lub `?SPX_KEY=dev&SPX_UI_URI=/` –
  `docker/php/conf.d/spx.ini`, `spx.http_key = "dev"`): profil `GET /api/v1/products?category=<id>` i `/health` –
  odsetek czasu w `Kernel::boot`/`ContainerBuilder`/`Psr18Client`/`PDO::__construct`. Uwaga: obraz dev ma `APP_ENV=dev`
  i `opcache.validate_timestamps=1`, więc do liczb bezwzględnych użyć obrazu `prod` (`docker build --target prod`) i
  `hey`/`k6` na `/health` (RPS przy c=16) oraz `pg_stat_activity`/`log_connections` (połączeń na sekundę).

### F5. `num_threads` × `memory_limit` przekracza limit pamięci poda – **Średnia** [KOD] + [HIPOTEZA]
- Dowód: 16 wątków × `memory_limit=256M` = 4 GiB, limit poda 2 GiB (prod), 1 GiB (domyślny chart). Wątki są
  w jednym procesie, więc OOM-kill zabija wszystkie żądania naraz (jedna replika).
- Typowe żądanie zużywa kilkanaście MB, więc to ryzyko przy dużych odpowiedziach (upload zdjęć do 64 MB z
  `post_max_size=70M`, panel, mapa strony z 50 000 wierszy) i przy przyszłym zwiększeniu liczby wątków.
- Rekomendacja (S): jawne `num_threads` w Caddyfile, `GOMEMLIMIT` i limit CPU (albo `GOMAXPROCS`) zgodny z requestem,
  żeby liczba wątków nie zależała od rozmiaru węzła.
- Pomiar: `kubectl top pod` / cgroup `memory.peak` w trakcie testu F1 i w trakcie równoległych uploadów 4 × 60 MB.

### F6. 503 z wyszukiwarki wysyła synchroniczne zdarzenie do Sentry w trakcie żądania – **Średnia** [HIPOTEZA]
- Dowód: [KOD] `ServerErrorReporter` (priorytet -99 na `kernel.exception`) woła `captureEvent` dla każdego 5xx,
  także `ServiceUnavailableHttpException` z `ListProductsController.php:132-139`. Transport Sentry PHP (4.x) wysyła
  zdarzenie synchronicznie; limit 2 s połączenia + 2 s odczytu; serwer w Kanadzie, ingest w UE (`*.ingest.de.sentry.io`),
  nowe połączenie TLS w każdym żądaniu (tryb klasyczny).
- Skutek: w burzy 503 każdy wątek PHP dodatkowo czeka na Sentry (setki ms), czyli przeciążenie wydłuża zajętość wątków
  – sprzężenie zwrotne; dodatkowo limit zdarzeń Sentry wyczerpie się w minutę.
- Rekomendacja (S): nie raportować `SearchUnavailable`/503 jako zdarzeń (licznik/metryka i log `warning` wystarczą)
  albo próbkować; rozważyć `sample_rate` dla błędów 503.
- Pomiar: test F1 z ustawionym DSN Sentry i bez (`SENTRY_DSN=` pusty) – różnica p95 i liczby 503 przy tej samej
  współbieżności; albo `tcpdump`/`ss -tn` na połączenia do `ingest.de.sentry.io` z poda `api`.

### F7. Śledzenie Sentry 10% wydłuża zajętość wątku – **Niska/Średnia** [HIPOTEZA]
- Dowód: [KOD] `tracesSampleRate: 0.1` w prod; przy próbkowaniu > 0 bundle owija DBAL i klienta HTTP w wersje
  śledzące dla każdego żądania, a transakcja jest wysyłana po odpowiedzi (`kernel.terminate`) – wątek PHP nadal jest zajęty
  na czas wysyłki (TLS do UE).
- Rekomendacja (S): zmierzyć; jeśli koszt widoczny – `traces_sample_rate` 0.01 albo śledzenie tylko w testach obciążenia.
- Pomiar: RPS `GET /api/v1/products/<slug>` (bez Varnisha) przy c=16 z `SENTRY_TRACES_SAMPLE_RATE=0` i `0.1`.

### F8. Listing: drzewo kategorii hydratowane przez ORM w każdym żądaniu – **Niska**
- Dowód: [KOD] `ListProductsHandler.php:41-47, 75-82` – `categories->get()` (ORM `find`) i `categories->all()`
  (`DoctrineCategoryRepository.php:74-86`, cały `catalog_category` z `HINT_REFRESH`, mapowanie na agregaty domeny
  i `CategoryTreeBuilder`) przy każdym listingu, także bez filtra. ~145 kategorii w danych demo
  (`fixtures/demo-data/categories.yaml`), więc koszt stały, rzędu ms.
- Przyczyna: użycie repozytorium agregatu (ścieżka zapisu, z odświeżeniem pod blokadę) do odczytu.
- Rekomendacja (S): odczyt drzewa przez DBAL (jak `DbalProductDetailsSource`) – jedno zapytanie, bez ORM i bez
  `HINT_REFRESH`; bez cache. Zysk mały, ale też trywialny.
- Pomiar: SPX listingu – czas `DoctrineCategoryRepository::all` + `CategoryTreeBuilder::build`.

### F9. Przebudowa kategorii i zdarzenia: reindeksacja pojedynczymi dokumentami (N+1 do SQL i ES) – **Średnia**
- Dowód: [KOD] `ReindexCategoryBatchHandler.php:66-71` – partia 500 produktów, dla każdego `ProductIndexing::reindex`
  (`ProductIndexing.php:37-50`): `source->find` + `offers->of([id])` (port do Cart → Pricing, Inventory) + jedno
  `index` w ES. Pełna przebudowa (`ProductSearchIndexRebuilder::fill`) robi to samo partiami i `bulk` – wzorzec już
  istnieje.
- Skalowanie: liniowo z rozmiarem poddrzewa × ~5-7 zapytań SQL + 1 żądanie ES na produkt; przeniesienie kategorii
  najwyższego poziomu przy 10 mln produktów = miliony round-tripów w jednym workerze (`worker-commands`, transport `jobs`),
  blokując po kolei `payment_simulator`, `scheduled`, `promotions` (kolejność w jednym procesie, `api.yaml`).
- Rekomendacja (S/M): w partii czytać `source` i `offers` dla 500 id naraz i pisać jednym `bulk` z wersją zewnętrzną
  (ten sam kod co `fill`).
- Pomiar: na medium zmiana rodzica kategorii z ~50-100 tys. produktów w panelu; czas do `finished_at`
  w `catalog_category_reindex_run` i liczba zapytań (`pg_stat_statements` przed/po).

### F10. Jeden proces workera na zdarzenia i outbox – **Średnia** [SZACUNEK]
- Dowód: [KOD] `worker` = jeden proces `messenger:consume outbox events` (`api.yaml`, `replicas: 1`); outbox Doctrine
  z odpytywaniem, po jednej wiadomości (SELECT … FOR UPDATE SKIP LOCKED, UPDATE, DELETE) i publikacją z potwierdzeniem
  (`confirm_timeout: 5`). Konsumenci zdarzeń robią reindeksację produktu w ES i czyszczenie Varnisha/sklepu po HTTP.
- Skalowanie: przepustowość asynchroniczna = 1 proces PHP; przy równoległym checkoucie opóźnienie indeksu
  i unieważnień rośnie liniowo z ruchem. Nie wpływa na czas żądania, wpływa na świeżość stanów/cen.
- Rekomendacja (S): więcej replik `worker` (transport Doctrine z SKIP LOCKED i AMQP to obsługują); osobny proces dla
  `outbox` i `events`.
- Pomiar: w trakcie `steady.js` z checkoutem: `SELECT count(*) FROM messenger_messages WHERE queue_name='outbox'`
  i kolejka `events` w RabbitMQ co 10 s; czas od zamówienia do zmiany dokumentu w ES.

### F11. `ordering:read-models:rebuild` – 256 MB w `dev`: przyczyna w trybie debug, nie w kodzie – **Niska** (prod nie dotyczy) [KOD] + [HIPOTEZA]
- Dowód: [KOD] `SalesRebuild::run` iteruje godziny (`nextHour` + `rebuild` = ~8 zapytań/godzinę, każda godzina
  w osobnej transakcji, `DbalSalesHours.php:64-120`), `CustomerRebuild::run` partie po 500 (`DbalCustomerSummaries.php`)
  – w kodzie nic nie trzyma wyników w pamięci (zwracane są tylko sumy). W `dev` (`APP_DEBUG=1`) DoctrineBundle włącza
  middleware debug/profiler, który zbiera każde zapytanie z parametrami w `DebugDataHolder` przez cały proces
  (+ Stopwatch). Kilka lat godzin sprzedaży = dziesiątki-setki tysięcy zapytań → setki MB.
- W `prod` (`APP_DEBUG=0`, `logging`/`profiling` = `kernel.debug` = false) tego kolektora nie ma; Job `backfill` działa
  na obrazie `prod`, limit pamięci Joba 1 GiB.
- Rekomendacja (S): uruchamiać długie komendy lokalnie z `--no-debug` (albo `APP_DEBUG=0`); dla porządku – zmierzyć.
- Pomiar: `docker compose exec api php -d memory_limit=-1 bin/console ordering:read-models:rebuild -vv` vs ten sam
  z `--no-debug`, z `memory_get_peak_usage` (np. `/usr/bin/time -v` → Maximum resident set size). Oczekiwanie: z `--no-debug` stała pamięć.

### F12. Readiness i dashboard panelu – **Niska**
- Dashboard (`GetDashboardHandler`, `ProbingSystemHealth`) wykonuje do ~8 sond HTTP/DB w żądaniu z limitem 1 s
  (`ServiceProbe::LIMIT_SECONDS`) – jeden wątek na ≤ ~1 s na wywołanie; panel ma mały ruch. Bez zmian.

## 2. Co jest zrobione dobrze (nie ruszać)
- [KOD] Ścieżki odczytu sklepu nie mają N+1: strona produktu to 3 zapytania DBAL (`DbalProductDetailsSource.php`),
  oferty i koszyk biorą warianty, ceny i stany jednym zapytaniem na moduł dla całej listy (`GetProductOffersHandler`,
  `GetCurrentPricesHandler`, `CurrentEffectivePrices` przez `findMany` z `ANY(uuid[])`, `GetCartHandler`).
- [KOD] Odczyty przez DBAL/DTO, nie przez agregaty (poza F8); listy panelu z keysetem i `BoundedCounter`; liczniki
  sprzedaży z 16 shardami wiersza godzinowego (brak gorącego wiersza); przebudowy partiami w osobnych transakcjach;
  pełna przebudowa indeksu przez `bulk` z wyłączonym odświeżaniem.
- [KOD] Klient ES bez ponowień (`setRetries(0)`), z krótkim limitem; błędy rozróżnione na przejściowe (503) i trwałe.
- [KOD] Obraz prod: `classmap-authoritative`, rozgrzany cache, `validate_timestamps=0`, bez SPX i dev; limity treści
  w Caddy przed PHP; `read_body` chroni wątki przed wolnymi klientami.
- [KOD] Limiter tylko na trasach zapisu (2 połączenia Redis + ~4 round-tripy, fail open) – nie obciąża katalogu.
- [KOD] Checkout (`PlaceOrderHandler`) – jedna transakcja, ustalona kolejność blokad, idempotencja; kilkadziesiąt
  zapytań, ale wszystkie po kluczu. Nie upraszczać kosztem gwarancji.

## 3. Uproszczenia
- Query bus (Messenger) dla odczytów kosztuje mikrosekundy – nie jest problemem wydajności; nie przepisywać.
- F8: drzewo kategorii przez DBAL zamiast repozytorium agregatu.
- F9: reindeksacja partii przez istniejący kod `bulk`, zamiast osobnej ścieżki per produkt.

## 4. Pokrycie k6 (`load/`)
- [KOD] Wszystko przez sklep (`store:3000`) i Varnish, po rozgrzaniu (`warmup.js`); zbiór danych to `DISCOVER_PRODUCTS=60`
  produktów z 3 porcji mapy i frazy z ich nazw → bardzo wysoki odsetek trafień w cache; nie mierzy kosztu API ani ES.
- Brak: zimnego cache (równoczesne pierwsze wejścia na różne URL-e), długiego ogona (losowe strony 2..N, losowe
  kombinacje filtrów, losowe kategorie), zalogowanego klienta (JWT, `/me/*`), panelu, bezpośredniego obciążenia `api`
  z pominięciem Varnisha, zachowania przy przeciążeniu (czas `/health`, odsetek 503), opóźnienia kolejek.

## 5. Pomiary dla sesji prowadzącej (najważniejsze najpierw)
1. F1/F2: `hey`/k6 bezpośrednio na `http://api` (bez Varnisha): `GET /api/v1/products?page=<1..400 losowo>` i
   `?q=<losowa fraza z nazw>` przy c = 8, 16, 32 przez 60 s; równolegle co 1 s `GET /health` i `GET /api/v1/products/<slug>`
   (czas), co 0,5 s `_tasks?actions=*search*&detailed` i `_cat/thread_pool/search`. Hipoteza: kolejka na wątkach PHP,
   zadania ES żyją po 503 PHP.
2. F3: `took` w ES dla ciała listingu bez parametrów: pełne vs bez `aggs` vs `track_total_hits: 10000`, z `"profile": true`.
3. F4: obraz `prod`, `GET /health` i `GET /api/v1/products/<slug>` przy c=1 i c=16 (p50, RPS); `SHOW log_connections`
   / liczba nowych połączeń na sekundę w Postgresie; profil SPX (obraz dev) listingu i strony produktu.
4. F6/F7: test 1 z Sentry DSN i bez, z `SENTRY_TRACES_SAMPLE_RATE` 0 i 0.1.
5. F5: szczyt pamięci poda `api` w teście 1 i przy równoległych uploadach.
6. F10: kolejki `outbox`/`events` w trakcie `steady.js` z checkoutem.
7. F9: czas reindeksacji poddrzewa ~100 tys. produktów po przeniesieniu kategorii.
8. F11: `ordering:read-models:rebuild` z i bez `--no-debug`, Maximum RSS.
