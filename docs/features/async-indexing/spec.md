# ASYNC-INDEXING (PERF-6): zdarzenia i operacje masowe

Epik PERF-6 programu wydajności (`docs/features/performance-program/plan.md`). Zdarzenia produktu są dziś obsługiwane po
jednym w jednym procesie, e-maile czekają w tej samej kolejce za reindeksami, reindeks kategorii idzie produkt po
produkcie, a pełna przebudowa indeksu 10 mln produktów trwa 7,27 h w jednym procesie i jest najdłuższym krokiem każdego
wdrożenia na worf.pl. Funkcja przenosi indeksowanie na paczki obsługiwane przez kilka procesów, rozdziela kolejki według
rodzaju pracy i daje alert na zaległość kolejek. Zakres funkcjonalny sklepu i panelu się nie zmienia.

Status: **szkic do akceptacji właściciela**. Z poprzedniej wersji tej specyfikacji zostają zaakceptowane D1–D5
(2026-11-26, pod warunkami: rozwiązanie możliwie proste, tryb synchroniczny i asynchroniczny dzielą logikę indeksowania,
logika domenowa Catalog nie trafia do `Shared`). Decyzje D6–D15 czekają na akceptację razem z całym projektem.

Zakres zmian: `apps/api` (Catalog, Pricing, Inventory, Ordering, Backoffice, cienka warstwa w Shared),
`infra/docker-compose.yml`, `infra/k8s` (chart, cele `reindex`, `generate`, `open`, `smoke`), `apps/api/bin/generate-demo`,
`load/`, dokumentacja z sekcji „Dokumentacja”.

## Zasadność przyspieszenia (ocena 2026-11-28)

Rekomendacja: **nie przyspieszać PERF-6.** Implementacja rusza po wynikach trzech bieżących epików z worf.pl i zgodzie
właściciela, w kolejności niżej. Jedna mała zmiana jest potrzebna już teraz, ale poza PERF-6.

