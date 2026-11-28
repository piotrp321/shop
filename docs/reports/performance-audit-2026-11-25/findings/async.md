# Audyt wydajności: procesy asynchroniczne i operacje masowe (rewizja 2b4258c7)

Analiza statyczna kodu i konfiguracji (bez pomiarów). Klasy dowodu: [KOD], [HIPOTEZA], [SZACUNEK].
Kod Symfony Messenger czytany z wolumenu `isr-tag_api-vendor` (tylko odczyt, `symfony/doctrine-messenger`).

## 1. Topologia (stan obecny)

[KOD] `apps/api/config/packages/messenger.yaml`, `infra/docker-compose.yml:337-391`,
`infra/k8s/charts/shop/templates/api.yaml:136-174`, `values.yaml:259-261`.

| Transport | Nośnik | Co niesie | Konsument | Retry |
|---|---|---|---|---|
| `outbox` | tabela `messenger_messages`, `queue_name=outbox`, `use_notify: false` (polling) | każde `DomainEvent` (zapis w transakcji agregatu) | `worker` (pierwszy na liście) – `RelayDomainEventToBroker` publikuje do AMQP z `confirm_timeout: 5` | 200 prób, do 60 s (~3 h) |
| `events` | RabbitMQ, **jedna kolejka** dla wszystkich typów zdarzeń, serializer JSON | wszystkie zdarzenia domenowe | `worker` (drugi na liście); wszystkie handlery `fromTransport: events` danego zdarzenia w jednym przebiegu | 10 prób, x3, do 300 s (~26 min) |
| `scheduled` | Postgres | `ExpireUnpaidOrder` (DelayStamp: termin + 5 min) | `worker-commands` | 5 prób |
| `payment_simulator` | Postgres | webhooki symulatora | `worker-commands` (pierwszy) | 5 prób |
| `promotions` | Postgres | `PromotionBoundaryReached`, `RefreshPromotionProducts` | `worker-commands` | 5 prób |
| `jobs` | Postgres | `ReindexCategoryBatch` (łańcuch partii po 500) | `worker-commands` | 5 prób |
| `failed` | Postgres | | ręcznie (`app:messaging:failed-retry`) | 0 |

- Procesy: **jedna replika** `worker` (`messenger:consume outbox events`) i **jedna** `worker-commands`
  (`payment_simulator scheduled promotions jobs`), obie `--time-limit=86400 --memory-limit=192M`, bez `--limit`,
  domyślne `--sleep=1` (1 s snu, gdy wszystkie odbiorniki są puste). Zasoby `worker`: request 100m CPU, limit 640Mi;
  `workerCommands`: 50m, limit 384Mi (`values.yaml:260-261`; profil prod może je nadpisywać).
- Worker Symfony po każdej obsłużonej wiadomości wraca do pierwszego odbiornika (spec E2): `outbox` ma ścisły priorytet
  nad `events`, więc dopóki outbox nie jest pusty, **żadne zdarzenie nie jest konsumowane** (e-maile, reindeks, purge).
- Pobieranie z transportu Doctrine (vendor `Connection::get()`): `BEGIN; SELECT … WHERE queue_name=? AND (delivered_at
  IS NULL OR delivered_at < ?) AND available_at <= ? ORDER BY available_at LIMIT 1 FOR UPDATE SKIP LOCKED; UPDATE
  delivered_at; COMMIT`, a `ack` = `DELETE … WHERE id=?`. Jedna wiadomość = ~5 round-tripów do Postgresa + (relay)
  publish AMQP z potwierdzeniem. Brak `fetchSize > 1`. Indeks `(queue_name, available_at, delivered_at, id)`.
- AMQP: Symfony `AmqpReceiver` używa `basic_get` (polling, wiadomość po wiadomości, bez prefetch/consume).
- Wersjonowanie dokumentów ES: `version_type: external_gte`, wersja = czas (µs) sprzed odczytu
  (`ElasticsearchProductIndexer.php`, `ProductIndexing.php`). Kolejność zdarzeń tego samego produktu nie ma znaczenia,
  bo każdy handler czyta stan bieżący – **równoległe workery są bezpieczne** (dobrze zrobione).
- E-mail: `message_bus: false` (`config/packages/mailer.yaml`) – SMTP synchronicznie w handlerze na `events`,
  z jednorazowym „claim” (`NotificationSender::sendOnce`). Idempotentne.

### Koszt jednej wiadomości (ścieżka wykonania) [KOD] + [SZACUNEK czasów]

Wspólny narzut każdego zdarzenia: zapis wiersza outbox (INSERT w transakcji nadawcy) → relay (~5 zapytań PG + publish
AMQP z confirm) → `basic_get` + deserializacja JSON + ack. Szacunek: 3–8 ms.

