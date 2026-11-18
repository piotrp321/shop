# ASYNC-RELIABILITY: niezawodne przetwarzanie asynchroniczne

Epik naprawczy z audytu aplikacji z 2026-11-18 (`docs/reports/app-audit-2026-11-18.md`, sekcja 7; ryzyko R1, błąd B3,
ryzyko R13; załącznik `02-zamowienia-platnosci.md`, znaleziska 1, 2, 4 i 5). Przetwarzanie asynchroniczne (Symfony
Messenger, outbox w Postgresie, RabbitMQ, jeden worker) to według audytu największe ryzyko techniczne: nieopłacone
zamówienie może nigdy nie wygasnąć, wiadomości w `failed` są niewidoczne, zdarzenia i komendy dzielą jeden proces, a worker
nie ma limitów czasu i pamięci. Epik rozdziela workery, dodaje siatkę bezpieczeństwa dla wygaszania zamówień, nadzór
nad kolejkami z alertem i procedurą oraz test zgodności wersji wiadomości.

Issues: brak (epik z raportu audytu; issue `epic` zakłada użytkownik, patrz „Wymagane przed startem”)
Zakres: `apps/api`, `apps/admin`, `infra/docker-compose.yml`, `Makefile`, `.github/workflows/ci.yml`, `.github/scripts`,
`infra/k8s`, `e2e/tests/staff-dashboard.spec.ts`, `docs/vision.md`, `docs/adr/backups-observability.md`,
`docs/features/payment/spec.md`, `docs/features/product-page/spec.md`, `docs/features/pricing/spec.md`,
`docs/features/deploy/spec.md`, `docs/features/deploy-ops/spec.md`, `docs/features/admin-features/spec.md`,
`docs/features/oncall/spec.md`, `docs/features/demo-data/spec.md`, `README.md`

## Cel i zakres

- Dwa procesy workera z tego samego obrazu, w compose i w chartcie: `worker` (zdarzenia: `outbox events`) i
  `worker-commands` (komendy w Postgresie: `payment_simulator scheduled promotions jobs`, webhooki symulatora przed
  anulowaniami). Komendy działają przy niedostępnym RabbitMQ.
- `--time-limit` i `--memory-limit` dla obu workerów.
- Ponowienia transportu `events` liczone w minutach (około 26 minut zamiast 13 s); relay outboxa ponawia przez około
  3 godziny zamiast 25 minut.
- Pominięcie reindeksacji i unieważniania cache sklepu po zmianie ceny ze źródła `tax_reconciliation` (brutto się nie
  zmienia).
- Komenda wygaszająca zamówienia `new` po terminie (siatka bezpieczeństwa dla `ExpireUnpaidOrder`) i komenda
  zamykająca płatności `pending` zamówień, których nie da się już opłacić (B3); w klastrze CronJob `order-expiry`.
- Liczba wiadomości w `failed` i wiek najstarszej gotowej wiadomości każdej kolejki w Postgresie: pulpit API (sekcja
  `system`), OpenAPI, wygenerowany klient i ekran „Stan systemu” panelu; ta sama ocena w komendzie sprawdzającej.
- CronJob `queue-check` z tą komendą i reguły alertów na niepowodzenie obu CronJobów oraz na brak przebiegów
  `order-expiry`.
- Cele make na serwerze: przegląd, ponowienie i usunięcie wiadomości z `failed`; procedura w runbooku.
- Test zgodności wersji wiadomości (R13) i poprawiona zasada wersjonowania wiadomości.

Kryterium ukończenia z audytu: przy zatrzymanym RabbitMQ `ExpireUnpaidOrder` po terminie jest obsłużone; wiadomość
w `failed` odpala alert; zamówienie, którego wiadomość jest w `failed`, wygasza CronJob.

## Decyzje do potwierdzenia

Specyfikacja przyjmuje rekomendacje poniżej. Kolejność od najważniejszej.

- **D1. Wygaszanie zamówień: CronJob uzupełnia opóźnioną wiadomość, nie zastępuje jej.**
  - (a) Uzupełnia (rekomendacja): `ExpireUnpaidOrder` z `DelayStamp` zostaje głównym mechanizmem (dokładny moment,
    działa w compose, gdzie nie ma harmonogramów), a komenda co 5 minut wygasza zamówienia przeterminowane o ponad
    10 minut. Wygaszanie jest idempotentne i zaczyna od blokady zamówienia, więc oba mechanizmy mogą się spotkać.
    Koszt: gdy `worker-commands` stoi dłużej niż karencja, CronJob anuluje także zamówienie, którego webhook opłacenia
    w terminie czeka w kolejce symulatora (patrz „Ryzyka”).
  - (b) Zastępuje: jeden mechanizm, ale wygaśnięcie o okres CronJoba później (rezerwacja dłużej zajęta), compose bez
    wygaszania (brak harmonogramów), a zgodność wersji wymagałaby jeszcze obsługi wiadomości już w kolejce.
- **D2. Podział transportów: `worker` = `outbox events`, `worker-commands` = `payment_simulator scheduled promotions jobs`.**
  - (a) Dwa workery (rekomendacja): transporty Doctrine nie zależą od brokera; w `worker-commands` worker Symfony po
    każdej wiadomości wraca na początek listy, więc dostępne webhooki symulatora idą przed dostępnymi anulowaniami.
    Gwarancja kolejności dotyczy jednego procesu: przy kilku replikach `worker-commands` to tylko pierwszeństwo, nie
    gwarancja. Pełna gwarancja „opłacone w terminie nigdy nie jest anulowane” wymaga uzgodnienia z Payment przed
    anulowaniem (R3, epik PAYMENTS-REAL).
  - (b) Worker na każdy transport: pełna izolacja, ale sześć Deploymentów i brak pierwszeństwa webhooków.
  - (c) Jeden worker z inną kolejnością: bez kosztu, ale awaria RabbitMQ dalej zatrzymuje komendy.
- **D3. Serializer wiadomości (R13): serializer PHP na transportach Doctrine zostaje; test „złotych” serializacji
  i zasada „nowy kształt to nowa klasa”.**
  - (a) Bez zmiany serializera (rekomendacja na ten epik): zero zmian w działającym systemie i zero ryzyka przy wymianie
    wersji. Zasada „pola dodawaj jako opcjonalne” w `apps/api/CLAUDE.md` jest dziś prawdziwa tylko dla JSON (`events`):
    `unserialize` nie woła konstruktora, więc w klasie `final readonly` nowe pole zostaje niezainicjalizowane, a nieznane
    pole kończy się błędem (klasa readonly nie przyjmuje właściwości dynamicznych). Zasada zmienia się na „pól klasy
    wiadomości i zdarzenia domenowego nie zmieniamy; nowy kształt to nowa klasa, nadawana dopiero w wersji po tej, która
    ją obsługuje” (jak dziś stemple), a test pilnuje, że kształt się nie zmienił bez nowej klasy. Koszt przechodzi na
    przyszłe zmiany zdarzeń (klasa `…V2` i dwa wdrożenia).
  - (b) JSON (Symfony Serializer) na wszystkich transportach: jedna zasada „pola opcjonalne” i czytelne wiersze
    `failed`; zdarzenia już dziś serializują się do JSON na `events`, więc koszt kodu jest mały, ale wymiana formatu
    wymaga dwóch kolejnych wdrożeń (najpierw odczyt obu formatów, potem zapis JSON), bo stary worker w trakcie wymiany
    nie odczyta JSON. Warto do tego wrócić przy pierwszej realnej zmianie kształtu zdarzenia; test złotych zapisów
    przydaje się w obu wariantach.