| Co | Czy blokuje teraz | Ocena |
|---|---|---|
| Pełny reindeks 10 mln: 7,27 h | **tak, przez limit czasu**: CronJob `reindex` ma `activeDeadlineSeconds` 21 600 (6 h), więc `make -C infra/k8s reindex` po wdrożeniu modelu R (nowa definicja indeksu, PERF-2B, M7) przerwie się przed końcem | Do naprawy teraz, poza PERF-6: osobny mały PR z limitem dla 10 mln z zapasem (np. 12 h). Sam czas 7,3 h nie blokuje, bo reindeks jest potrzebny tylko przy zmianie definicji indeksu albo po generowaniu, a nie po każdym wdrożeniu. |
| Produkt utworzony w trakcie przebudowy kończy ją błędem (#979) | nie | Ryzyko operacyjne w oknie przebudowy (rzadkiej). Wystarcza zasada „nie tworzyć produktów w oknie”, jak dziś w komunikacie celu `reindex`. Pełne rozwiązanie (D8) w PERF-6, fala A. |
| W19 (≤ 60 min) | nie | Wąskim gardłem jest odczyt z dysku Postgresa (H1, H5). PERF-1 zmienia jego pamięć, więc tempo przebudowy po PERF-1 trzeba najpierw zmierzyć na lokalnym `server`. Przed tym pomiarem nie wiadomo, ile z W19 zostaje do zrobienia. |
| W16–W18 (zdarzenia, 100 tys. zmian cen, przeniesienie działu) | nie | Demo ma mały ruch zapisów, a W1, W8 i W9 (odczyt) są dziś niespełnione i ważniejsze (PERF-2B, PERF-3, PERF-5). Zaległość zdarzeń i e-maile za reindeksami są widoczne dopiero przy seriach zapisów. |
| Unieważnienie cache przed widocznością zmiany w ES (stara cena na listingu do 60 s) | nie | Błąd poprawności o małym skutku, przy `refresh_interval` 5 s częstszy. Naprawa (D9) w PERF-6, fala B. |
| W13 | nie | Zapisy nie gubią zmian (wersje, alias przebudowy). Zostaje do sprawdzenia w pomiarach PERF-6 i PERF-9. |

Kolejność po wznowieniu:

1. Teraz, poza PERF-6: limit czasu CronJoba `reindex`.
2. Pomiar przebudowy 10 mln na lokalnym pełnym profilu `server` po PERF-1 (czas przebiegu synchronicznego, odczyty
   z dysku na dokument). Ten pomiar rozstrzyga, czy fala A jest potrzebna i w jakim zakresie.
3. Fala A (przebudowa: zadania PERF-6-4 do PERF-6-7 i PERF-6-9 z D8 i D13), jeśli po PERF-1 przebudowa nadal trwa ponad
   60 min.
4. Fala B (zdarzenia: PERF-6-2, PERF-6-3, PERF-6-8), gdy na kolejność wejdą wymagania z zapisami (W11, W14, W16–W18).
   Może iść równolegle z PERF-8.

## Cel i kryterium

Kryterium epiku (plan programu, PERF-6): na profilu `server` spełnione W16–W19, a po każdym scenariuszu z zapisami W13
(kolejność zdarzeń produktu bez znaczenia dla wyniku, brak utraconych zmian, 0 wiadomości w `failed`). W19a (zapis
w trakcie przebudowy jest w nowym indeksie) zostaje spełnione także dla przebudowy w kilku procesach i przy tworzeniu
produktów w trakcie.

| ID | Wymaganie (`server`) | Dziś |
|---|---|---|
| W13 | po pomiarze z zapisami: indeks zgodny z bazą dla próby produktów, 0 w `failed`, stany i zamówienia spójne | niezmierzone po zapisach |
| W16 | zmiana ceny albo stanu widoczna w sklepie: p95 ≤ 10 s, p99 ≤ 60 s przy 50 zmianach/s | niezmierzone; szacunek 12–40 zdarzeń/s na proces |
| W17 | 100 tys. zmian cen: sklep zgodny w 15 min; e-mail zamówienia złożonego w trakcie w 60 s | niezmierzone; szacunek godzin kolejki, e-maile za reindeksami |
| W18 | zmiana nazwy albo przeniesienie działu (20% katalogu, 2 mln produktów): zakończone w 30 min, W1 w trakcie | niezmierzone; szacunek godzin (łańcuch szeregowy produkt po produkcie) |
| W19 | pełny reindeks 10 mln ≤ 60 min (ok. 2800 dok./s) | 7,27 h, 382–393 dok./s (2026-11-26) |
| W19a | zapis w trakcie przebudowy obecny w nowym indeksie | spełnione przez PERF-2B (M5) dla przebudowy synchronicznej; produkt utworzony w trakcie kończy ją błędem (#979) |

Kryterium 12 poprzedniej wersji (T = 800 dok./s, C = 3 h 45 min przy 4 replikach) zastępuje W19 (D12). Specyfikacja nie
twierdzi, że wymagania są spełnione: rozstrzyga pomiar (sekcja „Pomiar i akceptacja”). Gdy pomiar pokaże, że wymaganie
jest nieosiągalne na obecnym serwerze, epik kończy się raportem z wariantami i decyzją właściciela (zasada programu).

## Stan wyjściowy

### Zmierzone

- **Przebudowa 10 mln na worf.pl** (`docs/reports/async-indexing-measurement-2026-11-26.md`, pkt 6;
  `docs/reports/server-profile-baseline-worf-2026-11-26.md`, §2): 7,27 h, tempo płaskie 393 → 382 dok./s. Postgres czyta
  z dysku ok. 4,4 tys. IOPS (szczyt 5,9 tys.), 11,4 odczytu z dysku na dokument (1 mln: 5,2), a Elasticsearch, Postgres
  i PHP są prawie bezczynne procesorowo (Job 0,1 rdzenia). Wąskim gardłem jest odczyt z Postgresa (losowy dostęp:
  korelacja kolejności `id` z układem fizycznym tabel ok. 0), nie zapis do ES ani CPU (H1, mocno poparta). Nie wiadomo,
  czy 4,4–5,9 tys. IOPS to limit dysku VPS, ani jakie jest opóźnienie odczytu.
- **Lokalnie (1 mln, inny sprzęt):** składowe paczki 500: odczyt produktów 33%, fakty ofert (`GetProductOffers` w częściach)
  52%, zapis 13%; 2 i 4 równoległe procesy dają 1,8× i 2,3× tempa jednego. Rozmiar paczki: płasko między 250 a 1000.
- Model R z PERF-2B (`main`, #974): jeden shard, sortowanie indeksu, `refresh_interval` z `SEARCH_REFRESH_INTERVAL`
  (w `prod` 5 s warunkowo, do potwierdzenia pomiarem W16 w tym epiku).

### Z kodu (rewizja `7fd4d9d2`)

- `messenger.yaml`: `outbox` (Doctrine, wszystkie `DomainEvent`), `events` (RabbitMQ, jedna kolejka dla wszystkich
  zdarzeń, exchange `fanout`), `scheduled`, `payment_simulator`, `promotions`, `jobs` (Doctrine), `failed`. Workery:
  `worker` = `outbox events` (jedna replika; `outbox` ma pierwszeństwo, więc przy serii zdarzeń `events` stoi),
  `worker-commands` = `payment_simulator scheduled promotions jobs`.
- Handlery na `events` (wszystkie zdarzenie po zdarzeniu): para reindeks + unieważnienie dla `VariantPriceChanged`
  i `PromotionPricesChanged` (Pricing), `StockLevelChanged` (Inventory), `ProductCreated`/`ProductChanged` (Catalog);
  `Send*` dla pięciu zdarzeń zamówienia (Ordering, SMTP synchronicznie, `NotificationSender::sendOnce`); drzewo kategorii
  (`ReindexSubtreeOnCategoryTreeChange`, `InvalidateStorefrontOnCategoryTreeChange`); `SyncStockRecordsOnProductChange`,
  `RepriceOnTaxClassChange`. Każdy reindeks to `GetVariant`, `ProductIndexing::reindex` (odczyt migawki, fakty ofert, jeden
  `_bulk` z dwiema pozycjami: alias przebudowy i alias), a każde unieważnienie osobny PURGE Varnisha i osobne
  `/api/revalidate` (oba przyjmują wiele tagów: `HttpStorefrontCache::invalidateTags`, sklep do 100 tagów).
- Unieważnienie idzie zaraz po zapisie dokumentu, przed jego widocznością w wyszukiwarce (`refresh_interval`): listing
  wyrenderowany w tym oknie trafia do Varnisha ze starą ceną na `max-age` 60 s (`ListProductsController`).
- Reindeks kategorii: łańcuch `ReindexCategoryBatch` na `jobs` (szeregowy), 500 produktów na partię, każdy produkt osobnym
  `ProductIndexing::reindex` w transakcji `command.bus` z blokadą przebiegu; na końcu `invalidateCatalog()`.
- Przebudowa: `ProductSearchIndexRebuilder` (jeden proces, kursor po `id`, paczki 500, `verify` z dokładną liczbą
  dokumentów). Zapis pojedynczy trafia przez alias `<alias>-rebuild` do nowego indeksu (PERF-2B, M5,
  `docs/features/search/spec.md`). Produkt utworzony w trakcie z identyfikatorem poniżej kursora albo po ostatniej
  paczce kończy przebudowę błędem weryfikacji (#979); runbook każe wtedy powtórzyć przebudowę, a komunikat celu
  `make -C infra/k8s reindex` mówi, że produktu nie należy tworzyć w trakcie. `DbalCatalogReset` robi `TRUNCATE` przed
  wzięciem blokady przebudowy, `infra/k8s/scripts/smoke.sh` też usuwa produkty bez blokady (#979).
- Alert kolejek (`app:messaging:check`, CronJob `queue-check`): wiek najstarszej gotowej wiadomości tylko kolejek
  Postgresa (próg 10 min) i `failed`; kolejki RabbitMQ mają na pulpicie tylko długość.
- CronJob `reindex` ma `activeDeadlineSeconds` 21 600 (6 h), mniej niż ostatnia przebudowa 10 mln.

### Sprawdzone zachowanie zależności (wersje z `composer.lock` i compose)

- **Symfony Messenger 8.1.12** (kod z wolumenu vendor): `BatchHandlerInterface` z `BatchHandlerTrait` (rozmiar wsadu,
  `getIdleTimeout` 1 s); wiadomość czeka niepotwierdzona do opróżnienia wsadu, każdą można potwierdzić albo odrzucić
  osobno (`Acknowledger`), a odrzucona idzie zwykłą ścieżką ponowień. Worker opróżnia wsady przy bezczynności
  (`flush(false)`, `getIdleTimeout`), wymusza opróżnienie wsadu, którego ostatnia wiadomość czeka 30 s (`Worker::run`,
  `flush(30.0)`, stała), i przy zatrzymaniu. Wiadomość z kilkoma handlerami, z których jeden jest wsadowy, jest
  potwierdzana po wszystkich (`DeferredBatchMessageQueue::combineAcks`). `messenger:consume --fetch-size=N` pobiera do N
  wiadomości naraz (Doctrine: jedno `SELECT … LIMIT N FOR UPDATE SKIP LOCKED`; AMQP: do N `basic_get`).
- **Routing Messengera** (`SendersLocator`): wiadomość trafia do nadawców klasy, rodziców i interfejsów naraz, więc trasa
  klasy obok trasy `DomainEvent` dałaby dwie kopie; trasy nie da się „nadpisać” klasą.
- **AMQP:** Symfony ustawia przy publikacji `timestamp` (czas w sekundach), a RabbitMQ 4.4.0 (obraz
  `rabbitmq:4.4.0-management`) podaje w API zarządzania `head_message_timestamp` kolejki klasycznej równe tej wartości
  dla najstarszej wiadomości (eksperyment 2026-11-28 na kontenerze z tym obrazem: opublikowana wiadomość z `timestamp`
  sprzed 120 s, odczyt `head_message_timestamp` zgodny co do sekundy). Typu istniejącego exchange nie da się zmienić
  ponowną deklaracją (RabbitMQ odrzuca deklarację z innym typem).
- **Elasticsearch 9.4.0** (dokumentacja parametru `refresh`): `refresh=wait_for` na indeksie z `refresh_interval: -1`
  czeka bez końca, aż coś innego wywoła odświeżenie; po `index.max_refresh_listeners` (domyślnie 1000) oczekujących żądań
  na shardzie kolejne wymusza odświeżenie. Dlatego `wait_for` nie może obejmować pozycji do indeksu w budowie.

## Decyzje

### Zaakceptowane (2026-11-26)

- **D1. Klucz typu dokumentu w wiadomości paczki** (`documentType: 'product'`), rejestr typów w kodzie; nieznany klucz to
  błąd trwały. Wiadomość nie niesie nazwy klasy ani indeksu.
- **D2. Pominięcie outboxa tylko dla paczek przebiegu.** Paczki idą wprost do RabbitMQ (przebieg jest powtarzalny);
  zdarzenia domenowe zostają przy outboxie (zmiana i zdarzenie w jednej transakcji).
- **D3. Ogólny mechanizm paczek, jedna implementacja `product`.**
- **D4. `catalog:reindex` domyślnie synchroniczny; asynchroniczny przez `--async`.** Testy i `make seed` nie wymagają
  workera `indexing`; `bin/generate-demo` i `make -C infra/k8s reindex` wołają `--async --wait`.
- **D5. Granice modułów:** w `Shared` tylko wiadomość `IndexDocuments`, port `DocumentIndexer`, rejestr i handler
  wybierający indeksator; wiedza o indeksie, dokumencie, przebiegu i aliasie zostaje w Catalog. Deptrac bez edycji
  `deptrac.yaml`; gdy się nie da, zadanie wraca do właściciela.

### Do akceptacji (z rekomendacją)

- **D6. Scenariusz W17: 100 promocji po 1000 wariantów startujących w tej samej minucie.** To 100 tys. zmian cen
  istniejącym mechanizmem (`Promotion::MAX_VARIANTS` = 1000), bez nowego API zbiorczego; e-mail liczony od zatwierdzenia
  zamówienia (`occurredAt` zdarzenia) do udanego wysłania (log handlera). Alternatywa: 100 tys. pojedynczych zmian
  przez API panelu (przy 50 zapisach/s trwa 33 min, więc nie mieści się w 15 min z definicji). **Rekomendacja: promocje.**
- **D7. Limit 1000 wariantów promocji (P10) zostaje.** Zbiorcza zmiana cen albo promocja działowa to nowa funkcja, poza
  programem. **Rekomendacja: bez zmian.**
- **D8. Kontrola kompletności przebudowy przez uzgodnienie zbioru identyfikatorów (#979).** Na końcu wypełniania
  finalizacja porównuje posortowany strumień identyfikatorów produktów z Postgresa z posortowanym strumieniem pola `id`
  z nowego indeksu (sekcja „Uzgodnienie zbioru dokumentów”). Brakujący w indeksie produkt jest dopisywany zwykłym
  zapisem z aktualnego stanu, a dokument bez produktu w bazie kończy przebudowę błędem. Wykrywa każdy utracony
  i nadmiarowy dokument, a produkt utworzony w trakcie nie przerywa przebudowy. Bez zmiany persystencji. Alternatywy:
  rejestr produktów utworzonych w trakcie przebudowy (nowa tabela zapisywana w transakcji tworzenia produktu) albo
  kolumna czasu utworzenia: obie zmieniają persystencję i wiążą tworzenie produktu z przebudową. **Rekomendacja:
  uzgodnienie zbioru; zastępuje dzisiejszą równość liczby dokumentów i zakaz tworzenia produktów w trakcie.**
- **D9. Unieważnienie cache dopiero po widoczności zmiany w wyszukiwarce.** Zapis wsadu idzie dwoma `_bulk` po kolei:
  najpierw pozycje do aliasu przebudowy (bez odświeżania), potem do aliasu z `refresh=wait_for`; PURGE i rewalidacja
  dopiero po odpowiedzi drugiego. Kolejność „najpierw alias przebudowy” zachowuje W19a (sekcja „Zapis wsadu”).
  Koszt: worker czeka na odświeżenie do `refresh_interval` na wsad, więc przepustowość procesu zależy od rozmiaru wsadu,
  a liczba replik od pomiaru. Alternatywa: unieważnienie wiadomością opóźnioną o `refresh_interval` (nowa wiadomość
  i trasa, worker nie czeka). **Rekomendacja: `wait_for`.**
- **D10. Wersja dokumentu z zegara bazy.** Wersja (`external_gte`) to dziś zegar procesu PHP sprzed odczytu. Przy kilku
  replikach na różnych węzłach różnica zegarów może dać starszemu odczytowi wyższą wersję i trwale zgubić zmianę do
  następnej zmiany produktu. Wersja z `clock_timestamp()` Postgresa (jedno źródło, jedno zapytanie na wsad i paczkę) nie
  zależy od węzła. Alternatywa: zegar procesu z zapisanym ograniczeniem „wszystkie procesy indeksujące na jednym węźle”
  (dziś worf.pl ma jeden węzeł). **Rekomendacja: zegar bazy** (nie ogranicza skalowania poziomego workerów).
- **D11. Wartość `SEARCH_REFRESH_INTERVAL` w `prod`** rozstrzyga pomiar W16 i W1 tego epiku (1 s i 5 s). Właściciel
  wybiera po raporcie; do tego czasu zostaje 5 s.
- **D12. W19 zastępuje kryterium 12 poprzedniej wersji.** Program wymaga pełnego reindeksu do 60 min; 3 h 45 min było
  celem pośrednim sprzed zatwierdzenia W1–W20. **Rekomendacja: W19.**
- **D13. Kolejność odczytu pełnej przebudowy wybiera pomiar na lokalnym `server`.** Kandydaci: (a) kursor po `id` jak dziś,
  (b) kolejność fizyczna tabeli produktów (zakresy bloków sterty, `ctid`, natywny skan zakresu TID Postgresa): kolejne
  produkty czytane są z sąsiednich stron, a ich warianty, ceny i stany, wstawione przez generator w tej samej kolejności,
  też (hipoteza do pomiaru: korelacji tabel podrzędnych z kolejnością fizyczną produktów nie mierzono). Producent ma obie
  kolejności do czasu pomiaru; przegrana usuwana jest w osobnym PR po pomiarze. Poprawność nie zależy od kolejności
  (uzgodnienie zbioru, D8). **Rekomendacja: obie do pomiaru.**
- **D14. Topologia workerów** (sekcja „Kolejki i workery”): osobne Deploymenty dla relaya outboxa, projekcji zdarzeń,
  e-maili, komend i indeksowania; liczba replik każdego to ustawienie chartu, wartości domyślne z pomiaru, budżet pamięci
  węzła z PERF-1 (zadanie 4). **Rekomendacja: jak w sekcji.**
- **D15. Reindeks kategorii w trakcie pełnej przebudowy** dzieli z nią kolejkę `indexing`, więc jest wolniejszy (poprawny:
  zapisy trafiają też do nowego indeksu). W18 mierzymy bez równoległej przebudowy. Alternatywa: osobna kolejka
  z pierwszeństwem. **Rekomendacja: wspólna kolejka.**

## Rozwiązanie

### Kolejki i workery

| Worker (Deployment i usługa compose) | Transporty | Repliki (chart) | Uwagi |
|---|---|---|---|
| `worker-relay` | `outbox` (`--fetch-size`) | `workers.relay.replicas` | tylko relay do RabbitMQ; `SKIP LOCKED` pozwala na kilka replik |
| `worker` | `events` (`--fetch-size`) | `workers.events.replicas` | projekcje: wsadowe odświeżanie produktów, drzewo kategorii, synchronizacja stanów |
| `worker-notifications` | `notifications` | `workers.notifications.replicas`, domyślnie 1 | e-maile zamówień; nie czeka za projekcjami |
| `worker-commands` | `payment_simulator scheduled promotions jobs` | 1 | bez zmian; `jobs` niesie już tylko producenta reindeksu kategorii (krótkie kroki) |
| `worker-indexing` | `indexing_finalize indexing` | `workers.indexing.replicas` | paczki przebudowy i reindeksu kategorii, finalizacja |

- **Exchange zdarzeń typu `topic`** (nowa nazwa, np. `domain_events`, bo typu istniejącego `events` nie da się zmienić).
  Relay publikuje każde zdarzenie z kluczem trasy z nazwy klasy (`App.Ordering.Domain.OrderPlaced`), bez wiedzy
  o modułach. Kolejka `events` wiąże `#` (wszystko), kolejka `notifications` wiąże klucze klas, które mają handlery
  `fromTransport: 'notifications'` (pięć zdarzeń zamówienia). Zdarzenie zamówienia trafia też do `events` i jest tam
  potwierdzane bez handlera (`allow_no_handlers`); zdarzenia produktu nie trafiają do `notifications`, więc e-mail nie czeka
  za nimi. Publikacja zostaje obowiązkowa (`RoutedAmqpTransport`): zdarzenie bez trasy jest błędem relaya, nie zgubioną
  wiadomością. Test: zbiór kluczy kolejki `notifications` równa się zbiorowi klas wiadomości handlerów z tego transportu
  (z kontenera), a każdy handler `Send*` ma `fromTransport: 'notifications'`.
- Kolejność e-maili jednego zamówienia: jeden konsument `notifications` zachowuje kolejność publikacji. Kilka replik
  relaya może zamienić kolejność dwóch zdarzeń opublikowanych w tej samej chwili; zdarzenia jednego zamówienia
  (złożenie, opłacenie, wysyłka) dzielą sekundy, więc to ryzyko przyjęte (sekcja „Ryzyka”).
- `--fetch-size` relaya i projekcji to ustawienia chartu i compose (domyślne z pomiaru). Każdy transport poza `failed`
  ma konsumenta w compose i w chartcie (test chartu, także przypadek negatywny); nowy transport i worker trafiają do
  compose i chartu w tym samym PR co definicja w `messenger.yaml` (`infra/k8s/CLAUDE.md`).

### Wsadowe odświeżanie produktów

- Pary handlerów reindeks + unieważnienie zastępuje jeden handler wsadowy na zdarzenie i moduł, w module zdarzenia
  (granice jak dziś: Pricing i Inventory wołają API Catalog): `RefreshProductsOnPriceChange` (`VariantPriceChanged`,
  `PromotionPricesChanged`), `RefreshProductsOnStockLevelChange`, `RefreshProductsOnProductChange` (`ProductCreated`,
  `ProductChanged`). Każdy implementuje `BatchHandlerInterface` (`BatchHandlerTrait`): zbiera wiadomości, wyznacza
  identyfikatory produktów (warianty jednym zapytaniem `GetVariantSummaries`, nie `GetVariant` na zdarzenie), usuwa
  powtórzenia i woła `ProductIndexing::reindexMany(productIds)`, a potem `StorefrontCache::invalidateProducts(productIds)`.
- Wsad opróżnia się po osiągnięciu rozmiaru (`app.events.batch_size`, domyślnie z pomiaru, start 100), gdy najstarsza
  wiadomość wsadu czeka dłużej niż `app.events.batch_max_wait` (start 1 s; sprawdzane przy każdej wiadomości tego handlera),
  przy bezczynności workera (1 s) i najpóźniej po 30 s (Worker). Zdarzenie rzadkie w strumieniu innych (np. `ProductChanged`
  przy burzy cen) może więc czekać do 30 s; mierzy to W16.
- Źródła `seed` i `tax_reconciliation` są pomijane jak dziś. `ProductCreated`/`ProductChanged` produktu, którego nie ma
  w bazie, jest błędem trwałym tej wiadomości jak dziś (pozostałe wiadomości wsadu potwierdzone).
- **Wynik każdej wiadomości osobno:** produkt zapisany albo przestarzały (409) to potwierdzenie wszystkich wiadomości,
  które go dotyczą; błąd przejściowy pozycji (`SearchUnavailable`, `OfferFactsUnavailable`) to odrzucenie tych wiadomości
  (ponowienie transportu `events`); błąd trwały pozycji to odrzucenie z `UnrecoverableMessageHandlingException`
  (`failed`). Błąd całego żądania (ES, Varnish, sklep niedostępne) odrzuca cały wsad. Powtórka jest nieszkodliwa (wersja,
  idempotentne unieważnienie).
- `ProductIndexing::reindexMany` (Catalog Application): wersja z zegara bazy (D10) przed odczytem, migawki jednym
  zapytaniem (`ProductSearchSource::findMany`, ta sama definicja co `page` i `find`), fakty ofert jednym wywołaniem dla
  całego wsadu (`ProductOfferFacts`, dziś `QueryBusProductOfferFacts` dzieli na części po `GetProductOffers::MAX_PRODUCTS` = 48; ścieżka wewnętrzna bez tego limitu), budowa dokumentów, zapis
  `ProductIndexer::indexMany`. `reindex(productId)` zostaje jako `reindexMany([productId])`.
- `StorefrontCache::invalidateProducts` dzieli tagi na żądania po najwyżej 100 (limit `/api/revalidate`): jeden PURGE
  z wieloma `xkey` i jedna rewalidacja na część.
- Handlery drzewa kategorii, `SyncStockRecordsOnProductChange` i `RepriceOnTaxClassChange` zostają pojedyncze.

### Zapis wsadu i widoczność (D9)

- `ProductIndexer::indexMany(documents)`: (1) `_bulk` z pozycjami `index` do `<alias>-rebuild` (`require_alias`,
  `external_gte`), 404 aliasu i 409 pomijane jak dziś; (2) dopiero po odpowiedzi `_bulk` do `<alias>` z `refresh=wait_for`.
  Wynik każdej pozycji jak w `docs/features/search/spec.md` („Zapisy w trakcie przebudowy”).
- **Dlaczego W19a zostaje spełnione przy dwóch żądaniach** (dziś jedno żądanie rozwiązuje oba aliasy w jednym stanie
  klastra): przepięcie aliasu jest atomowe, a żądanie (2) zaczyna się po zakończeniu (1). Jeśli (1) trafiło do nowego
  indeksu przez alias przebudowy, dokument tam jest. Jeśli (1) dostało 404, bo aliasu przebudowy już nie ma, przepięcie
  zaszło przed (1), więc także przed (2), a (2) zapisuje przez `<alias>` do nowego indeksu. Jeśli aliasu przebudowy nie
  ma, bo przebudowa jeszcze nie ruszyła, odczyt przebudowy zacznie się później i zobaczy zmianę. Jedyna kolejność, która
  gubiłaby zapis (pozycja główna do starego indeksu przed przepięciem, pozycja przebudowy po nim), wymagałaby (2) przed
  (1). Test kolejności wywołań i testy W19a z PERF-2B obejmują obie ścieżki.
- `wait_for` obejmuje tylko indeks aliasu (odświeżany co `refresh_interval`), nigdy indeks w budowie (`-1`, czekałby bez
  końca). Unieważnienie Varnisha i ISR po odpowiedzi (2), więc listing wyrenderowany po PURGE widzi nową wersję.

### Wersja dokumentu (D10)

- `ProductIndexing::version` bierze czas z `SELECT clock_timestamp()` połączenia Catalog tuż przed odczytem migawek
  (port `Clock` dla wersji z adapterem DBAL); to samo w paczce przebudowy (przed `findMany`) i w przebudowie synchronicznej
  (przed `page`). Wartość w mikrosekundach jak dziś, więc dokumenty zapisane przed wdrożeniem mają porównywalne wersje.
- Reguła „odczyt rozpoczęty później ma wyższą wersję i widzi co najmniej te same dane” opiera się wtedy na jednym zegarze
  serwera bazy niezależnie od liczby węzłów workerów.

### Paczki indeksowania (wachlarz)

Wspólny mechanizm pełnej przebudowy i reindeksu kategorii (D1–D3, D5). Treść z poprzedniej wersji tej specyfikacji,
dostosowana do PERF-2B.

- `IndexDocuments { documentType, runId, batch, ids }`: klucz typu, przebieg, numer paczki, 1..N identyfikatorów
  (N = rozmiar paczki, najwyżej 5000). Nie niesie indeksu: indeks docelowy (`target_index` przebiegu przebudowy) albo
  zapis przez aliasy (przebieg kategorii) handler czyta z wiersza przebiegu. Dane czyta handler w chwili obsługi.
- `DocumentIndexer` (`type()`, `index(IndexDocuments)`), rejestr `DocumentIndexers` (nieznany klucz: `UnknownDocumentType`,
  błąd trwały), `IndexDocumentsHandler` w Shared. `ProductDocumentIndexer` (Catalog) odrzuca zły UUID, pustą listę i listę
  ponad 5000 (błąd trwały), czyta przebieg (bez blokady), kończy bez zapisu, gdy go nie ma albo nie przyjmuje paczek,
  wczytuje migawki `findMany`, zapisuje i rozlicza paczkę.
- Zapis paczki przebudowy: `ProductBatchIndexer::write(index, snapshots, version)` (wydzielona treść pętli `fill`, wersja
  od wołającego, sprzed odczytu) do indeksu po nazwie fizycznej, przez `SearchIndexMaintenance::write` z ponowieniami
  pozycji przejściowych. Paczka przebiegu kategorii zapisuje przez `ProductIndexer::indexMany` (oba aliasy, jak wsad
  zdarzeń) i unieważnia produkty paczki.
- Rozmiar paczki: `SEARCH_INDEX_BATCH_SIZE` (parametr `app.search.indexing_batch_size`, domyślnie 500, zakres `1..5000`,
  błąd startu z nazwą zmiennej), nadpisywany opcją `--batch-size` przebudowy i zapisany w przebiegu.
- Szyna `indexing.bus` bez `doctrine_transaction` (tylko `CorrelationIdMiddleware`): handler paczki i finalizacji nie trzyma
  transakcji bazy podczas operacji na ES (test: `Connection::isTransactionActive()` fałsz w trakcie zapisu). Rozliczenia
  i przejścia stanu to krótkie, osobne transakcje.
- Transport AMQP `indexing` (kolejka trwała, potwierdzenie publikacji, `RoutedAmqpTransport`, serializer JSON,
  `prefetch_count` 1): ponowienia błędu przejściowego 1, 3, 9, 27, 81 s, potem `failed`; błąd trwały od razu do `failed`.
  Transport Doctrine `indexing_finalize` dla `FinalizeSearchRebuild` (wysyłana w transakcji przejścia stanu przez
  `command.bus` z `BusNameStamp('indexing.bus')`; `redeliver_timeout` ponad najdłuższy etap finalizacji). Zadanie
  wprowadzające zaczyna od testu tego mechanizmu; jeśli `BusNameStamp` tak nie działa, finalizacja zostaje na
  `command.bus` i wykonuje operacje ES poza transakcją przez wydzieloną komendę (decyzja dopisana do specyfikacji).
- `worker-indexing`: `messenger:consume indexing_finalize indexing --time-limit=86400 --memory-limit=...`, nie obsługuje
  zdarzeń sklepu ani komend, więc długa kolejka przebudowy nie opóźnia webhooków, wygaszania zamówień ani e-maili.

### Przebieg i rejestr

Tabele `catalog_search_rebuild_run` i `catalog_search_rebuild_batch` (klucz `(run_id, batch)`), jak w poprzedniej wersji,
z kolumną `kind` (`rebuild`, `category`).

| Pole przebiegu | Znaczenie |
|---|---|
| `id`, `kind`, `document_type` | UUID, rodzaj przebiegu, klucz typu |
| `status` | `producing`, `running`, `finalizing`, `reconciled`, `activating`, `activated`, `succeeded`, `failed`, `aborted` (przebieg kategorii: `producing`, `running`, `succeeded`, `failed`, `aborted`) |
| `target_index`, `previous_index` | indeks budowany i poprzedni (tylko `rebuild`) |
| `category_id` | dział (tylko `category`) |
| `batch_size`, `total_batches`, `done_batches`, `documents` | rozmiar, liczba paczek (`null` do końca produkcji), liczniki |
| `read_order` | `id` albo `physical` (tylko `rebuild`, do czasu pomiaru D13) |
| `started_at`, `heartbeat_at`, `finished_at`, `failure` | czasy i pierwszy błąd trwały |

- **Wzajemne wykluczanie** (tylko `kind = rebuild`): przebudowa synchroniczna, asynchroniczna, `setup`, `reset`
  i każda operacja usuwająca produkty biorą blokadę doradczą `SearchIndexRebuildLock`, a potem sprawdzają brak aktywnego
  przebiegu przebudowy (status inny niż `succeeded`, `failed`, `aborted`). Start asynchroniczny zakłada wiersz pod blokadą
  i dopiero wtedy ją zwalnia; unikalny indeks częściowy „jeden aktywny przebieg przebudowy na typ” w bazie. Przebiegi
  kategorii nie wykluczają się z przebudową ani ze sobą (każda zmiana drzewa zakłada własny przebieg, zapisy idempotentne).
- **Rozliczenie paczki** (po udanym zapisie, jedna krótka transakcja): `INSERT` wiersza paczki `ON CONFLICT DO NOTHING`
  (powtórka kończy rozliczenie), `UPDATE` przebiegu (`done_batches + 1`, `documents + n`, `heartbeat_at`, warunek
  `status IN ('producing','running')`); gdy `total_batches` znane i `done = total` przy statusie `running`, warunkowe
  `running → finalizing` i wysłanie `FinalizeSearchRebuild` w tej samej transakcji (przebieg kategorii: `running →
  succeeded` i jedno `invalidateCatalog()` po zatwierdzeniu). Blokada wiersza przez `UPDATE` szereguje rozliczenia,
  więc komplet widzi dokładnie jedno. Bez `count(*)` po paczkach.
- **Koniec produkcji:** producent po ostatniej paczce w jednej transakcji ustawia `total_batches` i `producing → running`,
  a gdy `done = total`, od razu finalizuje. Pusty zbiór: `total_batches = 0`, finalizacja od razu.

### Pełna przebudowa w kilku procesach

1. **Start** (`catalog:reindex --async [--batch-size N] [--order id|physical] [--wait]`): blokada i sprawdzenie aktywnego
   przebiegu, `activeIndex`, sprzątanie osieroconych indeksów, `createIndex` (nowy indeks z aliasem przebudowy, PERF-2B),
   wiersz `producing`, zwolnienie blokady, produkcja.
2. **Produkcja:** producent czyta same identyfikatory i wysyła paczki na `indexing` z potwierdzeniem publikacji. Kolejność
   `id`: kursor `id > :after ORDER BY id`. Kolejność `physical`: kolejne zakresy bloków sterty `catalog_product`
   (`ctid >= '(a,0)' AND ctid < '(b,0)'`, skan zakresu TID; ostatni zakres bez górnej granicy, więc obejmuje bloki dopisane
   w trakcie). Wiersz przeniesiony w trakcie (aktualizacja) może być przeczytany dwa razy albo wcale: dwa razy jest
   nieszkodliwe, a zmiana po odczycie i tak trafia do nowego indeksu przez alias przebudowy, a brak wyłapuje uzgodnienie.
   Heartbeat co kilkadziesiąt paczek i kilkanaście sekund. Błąd publikacji: przebieg `failed`, nowy indeks usunięty.
3. **Paczki** obsługują repliki `worker-indexing` równolegle.
4. **Finalizacja** (handler `FinalizeSearchRebuild` na `indexing.bus`, etapy wznawialne, każde przejście statusu
   warunkowym `UPDATE … WHERE status = :widziany`, także do `failed`):

   | Status przy starcie | Co robi |
   |---|---|
   | `finalizing` | `refresh_interval` z ustawienia, `refresh`, zdrowie, skrót definicji, **uzgodnienie zbioru** (niżej); powodzenie `reconciled`, niezgodność `failed` (nowy indeks usunięty, alias bez zmian) |
   | `reconciled` | `activating` (zapis przed żądaniem) |
   | `activating` | czyta `activeIndex()`: poprzedni → `activate(target, previous)` (atomowo, zdejmuje alias przebudowy) i odczyt; już `target` → przełączony; inny albo nieczytelny → `failed`, oba indeksy zachowane (kod 2) |
   | `activated` | `deleteIndex(previous)` (404 to sukces), potem `succeeded`; błąd usunięcia to ostrzeżenie |
   | stan końcowy | koniec bez skutku |

   Dwie wiadomości finalizacji naraz (redelivery przy długim etapie, ręczne ponowienie): etapy idempotentne, przejścia
   warunkowe, alias przełączony najwyżej raz, `succeeded` nie nadpisany; dzierżawy nie dodajemy.
5. **`--wait`** odpytuje przebieg do stanu końcowego, wypisuje postęp (paczki, dokumenty, tempo) i kończy się błędem, gdy
   heartbeat nie rusza dłużej niż limit (np. brak workera). Kody wyjścia: 0 alias na nowym indeksie, 1 alias bez zmian,
   2 stan aliasu nieznany. Postęp loguje kanał `search_rebuild` (`documents_per_second`).
6. **Abort** (`catalog:reindex:abort [--force]`): tylko `producing`/`running`, warunkowe `UPDATE` na `aborted`, potem
   usunięcie `target_index`, jeśli nie wskazuje go alias; od `finalizing` odmowa; przebieg żywy (heartbeat młodszy niż
   30 min) wymaga `--force`. Paczka w trakcie zapisu podczas abortu kończy się rozliczeniem bez skutku; zapis po usunięciu
   indeksu może odtworzyć osierocony indeks bez aliasu, sprzątany przy następnym starcie.
7. Bez `--async` komenda działa jak dziś, z odmową przy aktywnym przebiegu; jej weryfikacja to także uzgodnienie zbioru
   (D8), więc produkt utworzony w trakcie nie przerywa przebudowy synchronicznej.

### Uzgodnienie zbioru dokumentów (D8, #979)

- Po `refresh` nowego indeksu finalizacja przechodzi naraz dwa strumienie rosnących identyfikatorów: Postgres
  (`SELECT id FROM catalog_product WHERE id > :after ORDER BY id LIMIT :n`) i nowy indeks (agregacja `composite` po polu
  `id` typu `keyword`, `size: 0`, strony po kilka tysięcy kluczem `after`; nie `search_after`, które przy sortowaniu
  indeksu po `sort_path` oceniałoby na każdej stronie wszystkie dokumenty). Kolejność tekstowa małych liter UUID
  w ES jest kolejnością `uuid` w Postgresie (stały format szesnastkowy; test także dla identyfikatora wielkimi literami
  w danych wejściowych).
- Identyfikator tylko w bazie: produkt bez dokumentu (utracony zapis paczki albo utworzony po odczycie swojego zakresu,
  przed obsługą zdarzenia). Zbierane w paczki i zapisywane do nowego indeksu z aktualnego stanu (`ProductBatchIndexer`,
  wersja sprzed odczytu), potem sprawdzane `_mget`/zapytaniem `ids`; brak po zapisie to `failed`.
- Identyfikator tylko w indeksie: sprawdzany w bazie po identyfikatorze (produkt mógł powstać po minięciu go przez
  strumień bazy); istnieje → zgodny, nie istnieje → dokument bez produktu → `failed` (katalog nie usuwa produktów, więc
  to błąd albo usunięcie spoza zasad).
- Wynik w logu przebiegu i raporcie: liczba dopisanych i sprawdzonych identyfikatorów. Koszt przy 10 mln: dwa strumienie
  po 10 mln kluczy (szacunek: minuty, część W19; mierzony).
- Operacje usuwające produkty (`DbalCatalogReset` w `catalog:seed-demo`/`make seed`, `TRUNCATE` w
  `infra/k8s/scripts/smoke.sh`) biorą blokadę przebudowy **przed** usunięciem i odmawiają przy aktywnym przebiegu
  przebudowy (test kolejności: blokada zajęta → nic nie usunięte).

### Reindeks kategorii (W18)

- Zmiana drzewa (`Revised`, `Moved`) zakłada przebieg `kind = category` (jak dziś `CategoryReindexRun`, który zastępuje)
  i pierwszą wiadomość producenta na `jobs`. Producent to istniejący wzorzec łańcucha partii (`apps/api/CLAUDE.md`):
  krok czyta kolejną stronę identyfikatorów poddrzewa (`SubtreeProductIds::after`, kursor), publikuje jedną paczkę
  `IndexDocuments` na `indexing` i w tej samej transakcji wysyła następny krok; pusta strona kończy produkcję (`total`).
  Krok trwa milisekundy, więc `worker-commands` nie wstrzymuje webhooków ani wygaszania.
- Paczki obsługują repliki `worker-indexing` (zapis przez aliasy, unieważnienie produktów paczki). Komplet: jedno
  `invalidateCatalog()` (dziś też jedno po łańcuchu; drugie przy samym zdarzeniu zostaje, purge celowany to PERF-3, P8).
- Ponowiona publikacja paczki (krok wycofany po publikacji) daje kopię rozliczaną raz (`ON CONFLICT`).

### Alert zaległości

- `app:messaging:check` (CronJob `queue-check`) czyta także kolejki RabbitMQ `events` i `notifications` z API zarządzania
  (`messages_ready`, `head_message_timestamp`): najstarsza gotowa wiadomość starsza niż próg (10 min, jak kolejki
  Postgresa) to stan niezdrowy, kod wyjścia i alert jak dziś (`MessagingUnhealthy`); niedostępne API zarządzania to błąd
  sprawdzenia, nigdy „zdrowe”. Kolejka `indexing` ma własny sygnał (heartbeat przebiegu, `--wait`, `abort` martwego
  przebiegu) i nie jest oceniana wiekiem, bo przy przebudowie legalnie czeka w niej do 20 tys. paczek; brak jej konsumenta
  jest jednak błędem (liczba `consumers` 0 przy niepustej kolejce).
- Pulpit panelu bez zmian (długość kolejek RabbitMQ jak dziś); kontrakt API panelu się nie zmienia.
- Lista ocenianych kolejek jest zamknięta: `events`, `notifications` (wiek i konsumenci), `indexing` (konsumenci); każda
  inna kolejka vhosta jest pomijana z ostrzeżeniem w wyniku komendy, kolejka z listy nieobecna w brokerze to błąd.

## Integracje

- `bin/generate-demo`: ostatni krok `catalog:reindex --async --wait`. Cele `generate`, `open` i `reindex` w `infra/k8s`
  skalują `worker-indexing` jak dziś `worker`, odmawiają przy aktywnym przebiegu; komunikat `reindex` nie zakazuje już
  tworzenia produktów. `activeDeadlineSeconds` CronJoba `reindex` dobrany do W19 z zapasem (nie krótszy niż 2 h).
- Chart i compose odzwierciedlają się (`infra/k8s/CLAUDE.md`, „Chart a compose”): nowe Deploymenty i usługi, zmienne
  (`SEARCH_INDEX_BATCH_SIZE`, rozmiary wsadów, `--fetch-size`), repliki, zasoby z budżetu PERF-1.
- `load/`: scenariusze W17, W18 i W19 obok istniejącego scenariusza zapisów (W16, W13); sekcja „Pomiar i akceptacja”.

## Pomiar i akceptacja

Wszystkie pomiary epiku biegną na istniejącym lokalnym pełnym profilu `server` (dane generuje sesja PERF-7), bez
ponownego generowania danych. Jeden pełny pomiar akceptacyjny na worf.pl wykonuje PERF-9. Pełny reindeks na worf.pl
tylko przy zmianie definicji indeksu, nie po każdym wdrożeniu.

- **Przed zmianą** (lokalny `server`, ta sama rewizja i dane przed i po, okno pomiarowe uzgodnione z sesjami PERF):
  przepustowość zdarzeń (seria `PromotionPricesChanged` z audytu, P1), scenariusz zapisów W16 przy 50 zmianach/s,
  W17 (100 promocji po 1000 wariantów, zamówienie w trakcie), W18 (przeniesienie działu z 20% katalogu, W1
  próbkowane w trakcie), W19 (przebudowa z zapisami i tworzeniem produktów w trakcie). Wynik W13 po każdym.
- **Po zmianie** te same scenariusze na lokalnym `server`: przebudowa `--async` przy 1, 2 i 4 replikach w obu
  kolejnościach odczytu (D13), W16 przy `refresh_interval` 1 s i 5 s (D11), W17, W18, W13 po każdym. Raport
  w `docs/reports/` z porównaniem, rozrzutem trzech przebiegów, komendą każdego pomiaru i wartościami domyślnymi
  (repliki, rozmiary wsadów i paczek, `--fetch-size`, kolejność odczytu, `refresh_interval`) do decyzji właściciela;
  potem PR ustawiający wartości i usuwający przegraną kolejność. Wynik lokalny nie jest wynikiem serwera: spełnienie na
  worf.pl potwierdza PERF-9.
- Bez nowego narzędzia pomiarowego: k6 i skrypty `load/`, logi `search_rebuild`, Grafana (tylko odczyt), API zarządzania
  RabbitMQ.

## Skala (10 mln produktów)

- Przebudowa: 20 000 paczek po 500, 20 000 krótkich aktualizacji wiersza przebiegu (godziny, nie sekundy), producent
  czyta same klucze; uzgodnienie przechodzi 2 × 10 mln kluczy strumieniowo, bez ładowania zbioru do pamięci.
- Zdarzenia: koszt wsadu rośnie z rozmiarem wsadu, nie z katalogiem; deduplikacja zmniejsza zapisy przy wielu zmianach
  jednego produktu. Relay i projekcje skalują się replikami (`SKIP LOCKED`, wielu konsumentów AMQP, wersje).
- Wąskie gardło przebudowy (odczyt z dysku Postgresa) może się nie skalować z liczbą procesów: to rozstrzyga pomiar
  (D13), a pamięć Postgresa ustala PERF-1.

## Scenariusze błędów

| Zdarzenie | Skutek |
|---|---|
| Przejściowy błąd ES, ofert, Varnisha albo sklepu we wsadzie zdarzeń | Odrzucone wiadomości dotkniętych produktów (albo całego wsadu), ponowienia `events` (ok. 26 min), potem `failed` z alertem |
| Błąd trwały pozycji wsadu | Tylko wiadomości tego produktu w `failed`; reszta potwierdzona |
| Worker zabity z niepełnym wsadem | Niepotwierdzone wiadomości wracają do kolejki; powtórka nieszkodliwa |
| Dwie repliki odświeżają ten sam produkt | Wygrywa nowszy odczyt (wersja z zegara bazy); unieważnienie idempotentne |
| Zdarzenie bez trasy w exchange | Relay kończy się błędem i ponawia (ok. 3 h), potem `failed`; nie ginie |
| Seria 100 tys. zdarzeń produktu | E-maile w `notifications` bez czekania na projekcje; zaległość `events` widoczna w alercie po progu |
| API zarządzania RabbitMQ niedostępne | `queue-check` kończy się błędem (alert), nie „zdrowe” |
| Paczka przebudowy: błąd przejściowy, trwały, powtórka, dwie kopie naraz, worker zabity przed rozliczeniem | Jak w poprzedniej wersji: ponowienia, `failed` bez przełączenia aliasu, rozliczenie raz (`ON CONFLICT`), finalizacja dokładnie raz |
| Produkt utworzony albo zmieniony w trakcie przebudowy | Trafia do nowego indeksu przez alias przebudowy albo przez uzgodnienie; przebudowa się nie przerywa |
| Dokument w nowym indeksie bez produktu w bazie | Uzgodnienie: `failed`, nowy indeks usunięty, alias bez zmian |
| Seed albo smoke usuwający produkty w trakcie przebudowy | Odmowa przed usunięciem (blokada zajęta); nic nie usunięte |
| Utrata finalizacji między zatwierdzeniem a publikacją | Niemożliwa (wiersz transportu w tej samej transakcji) |
| Awaria w trakcie finalizacji, dwie finalizacje naraz | Wznowienie od zapisanego etapu; alias przełączony najwyżej raz |
| Abort ścigający się z `running → finalizing` | Warunkowe `UPDATE`: wygrywa jedno; abort nie usuwa indeksu aliasu |
| RabbitMQ niedostępny przy produkcji | Przebieg `failed`, nowy indeks usunięty |
| Wiadomości paczek utracone w brokerze | Przebieg bez kompletu, martwy po limicie heartbeatu; `abort` i nowy przebieg |
| Restart stosu w trakcie | Kolejki trwałe, rejestr i finalizacja w Postgresie; przebieg kończy się po powrocie workerów |
| Dwie zmiany drzewa naraz | Dwa przebiegi kategorii, oba kończą się; zapisy idempotentne |

## Model zagrożeń

- Klucz typu z kolejki nigdy nie wskazuje klasy; identyfikatory walidowane jako UUID, parametry zapytań; limit długości.
- Wiadomość nie niesie indeksu; `deleteIndex` odmawia indeksu aliasu.
- Logi bez danych produktów: identyfikatory przebiegu, numery paczek, liczby.
- Klucz trasy zdarzenia pochodzi z klasy wiadomości po stronie relaya, nie z danych wejściowych.

## Przejście (etap demo)

Wdrożenie z przestojem zdarzeń: przed wdrożeniem kolejki `outbox` i `events` puste (worker zostaje, aż je opróżni),
potem wdrożenie z nowym exchange, transportami i workerami; stary exchange i kolejka `events` bez konsumentów są usuwane
w kroku runbooka. Wiadomości paczek starszego formatu nie istnieją (nowy transport). Po wdrożeniu `catalog:reindex`
w nowym trybie. Bez zgodności ze starą wersją wiadomości.

## Poza zakresem

Zmiana treści dokumentu i zapytań wyszukiwarki, zasoby Postgresa i ES (PERF-1), purge celowany zamiast czyszczenia całego
cache przy zmianie drzewa (PERF-3, P8), operacje zbiorcze cen (D7), wiele replik sklepu (PERF-5), nowy eksporter metryk,
komenda `status` przebiegu (postęp w logach i `--wait`).

## Kryteria akceptacji

1. Wsadowe odświeżanie: wsad zdarzeń cen, promocji, stanów i produktów daje jeden odczyt migawek, jedno wywołanie faktów
   ofert, dwa `_bulk` (alias przebudowy, potem alias z `wait_for`) i unieważnienie w żądaniach po najwyżej 100 tagów;
   powtórzone produkty zapisane raz; wynik każdej wiadomości osobno (przestarzały = potwierdzony, przejściowy = ponowiony,
   trwały = `failed`); źródła `seed` i `tax_reconciliation` bez zapisu.
2. PURGE i rewalidacja następują po odpowiedzi zapisu z `wait_for`; żądanie z `wait_for` nigdy nie obejmuje indeksu
   w budowie; W19a: testy zapisu w trakcie przebudowy przechodzą dla dwóch żądań, także w chwili przepięcia aliasu.
3. Wersja dokumentu pochodzi z zegara bazy sprzed odczytu we wsadzie zdarzeń, paczce, przebudowie synchronicznej
   i uzgodnieniu; dwie repliki odświeżające ten sam produkt kończą z dokumentem nowszego odczytu.
4. E-maile zamówień obsługuje `notifications`; seria zdarzeń produktu nie opóźnia ich (test: e-mail obsłużony, gdy
   `events` ma zaległość); każda klasa z handlerem `notifications` ma powiązanie; zdarzenie bez trasy jest błędem relaya.
5. Każdy transport poza `failed` ma konsumenta w compose i w chartcie; `worker-commands` konsumuje tylko swoje cztery.
6. `queue-check` zgłasza wiek najstarszej gotowej wiadomości `events` i `notifications` ponad próg, brak konsumenta
   niepustej `indexing` i niedostępne API zarządzania; kolejka spoza listy daje ostrzeżenie, z listy nieobecna błąd.
7. Przebudowa `--async` kończy się `succeeded`, alias na nowym indeksie, uzgodnienie zgodne, poprzedni indeks usunięty;
   liczba paczek `ceil(produkty / rozmiar)` (także 0, 1, rozmiar ± 1); obie kolejności odczytu dają ten sam zbiór
   dokumentów.
8. Produkt utworzony w trakcie przebudowy (synchronicznej i asynchronicznej; z identyfikatorem poniżej i powyżej kursora,
   po ostatniej paczce) jest w nowym indeksie, a przebudowa kończy się powodzeniem; dokument bez produktu w bazie
   i utracony zapis paczki są wykrywane (utracony dopisany, nadmiarowy `failed`).
9. Seed i smoke nie usuwają produktów w trakcie przebudowy (odmowa przed `TRUNCATE`).
10. Paczka w `failed` i częściowy błąd bulka nie przełączają aliasu; po ponowieniu przebieg się kończy; powtórzenie paczki
    nie zmienia liczników; dwa procesy kończące ostatnie paczki dają jedną finalizację; finalizacja nie ginie i jest
    wznawialna na każdym etapie; dwie finalizacje naraz przełączają alias najwyżej raz.
11. `abort` nie usuwa indeksu aliasu, nie ściga się z finalizacją, odmawia od `finalizing`.
12. Przebudowa synchroniczna, asynchroniczna, `setup`, `reset` i usuwanie produktów wykluczają się w obu kolejnościach.
13. Reindeks kategorii jest przebiegiem wachlarza: paczki na `indexing`, komplet kończy przebieg jednym
    `invalidateCatalog()`; krok producenta nie wstrzymuje `worker-commands`; dwie zmiany drzewa naraz kończą się obie.
14. `catalog:reindex` bez `--async` działa jak dziś (poza uzgodnieniem); `generate-demo` kończy `--async --wait`; cele
    `infra/k8s` na ścieżce błędu (aktywny przebieg, brak workera).
15. `make check` i testy wszystkich dotkniętych aplikacji przechodzą.
16. **Wydajność:** raport z pomiarem przed i po na lokalnym pełnym profilu `server` (akceptacja na worf.pl w PERF-9): W16, W17,
    W18, W19 i W13 po każdym scenariuszu, z wartością zmierzoną i spełnieniem podanym wprost. Do tego pomiaru nie
    twierdzimy, że wymagania są spełnione.

## Dokumentacja (zadania razem z kodem)

- `docs/features/search/spec.md`: „Zapis i kolejność” (wersja z zegara bazy), „Zapisy w trakcie przebudowy” (dwa żądania,
  `wait_for`), weryfikacja przez uzgodnienie, „Wyzwalacze” (wsady), „Infrastruktura” (`--async`, `indexing`,
  `worker-indexing`, cel `reindex` bez zakazu tworzenia produktów).
- `docs/features/walking-skeleton/spec.md`: „Ograniczenia tej przebudowy” (usunąć zdania, które przestają być prawdziwe).
- `docs/features/async-reliability/spec.md`: workery, kolejki, alert kolejek RabbitMQ (dziś „poza zakresem”).
- `docs/features/catalog-admin/spec.md` i specyfikacje Pricing/Inventory w miejscach o handlerach reindeksu i unieważnienia.
- `docs/features/demo-scale/spec.md`: krok indeksowania generatora i cel `reindex`.
- `apps/api/CLAUDE.md`: workery i transporty, szyna `indexing.bus` bez transakcji, wzorzec wachlarza paczek obok łańcucha,
  handlery wsadowe. `infra/k8s/CLAUDE.md` i runbook `infra/k8s/README.md`: workery, repliki, przejście (stary exchange),
  `reindex`.
- `docs/features/performance-program/plan.md`: status PERF-6.
- Po ukończeniu funkcji plan jest usuwany; ta specyfikacja opisuje stan obecny.

## Ryzyka

- **Wąskie gardło dysku Postgresa nie skaluje się procesami.** Wtedy W19 zależy od kolejności odczytu (D13) i pamięci
  Postgresa (PERF-1); gdy żadna nie wystarczy, raport z wariantami i decyzja właściciela.
- **Oczekiwanie `wait_for` ogranicza przepustowość procesu projekcji** (do `refresh_interval` na wsad); kompensują to
  rozmiar wsadu i repliki, pomiar ustala wartości.
- **Relay jako wąskie gardło W17:** e-mail czeka w outboxie za zdarzeniami opublikowanymi wcześniej. Jeśli pomiar pokaże
  zaległość outboxa ponad 60 s przy `--fetch-size` i replikach relaya, wariant zapasowy to osobna kolejka outboxa dla
  zdarzeń z handlerami `notifications` (decyzja właściciela po pomiarze).
- **E-maile przy W11** (20 zamówień/s) mogą wymagać kilku replik `worker-notifications`; wtedy dwa e-maile jednego
  zamówienia mogą przyjść w odwrotnej kolejności. Kolejność publikacji przy kilku replikach relaya: ryzyko przyjęte.
- **Zdarzenie rzadkie w strumieniu innych** czeka do 30 s (stała Workera Symfony).
- **Pamięć węzła:** dodatkowe Deploymenty i repliki muszą zmieścić się w budżecie PERF-1 (22,9 GiB, generator 4 GiB).
- **Gorący wiersz przebiegu** (aktualizacja na paczkę): pomiar; w razie potrzeby rozliczanie w grupach.
- **Koszt uzgodnienia przy 10 mln** (strumień `composite` po `id`) nie jest zmierzony. Jeśli przekroczy budżet W19,
  wariant zapasowy to porównanie liczności w zakresach identyfikatorów (zapytanie zakresowe po `id` i `WHERE id >= :a AND
  id < :b`) i szczegółowe porównanie tylko zakresów niezgodnych; jest słabszy (nie wykrywa pary utracony i nadmiarowy
  dokument w jednym zakresie), więc wymaga decyzji właściciela.