| Zdarzenie | Handlery na `events` | SQL | ES | HTTP | SMTP |
|---|---|---|---|---|---|
| `StockLevelChanged` (tylko przejście poziomu: >5 → ≤5 → 0, `AvailabilityLevel.php:13`) | `ReindexProductOnStockLevelChange`, `InvalidateStorefrontOnStockLevelChange` | 2×`GetVariant` + `ProductIndexing` (1 duże zapytanie z 3 LATERAL + `GetProductOffers`: warianty, ceny, promocje, stany, stawki ≈ 4–6) ≈ 7–9 | 1 pojedynczy `index` (bez `refresh`, widoczność po `refresh_interval` 1 s) | PURGE Varnish (xkey) + POST `/api/revalidate` sklepu | – |
| `VariantPriceChanged` (per wariant, źródło ≠ seed/tax) | `ReindexProductOnPriceChange`, `InvalidateStorefrontOnPriceChange` | jw. ≈ 7–9 | 1 | 2 | – |
| `PromotionPricesChanged` (per produkt) | `ReindexProductOnPromotionChange`, `InvalidateStorefrontOnPromotionChange` | ≈ 6 | 1 | 2 | – |
| `ProductCreated/Changed` | `IndexProduct`, `InvalidateStorefrontOnProductChange`, `SyncStockRecordsOnProductChange` | ≈ 8+ | 1 | 2 | – |
| `CategoryTreeChanged` (rename/move) | `ReindexSubtreeOnCategoryTreeChange` (start łańcucha `jobs`), `InvalidateStorefrontOnCategoryTreeChange` (**purge tagu `catalog` = cały Varnish + całe ISR**) | 2 | 0 | 2 | – |
| `OrderPlaced`, `OrderPaid`, `OrderShipped`, `OrderCancelled`, `OrderReturned` | `Send*` | odczyt zamówienia + claim/markSent ≈ 4–6 | 0 | 0 | 1 synchronicznie |

[SZACUNEK] zdarzenie reindeks+purge: 25–80 ms czasu ściennego workera (PG ~5–10 ms, ES pojedynczy index 5–30 ms,
revalidate Next 5–40 ms, narzut Messenger/relay 3–8 ms) → **12–40 zdarzeń/s na jeden worker**. E-mail przez Mailpit
~5–20 ms, przez Brevo SMTP 150–800 ms → 1–6 e-maili/s.

Opóźnienie end-to-end przy pustych kolejkach [KOD+SZACUNEK]: zapis → do 1 s (`--sleep=1`, polling outboxa) → relay
~5 ms → `basic_get` w tej samej pętli → handler 25–80 ms → widoczność w ES po `refresh_interval` (1 s) → **ok. 0,1–2,5 s**.
Pod obciążeniem opóźnienie = głębokość kolejki / przepustowość jednego procesu (poniżej).

## 2. Znaleziska

### A1. Jeden proces, jedna kolejka FIFO dla wszystkiego: e-maile i kasy czekają za reindeksami (head-of-line) — **Wysoka**
- [KOD] `worker` = jeden proces, jedna replika (`api.yaml:165-167`, brak `replicas`), konsumuje `outbox` i `events`;
  `events` to jedna kolejka RabbitMQ dla wszystkich zdarzeń (`messenger.yaml` – jeden transport, handlery
  `fromTransport: events`). `SendOrderConfirmation` stoi w tej samej FIFO co `ReindexProductOnPromotionChange`.
- [KOD] Priorytet `outbox` przed `events` (E2 w spec): przy serii N zdarzeń worker najpierw relayuje całe N, a dopiero
  potem obsługuje cokolwiek z `events`.
- [SZACUNEK] relay 2–10 ms/wiadomość (5 round-tripów PG + publish z potwierdzeniem RabbitMQ) → 100–500/s; obsługa
  reindeks+purge 25–80 ms → 12–40/s. Seria 100 tys. `PromotionPricesChanged` (100 promocji po 1000 wariantów startujących
  o północy) = 3–17 min samego relayu (zero e-maili w tym czasie), potem 0,7–2,3 h kolejki `events`; potwierdzenie
  zamówienia złożonego o 0:05 przychodzi po godzinach, a cena na liście i w wyszukiwarce jest stara, choć koszyk liczy
  już promocyjną (rozjazd ceny prezentowanej – ryzyko Omnibus/UOKiK).
- Przyczyna: architektura (jeden konsument, brak podziału na klasy pracy), konfiguracja (1 replika).
- Skalowanie: koszt liniowy z liczbą zdarzeń; współbieżność — dziś zero (1 proces).
- Rekomendacja (prosta, natywna):
  1. Dwie kolejki związane z tym samym exchange (`events` → np. `events_notify` dla `Send*`, `events_projection` dla
     reindeksu/purge) jako dwa transporty Messenger z własnym `queues:` i odpowiednim `fromTransport`; osobne Deploymenty
     (`worker-notify` 1 replika, `worker-projection` N replik). Relay `outbox` jako osobny proces (`messenger:consume
     outbox`) albo pierwszy w workerze projekcji. Koszt M.
  2. Replik `worker` > 1 jest bezpieczne już dziś: `SKIP LOCKED` na outboxie (spec E6), wiele konsumentów AMQP,
     handlery idempotentne, `external_gte` w ES, `sendOnce` dla e-maili. Koszt S (wartość w chartcie + budżet węzła).