- **D4. Alert na `failed` przez CronJob sprawdzający kolejki, nie przez metrykę.** Reguły alertów widzą dziś tylko
  metryki klastra (kube-state-metrics). CronJob `queue-check` co 5 minut kończy się błędem, gdy `failed` nie jest puste
  albo kolejka stoi, a reguła łapie nieudany Job (wzorzec `BackupFailed`, z jedną instancją alertu na CronJob). Każda
  wiadomość w `failed` jest alertem, dopóki operator jej nie ponowi albo nie usunie. Alternatywa: eksporter metryk
  aplikacji (nowa usługa, APP-OBSERVABILITY) albo tylko Sentry (już dziś, poza repo, bez procedury).
- **D5. Płatność `pending` (B3) zamyka komenda Payment w CronJobie, nie odbiorca `OrderCancelled`.** Komenda zamyka
  płatność `pending` zamówienia, którego Ordering nie uznaje już za płatne: anuluje sesję u dostawcy jak nowa próba
  płatności, a sesję opłaconą u dostawcy zostawia do webhooka. Odbiorca zdarzenia (przez zdarzenie integracyjne
  Ordering w warstwie Application, jak `ProductChanged` w Catalog) też jest możliwy i outbox dostarczyłby je po awarii
  brokera, ale nie obsłużyłby zaległych `pending` sprzed epiku ani płatności zamówień `new` po terminie, których nikt
  jeszcze nie anulował; komenda obsługuje wszystkie przypadki jednym mechanizmem.

## Wymagane przed startem

- **Zasoby spoza repo: brak.** Epik nie potrzebuje sekretów, kont ani uprawnień: CronJoby i reguły używają istniejących
  metryk z listy dozwolonych (`kube_job_status_condition`, `kube_job_owner`, `kube_cronjob_status_last_successful_time`),
  synchronizacja reguł do Grafana Cloud już działa.
- **użytkownik:** issue z etykietą `epic` dla ASYNC-RELIABILITY wpisane do linii `Issues:` (potrzebne, gdy epik idzie
  przez `/ao-run`, który dopisuje `Closes #N` z tej linii; przy realizacji w sesji głównej opcjonalne).
- **Eksperymenty na wersjach epiku** (`symfony/messenger` v8.1.9 z `apps/api/composer.lock`, PHP 8.5, k3s z
  `infra/k8s/scripts/tools.sh`). Szkic powstał bez uruchamiania Dockera (na maszynie trwał przebieg orkiestratora),
  więc wyniki wpisuje orkiestrator epiku przed startem zadań; każde założenie potwierdza też test zadania, które na nim
  stoi. Wynik inny niż oczekiwany to pytanie do użytkownika przed startem, nie odkrycie w trakcie zadania.
  - E1, odbiór bez brokera (D2, W1, zadanie 1): `RABBITMQ_HOST` na zamknięty port,
    `docker compose exec api bin/console messenger:consume payment_simulator scheduled promotions jobs --limit=1
    --time-limit=10 -vv`; oczekiwane: kod 0, bez próby połączenia AMQP. Przesłanka z kodu: `RoutedAmqpTransportFactory`
    tworzy tylko `Connection::fromDsn`, który się nie łączy, a zdarzenia trasuje `DomainEvent: outbox`.
  - E2, kolejność odbiorców (D2): `grep -n "break" vendor/symfony/messenger/Worker.php` w kontenerze `api`; oczekiwane:
    po obsłużonej kopercie pętla odbiorców przerywa się i zaczyna od pierwszego.
  - E3, limity (W2): `messenger:consume jobs --memory-limit=1M --limit=1` i `--time-limit=1`; oczekiwane: łagodne
    zakończenie z kodem 0 po wiadomości.
  - E4, `unserialize` klasy `final readonly` (D3, W11): skrypt `php -r` z klasą z dodanym i usuniętym polem;
    oczekiwane: niezainicjalizowana właściwość, błąd przy nadmiarowej.
  - E5, `messenger:failed:retry` (W10): wiadomość, która znów zawodzi, wraca do `failed`; wiadomość przeniesiona do
    `failed` zapytaniem SQL (bez `SentToFailureTransportStamp`, test dymny) da się usunąć `messenger:failed:remove`.
  - E6, pobieranie z transportu Doctrine (W1): `grep -n "SKIP LOCKED\|FOR UPDATE" vendor/symfony/doctrine-messenger/Transport/Connection.php`;
    oczekiwane: blokada wiersza, więc dwa procesy nie biorą tej samej wiadomości.
  - E7, Job z CronJoba (W9, test dymny): `kubectl create job x --from=cronjob/cart-purge-expired` na k3d testu dymnego,
    potem `kubectl get job x -o jsonpath='{.metadata.ownerReferences}'`; oczekiwane: właściciel `CronJob`, więc
    `kube_job_owner` łączy taki Job z regułą.

## Poza zakresem