- Efekt: e-mail niezależny od burzy reindeksów; przepustowość projekcji ×N.
- Pomiar: na medium wygenerować 20 tys. `PromotionPricesChanged` (pkt 4, P1) i jednocześnie złożyć zamówienie; zmierzyć
  czas do e-maila w Mailpit i głębokość `events` (`rabbitmqctl list_queues name messages`) co 10 s.

### A2. Brak przetwarzania wsadowego: 1 zdarzenie = 1 pojedynczy zapis ES + 1 purge + 1 revalidate — **Wysoka**
- [KOD] `ProductIndexing::reindex()` – jeden produkt: zapytanie strony (3× LATERAL), `GetProductOffers` (4–6 zapytań),
  `ElasticsearchProductIndexer::index()` – pojedynczy `index` z `external_gte`. Każde zdarzenie cenowe/stanu/promocji
  osobno woła `HttpStorefrontCache::invalidateTags([jeden tag])` (PURGE + POST `/api/revalidate`), choć API przyjmuje do
  100 tagów w jednym żądaniu (`route.ts`, `MAX_TAGS = 100`).
- [KOD] `VariantPriceChanged` jest per wariant: zmiana cen 20 wariantów jednego produktu = 20 reindeksów i 20 purge tego
  samego produktu (bez koalescencji).
- [KOD] Łańcuch kategorii `ReindexCategoryBatchHandler` (500 produktów na partię) też woła `ProductIndexing::reindex()`
  pojedynczo w pętli, w jednej transakcji command.bus trzymającej blokadę przebiegu: partia = 500 × (≈6 SQL + 1 HTTP ES)
  ≈ 5–15 s otwartej transakcji [SZACUNEK].
- Skalowanie: liniowe, stała ~25–80 ms/produkt; bulk byłby ~1–2 ms/produkt (pełny reindex osiąga 850–2900 dok./s
  właśnie dzięki bulk + ofertom po 100).
- Rekomendacja: handler wsadowy Messenger (`BatchHandlerInterface` + `BatchHandlerTrait`, natywne w Symfony,
  https://symfony.com/doc/current/messenger.html#process-messages-by-batches) dla zdarzeń „produkt do odświeżenia”:
  deduplikacja `productId` w paczce (np. 100 wiadomości albo flush przy pustej kolejce), `ProductOfferFacts::of()` dla
  całej paczki, jeden `_bulk` z `external_gte`, jeden PURGE z wieloma `xkey` i jedno `/api/revalidate` z ≤100 tagami.
  Ten sam rdzeń „reindeksuj listę id” w `ReindexCategoryBatchHandler` (ma już listę 500 id). Koszt M.
- Efekt [SZACUNEK]: 10–30× wyższa przepustowość projekcji na proces; łańcuch kategorii 500 produktów w <1 s zamiast
  5–15 s.
- Pomiar: P1 i P3 (pkt 4) – tempo opróżniania przed i po; `GET _nodes/stats/indices/indexing` (index_total/index_time).

### A3. Masowe zmiany cen/promocji praktycznie niewykonalne przy 10 mln produktów — **Wysoka** (architektura/produkt)
- [KOD] `Promotion::MAX_VARIANTS = 1000` (`Pricing/Domain/Promotion/Promotion.php:30`); promocja „cały dział 500 tys.
  produktów” wymaga ≥500 promocji (ręcznie w panelu). Zmiana ceny to tylko `SetVariantPrice` (pojedynczo), brak operacji
  zbiorczej i importu.
- [SZACUNEK] gdyby ją zrobić (500 promocji startujących o tej samej godzinie): 500 tys. zdarzeń → relay 0,3–1,4 h,
  następnie 3,5–11,5 h obsługi `events` na jednym workerze; przy końcu promocji drugie tyle. 500 tys. purge w Varnish
  (xkey – koszt O(obiektów z tagiem), ok.) i 500 tys. revalidate (65 536 kubełków ISR → praktycznie każdy kubełek
  produktowy unieważniony = zimny cache stron produktu i list).
- Rekomendacja: operacje masowe nie przez zdarzenie per produkt, tylko przez istniejący wzorzec „łańcucha partii”
  (`jobs`, jak `ReindexCategoryBatch`) z bulk ES i jednym `invalidateTags([catalog])` na końcu (albo wcale – patrz A6);
  pełny `catalog:reindex` jest szybszy niż 500 tys. pojedynczych zdarzeń. Decyzja produktowa: czy promocja działowa
  jest wymagana (vision). Koszt L.
- Pomiar: P1 z 50 promocjami po 1000 wariantów (50 tys. zdarzeń) na medium; ekstrapolacja liniowa.

### A4. Pełny reindex: szeregowy potok jednego procesu PHP; zapisy w trakcie giną — **Wysoka**
- [KOD] `ProductSearchIndexRebuilder::fill()` – pętla: `source->page(500)` (keyset `id > :after`, jedno zapytanie
  z LATERAL, bez N+1) → `offers->of()` (5 × `GetProductOffers` po 100 produktów, każde 4–6 zapytań: warianty, ceny,
  promocje, stany) → budowa dokumentów w PHP → synchroniczny `_bulk` 500 dok. → następna strona. Żadnej równoległości ani
  nakładania I/O: Postgres czeka na PHP i ES, ES czeka na PHP. Indeks budowany z `refresh_interval: -1`
  (`ElasticsearchSearchIndexMaintenance::create()`), `number_of_replicas` = `app.search.replicas` = 0, alias swap
  atomowy z `must_exist` — **to jest zrobione dobrze**.
- Obserwacja z briefu (850 dok./s na serwerze, ES 0,26 rdzenia, PG 0,45) pasuje do jednowątkowego PHP: ~590 ms na
  partię 500, z czego PG+ES razem < 0,7 rdzenia → reszta to CPU PHP (hydratacja obiektów domenowych `VariantPrice`,
  `EffectivePrice`, `ProductOfferSummary`, budowa dokumentu, `json_encode`, query bus) i czekanie na round-tripy
  (~25 zapytań/partię). [HIPOTEZA co do podziału – potrzebny pomiar P4].
- [SZACUNEK] 10 mln: 850/s → **3,3 h** (limit Joba 6 h, `values.yaml:231`); 2,2–2,9 tys./s lokalnie → 1–1,3 h.
  Produkty mają UUID v5 (losowe, `DemoUuid::v5`), więc odczyt po PK skacze po stercie: przy tabeli większej niż cache
  stron koszt odczytu może rosnąć gorzej niż liniowo [HIPOTEZA].
- [KOD] Zapisy w trakcie przebudowy idą przez alias do starego indeksu i nie trafiają do nowego
  (`docs/features/search/spec.md:380`, komentarz `infra/k8s/Makefile:159`: „The shop stays open; a change written during
  it may be lost”). Przy 3,3 h każda zmiana stanu, ceny i każda granica promocji z tego okna jest błędna w wyszukiwarce
  i listach aż do następnej zmiany produktu. Skala okna rośnie liniowo z katalogiem.
- Rekomendacje:
  1. Równoległość przez podział zakresu UUID (v5 jest równomierny): `--partition=k/N` albo N procesów potomnych
     (wzorzec `run_parts` z `bin/generate-demo`), każdy keysetem w swoim zakresie; licznik dokumentów sumowany przed
     `verify`. 4 procesy → [SZACUNEK] ~3–3,5 tys./s na serwerze, 10 mln w ~50 min, dopóki PG/ES nie nasycą się. Koszt M.
  2. Podwójny zapis w trakcie budowy: blokada przebudowy (`PostgresSearchIndexRebuildLock`) zapisuje nazwę nowego
     indeksu; `ElasticsearchProductIndexer::index()` pisze do aliasu i (gdy budowa trwa) do nowego indeksu — `external_gte`
     gwarantuje, że starszy odczyt nie nadpisze nowszego. `verify()` musi wtedy przyjąć `count >= written`. Koszt M.
  3. Wewnętrzne wywołanie ofert całą stroną (500) zamiast 5 × 100 — limit `MAX_PRODUCTS` dotyczy endpointu HTTP;
     mniej round-tripów (efekt mały, S).
- Pomiar P4.

### A5. Brak nadzoru nad zatorem `events` — zator godzinny jest niewidoczny dla alertów — **Wysoka** (obserwowalność)
- [KOD] Alert `MessagingUnhealthy` liczy wiek najstarszej gotowej wiadomości tylko kolejek Postgresa
  (`MessagingStatisticsAdapter`, `app:messaging:check`); `events` (RabbitMQ) ma tylko długość na pulpicie, bez alertu
  (spec async-reliability, „Poza zakresem”). Założenie spec „zawieszony worker widać po wieku outbox” działa tylko dla
  zawieszenia całkowitego: worker, który jest **wolny** (np. revalidate sklepu po 30 s timeoutu na każde zdarzenie –
  `HttpStorefrontCache::$storeTimeout = 30.0`), nadal relayuje outbox po każdej wiadomości, więc outbox jest pusty,
  a `events` rośnie bez sygnału.
- Brak metryk przepustowości i czasu obsługi (ile wiadomości/s, czas handlera, opóźnienie od `occurredAt`).
- Rekomendacja: (a) w `app:messaging:check` dodać odczyt `messages` i `messages_ready` kolejek RabbitMQ z management
  API (już używanego przez pulpit) z progiem długości; (b) wiek: log `occurredAt → handled` w listenerze
  `WorkerMessageHandledEvent` (Loki już jest) albo wbudowany plugin `rabbitmq_prometheus` (bez nowej usługi). Koszt S.
- Pomiar: P1 przy zatrzymanym sklepie (`store` scale 0) – obserwować, czy `queue-check` cokolwiek zgłosi.

### A6. Zmiana kategorii czyści cały cache sklepu (Varnish + ISR), dwukrotnie — **Wysoka** przy znanym problemie zimnych list
- [KOD] `InvalidateStorefrontOnCategoryTreeChange` → `invalidateTags([CacheTags::CATALOG])` (cały Varnish i całe ISR) na
  każde `Revised`/`Moved`; po zakończeniu łańcucha `ReindexCategoryBatchHandler` jeszcze raz `invalidateCatalog()`.
  Ponieważ 8 równoczesnych zimnych wejść na listing daje 500/503 (brief), zwykła zmiana nazwy kategorii w panelu
  w godzinach ruchu = burza zimnych renderów na całym sklepie, dwa razy.
- Zmiana nazwy kategorii z 1 mln produktów w poddrzewie: [SZACUNEK] 2000 partii × 5–15 s = 3–8 h łańcucha na
  `worker-commands` (50–200m CPU request), w tym czasie listy i wyszukiwarka mają mieszane stare/nowe nazwy.
- Rekomendacja: purge celowany (tag kategorii na odpowiedziach list i menu, `xkey` już jest), bez drugiego pełnego
  czyszczenia; łańcuch na wsadowym rdzeniu z A2. To pogranicze z obszarem cache – do skoordynowania. Koszt M.

### A7. Zdarzenia stanu przy zamówieniach: tylko przy zmianie poziomu – dobrze; ale purge trafia w najpopularniejsze listy — **Średnia** [HIPOTEZA]
- [KOD] `StockLevelChanged` tylko przy zmianie poziomu (`AvailabilityLevel`: >5, ≤5, 0; `StockLevelChanged.php`), więc
  zwykła rezerwacja nie reindeksuje – **dobrze zaprojektowane**. Przy niskich stanach (demo: wiele wariantów z kilkoma
  sztukami) znaczna część zamówień przekracza próg i daje reindeks + purge tagu `product-<id>`, który w Varnish usuwa
  także **wszystkie odpowiedzi list zawierające ten produkt** (`ListProductsController.php:140`). Kupowane produkty są
  na najczęściej oglądanych listach (bestsellery, pierwsze strony kategorii), więc ruch zakupowy systematycznie
  wychładza najgorętsze wpisy cache.
- Rekomendacja: zmierzyć udział zamówień, które zmieniają poziom, i liczbę obiektów usuwanych na purge; ewentualnie
  odpowiedzi list bez tagów produktów (krótki TTL zamiast purge per produkt) – decyzja do obszaru cache.
- Pomiar P5.

### A8. `ordering:read-models:rebuild` wyczerpuje 256 MB w dev – przyczyna: profilowanie DBAL w trybie debug — **Średnia** (narzędzia), [KOD]+[HIPOTEZA ilościowej]
- [KOD] W dev `kernel.debug = true`, a DoctrineBundle domyślnie ma `profiling: %kernel.debug%`
  (vendor `doctrine-bundle/src/DependencyInjection/Configuration.php:179`), co rejestruje `DebugMiddleware`
  i `BacktraceDebugDataHolder` przechowujący **każde zapytanie DBAL z parametrami** do `reset()` (`kernel.reset`).
  Worker resetuje usługi po każdej wiadomości, ale komenda konsolowa – nigdy. `config/packages/doctrine.yaml` nie
  wyłącza `profiling` (ani w `when@dev`), compose ma `APP_ENV: dev` (`docker-compose.yml:241`).
- `SalesRebuild` robi ~4–5 instrukcji na każdą godzinę z płatnościami (lata zamówień ≈ 10–20 tys. godzin), a
  `CustomerRebuild` kilka na każdą partię 500 klientów; sam kod nie gromadzi danych w PHP (wszystko w SQL,
  `DbalSalesHours`, `DbalCustomerSummaries`). [SZACUNEK] 50–100 tys. obiektów `Query` z SQL ~0,5–2 KB → 100–250 MB.
- Ten sam mechanizm dotyczy w dev każdej długiej komendy (`catalog:reindex` z listami 100–500 UUID w parametrach,
  `ordering:expire-overdue` przy zaległości, kroki generatora) i dodatkowo je spowalnia. W k8s (`APP_ENV=prod`) nie
  występuje.
- Rekomendacja: `doctrine.dbal.profiling: false` (albo `when@dev` tylko dla HTTP), względnie uruchamianie komend
  wsadowych z `--no-debug` w `bin/generate-demo` i Makefile. Koszt S.
- Pomiar: `docker compose exec api php -d memory_limit=-1 bin/console ordering:read-models:rebuild -v` z `/usr/bin/time -v`
  (max RSS) vs to samo z `--no-debug`; to samo dla `catalog:reindex` na 100 tys. produktów.

### A9. Transport Doctrine: 1 wiadomość = 5 round-tripów, UPDATE kolumny indeksowanej, kasowanie po jednej — **Średnia** [HIPOTEZA]
- [KOD] vendor `Connection::get()`/`ack()`: `BEGIN; SELECT … ORDER BY available_at LIMIT 1 FOR UPDATE SKIP LOCKED;
  UPDATE delivered_at; COMMIT;` + `DELETE`. `delivered_at` jest w indeksie `(queue_name, available_at, delivered_at, id)`,
  więc UPDATE nie jest HOT → 2 martwe krotki i wpisy indeksu na wiadomość. Wszystkie kolejki w jednej tabeli
  `messenger_messages` (outbox z burstami, `scheduled` z wierszem na każde zamówienie żyjącym 35 min).
- Przy burstach (A3) indeks i sterta puchną szybciej niż autovacuum (domyślne progi), a `SELECT … ORDER BY available_at
  LIMIT 1` przechodzi po martwych wpisach na początku zakresu – koszt pobrania może rosnąć z rozmiarem serii.
- Rekomendacja: najpierw pomiar; jeśli potwierdzony – `autovacuum_vacuum_scale_factor` niski dla `messenger_messages`
  (ALTER TABLE … SET), ewentualnie `fetchSize`/batch relay. Koszt S.
- Pomiar P2.

### A10. E-mail synchronicznie w workerze zdarzeń — **Średnia** (przy przejściu na Brevo)
- [KOD] `message_bus: false`, SMTP w handlerze, timeout gniazda z DSN (`SmtpTimeoutTransportFactory`). Na Mailpit
  ~ms; na Brevo 150–800 ms/e-mail [SZACUNEK] → 1–6 e-maili/s blokujących też reindeks (A1). Zamówienie generuje 2–3
  e-maile (złożone, opłacone, wysłane).
- Rekomendacja: rozwiązuje A1 (osobna kolejka/proces dla `Send*`); nie zmieniać `message_bus: false` (dobra decyzja –
  błąd SMTP wraca do handlera, `sendOnce` daje idempotencję).

### A11. Wygaszanie zamówień i płatności — **Niska** (zrobione dobrze)
- [KOD] `ExpireUnpaidOrder` z `DelayStamp` (1 wiersz `scheduled` na zamówienie, także opłacone – handler kończy bez
  pracy), siatka `ordering:expire-overdue` co 5 min (keyset `(placed_at,id)` po indeksie `status, placed_at, id`,
  osobna transakcja na zamówienie), `payment:close-abandoned` po indeksie częściowym `pending`. Koszt per zamówienie
  stały; przepustowość setki/s na `worker-commands` [SZACUNEK]. Zaległość ograniczona `activeDeadlineSeconds: 600`.
- Uwaga: `jobs` dzieli proces z `scheduled`/`payment_simulator`; partia łańcucha kategorii (5–15 s, A2) opóźnia
  webhook/wygaśnięcie najwyżej o czas jednej partii (worker wraca do pierwszego transportu po każdej wiadomości).
- Nie ruszać.

### A12. Generator danych demo — **Średnia/Niska**
- [KOD] COPY (`CopyWriter`, `copyFromArray`, partie 5000, `synchronous_commit=off`), części równoległe (`--processes=4`,
  `run_parts`), konta Keycloak przez `partialImport` po 2000 w 4 strumieniach bez haseł – **dobrze**.
- [KOD] Tabele ładowane **z indeksami** („into tables that keep their indexes”); identyfikatory produktów UUID v5
  (losowe) → wstawianie w losowe miejsca B-drzew PK i indeksów `product_id`, dużo FPW w WAL. Przy 10 mln (×10 względem
  medium) koszt utrzymania indeksów rośnie szybciej niż liniowo, gdy indeksy przestają mieścić się w pamięci
  [HIPOTEZA]; spec demo-data sama oznacza ekstrapolację 4 h dla `server` jako niezweryfikowaną.
- [KOD] `demo-data:stock` – jedna transakcja `INSERT … SELECT` dla wszystkich wariantów (rezerwacje, ruchy, stany),
  jednowątkowo (`StockLoader::load()`): przy `server` dziesiątki milionów wierszy w jednej transakcji [SZACUNEK
  min. kilkanaście–kilkadziesiąt minut, duży WAL szczytowy].
- `demo-data:verify` – pełne skany SQL kolejno, 596 s na medium; dla `server` ×7,5 wg spec (~75 min).
- Na końcu `catalog:reindex` (A4) – 3,3 h z 4 h całego przebiegu `server` to reindeks [SZACUNEK z briefu].
- Rekomendacje: najpierw A4 (największy składnik); `DROP INDEX`/`CREATE INDEX` drugorzędnych indeksów wokół ładowania
  największych tabel z `maintenance_work_mem` podniesionym na sesję; `stock` podzielony na części jak `prices`;
  `verify` sprawdzenia równolegle na osobnych połączeniach. Koszt M.
- Pomiar P6.

### A13. Rewalidacja ISR trafia do jednego poda sklepu — **Niska dziś, Wysoka przy skalowaniu sklepu** [KOD]
- `HttpStorefrontCache::revalidate()` woła `STORE_INTERNAL_URL` (Service). Pamięciowy cache ISR (L9,
  `cacheMaxMemorySize` w `next.config`) jest per pod; przy >1 replice `store` rewalidacja unieważni tylko jeden pod.
  Dziś `store` ma 1 replikę, więc to ograniczenie skalowania poziomego sklepu, nie błąd. Do obszaru cache.

## 3. Co jest zrobione dobrze (nie ruszać)

- Outbox w transakcji agregatu + relay z potwierdzeniem publikacji i `mandatory` (brak utraty zdarzeń), długie ponowienia,
  `failed` z alertem i procedurą (W9–W12).
- Wersjonowanie dokumentów ES `external_gte` czasem odczytu: kolejność i duplikaty zdarzeń są nieszkodliwe → workery
  można skalować poziomo bez partycjonowania po produkcie.
- Handlery czytają stan bieżący (zdarzenie niesie tylko id) – naturalnie nadają się do deduplikacji/wsadów (A2).
- `StockLevelChanged` tylko przy zmianie poziomu, nie przy każdej rezerwacji.
- Pomijanie reindeksu i purge dla źródeł `seed` i `tax_reconciliation`.
- Pełny reindex: keyset po PK (plan stały przy prepared statements), bulk, `refresh_interval: -1`, 0 replik, weryfikacja
  liczby, atomowy alias swap, ponowienia bulk z backoffem, blokada przebudowy.
- Purge Varnish przez `xkey` (koszt proporcjonalny do obiektów z tagiem, nie do rozmiaru cache, w odróżnieniu od banów).
- Kubełki tagów ISR (65 536) – ograniczona lista tagów w pamięci Next.
- Dwa workery: komendy (wygaszanie, webhooki) nie zależą od RabbitMQ.
- Wygaszanie zamówień: opóźniona wiadomość + siatka CronJob, keyset po indeksie; `payment:close-abandoned` po indeksie
  częściowym.
- Generator: COPY, części równoległe, `partialImport` Keycloaka bez haseł, deterministyczne id.
- Liczniki kolejek ograniczone `LIMIT 1001` (pulpit nie skanuje całej tabeli).

## 4. Co można uprościć

- `ReindexProductOnStockLevelChange` i `InvalidateStorefrontOnStockLevelChange` (oraz para cenowa) robią to samo
  `GetVariant` i działają zawsze razem → jeden handler wsadowy „odśwież produkty” (A2) zastępuje 6 handlerów
  (`ReindexProductOn{Stock,Price,Promotion}Change`, `InvalidateStorefrontOn{Stock,Price,Promotion}Change`) i
  `IndexProduct`/`InvalidateStorefrontOnProductChange`. Mniej kodu, mniej wywołań HTTP.
- `ReindexCategoryBatchHandler` może używać tego samego wsadowego rdzenia zamiast pętli `reindex()`.
- Druga pełna inwalidacja na końcu łańcucha kategorii (A6) – zbędna, jeśli purge jest celowany.

## 5. Pomiary dla sesji prowadzącej (stos medium; najważniejsze najpierw)

Wspólne: `docker compose -p <stos> exec postgres psql -U … shop`, `exec rabbitmq rabbitmqctl list_queues name messages
messages_ready messages_unacknowledged`, `exec varnish varnishstat -1 -f MAIN.n_obj_purged -f MAIN.n_object`.

**P1. Przepustowość relay i `events` dla `PromotionPricesChanged` (A1, A2, A3).**
1. `docker compose stop worker` (outbox się zbiera).
2. Panel/API (token `admin`): 20–50 × `POST /api/v1/admin/promotions` z 1000 wariantów różnych produktów i startem za
   2 min (albo `PUT …/promotions/{id}` zmieniający koniec aktywnej promocji → `RefreshPromotionProducts` od razu).
   Po starcie: `SELECT count(*) FROM messenger_messages WHERE queue_name='outbox'` = liczba produktów.
3. Złożyć 1 zamówienie (kontrola e-maila).
4. `docker compose start worker`; co 5 s: licznik outbox (tempo relayu), `rabbitmqctl list_queues` (tempo `events`),
   czas pojawienia się e-maila w Mailpit.
5. Opóźnienie do widoczności w ES bez instrumentacji: `_version` dokumentu = czas odczytu w µs
   (`GET <alias>/_doc/<id>?_source=false`), porównać z `created_at` wiersza outbox (zanotowanym przed startem workera)
   dla próbki 100 produktów: lag = `_version/1e6 − created_at`.
6. Wynik: zdarzenia/s relay, zdarzenia/s `events`, opóźnienie e-maila; `docker stats` CPU workera vs PG/ES/Varnish/store
   (co jest wąskim gardłem). Powtórzyć z 2 i 4 replikami workera (`docker compose up -d --scale worker=4`).

**P2. Puchnięcie `messenger_messages` (A9).** W trakcie P1 co 30 s: `SELECT n_live_tup,n_dead_tup,last_autovacuum
FROM pg_stat_user_tables WHERE relname='messenger_messages'`, `pg_relation_size` tabeli i indeksu,
`EXPLAIN (ANALYZE, BUFFERS) SELECT * FROM messenger_messages WHERE queue_name='outbox' AND (delivered_at IS NULL OR
delivered_at < now()-interval '300 s') AND available_at <= now() ORDER BY available_at LIMIT 1 FOR UPDATE SKIP LOCKED;`
(w transakcji z ROLLBACK) – czy liczba odwiedzonych buforów rośnie z serią.

**P3. Łańcuch reindeksu kategorii (A2, A6).** Wybrać kategorię z największym poddrzewem
(`SELECT count(*) …catalog_product WHERE primary_category_id IN (poddrzewo)`), `PUT /api/v1/admin/categories/{id}`
ze zmianą nazwy; co 30 s `SELECT next_batch, finished_at FROM catalog_category_reindex ORDER BY … DESC LIMIT 1`
(partie/min → produkty/s), `varnishstat MAIN.n_object` przed/po (pełne czyszczenie) i czas trwania transakcji partii
(`SELECT now()-xact_start FROM pg_stat_activity WHERE query LIKE '%catalog_category_reindex%'`).

**P4. Rozkład czasu pełnego reindeksu (A4).** `pg_stat_statements_reset()`, `GET _nodes/stats/indices/indexing`,
`catalog:reindex --no-debug` na medium (lub 200 tys. produktów); potem: suma `total_exec_time` zapytań reindeksu,
przyrost `indexing.index_time_in_millis` + `bulk` w ES, czas ścienny → reszta = PHP+sieć. Równolegle `docker stats`
(CPU kontenera api). Opcjonalnie: dwa procesy na rozłącznych zakresach id (prowizorycznie nie da się bez zmiany kodu –
wtedy tylko rozkład czasu).

**P5. Purge przy zamówieniach (A7).** k6 checkout (np. 2 zamówienia/s przez 10 min): liczba `StockLevelChanged`
(`SELECT count(*) …` z outboxu przy zatrzymanym workerze albo log handlera), `MAIN.n_obj_purged` na minutę, trafienia
Varnish dla tras list (`varnishncsa`/`MAIN.cache_hit` vs `cache_miss`) w porównaniu z przebiegiem bez zamówień.

**P6. Generator i komendy w dev (A8, A12).** `/usr/bin/time -v php bin/console ordering:read-models:rebuild` z i bez
`--no-debug` (max RSS); dla generatora czasy kroków z logu `bin/generate-demo` i `pg_stat_wal` (wal_bytes) dla kroku
produktów i `demo-data:stock`; `pg_stat_activity` – czas jednej transakcji `demo-data:stock`.

## 6. Tabela znalezisk

| # | Znalezisko | Waga | Dowód | Typ | Koszt naprawy |
|---|---|---|---|---|---|
| A1 | Jeden proces i jedna FIFO dla e-maili i projekcji; outbox ma ścisły priorytet | Wysoka | KOD + SZACUNEK | architektura/konfiguracja | S (repliki) / M (podział kolejek) |
| A2 | Brak wsadów: 1 zdarzenie = 1 zapis ES + 1 purge + 1 revalidate; łańcuch kategorii pojedynczo | Wysoka | KOD + SZACUNEK | kod | M |
| A3 | Masowe zmiany cen/promocji niewykonalne (limit 1000 wariantów, brak bulk) i kosztowne (godziny) | Wysoka | KOD + SZACUNEK | architektura/produkt | L |
| A4 | Pełny reindex szeregowy jednowątkowy (~3,3 h dla 10 mln); zapisy w trakcie giną | Wysoka | KOD + SZACUNEK | kod/architektura | M |
| A5 | Brak alertu na zator `events`; wolny worker niewidoczny | Wysoka | KOD | obserwowalność | S |
| A6 | Zmiana kategorii czyści cały cache 2×; łańcuch godzinami | Wysoka | KOD + SZACUNEK | kod | M |
| A7 | Purge list przy każdym przejściu progu stanu wychładza gorące listy | Średnia | KOD + HIPOTEZA | architektura cache | S–M |
| A8 | Profilowanie DBAL w dev trzyma każde zapytanie → OOM długich komend | Średnia | KOD + SZACUNEK | konfiguracja | S |
| A9 | Tabela kolejek: 5 RTT/wiadomość, UPDATE kolumny indeksu, bloat przy seriach | Średnia | KOD + HIPOTEZA | konfiguracja | S |
| A10 | SMTP synchronicznie w workerze projekcji | Średnia (Brevo) | KOD + SZACUNEK | architektura | w A1 |
| A11 | Wygaszanie zamówień/płatności | Niska | KOD | – | – |
| A12 | Generator: ładowanie z indeksami, `stock` w jednej transakcji, verify szeregowo | Średnia/Niska | KOD + HIPOTEZA | kod | M |
| A13 | Rewalidacja ISR do jednego poda | Niska (dziś) | KOD | architektura | – |