- Uzgodnienie stanu płatności przed anulowaniem, odczyt stanu sesji u dostawcy, rejestr zwrotów (#221), dłuższe
  ponowienia dostawy webhooków symulatora: epik PAYMENTS-REAL (R3). Same dłuższe ponowienia nie pomagają, bo anulowanie
  przychodzi 5 minut po terminie niezależnie od nich.
- Liczba replik workerów w wartościach chartu i druga replika czegokolwiek (R6, SCALE-PROOF); workery są przygotowane
  do skalowania, ale chart dalej ma jedną replikę.
- Sondy liveness, `statement_timeout`, alert na nieudany Job wersji (R11, OPS-GUARDS). Zawieszony w obsłudze wiadomości
  worker nie jest przerywany; widać go po wieku kolejki (W7). Jawny `connect_timeout` połączenia AMQP (zawieszone
  połączenie przy partycji sieci) też należy do OPS-GUARDS.
- Alert na zator kolejki `events` w RabbitMQ (management API nie podaje wieku wiadomości); pulpit nadal pokazuje jej
  długość, a zawieszony `worker` widać po wieku `outbox`, którą obsługuje przed `events`.
- Alert na brak przebiegów `queue-check`: przy niepustym `failed` udanych przebiegów legalnie nie ma, a metryki
  ostatniego planowania nie ma na liście dozwolonych; wstrzymany `queue-check` opisuje „Ryzyka”.
- Licznik zamówień `new` po terminie na pulpicie (audyt, znalezisko 4): po epiku wygasza je CronJob, a jego
  niepowodzenie i brak przebiegów są alertami.
- Metryki aplikacji, eksporter, alerty z logów (APP-OBSERVABILITY).
- Zmiana serializera na JSON (D3, wariant b).
- Harmonogramy w compose: CronJoby są funkcją środowiska wdrożeniowego, jak `cart-purge-expired`; lokalnie komendy
  uruchamia się ręcznie, a wygaszanie robi opóźniona wiadomość.
- Uruchomienie na serwerze: wdrożenie epiku i reakcja na pierwsze alerty to kroki runbooka po merge'u.

## Stan obecny

- **Jeden worker, transporty po kolei.** `infra/docker-compose.yml:350` i `infra/k8s/charts/shop/templates/api.yaml:136`:
  `messenger:consume outbox events scheduled payment_simulator promotions jobs`, jedna replika, bez `--time-limit`
  i `--memory-limit`; sonda (`api.yaml:145`, healthcheck `docker-compose.yml:357`) sprawdza tę listę w PID 1. Worker
  Symfony po obsłużeniu wiadomości zaczyna od pierwszego transportu, więc `events` (jedyny na RabbitMQ,
  `apps/api/config/packages/messenger.yaml:40-51`) stoi przed wszystkimi transportami w Postgresie, a `scheduled`
  (anulowania) przed `payment_simulator` (webhooki). Worker czeka na RabbitMQ: `depends_on` w compose
  (`docker-compose.yml:337-338`), initContainer `wait-for-rabbitmq` w chartcie (`api.yaml:157`). Worker ma własny
  `APP_CACHE_DIR: /tmp/cache` (`docker-compose.yml:344-346`), bo przebudowa cache przez `api` na wspólnym wolumenie
  wywracała długożyjący proces.
- **Ponowienia.** `events`: 3 próby po 1, 3 i 9 s, potem `failed` (`messenger.yaml:48-51`); `outbox`: 30 prób przez
  około 25 minut (`:33-39`); `scheduled`, `payment_simulator`, `promotions`, `jobs`: 5 prób przez około 2 minuty
  (`:59-101`). Kernel `test_messaging` skraca pierwsze opóźnienie `events` (`config/packages/test_messaging/messenger.yaml`,
  `tests/Unit/Shared/Messaging/EventsRetryStrategyTest.php`).
- **Wygaszanie.** `PlaceOrder` wysyła `ExpireUnpaidOrder` z `DelayStamp` na termin + 5 min
  (`apps/api/src/Ordering/Infrastructure/Messaging/MessengerUnpaidOrderExpiry.php:25-28`, `Order.php:31-32,311-314`;
  termin to zawsze `placed_at` + 30 min). Handler blokuje zamówienie, zwalnia rezerwację i użycie kodu, zapisuje
  anulowanie z `OrderCancelled` w outboxie (`ExpireUnpaidOrderHandler.php:54-80`); jest idempotentny. Po 5 próbach
  wiadomość trafia do `failed`, a zamówienie zostaje `new` (`messenger.yaml:59-60`); pracownik nie ma przejścia ze
  statusu `new` (`OrderStatus.php:32`).
- **Płatność `pending` po wygaśnięciu (B3).** `pending` kończy tylko `Payment::end()` (`Payment.php:213-220`), wołane
  z webhooka i z nowej próby płatności (`StartPaymentHandler.php:119-135`, prywatna `endPendingPayment` z mapowaniem
  `SessionCancellation`); wygaśnięcie zamówienia nie zna Payment. Płatności `pending` mają tylko indeks częściowy
  `payment_payment_one_pending_per_order (order_id) WHERE status = 'pending'`. Odczyt zamówienia przez Payment
  (`PayableOrders::find`, Ordering `GetOrderForPayment`) zawsze sprawdza dostęp właściciela.
- **Nadzór.** Pulpit API zna tylko outbox (`apps/api/src/Backoffice/Infrastructure/MessagingStatisticsAdapter.php:33-40`)
  i kolejki RabbitMQ (`:42-80`); panel pokazuje je na ekranie „Stan systemu” (`apps/admin/src/dashboard/SystemStatus.vue:54-57,82`).
  Siedem reguł alertów dotyczy kopii i klastra (`infra/k8s/charts/shop/templates/00-alerts.yaml`); `cronjobFailed`
  (`:15-31`) daje osobną instancję alertu na każdy nieudany Job. Ostatnia nieudana próba daje zdarzenie Sentry
  (`apps/api/config/packages/sentry.yaml:36-37`). `messenger:failed:*` nie występuje w celach make ani w runbooku;
  jedyna wzmianka to `docs/features/payment/spec.md:651-652`.
- **Odtworzenie.** `restore.sh` odmawia, gdy w przestrzeni jest aktywny Job (`:128-135`), skaluje do zera `api worker
  store keycloak` (`:67`) i wstrzymuje CronJoby `cart-purge-expired` i `backup`; test dymny sprawdza te CronJoby
  (`smoke.sh:790`).
- **Reindeksacja przy uzgodnieniu VAT.** `ReindexProductOnPriceChange.php:47` i `InvalidateStorefrontOnPriceChange.php:43`
  pomijają tylko źródło `seed`; `pricing:reconcile-tax --apply` i zmiana klasy podatkowej (`reprice`, to samo brutto,
  źródło `tax_reconciliation`) dają po jednej reindeksacji i jednym unieważnieniu na cenę. Odpowiedzi katalogu sklepu
  (`/api/v1/products`, `/api/v1/products/{slug}`, `/api/v1/product-offers`) i dokument indeksu nie mają stawki ani netto,
  tylko brutto.
- **Serializacja.** Transporty Doctrine (`outbox` ze zdarzeniami domenowymi, `scheduled`, `payment_simulator`,
  `promotions`, `jobs`, `failed`) używają domyślnego serializera PHP; tylko `events` ma Symfony Serializer (JSON,
  `messenger.yaml:42`). Wiadomości to klasy `final readonly`. Zasada zgodności w `apps/api/CLAUDE.md` („Zgodność wersji”)
  każe dodawać pola jako opcjonalne, co serializer PHP łamie w obie strony.
- **Pamięć i zasoby.** `memory_limit = 256M` (`apps/api/docker/php/conf.d/app.ini:2`); worker: `values.yaml:193`
  (100m, 256Mi, limit 768Mi), `prod`: `environments/prod/values.yaml:102` (500m, 512Mi, limit 1Gi). Kolumny
  `messenger_messages` to `TIMESTAMP WITHOUT TIME ZONE` z indeksem `(queue_name, available_at, delivered_at, id)`
  (`Version20261029142852CreateMessengerTables.php`).

## Scenariusze awarii

| Scenariusz | Dziś | Po epiku |
|---|---|---|
| RabbitMQ niedostępny | worker pada na odbiorze `events` i restartuje się w pętli; anulowania, webhooki symulatora, granice promocji i partie zadań stoją; po powrocie anulowania idą przed webhookami | `worker-commands` działa dalej (nie łączy się z brokerem i na niego nie czeka); `worker` restartuje się w pętli (`WorkloadNotReady` po 10 min); zdarzenia czekają w outboxie przez około 3 godziny ponowień; dłuższa awaria: wpisy outboxa w `failed` (alert, ponowienie) |
| Elasticsearch, Varnish, SMTP albo sklep niedostępne dłużej niż 13 s | zdarzenia z tego okna w `failed`, nikt o tym nie wie | ponowienia `events` przez około 26 minut; dłuższa przerwa: `failed`, alert w ciągu 15 minut |
| Duża seria zdarzeń (`pricing:reconcile-tax --apply`, masowe zmiany) | e-maile, anulowania i webhooki czekają za reindeksacją | komendy w osobnym procesie; uzgodnienie VAT nie reindeksuje ani nie unieważnia cache |
| `ExpireUnpaidOrder` w `failed` (błąd logiczny, błąd nowej wersji) | zamówienie `new` na zawsze, towar i kod zajęte, brak sygnału | CronJob `order-expiry` wygasza zamówienie najpóźniej około 15 minut po terminie wygaśnięcia; wiadomość w `failed` daje alert; trwały błąd wygaszania daje alert `OrderExpiryFailed` |
| Wiadomość opóźniona utracona (zamówienie sprzed migracji, ręczne czyszczenie kolejki) | zamówienie `new` na zawsze | jak wyżej, CronJob |
| CronJob `order-expiry` nie startuje (wstrzymany po przerwanym `restore`, ręcznie) | — | alert `OrderExpiryMissing` po 30 minutach bez udanego przebiegu |
| Porzucona płatność (klient zamknął stronę dostawcy) | `pending` na zawsze | `cancelled` (albo `failed`, gdy dostawca odrzucił) po wygaśnięciu zamówienia; opłacona u dostawcy zostaje do webhooka |
| Worker zawieszony albo zatrzymany | nic nie sygnalizuje (proces żyje, sonda gotowości przechodzi) | najstarsza gotowa wiadomość kolejki starsza niż 10 minut: alert `MessagingUnhealthy` |
| `worker-commands` stoi dłużej niż karencja | — | CronJob wygasza zamówienia po terminie, także te, których webhook opłacenia czeka w `payment_simulator` (zamówienie anulowane, płatność do zwrotu); alert `MessagingUnhealthy` po 10 minutach zatoru |
| Wyciek pamięci workera | proces rośnie do limitu kontenera, OOM, restart w trakcie wiadomości | worker kończy się łagodnie po wiadomości, gdy przekroczy 192 MB, i jest uruchamiany od nowa |
| Wdrożenie zmieniające pola wiadomości | wiadomości starej wersji mogą trafić do `failed` albo zostać odrzucone (R13) | test zgodności nie przechodzi, dopóki zmiana nie jest nową klasą |

## Wymagane zachowanie

### W1. Dwa workery

- Compose: wspólna definicja workera (kotwica YAML: `build`, jeden obraz dla obu usług, środowisko z własnym
  `APP_CACHE_DIR` w katalogu kontenera, wolumeny, `restart`); usługa `worker` uruchamia `messenger:consume outbox events`
  (+ limity z W2) i czeka na `api` i `rabbitmq`; nowa usługa `worker-commands` uruchamia
  `messenger:consume payment_simulator scheduled promotions jobs` (+ limity) i czeka tylko na `api`. Healthcheck każdej
  usługi sprawdza jej listę transportów w PID 1. `make up` startuje obie na końcu (dziś `LAST := worker`, `Makefile:27`)
  i obie tworzy od nowa. CI nie buduje drugiego obrazu: obie usługi używają obrazu oznaczanego dziś dla `worker`
  (`.github/workflows/ci.yml`, tagi `shop-api shop-worker`).
- Chart: Deployment `worker` (nazwa bez zmian) z listą `outbox events` i initContainerami jak dziś (migracje, RabbitMQ);
  nowy Deployment `worker-commands` (fala 1, `shop.deployment`, ten sam obraz `api`, `shop.apiEnv`, sekret) z listą komend,
  initContainer tylko `wait-for-migrate` (bez czekania na RabbitMQ), sonda gotowości jak `worker` z własną listą; jeden
  sparametryzowany szablon kontenera workera. Zasoby: osobne klucze w `resources` (`worker`, `workerCommands`) we
  wszystkich profilach; w `prod` i `load` suma requestów obu workerów i suma ich limitów pamięci nie przekraczają
  dzisiejszego jednego workera (500m, 512Mi, limit 1Gi), np. `worker` 300m/320Mi/640Mi i `workerCommands`
  200m/192Mi/384Mi; budżet węzła z `make -C infra/k8s check` przechodzi.
- Test `test_worker_consumes_the_transports_of_compose` porównuje komendy obu workerów z compose (`prod` i `ci`) i ich
  sondy; zbiór transportów obu workerów razem to wszystkie transporty z `messenger.yaml` poza `failed` (nowy transport
  bez workera kończy test błędem).
- Wszędzie, gdzie skrypty i szablony wymieniają `worker`, dochodzi `worker-commands`: oczekiwanie na rollout w Jobach
  `initialize` i `backfill` (`templates/jobs.yaml:157-158,218`), skalowanie do zera i przywracanie w `restore.sh`
  (`:67,255,309,317`; `worker-commands` zapisuje w bazie, więc odtworzenie musi go zatrzymać), listy podów w teście dymnym
  (`smoke.sh:773,783`) i faza nieudanej migracji (`smoke.sh:953-963`: stary pod obu workerów obsługuje, nowy nie jest
  gotowy), DSN Sentry api (`tests/test_sentry.py`), rejestr workloadów `WorkloadNotReady` (z `shop.deployment`, bez zmian
  w regule), lista workloadów wdrażanych hypercare (`docs/features/oncall/spec.md`, K1–K3).
- Kolejność w `worker-commands` daje pierwszeństwo webhookom symulatora (D2). `worker` relayuje outbox przed `events`.
- Obie listy razem obsługują te same transporty co dziś, więc wymiana wersji jest bezpieczna: przez chwilę stary
  `worker` (wszystkie transporty) i nowy `worker-commands` biorą wiadomości z tych samych tabel; transport Doctrine
  pobiera wiersz z blokadą (E6), więc wiadomość obsługuje jeden z nich.

### W2. Limity czasu i pamięci

- Oba workery (compose i chart) mają `--time-limit=86400 --memory-limit=192M`. 192 MB to 75% `memory_limit` PHP
  (256M), poniżej limitu kontenera każdego profilu. Worker sprawdza limity po każdej wiadomości i kończy się kodem 0
  (E3); compose (`restart: unless-stopped`) i Kubernetes uruchamiają go od nowa.
- Limit czasu to doba, nie godzina: restart kontenera liczy się w `kube_pod_container_status_restarts_total`, a hypercare
  `/oncall` (`docs/features/oncall/spec.md`, K2) rekomenduje rollback przy 3 restartach nowej wersji w workloadzie w oknie
  do 24 h. Doba daje najwyżej jeden planowy restart w tym oknie.
- Sygnał zakończenia (SIGTERM przy wymianie poda) kończy worker po bieżącej wiadomości, jak dziś.

### W3. Ponowienia `events` i outboxa

- `events`: `max_retries: 10`, `delay: 1000`, `multiplier: 3`, `max_delay: 300000`, czyli po 1, 3, 9, 27, 81, 243 s i
  czterech razy po 300 s (około 26 minut), potem `failed`. Handlery `events` są idempotentne i czytają stan bieżący, więc
  ponowienia w innej kolejności niczego nie psują.
- `outbox` (relay zawodzi tylko przy niedostępnym RabbitMQ, który i tak daje `WorkloadNotReady`): `max_retries: 200`
  przy dzisiejszym `max_delay` 60 s, czyli około 3 godzin zamiast 25 minut, więc zwykła awaria brokera nie kończy się
  ręcznym ponawianiem.
- `UnrecoverableMessageHandlingException` (np. `SearchIndexError`) dalej idzie od razu do `failed`.
- Kernel `test_messaging`: ta sama liczba prób i mnożnik, opóźnienia skrócone tak, żeby test wyczerpania ponowień
  `events` trwał najwyżej kilka sekund (pierwsze opóźnienie i `max_delay` pomniejszone w tej samej proporcji);
  `EventsRetryStrategyTest` pilnuje liczby prób, mnożnika i proporcji `max_delay` do pierwszego opóźnienia jak w produkcji.

### W4. Uzgodnienie VAT bez reindeksacji

- `ReindexProductOnPriceChange` i `InvalidateStorefrontOnPriceChange` potwierdzają bez pracy zdarzenie
  `VariantPriceChanged` ze źródłem `tax_reconciliation` (jak `seed`). Brutto, historia brutto (Omnibus) i dostępność się
  nie zmieniają, a ani indeks, ani odpowiedzi katalogu sklepu nie zawierają stawki ani netto.
- Zmiana ze źródłem `staff` dalej reindeksuje i unieważnia (test obu źródeł).

### W5. Wygaszanie zamówień po terminie

- Logika wygaśnięcia (blokada zamówienia, decyzja `Order::expireUnpaid`, zwolnienie rezerwacji i użycia kodu, zapis
  z `OrderCancelled` w outboxie, ponowne zaplanowanie przy `TooEarly`) jest w jednym miejscu Ordering Application, którego
  używa `ExpireUnpaidOrderHandler` i nowa komenda synchroniczna `ExpireOverdueOrder(orderId)` (`command.bus`, bez trasy
  w `routing`, więc obsługiwana w procesie nadawcy). Żadnego `ReceivedStamp` ani kopii logiki handlera.
- Komenda konsoli `ordering:expire-overdue [--batch-size=100]` (Ordering, warstwa UI) wybiera identyfikatory zamówień
  `new` z `payment_deadline` < teraz − okno webhooków (`Order::DELIVERY_WINDOW`, 5 min) − karencja (parametr, 10 min)
  zapytaniem DBAL stronicowanym keysetem `(placed_at, id)` (termin to zawsze `placed_at` + 30 min), partiami po
  `--batch-size`, do pustej partii, i dla każdego wysyła `ExpireOverdueOrder`: każde zamówienie w osobnej transakcji
  `command.bus`; między zamówieniami czyści stan ORM. Karencja zostawia zwykły przypadek opóźnionej wiadomości.
- Równoległa wiadomość opóźniona albo równoległa płatność czeka na blokadę i widzi stan po niej: opłacone albo
  anulowane zamówienie nic nie zmienia. Konflikt wersji przy zapisie jest ponawiany raz na świeżym odczycie.
- Zapytanie kandydatów korzysta z indeksu `ordering_order_status_placed_at_id_idx` (`status, placed_at DESC, id DESC`):
  zamówień `new` jest tyle, ile otwartych zamówień (plus te z błędem). Test planu zapytania (po `ANALYZE`) na 100 tys.
  zamówień w innych statusach potwierdza użycie indeksu.
- Błąd jednego zamówienia (np. `ReservationStateConflict`) jest zapisany w logu (`error`, z identyfikatorem zamówienia
  i wyjątkiem) i nie zatrzymuje pozostałych. Wypisuje liczbę wygaszonych zamówień i błędów.
- Kod wyjścia: 0 – gotowe (także bez kandydatów), 1 – zła wartość `--batch-size` (bez zmian), 2 – co najmniej jedno
  zamówienie się nie udało albo przerwanie błędem (np. niedostępna baza); wygaszone wcześniej zostają.
- Wiadomość tego zamówienia w `failed` zostaje (alert z W9 trwa); jej ponowienie po wygaszeniu nic nie zmienia
  (zamówienie `cancelled`) i usuwa ją z `failed`.
- Komentarz transportu `scheduled` w `messenger.yaml` („the order stays new”) mówi o siatce bezpieczeństwa.

### W6. Zamykanie porzuconych płatności (B3)

- Nowe zapytanie Ordering `GetOrderPayability(orderIds)` (`query.bus`, do 100 identyfikatorów) zwraca dla każdego
  zamówienia tylko to, czy jest płatne (`new` przed terminem) i jego status dla Payment (`new`, `paid`, `cancelled`), bez
  numeru, kwoty i danych osobowych; nie ma kontroli właściciela, bo nic nie ujawnia. Payment dostaje osobną metodę portu
  (adapter w `Payment_Infrastructure`, Deptrac bez zmian). `PayableOrders::find` i `GetOrderForPayment` z kontrolą dostępu
  zostają bez zmian.
- Mapowanie wyniku anulowania sesji u dostawcy (`SessionCancellation` → `cancel`, `fail` albo „opłacona”) jest w jednym
  miejscu Payment, z którego korzysta `StartPaymentHandler` (który przy „opłaconej” dalej rzuca `PaymentInProgress`)
  i nowa ścieżka.
- Komenda `payment:close-abandoned [--batch-size=100]` (Payment, warstwa UI) przechodzi płatności `pending` keysetem po
  `order_id` (indeks częściowy `payment_payment_one_pending_per_order`, test planu zapytania), partiami, i pyta Ordering
  o płatność zamówień całej partii jednym zapytaniem. Zamówienie płatne – płatność zostaje.
- Inaczej, w osobnej transakcji z blokadą płatności (`lockPendingOfOrder`), anuluje sesję u dostawcy: `Cancelled` →
  `cancelled`, `AlreadyRejected` → `failed`, `AlreadyPaid` → płatność zostaje `pending` z ostrzeżeniem w logu (webhook ją
  rozliczy, także po ponowieniu z `failed`). Płatność zamknięta w ten sposób dalej przyjmuje późny webhook sukcesu
  (`succeed` z `cancelled` i `failed`, jak dziś), z oznaczeniem zwrotu, gdy zamówienie jest anulowane.
- Płatność „opłacona u dostawcy”, której webhook nie dotrze (np. usunięty z `failed`), zostaje `pending`; każdy
  przebieg pyta o nią dostawcę i zapisuje ostrzeżenie. Runbook: takiej wiadomości webhooka nie usuwa się, tylko ponawia.
- Dostawca niedostępny: ta płatność zostaje, błąd w logu, komenda idzie dalej. Kod wyjścia jak w W5 (2, gdy któraś
  płatność się nie udała).
- Dane sprzed epiku (`pending` porzucone wcześniej) zamyka pierwsze uruchomienie; gdy zaległość nie zmieści się w limicie
  czasu Joba, Job kończy się błędem (`OrderExpiryFailed`), a kolejne przebiegi kończą pracę (komenda jest idempotentna).

### W7. Statystyki wiadomości: pulpit i komenda sprawdzająca

- Port `MessagingStatistics` (Backoffice) dostaje odczyt kolejek Postgresa: dla `failed` liczbę wiadomości (do 1000,
  z oznaczeniem przekroczenia) i chwilę najstarszej (`created_at` jej wiersza); dla `outbox`, `scheduled`,
  `payment_simulator`, `promotions` i `jobs` liczbę gotowych wiadomości (`delivered_at IS NULL`, `available_at` ≤ teraz,
  do 1000) i wiek najstarszej gotowej w sekundach: teraz (UTC) − `min(available_at)` gotowych, `null` bez gotowych.
  Wiadomość opóźniona (np. `ExpireUnpaidOrder`, utworzona 35 minut wcześniej) ma wiek od chwili, w której stała się
  gotowa, nie od utworzenia. Zapytania korzystają z indeksu `(queue_name, available_at, delivered_at, id)`; czas pulpitu
  mieści się w limitach `DashboardTimingTest`.
- Lista kolejek adaptera to transporty `doctrine://` z `messenger.yaml` poza `failed`, w stałej kolejności `outbox`,
  `scheduled`, `payment_simulator`, `promotions`, `jobs`; test porównuje ją z konfiguracją (nowy transport Doctrine bez
  wpisu kończy test błędem).
- Ocenę zdrowia liczy API, w jednym miejscu: kolejka stoi, gdy wiek najstarszej gotowej wiadomości przekracza próg
  (parametr, 600 s); całość jest zdrowa, gdy `failed` jest puste i żadna kolejka nie stoi.
- `GET /api/v1/admin/dashboard`, sekcja `system` (tylko `admin`), nowe wymagane pole `messages`:
  `{ healthy: bool, failed: { count: int, capped: bool, oldestAt: date-time|null }, transports: [{ name, ready: int,
  capped: bool, oldestReadyAgeSeconds: int|null, stalled: bool }] }`. `outbox.pending` i `queues` (RabbitMQ) bez zmian.
  Eksport `openapi-admin.json` i klient panelu (`make -C apps/api openapi`, `make api-client`) w tym samym zadaniu, razem
  z typowanymi danymi testowymi panelu (`apps/admin/tests/dashboardSupport.ts`). Inne role: `system` dalej `null`.
- Komenda `app:messaging:check` (warstwa UI Backoffice) wypisuje tę samą ocenę: linię `failed` i linię na transport (bez
  treści wiadomości). Kod wyjścia: 0 – zdrowe, 1 – `failed` niepuste albo kolejka stoi, 2 – błąd odczytu (np. niedostępna
  baza). Nie łączy się z RabbitMQ, więc działa przy jego awarii.

### W8. Panel: ekran „Stan systemu”

- `SystemStatus` dostaje sekcję „Wiadomości”:
  - „Nieudane wiadomości”: liczba (`1000+` przy `capped`, ton `danger` przy liczbie > 0) z datą najstarszej w strefie
    przeglądarki; „Brak” przy zerze.
  - Tabela kolejek w bazie w regionie jak tabela kolejek RabbitMQ (nazwa, `tabindex="0"`, przewijanie w poziomie):
    kolejka, gotowe (`1000+` przy `capped`), najstarsza gotowa, stan „W normie” albo „Zator” (`stalled`).
  - Etykiety (`labels.ts`; nieznana nazwa zostaje jak z API): `outbox` „Zdarzenia do wysłania”, `scheduled` „Komendy
    opóźnione”, `payment_simulator` „Webhooki symulatora płatności”, `promotions` „Granice promocji”, `jobs` „Zadania
    w tle”.
  - Wiek: „—” przy `null`, „N s” poniżej minuty, „N min” poniżej godziny, „N h” powyżej (zaokrąglenie w dół).
  - Podsumowanie z `healthy`: „Kolejki działają” albo „Wymaga uwagi”.
- Panel nie liczy progu: wyświetla `stalled` i `healthy` z API. Pulpit nie pokazuje stanu systemu (bez zmian, decyzja
  ADMIN-SCREENS).
- Testy komponentu (vitest) i asercja w `e2e/tests/staff-dashboard.spec.ts`: sekcja widoczna, liczba nieudanych
  w formacie liczby albo „Brak”, wiersz każdej kolejki (asercja strukturalna, jak dziś liczba outboxa, bo stan `failed`
  zależy od wcześniejszych testów shardu).

### W9. CronJoby i alerty

- CronJob `queue-check` (`app:messaging:check`): co 5 minut, `concurrencyPolicy: Forbid`, `startingDeadlineSeconds: 240`,
  `backoffLimit: 0`, `activeDeadlineSeconds: 120`, historia 1 udany i 3 nieudane, zasoby `job`, środowisko `shop.apiEnv`.
- CronJob `order-expiry`: co 5 minut (przesunięty względem `queue-check`), `Forbid`, `startingDeadlineSeconds: 240`,
  `backoffLimit: 1`, `activeDeadlineSeconds: 600`; kontener uruchamia `ordering:expire-overdue`, potem
  `payment:close-abandoned`, zawsze obie, i kończy się błędem, gdy którakolwiek się nie udała.
- Harmonogramy i strefa w wartościach chartu, jak `cartPurge`.
- Reguły w `00-alerts.yaml`, grupa `shop-messaging`, przy `alerts.enabled`:
  - `MessagingUnhealthy` (`queue-check`, severity `warning`) i `OrderExpiryFailed` (`order-expiry`, `critical`): nieudany
    Job CronJoba nowszy niż jego ostatni sukces, jak `cronjobFailed`, ale jedna instancja alertu na CronJob niezależnie od
    liczby nieudanych Jobów (agregacja bez `job_name`; przy trwałym `failed` co 5 minut dochodzi nowy nieudany Job);
  - `OrderExpiryMissing` (`critical`): brak udanego przebiegu `order-expiry` od 30 minut albo brak jego metryki
    (`cronjobMissing` z progiem jako parametrem; `BackupMissing` i `BackupVerifyMissing` zostają przy 26 h).
  - Opisy po polsku mówią, co sprawdzić (`make -C infra/k8s failed`, ekran „Stan systemu”, `make -C infra/k8s status`)
    i odsyłają do runbooka. Metryki są już na liście dozwolonych. `MessagingUnhealthy` i `OrderExpiryFailed` milkną po
    pierwszym udanym Jobie po naprawie.
- Reguł jest dziesięć (w `prod` przy wyłączonych kopiach sześć).
- Odtworzenie: `restore.sh` nie odmawia z powodu aktywnych Jobów tych dwóch CronJobów (krótkie limity czasu); wstrzymuje
  je razem z `cart-purge-expired` i `backup`, czeka na ich aktywne Joby (najwyżej ich `activeDeadlineSeconds`) przed
  skalowaniem do zera i przywraca je jak pozostałe (także po przerwanej próbie). Test dymny sprawdza je w pętli CronJobów
  odmowy odtworzenia (`smoke.sh:790`).
- Czas od wiadomości w `failed` do alertu: do 5 minut na Job, do kilku minut ewaluacji reguły; razem do 15 minut.

### W10. Cele make dla `failed` i runbook

- `make -C infra/k8s failed HOST=…` (tylko odczyt): `messenger:failed:show --stats`, potem lista (identyfikator, klasa,
  błąd, data) z `--max=200`, przez `kubectl exec` w `deploy/worker-commands`.
- `make -C infra/k8s failed-retry HOST=… [ID=…]`: ponowienie jednej albo wszystkich (`messenger:failed:retry --force`)
  w procesie uruchomionym w podzie `worker-commands` z `php -d memory_limit=128M`, żeby drugi proces PHP nie przekroczył
  limitu kontenera obok workera.
- `make -C infra/k8s failed-remove HOST=… ID=…`: usunięcie jednej wiadomości; wypisuje ją i działa dopiero po słowie `tak`
  (jak `reinitialize`).
- `failed-retry` i `failed-remove` to w runbooku kroki „zgoda użytkownika”. Cele kończą się błędem bez `HOST`, bez `ID`
  (`failed-remove`) i przy błędzie `ssh` albo `kubectl`, bez zmian. Testy na atrapach `ssh` i `docker` jak `status`.
- Runbook `infra/k8s/README.md`, „Eksploatacja”: reakcja na `MessagingUnhealthy` (przegląd, przyczyna, ponowienie albo
  usunięcie, sprawdzenie w „Stanie systemu”; webhooka płatności się nie usuwa), `OrderExpiryFailed` (log Joba, zamówienie
  albo płatność z błędem, decyzja właściciela) i `OrderExpiryMissing` (wstrzymany CronJob, `suspend`, przerwany
  `restore`); przy zatorze `worker-commands` dłuższym niż karencja: sprawdzenie zamówień anulowanych z płatnością do zwrotu.

### W11. Zgodność wersji wiadomości (R13)

- Katalog złotych serializacji w testach API: dla każdej klasy wiadomości trasowanej na transport Doctrine (`routing`
  w `messenger.yaml`) i każdej klasy zdarzenia domenowego (`DomainEvent`, transport `outbox`) zapis serializerem PHP
  przykładowej wiadomości w kopercie ze stemplami, które produkcja na niej zostawia (np. `DelayStamp`, `BusNameStamp`,
  `CorrelationIdStamp`; bez `ErrorDetailsStamp`, którego `FlattenException` jest niedeterministyczny); dla zdarzeń także
  zapis JSON transportu `events`.
- Test (jednostkowy, bez usług):
  - każdy zapis dekoduje się bieżącym kodem do koperty z obiektem o zainicjalizowanych wszystkich właściwościach;
  - dla klas aplikacji (wiadomości, zdarzenia, `CorrelationIdStamp`) ponowne zakodowanie bieżącym kodem daje ten sam
    zapis ich części, więc zmiana pól klasy kończy test błędem; stemple Symfony sprawdza tylko dekodowanie starych zapisów;
  - każda klasa wiadomości i zdarzenia ma zapis (nowa klasa bez zapisu kończy test błędem).
- Zapisów się nie nadpisuje: po aktualizacji Symfony albo przy nowej klasie dochodzi nowy zapis obok starych, a stary
  zapis klasy usuniętej z kodu znika dopiero w wersji, w której znika jej handler.
- `apps/api/CLAUDE.md`, „Zgodność wersji”: zasada z D3 i odwołanie do testu; zasada „pola opcjonalne” zostaje tylko jako
  własność JSON, nie jako zgoda na zmianę pól.

## Zgodność wersji przy wdrożeniu epiku

- Kształt żadnej wiadomości się nie zmienia; serializer też nie. `ExpireOverdueOrder` nie ma trasy, więc nie trafia do
  żadnej kolejki.
- Deployment `worker` zachowuje nazwę, więc Argo CD wymienia jego pod jak przy każdym wdrożeniu; `worker-commands`
  powstaje w tej samej fali. Do końca wymiany stary pod obsługuje także transporty komend.
- Ponowienia `events` i `outbox` dotyczą wiadomości ponawianych po wdrożeniu; kolejki opóźnień RabbitMQ nowych długości
  powstają same.
- CronJoby ruszają po synchronizacji; pierwsze `order-expiry` wygasza zaległe zamówienia i zamyka zaległe płatności,
  pierwsze `queue-check` może od razu zgłosić wiadomości, które już leżą w `failed` (oczekiwane; runbook).

## Skutki operacyjne

- **Zasoby.** Drugi proces PHP (około 60–100 MB RSS w spoczynku); requesty i limity obu workerów razem jak dziś jeden
  (W1). CronJoby: dwa krótkie pody co 5 minut (start obrazu `api` i jedno zapytanie albo pusta partia), w szczycie dwa
  pody po zasobach `job`; historia Jobów ograniczona.
- **Skalowanie.** Oba workery można zwiększyć replikami po R6: `worker` konkuruje o wiadomości RabbitMQ i wiersze outboxa,
  `worker-commands` o wiersze transportów Doctrine; przy kilku replikach `worker-commands` pierwszeństwo webhooków jest
  „najlepszym wysiłkiem” (D2). Komendy CronJobów idą keysetem i partiami, więc ich koszt zależy od liczby zamówień `new`
  i płatności `pending`, nie od rozmiaru bazy.
- **Dyżurny widzi:** e-mail `MessagingUnhealthy`, `OrderExpiryFailed` albo `OrderExpiryMissing` z opisem; w panelu
  „Stan systemu”: liczba nieudanych wiadomości, kolejki z wiekiem i stanem; na serwerze `make -C infra/k8s failed`
  z listą i błędami; po naprawie alert znika po następnym udanym Jobie. Awaria RabbitMQ: `WorkloadNotReady` dla
  `rabbitmq` i `worker`, sklep przyjmuje i anuluje zamówienia, e-maile i indeks czekają w outboxie.

## Dokumentacja do zmiany

Opis działania (workery i transporty, handlery, endpoint, komendy CronJobów, CronJoby i reguły) jest w tej specyfikacji
i w kodzie; zadania nie dopisują go do `CLAUDE.md` aplikacji ani obszaru (zasada dokumentacji z nowego głównego
`CLAUDE.md`, #737). `CLAUDE.md` zmieniają tylko dwa zadania, bo zmieniają zasady albo polecenia dla agentów:

- `apps/api/CLAUDE.md`, „Zgodność wersji”: zasada z D3 (W11), w zadaniu testu zgodności wiadomości.
- `infra/k8s/CLAUDE.md`, „Cele Makefile”: nowe cele `failed`, `failed-retry`, `failed-remove`, którymi agent może
  operować na serwerze, i zgoda użytkownika na dwa ostatnie (W10), w zadaniu tych celów.

Dokumenty, które dziś podają zachowanie zmieniane przez epik, zmienia zadanie, które je zmienia:

- `infra/k8s/README.md`: odtworzenie (Deploymenty i CronJoby), alerty (liczba reguł, nowe severity), reakcja na nowe
  alerty i cele `failed*` (W1, W9, W10).
- `docs/vision.md` („Alerty”), `docs/adr/backups-observability.md` (lista reguł; lista skalowanych do zera przy
  odtworzeniu), `docs/features/deploy-ops/spec.md` (alerty, Deploymenty odtworzenia), `docs/features/deploy/spec.md`
  (worker, fale, oczekiwanie na rollout, nieudana migracja), `docs/features/admin-features/spec.md` (transport `jobs`
  w workerze, `backfill` po rolloucie), `docs/features/oncall/spec.md` (workloady wdrażane) (W1, W9).
- `docs/features/payment/spec.md` („Ryzyka i ograniczenia”: kolejność transportów, `ExpireUnpaidOrder` w `failed`,
  porzucone `pending`) (W1, W5, W6), `docs/features/demo-data/spec.md` („`new` wygasa tylko przez wiadomość”) (W5),
  `docs/features/product-page/spec.md` (ponowienia `events`) i `docs/features/pricing/spec.md` (konsumenci zdarzenia ceny
  i źródła, które potwierdzają bez pracy) (W3, W4).
- `README.md` (`docker compose restart worker` → oba workery) (W1).

## Kryteria akceptacji

1. **Komendy bez brokera (compose).** Test integracyjny API (`make -C apps/api test`, `tests/Integration/Shared/Messaging`):
   proces `messenger:consume payment_simulator scheduled promotions jobs` z adresem RabbitMQ na zamkniętym porcie
   (natychmiastowa odmowa) obsługuje gotowe `ExpireUnpaidOrder` (zamówienie `cancelled`, rezerwacja zwolniona,
   `OrderCancelled` w outboxie) i `DeliverSimulatedWebhook`; proces `outbox events` z tym samym adresem kończy się błędem
   (stan oczekiwany, opisany w scenariuszach awarii).
2. **Pierwszeństwo webhooków.** Test integracyjny: dostępne naraz webhook opłacenia (płatność w terminie) i anulowanie
   tego samego zamówienia; jeden proces z listą `worker-commands` kończy z zamówieniem `paid`.
3. **Komendy bez brokera (k3d).** Faza awarii testu dymnego: zamówienie z terminem przesuniętym w przeszłość (SQL)
   i gotową wiadomością, RabbitMQ zeskalowany do zera, CronJob `order-expiry` wstrzymany na czas tej części; zamówienie
   jest `cancelled` w ciągu 2 minut; po przywróceniu brokera wszystkie workloady wracają do gotowości.
4. **`failed` daje alert.** Test funkcjonalny `app:messaging:check`: puste kolejki – 0; wiadomość w `failed` – 1; gotowa
   wiadomość starsza niż próg – 1; wiadomość opóźniona, która właśnie stała się gotowa, nie stoi; wiadomość z
   `available_at` w przyszłości nie jest gotowa; niedostępna baza – 2. `promtool test rules`: `MessagingUnhealthy`
   i `OrderExpiryFailed` odpalają przy nieudanym Jobie swojego CronJoba nowszym niż ostatni sukces jako jedna instancja
   także przy wielu nieudanych Jobach, milczą po udanym Jobie, nie łapią Jobów innego CronJoba; `OrderExpiryMissing`
   odpala po 30 minutach bez sukcesu i przy braku metryki, milczy przy udanych przebiegach; dotychczasowe scenariusze bez
   zmian. Faza awarii testu dymnego: z wiadomością w `failed` Job z `queue-check` kończy się `Failed`, a po jej usunięciu
   albo ponowieniu następny Job kończy się sukcesem.
5. **CronJob wygasza zamówienie z wiadomością w `failed`.** Test funkcjonalny `ordering:expire-overdue`: zamówienie
   `new` po terminie z `ExpireUnpaidOrder` w `failed` jest `cancelled`, rezerwacja i użycie kodu zwolnione; zamówienie
   przed granicą karencji, opłacone i anulowane bez zmian; drugie uruchomienie bez zmian; równoległa płatność albo
   wiadomość w trakcie (dwa procesy) daje jeden wynik bez błędu; błąd jednego zamówienia nie zatrzymuje innych i daje
   kod 2; zła `--batch-size` – 1 bez zmian; test planu zapytania kandydatów. Faza awarii testu dymnego: Job z
   `order-expiry` wygasza takie zamówienie.
6. **Płatności `pending` (B3).** Test funkcjonalny `payment:close-abandoned`: `pending` zamówienia anulowanego albo
   przeterminowanego → `cancelled`; dostawca „odrzucona” → `failed`; dostawca „opłacona” → zostaje `pending`; zamówienie
   płatne → bez zmian; późny webhook sukcesu po zamknięciu → `succeeded` z oznaczeniem zwrotu; niedostępny dostawca →
   kod 2, pozostałe płatności zamknięte; test planu zapytania `pending`; `GetOrderPayability` nie zwraca danych zamówienia
   poza płatnością i statusem.
7. **Ponowienia.** `EventsRetryStrategyTest` z wartościami W3; test konfiguracji outboxa (200 prób, `max_delay` 60 s);
   test integracyjny: odbiorca `events` z błędem przejściowym jest ponawiany 10 razy, potem wiadomość jest w `failed`;
   błąd nieodwracalny od razu w `failed`.
8. **Uzgodnienie VAT.** Testy: `VariantPriceChanged` ze źródłem `tax_reconciliation` nie wywołuje `ProductIndexing`
   ani unieważnienia cache; ze źródłem `staff` wywołuje oba.
9. **Pulpit i panel.** Test funkcjonalny `GET /dashboard`: `system.messages` dla `admin` z liczbami, ograniczeniem do
   1000 i `stalled`, `system` `null` dla innych ról, 401 bez tokena; `DashboardTimingTest` przechodzi; lista transportów
   adaptera zgodna z konfiguracją; commitowane `openapi-admin.json` i klient są aktualne (CI). Testy panelu: liczba,
   `1000+`, „Brak”, „Zator”, etykiety, format wieku, `null`; E2E: sekcja na stosie (asercja strukturalna).
10. **Zgodność wiadomości.** Test złotych serializacji przechodzi; celowo dodane pole w klasie wiadomości, usunięte pole
    i nowa klasa bez zapisu kończą go błędem (sprawdzone w zadaniu, wynik w raporcie).
11. **Chart.** `make -C infra/k8s check`: oba workery z komendami jak w compose i limitami z W2, `worker-commands` bez
    czekania na RabbitMQ, oba CronJoby z parametrami z W9, budżet węzła, Sentry `worker-commands`, `worker-commands`
    i oba CronJoby w `restore.sh` (testy na atrapach, także ścieżka błędu i aktywny Job CronJoba sprawdzającego).
    `make -C infra/k8s smoke` przechodzi z fazą awarii (kryteria 3–5) i fazą nieudanej migracji obu workerów.
12. **Cele `failed*`.** Testy na atrapach: `failed` tylko czyta; `failed-retry` z `ID` i bez; `failed-remove` bez `tak`,
    bez `ID`, bez `HOST` i przy błędzie `ssh`/`kubectl` niczego nie zmienia.
13. **Całość.** `make check`, `make test` i `make e2e` przechodzą na stosie compose z dwoma workerami.

## Ryzyka i ograniczenia

- **Kolejność webhooków przed anulowaniami nie jest gwarancją domeny.** Webhook ponawiany po błędzie (opóźniony) albo
  druga replika `worker-commands` może przegrać z anulowaniem; z prawdziwym operatorem webhook omija worker. Zamyka to
  dopiero uzgodnienie z Payment przed anulowaniem (PAYMENTS-REAL).
- **CronJob omija pierwszeństwo webhooków przy stojącym `worker-commands`.** Gdy `worker-commands` stoi dłużej niż
  karencja (10 minut), `order-expiry` anuluje zamówienia po terminie, także opłacone w terminie, których webhook czeka
  w `payment_simulator`; płatność dostaje potem oznaczenie zwrotu. Dotyczy tylko symulatora (prawdziwy operator nie
  przechodzi przez worker); zator daje wcześniej `MessagingUnhealthy`, a runbook każe sprawdzić takie zamówienia.
  Droższe warianty (dłuższa karencja, pominięcie przebiegu przy zatorze `payment_simulator`) nie są potrzebne przy demo.
- **Długa awaria RabbitMQ.** Po około 3 godzinach wpisy outboxa trafiają do `failed`; alert i ponowienie po powrocie
  brokera. Zdarzenia (e-maile, indeks, cache) czekają przez całą awarię.
- **Wywołanie dostawcy w transakcji** przy zamykaniu płatności (jak dziś w `StartPayment`, D18 w specyfikacji
  hardening); przy symulatorze bez znaczenia, przy prawdziwym operatorze do przeniesienia w PAYMENTS-REAL.
- **Alert na każdą wiadomość w `failed`.** Wiadomość, której nie da się obsłużyć, trzyma alert do decyzji operatora
  (usunięcie). To zamierzone: każda taka wiadomość to utracony skutek.
- **Wstrzymany `queue-check`** (np. po przerwanym `restore`) nie daje sygnału; `restore.sh` przywraca CronJoby także po
  przerwanej próbie, a runbook odtworzenia każe sprawdzić `suspend`.
- **Zawieszony worker** (pętla w handlerze, zawieszone połączenie) widać dopiero po 10 minutach wieku kolejki i nikt go
  nie restartuje automatycznie (liveness: OPS-GUARDS).
- **Restarty z `--memory-limit`** liczą się w K2 `/oncall`; wyciek, który restartuje worker częściej niż co kilka godzin,
  da rekomendację rollbacku, co przy wycieku jest właściwym sygnałem.
- **Czas testu dymnego** rośnie o fazę awarii (zatrzymanie i powrót RabbitMQ, Joby z CronJobów, kilka minut);
  `worker` po powrocie brokera może czekać na backoff restartów, więc faza usuwa jego pod.
- **Wydajność transportów Doctrine przy docelowej skali** dalej niepotwierdzona testami obciążeniowymi (bez zmian).
