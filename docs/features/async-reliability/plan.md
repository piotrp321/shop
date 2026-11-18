# ASYNC-RELIABILITY – plan zadań

Specyfikacja: `docs/features/async-reliability/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | ASYNC-RELIABILITY-1, ASYNC-RELIABILITY-3, ASYNC-RELIABILITY-4, ASYNC-RELIABILITY-5, ASYNC-RELIABILITY-7 | |
| 2 | ASYNC-RELIABILITY-2, ASYNC-RELIABILITY-6 | |
| 3 | ASYNC-RELIABILITY-8 | |
| 4 | ASYNC-RELIABILITY-9 | |

Stan epiku prowadzonego przez AO jest w `ao status`, a plan usuwa `FINALIZE`.

Przed startem orkiestrator wykonuje eksperymenty E1–E7 ze specyfikacji („Wymagane przed startem”) i wpisuje wyniki do
specyfikacji; wynik inny niż oczekiwany to pytanie do użytkownika.

Łańcuchy zależności:
- testy i opis API niezależności od brokera (1) → dwa workery w compose i chartcie (2) → cele `failed*` (8);
- kontrakt pulpitu z klientem (5) → panel (6);
- workery (2), wygaszanie i zamykanie płatności (4), komenda sprawdzająca (5) i cele `failed*` (8) → CronJoby, alerty
  i faza awarii testu dymnego (9).

Zadania API (1, 3, 4, 5, 7) idą jedno po drugim (wspólny `apps/api/CLAUDE.md`, sekcja „Praca szeregowa”). Zaleca się
kolejność 1, 5, 4, 3, 7: 1 i 5 odblokowują 2 i 6, które mają pary równoległe z pozostałymi zadaniami API
(`parallel_with`), a 4 jest na ścieżce krytycznej do 9.

## Praca szeregowa

- ASYNC-RELIABILITY-1, ASYNC-RELIABILITY-3: `apps/api/CLAUDE.md`; jeden dokument zasad API (oba opisują albo zmieniają tam przetwarzanie wiadomości)
- ASYNC-RELIABILITY-1, ASYNC-RELIABILITY-4: `apps/api/CLAUDE.md`, `docs/features/payment/spec.md`; jeden dokument zasad API, jedna sekcja ryzyk płatności (oba opisują albo zmieniają tam przetwarzanie wiadomości)
- ASYNC-RELIABILITY-1, ASYNC-RELIABILITY-5: `apps/api/CLAUDE.md`; jeden dokument zasad API (oba opisują albo zmieniają tam przetwarzanie wiadomości)
- ASYNC-RELIABILITY-1, ASYNC-RELIABILITY-7: `apps/api/CLAUDE.md`; jeden dokument zasad API (oba opisują albo zmieniają tam przetwarzanie wiadomości)
- ASYNC-RELIABILITY-3, ASYNC-RELIABILITY-4: `apps/api/CLAUDE.md`, `apps/api/config/packages/messenger.yaml`; jeden dokument zasad API, jedna konfiguracja transportów (oba opisują albo zmieniają tam przetwarzanie wiadomości)
- ASYNC-RELIABILITY-3, ASYNC-RELIABILITY-5: `apps/api/CLAUDE.md`; jeden dokument zasad API (oba opisują albo zmieniają tam przetwarzanie wiadomości)
- ASYNC-RELIABILITY-3, ASYNC-RELIABILITY-7: `apps/api/CLAUDE.md`; jeden dokument zasad API (oba opisują albo zmieniają tam przetwarzanie wiadomości)
- ASYNC-RELIABILITY-4, ASYNC-RELIABILITY-5: `apps/api/CLAUDE.md`, `apps/api/config/services.yaml`; jeden dokument zasad API, jeden plik parametrów usług (oba opisują albo zmieniają tam przetwarzanie wiadomości)
- ASYNC-RELIABILITY-4, ASYNC-RELIABILITY-7: `apps/api/CLAUDE.md`; jeden dokument zasad API (oba opisują albo zmieniają tam przetwarzanie wiadomości)
- ASYNC-RELIABILITY-5, ASYNC-RELIABILITY-7: `apps/api/CLAUDE.md`; jeden dokument zasad API (oba opisują albo zmieniają tam przetwarzanie wiadomości)

## Zadania

```json
{
  "schema_version": 1,
  "epic": "ASYNC-RELIABILITY",
  "spec": "docs/features/async-reliability/spec.md",
  "tasks": [
    {
      "id": "ASYNC-RELIABILITY-1",
      "title": "Komendy bez brokera i pierwszeństwo webhooków: testy i opis w API",
      "scope": "Spec: W1 (część API), decyzja D2, kryteria 1 i 2, eksperymenty E1, E2 i E6. Testy integracyjne API w `tests/Integration/Shared/Messaging`: proces `messenger:consume payment_simulator scheduled promotions jobs` z RabbitMQ na zamkniętym porcie oraz pierwszeństwo webhooka przed anulowaniem w jednym procesie z tą listą. Dokumentacja bez opisu topologii, która zmieni się w zadaniu 2: `apps/api/CLAUDE.md` (miejsca wymieniające worker i jego listę transportów odsyłają do `infra/k8s/CLAUDE.md`, „Worker”; niezależność transportów Doctrine od brokera z odwołaniem do testu; zmienne compose „dla `api` i workerów”), `docs/features/payment/spec.md` (zdanie o kolejności transportów odsyła do „Worker”). Nie obejmuje: compose, chartu i skryptów (2), kodu aplikacji.",
      "acceptance_criteria": [
        "Test integracyjny: proces `messenger:consume payment_simulator scheduled promotions jobs` z adresem RabbitMQ na zamkniętym porcie obsługuje gotowe `ExpireUnpaidOrder` (zamówienie `cancelled`, rezerwacja zwolniona, `OrderCancelled` w outboxie) i `DeliverSimulatedWebhook`; proces `outbox events` z tym samym adresem kończy się błędem.",
        "Test integracyjny: przy dostępnych naraz webhooku opłacenia w terminie i anulowaniu tego samego zamówienia jeden proces z listą `payment_simulator scheduled promotions jobs` kończy z zamówieniem `paid`.",
        "`apps/api/CLAUDE.md` i `docs/features/payment/spec.md` nie podają listy transportów jednego workera; odsyłają do `infra/k8s/CLAUDE.md`, „Worker”, i podają, że transporty Doctrine nie wymagają brokera (odwołanie do testu).",
        "`make -C apps/api check` i `make -C apps/api test` przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "apps/api/tests/Integration/Shared/Messaging/",
        "apps/api/CLAUDE.md",
        "docs/features/payment/spec.md"
      ],
      "parallel_with": [
        "ASYNC-RELIABILITY-6"
      ]
    },
    {
      "id": "ASYNC-RELIABILITY-2",
      "title": "Dwa workery w compose i chartcie z limitami czasu i pamięci",
      "scope": "Spec: W1 (compose, chart, skrypty), W2, „Zgodność wersji przy wdrożeniu epiku”, „Skutki operacyjne”, kryteria 11 (workery) i 13, eksperyment E3. Compose: wspólna definicja workera (kotwica, jeden obraz, własny `APP_CACHE_DIR`), `worker` = `outbox events` z zależnością od `rabbitmq`, `worker-commands` = `payment_simulator scheduled promotions jobs` tylko z zależnością od `api`, healthchecki z własnymi listami, `--time-limit=86400 --memory-limit=192M`; `make up` startuje i odtwarza oba na końcu (`Makefile`); CI nie buduje drugiego obrazu (`.github/workflows/ci.yml`, `.github/scripts`). Chart: jeden sparametryzowany szablon kontenera workera, Deployment `worker` z nową listą, Deployment `worker-commands` bez czekania na RabbitMQ, zasoby `worker` i `workerCommands` we wszystkich profilach, schemat wartości, oczekiwanie Jobów `initialize` i `backfill` na oba, Sentry. `restore.sh` skaluje do zera i przywraca `worker-commands`; test dymny: listy podów i faza nieudanej migracji z oboma workerami (bez fazy awarii, ta jest w 9). Dokumentacja: `infra/k8s/CLAUDE.md` („Worker”), `infra/k8s/README.md`, `README.md`, `docs/features/deploy/spec.md`, `docs/features/deploy-ops/spec.md`, `docs/features/admin-features/spec.md`, `docs/adr/backups-observability.md` (lista skalowanych do zera), `docs/features/oncall/spec.md` (workloady wdrażane). Zależność od 1: podział opiera się na niezależności transportów Doctrine od brokera, którą dowodzi test zadania 1, a opis API odsyła już do „Worker”. Nie obejmuje: ponowień (3), CronJobów i alertów (9), kodu API.",
      "acceptance_criteria": [
        "Compose: `worker` i `worker-commands` z listami i limitami z W1 i W2 ze wspólnej definicji, `worker-commands` bez `depends_on` na `rabbitmq`, każdy z własnym katalogiem cache; `make up`, `make test` i `make e2e` przechodzą na stosie z dwoma workerami; CI nie buduje dodatkowego obrazu.",
        "`make -C infra/k8s check`: komendy obu Deploymentów równe komendom compose w `prod` i `ci`, sondy z ich listami, zbiór transportów obu workerów to wszystkie transporty z `messenger.yaml` poza `failed` (brakujący transport kończy test błędem), `worker-commands` bez initContainera RabbitMQ, oba z `--time-limit=86400 --memory-limit=192M`, zasoby obu workerów w `prod` i `load` razem nie większe niż 500m requestu CPU, 512Mi requestu i 1Gi limitu pamięci, budżet węzła przechodzi, DSN Sentry api także w `worker-commands`, Joby `initialize` i `backfill` czekają na rollout obu.",
        "Testy `restore.sh` na atrapach: `worker-commands` skalowany do zera i przywracany razem z `api`, `worker`, `store` (i `keycloak` przy skalowaniu), także na ścieżce błędu, która zostawia aplikacje wyłączone.",
        "`make -C infra/k8s smoke` przechodzi: listy podów obejmują `worker-commands`, a w fazie nieudanej migracji stare pody obu workerów obsługują i nowe nie są gotowe.",
        "Dokumentacja z zakresu opisuje dwa workery, ich transporty, limity i to, że `worker-commands` nie czeka na RabbitMQ; żadne miejsce nie opisuje już jednego workera ze wszystkimi transportami."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "ASYNC-RELIABILITY-1"
      ],
      "paths": [
        "infra/docker-compose.yml",
        "Makefile",
        "README.md",
        ".github/workflows/ci.yml",
        ".github/scripts/",
        "infra/k8s/charts/shop/",
        "infra/k8s/environments/ci/values.yaml",
        "infra/k8s/environments/load/values.yaml",
        "infra/k8s/environments/prod/values.yaml",
        "infra/k8s/scripts/restore.sh",
        "infra/k8s/scripts/smoke.sh",
        "infra/k8s/Makefile",
        "infra/k8s/tests/",
        "infra/k8s/CLAUDE.md",
        "infra/k8s/README.md",
        "docs/features/deploy/spec.md",
        "docs/features/deploy-ops/spec.md",
        "docs/features/admin-features/spec.md",
        "docs/features/oncall/spec.md",
        "docs/adr/backups-observability.md"
      ],
      "parallel_with": [
        "ASYNC-RELIABILITY-3",
        "ASYNC-RELIABILITY-4",
        "ASYNC-RELIABILITY-5",
        "ASYNC-RELIABILITY-6",
        "ASYNC-RELIABILITY-7"
      ]
    },
    {
      "id": "ASYNC-RELIABILITY-3",
      "title": "Ponowienia `events` w minutach, dłuższy relay outboxa i uzgodnienie VAT bez reindeksacji",
      "scope": "Spec: W3, W4, kryteria 7 i 8. API: strategia ponowień `events` w `config/packages/messenger.yaml` (10 prób, 1 s, ×3, do 300 s) i `outbox` (200 prób przy `max_delay` 60 s), skrócone opóźnienia `events` w `config/packages/test_messaging/messenger.yaml` z tą samą liczbą prób i mnożnikiem, `EventsRetryStrategyTest`; `ReindexProductOnPriceChange` i `InvalidateStorefrontOnPriceChange` (Pricing Infrastructure) pomijają źródło `tax_reconciliation`. Dokumentacja: `apps/api/CLAUDE.md` (ponowienia `events` i `outbox`, `test_messaging`, handlery ceny), `docs/features/product-page/spec.md` i `docs/features/pricing/spec.md`. Nie obejmuje: ponowień transportów komend, workerów (1, 2).",
      "acceptance_criteria": [
        "`EventsRetryStrategyTest`: `events` w produkcji ma 10 prób, opóźnienie 1 s, mnożnik 3 i `max_delay` 300 s; `test_messaging` ma tę samą liczbę prób, mnożnik i proporcję `max_delay` do pierwszego opóźnienia; `outbox` ma 200 prób przy `max_delay` 60 s.",
        "Test integracyjny (`test_messaging`): odbiorca `events` z błędem przejściowym jest ponawiany 10 razy, potem wiadomość jest w `failed`; `UnrecoverableMessageHandlingException` trafia do `failed` bez ponowień; test wyczerpania trwa najwyżej kilka sekund.",
        "Testy: `VariantPriceChanged` ze źródłem `tax_reconciliation` nie wywołuje `ProductIndexing` ani unieważnienia cache sklepu; ze źródłem `staff` wywołuje oba (testy tych handlerów zmieniają się razem z nowym zachowaniem).",
        "`make -C apps/api check` i `make -C apps/api test` przechodzą; dokumentacja z zakresu podaje nowe ponowienia i pominięcie `tax_reconciliation`."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "apps/api/config/packages/messenger.yaml",
        "apps/api/config/packages/test_messaging/messenger.yaml",
        "apps/api/src/Pricing/Infrastructure/Search/ReindexProductOnPriceChange.php",
        "apps/api/src/Pricing/Infrastructure/Storefront/InvalidateStorefrontOnPriceChange.php",
        "apps/api/tests/",
        "apps/api/CLAUDE.md",
        "docs/features/product-page/spec.md",
        "docs/features/pricing/spec.md"
      ],
      "parallel_with": [
        "ASYNC-RELIABILITY-2",
        "ASYNC-RELIABILITY-6",
        "ASYNC-RELIABILITY-8",
        "ASYNC-RELIABILITY-9"
      ]
    },
    {
      "id": "ASYNC-RELIABILITY-4",
      "title": "Wygaszanie zamówień po terminie i zamykanie porzuconych płatności",
      "scope": "Spec: W5, W6, kryteria 5 i 6 (testy API), decyzje D1 i D5. Ordering: logika wygaśnięcia w jednym miejscu Application używanym przez `ExpireUnpaidOrderHandler` i nową nietrasowaną komendę synchroniczną `ExpireOverdueOrder`, zapytanie DBAL identyfikatorów kandydatów (`status = 'new'`, `payment_deadline` przed granicą z oknem webhooków i karencją, keyset `(placed_at, id)`), komenda `ordering:expire-overdue [--batch-size]`, parametr karencji, zapytanie `GetOrderPayability(orderIds)` bez danych zamówienia, komentarz transportu `scheduled` w `messenger.yaml`. Payment: mapowanie wyniku anulowania sesji w jednym miejscu (używa go też `StartPaymentHandler`), metoda portu dla `GetOrderPayability`, komenda `payment:close-abandoned [--batch-size]` z keysetem po `order_id`. Bez nowego indeksu, bez nowej klasy wiadomości trasowanej na transport, bez zmian `PayableOrders::find` i `GetOrderForPayment`. Dokumentacja: `apps/api/CLAUDE.md`, `docs/features/payment/spec.md` (B3, `ExpireUnpaidOrder` w `failed`), `docs/features/order-management/spec.md`, `docs/features/demo-data/spec.md` (wygaszanie nie tylko przez wiadomość). Nie obejmuje: CronJoba (9), uzgodnienia płatności przed anulowaniem (poza zakresem).",
      "acceptance_criteria": [
        "Test funkcjonalny `ordering:expire-overdue`: zamówienie `new` po terminie wygaśnięcia i karencji z `ExpireUnpaidOrder` w `failed` jest `cancelled`, rezerwacja i użycie kodu zwolnione, `OrderCancelled` w outboxie, żadna wiadomość nie trafia na transport; zamówienie przed granicą karencji, opłacone i anulowane bez zmian; drugie uruchomienie bez zmian; kod 0 bez kandydatów.",
        "`ExpireUnpaidOrderHandler` zachowuje się jak dziś (dotychczasowe testy handlera przechodzą bez zmian), korzystając z tej samej logiki co `ExpireOverdueOrder`.",
        "Test współbieżności (dwa procesy): wygaszanie komendą i równoległe opłacenie albo równoległa wiadomość `ExpireUnpaidOrder` tego samego zamówienia dają jeden spójny wynik bez błędu komendy.",
        "Błąd jednego zamówienia (np. `ReservationStateConflict`) jest w logu `error` z identyfikatorem zamówienia, pozostałe są wygaszone, kod wyjścia 2; niedostępna baza – kod 2; `--batch-size` niebędąca dodatnią liczbą całkowitą – kod 1 bez zmian.",
        "Testy planu zapytań (po `ANALYZE`): kandydaci do wygaszenia na 100 tys. zamówień w innych statusach używają indeksu `ordering_order_status_placed_at_id_idx`; płatności `pending` czytane po indeksie `payment_payment_one_pending_per_order`.",
        "Test funkcjonalny `payment:close-abandoned`: `pending` zamówienia anulowanego albo przeterminowanego → `cancelled` z `completed_at`; dostawca „odrzucona” → `failed`; dostawca „opłacona” → zostaje `pending` z ostrzeżeniem w logu; zamówienie płatne → bez zmian; późny webhook sukcesu po zamknięciu → `succeeded` z oznaczeniem zwrotu; niedostępny dostawca → kod 2, pozostałe płatności zamknięte; jedno zapytanie `GetOrderPayability` na partię, bez numeru, kwoty i danych osobowych w wyniku.",
        "Deptrac bez nowych zależności między modułami; `make -C apps/api check` i `make -C apps/api test` przechodzą; dokumentacja z zakresu opisuje obie komendy, kody wyjścia i to, że wiadomość w `failed` zostaje do decyzji operatora."
      ],
      "risk": "CRITICAL",
      "depends_on": [],
      "paths": [
        "apps/api/src/Ordering/",
        "apps/api/src/Payment/",
        "apps/api/config/services.yaml",
        "apps/api/config/packages/messenger.yaml",
        "apps/api/tests/",
        "apps/api/CLAUDE.md",
        "docs/features/payment/spec.md",
        "docs/features/order-management/spec.md",
        "docs/features/demo-data/spec.md"
      ],
      "parallel_with": [
        "ASYNC-RELIABILITY-2",
        "ASYNC-RELIABILITY-6",
        "ASYNC-RELIABILITY-8"
      ]
    },
    {
      "id": "ASYNC-RELIABILITY-5",
      "title": "Statystyki wiadomości: pulpit API, kontrakt, klient i komenda sprawdzająca",
      "scope": "Spec: W7, kryteria 4 (testy komendy) i 9 (część API). Backoffice: odczyt kolejek Postgresa w porcie `MessagingStatistics` i jego adapterze (`failed` z liczbą do 1000 i `created_at` najstarszej; `outbox`, `scheduled`, `payment_simulator`, `promotions`, `jobs` z liczbą gotowych do 1000 i wiekiem teraz − `min(available_at)` gotowych), lista transportów zgodna z `messenger.yaml` (test), ocena zdrowia z progiem 600 s (parametr) w jednym miejscu, pole `system.messages` w `GET /api/v1/admin/dashboard`, komenda `app:messaging:check` (0/1/2, bez RabbitMQ). Kontrakt: `openapi-admin.json`, klient panelu (`apps/admin/src/api/schema.d.ts`) i typowane dane testowe panelu (`apps/admin/tests/dashboardSupport.ts`) w tym samym PR. Dokumentacja: `apps/api/CLAUDE.md`. Nie obejmuje: ekranu panelu (6), CronJoba i alertu (9); `outbox.pending` i `queues` bez zmian.",
      "acceptance_criteria": [
        "Test funkcjonalny `GET /api/v1/admin/dashboard`: `system.messages` dla roli `admin` z `failed.count`, `capped` przy ponad 1000, `oldestAt`, transportami w stałej kolejności z `ready`, `capped`, `oldestReadyAgeSeconds` (`null` bez gotowych) i `stalled` powyżej progu, `healthy`; dla innych ról `system` jest `null`; bez tokena 401.",
        "`DashboardTimingTest` przechodzi z nowymi odczytami; zapytania korzystają z indeksu `(queue_name, available_at, delivered_at, id)`; test porównuje listę transportów adaptera z transportami `doctrine://` z `messenger.yaml` poza `failed`.",
        "Test funkcjonalny `app:messaging:check`: puste kolejki – kod 0; wiadomość w `failed` – 1; gotowa wiadomość starsza niż próg – 1; wiadomość opóźniona, która właśnie stała się gotowa, nie stoi; wiadomość z `available_at` w przyszłości nie jest gotowa; niedostępna baza – 2; wyjście bez treści wiadomości; komenda nie łączy się z RabbitMQ.",
        "Commitowane `apps/api/openapi-admin.json` i `apps/admin/src/api/schema.d.ts` są aktualne (sprawdzenie CI); `make -C apps/api check`, `make -C apps/api test`, `make -C apps/admin check` i `make -C apps/admin test` przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "apps/api/src/Backoffice/",
        "apps/api/config/services.yaml",
        "apps/api/openapi-admin.json",
        "apps/admin/src/api/schema.d.ts",
        "apps/admin/tests/dashboardSupport.ts",
        "apps/api/tests/",
        "apps/api/CLAUDE.md"
      ],
      "parallel_with": [
        "ASYNC-RELIABILITY-2",
        "ASYNC-RELIABILITY-8"
      ]
    },
    {
      "id": "ASYNC-RELIABILITY-6",
      "title": "Panel: nieudane wiadomości i kolejki na ekranie „Stan systemu”",
      "scope": "Spec: W8, kryterium 9 (część panelu). `apps/admin/src/dashboard`: sekcja „Wiadomości” w `SystemStatus` (liczba nieudanych z `1000+`, tonem `danger` i datą najstarszej, „Brak” przy zerze; tabela kolejek w regionie z nazwą i `tabindex=\"0\"`, etykiety z W8 w `labels.ts`, wiek w formacie z W8, „W normie” / „Zator” z `stalled`, podsumowanie z `healthy`), tylko z wygenerowanego klienta, bez liczenia progów w panelu. Testy vitest i asercja strukturalna w `e2e/tests/staff-dashboard.spec.ts`. Dokumentacja: `apps/admin/CLAUDE.md`. Nie obejmuje: Pulpitu (bez stanu systemu), zmian API (5).",
      "acceptance_criteria": [
        "Testy komponentu: liczba nieudanych, `1000+` przy `capped`, „Brak” przy zerze, ton `danger` przy liczbie większej od zera, etykiety transportów z W8 i nieznana nazwa jak z API, wiek „—”, „N s”, „N min”, „N h”, „Zator” przy `stalled`, podsumowanie według `healthy`, `system: null` jak dziś.",
        "Tabela kolejek w regionie z nazwą dostępną i `tabindex=\"0\"`; ekran przechodzi istniejące testy dostępności i reflow ekranu „Stan systemu”.",
        "E2E `staff-dashboard.spec.ts`: sekcja „Wiadomości” jest widoczna, liczba nieudanych to liczba albo „Brak”, każda z pięciu kolejek ma wiersz (asercja strukturalna).",
        "`make -C apps/admin check` i `make -C apps/admin test` przechodzą; `apps/admin/CLAUDE.md` opisuje sekcję."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "ASYNC-RELIABILITY-5"
      ],
      "paths": [
        "apps/admin/src/dashboard/",
        "apps/admin/tests/",
        "apps/admin/CLAUDE.md",
        "e2e/tests/staff-dashboard.spec.ts"
      ],
      "parallel_with": [
        "ASYNC-RELIABILITY-1",
        "ASYNC-RELIABILITY-2",
        "ASYNC-RELIABILITY-3",
        "ASYNC-RELIABILITY-4",
        "ASYNC-RELIABILITY-7",
        "ASYNC-RELIABILITY-8",
        "ASYNC-RELIABILITY-9"
      ]
    },
    {
      "id": "ASYNC-RELIABILITY-7",
      "title": "Test zgodności wersji wiadomości i zasada wersjonowania",
      "scope": "Spec: W11, decyzja D3, kryterium 10, eksperyment E4. Testy API (jednostkowe, bez usług): złote serializacje każdej klasy wiadomości trasowanej na transport Doctrine i każdej klasy `DomainEvent` (serializer PHP w kopercie ze stemplami z produkcji bez `ErrorDetailsStamp`; zdarzenia także JSON transportu `events`), dekodowanie do obiektów z zainicjalizowanymi właściwościami, porównanie ponownego zapisu tylko dla klas aplikacji, stemple Symfony tylko dekodowane, pokrycie wszystkich klas, zapisy dopisywane, nigdy nadpisywane. Dokumentacja: `apps/api/CLAUDE.md`, „Zgodność wersji” (zasada z D3). Nie obejmuje: zmiany serializera ani kodu wiadomości.",
      "acceptance_criteria": [
        "Test przechodzi dla wszystkich klas wiadomości z `routing` w `messenger.yaml` i wszystkich implementacji `DomainEvent`.",
        "Celowo dodane pole w klasie wiadomości, usunięte pole i nowa klasa bez zapisu kończą test błędem z nazwą klasy (sprawdzone w zadaniu na kopii roboczej, wynik w raporcie, bez commitowania zmian); zmiana samego stempla Symfony nie wymaga przegenerowania istniejących zapisów.",
        "`apps/api/CLAUDE.md`, „Zgodność wersji”: zmiana kształtu wiadomości lub zdarzenia to nowa klasa nadawana w wersji po tej, która ją obsługuje; zapisy się dopisuje, a zapis klasy usuwa dopiero z jej handlerem; odwołanie do testu.",
        "`make -C apps/api check` i `make -C apps/api test` przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "apps/api/tests/",
        "apps/api/CLAUDE.md"
      ],
      "parallel_with": [
        "ASYNC-RELIABILITY-2",
        "ASYNC-RELIABILITY-6",
        "ASYNC-RELIABILITY-8",
        "ASYNC-RELIABILITY-9"
      ]
    },
    {
      "id": "ASYNC-RELIABILITY-8",
      "title": "Cele make `failed`, `failed-retry`, `failed-remove` i runbook",
      "scope": "Spec: W10, kryterium 12, eksperyment E5. `infra/k8s`: cele `failed HOST=…` (`--stats` i lista `--max=200`), `failed-retry HOST=… [ID=…]` (`php -d memory_limit=128M`) i `failed-remove HOST=… ID=…` (słowo `tak`) przez `kubectl exec` w `deploy/worker-commands` na serwerze (jak `status`), skrypty w `infra/k8s/scripts`, testy na atrapach `ssh` i `docker`. Runbook `infra/k8s/README.md`, „Eksploatacja”: przegląd i ponowienie albo usunięcie (kroki „zgoda użytkownika”), reakcja na `MessagingUnhealthy`, `OrderExpiryFailed` i `OrderExpiryMissing` (nazwy reguł ze specyfikacji), w tym sprawdzenie zamówień anulowanych z płatnością do zwrotu po zatorze `worker-commands` i zakaz usuwania webhooków płatności. Dokumentacja: `infra/k8s/CLAUDE.md` (cele), `CLAUDE.md` (cele wymagające zgody). Zależność od 2: cele działają w Deploymencie `worker-commands`. Nie obejmuje: CronJobów i reguł (9).",
      "acceptance_criteria": [
        "Testy na atrapach: `failed` wywołuje tylko `messenger:failed:show` (`--stats` i `--max=200`) w `deploy/worker-commands` i niczego nie zmienia; `failed-retry` z `ID` ponawia jedną wiadomość, bez `ID` wszystkie (`--force`), w procesie z `memory_limit=128M`.",
        "`failed-remove` bez słowa `tak`, bez `ID`, bez `HOST` i przy błędzie `ssh` albo `kubectl` kończy się błędem bez wywołania usunięcia; z `tak` usuwa wskazaną wiadomość.",
        "`failed` i `failed-retry` bez `HOST` oraz przy błędzie `ssh` albo `kubectl` kończą się niezerowym kodem z komunikatem.",
        "Runbook opisuje reakcję na trzy alerty i oznacza `failed-retry` i `failed-remove` jako „zgoda użytkownika”; `CLAUDE.md` wymienia je wśród celów uruchamianych po zgodzie; `make -C infra/k8s check` (shellcheck i testy) przechodzi."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "ASYNC-RELIABILITY-2"
      ],
      "paths": [
        "infra/k8s/Makefile",
        "infra/k8s/scripts/",
        "infra/k8s/tests/",
        "infra/k8s/README.md",
        "infra/k8s/CLAUDE.md",
        "CLAUDE.md"
      ],
      "parallel_with": [
        "ASYNC-RELIABILITY-3",
        "ASYNC-RELIABILITY-4",
        "ASYNC-RELIABILITY-5",
        "ASYNC-RELIABILITY-6",
        "ASYNC-RELIABILITY-7"
      ]
    },
    {
      "id": "ASYNC-RELIABILITY-9",
      "title": "CronJoby `queue-check` i `order-expiry`, alerty i faza awarii w teście dymnym",
      "scope": "Spec: W9, decyzje D1 i D4, kryteria 3, 4 i 5 (promtool i test dymny), 11 (CronJoby), eksperyment E7. Chart: CronJoby `queue-check` (`app:messaging:check`) i `order-expiry` (`ordering:expire-overdue`, potem `payment:close-abandoned`, zawsze obie) z harmonogramami w wartościach, `startingDeadlineSeconds`, limitami i historią z W9; grupa reguł `shop-messaging`: `MessagingUnhealthy` i `OrderExpiryFailed` (jedna instancja na CronJob, makro agregujące bez `job_name`), `OrderExpiryMissing` (`cronjobMissing` z progiem jako parametrem; reguły kopii bez zmian); scenariusze `tests/alerts/alerts.test.yml`. `restore.sh`: aktywne Joby tych CronJobów nie dają odmowy, CronJoby wstrzymane i przywracane, oczekiwanie na ich Joby przed skalowaniem do zera. Test dymny: jedna faza awarii (RabbitMQ zeskalowany do zera przy wstrzymanym `order-expiry`: zamówienie wygasza `worker-commands`; potem wiadomość w `failed`: Job z `queue-check` `Failed`, Job z `order-expiry` wygasza zamówienie, po ponowieniu albo usunięciu wiadomości Job z `queue-check` udany; usunięcie poda `worker` po powrocie brokera) i nowe CronJoby w pętli odmowy odtworzenia. Dokumentacja: `infra/k8s/CLAUDE.md`, `infra/k8s/README.md` (liczba reguł, severity, odtworzenie), `docs/vision.md`, `docs/adr/backups-observability.md`, `docs/features/deploy-ops/spec.md`. Nie obejmuje: harmonogramów w compose.",
      "acceptance_criteria": [
        "`promtool test rules`: `MessagingUnhealthy` i `OrderExpiryFailed` odpalają przy nieudanym Jobie swojego CronJoba nowszym niż ostatni sukces jako jedna instancja także przy wielu nieudanych Jobach, milczą po udanym Jobie i nie łapią Jobów innego CronJoba; `OrderExpiryMissing` odpala po 30 minutach bez sukcesu i przy braku metryki, milczy przy udanych przebiegach; dotychczasowe scenariusze bez zmian; reguły tylko przy `alerts.enabled`.",
        "Testy renderu: oba CronJoby z harmonogramami z wartości, `concurrencyPolicy: Forbid`, `startingDeadlineSeconds: 240`, `backoffLimit` 0 i 1, `activeDeadlineSeconds` 120 i 600, zasobami `job` i `shop.apiEnv`; kontener `order-expiry` uruchamia obie komendy i kończy się błędem, gdy którakolwiek się nie udała (test skryptu, także ścieżka błędu pierwszej komendy).",
        "Testy `restore.sh` na atrapach: aktywny Job `queue-check` albo `order-expiry` nie daje odmowy, a odtworzenie czeka na jego koniec przed skalowaniem do zera; oba CronJoby wstrzymane przed odtworzeniem i przywrócone, także po przerwanej próbie; inny aktywny Job dalej daje odmowę.",
        "`make -C infra/k8s smoke`: przy RabbitMQ zeskalowanym do zera i wstrzymanym `order-expiry` zamówienie po terminie z gotową wiadomością jest `cancelled` w ciągu 2 minut, a po powrocie brokera wszystkie workloady są gotowe; z wiadomością w `failed` Job z `queue-check` kończy się `Failed`, Job z `order-expiry` zmienia zamówienie `new` po terminie na `cancelled`, po ponowieniu albo usunięciu wiadomości następny Job z `queue-check` kończy się sukcesem; odmowa odtworzenia nie wstrzymuje żadnego z czterech CronJobów; pozostałe fazy przechodzą.",
        "`make -C infra/k8s check` przechodzi; dokumentacja z zakresu podaje dziesięć reguł (sześć w `prod` przy wyłączonych kopiach) i oba CronJoby."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "ASYNC-RELIABILITY-2",
        "ASYNC-RELIABILITY-4",
        "ASYNC-RELIABILITY-5",
        "ASYNC-RELIABILITY-8"
      ],
      "paths": [
        "infra/k8s/charts/shop/",
        "infra/k8s/scripts/smoke.sh",
        "infra/k8s/scripts/restore.sh",
        "infra/k8s/smoke/checks.py",
        "infra/k8s/tests/",
        "infra/k8s/CLAUDE.md",
        "infra/k8s/README.md",
        "docs/vision.md",
        "docs/adr/backups-observability.md",
        "docs/features/deploy-ops/spec.md"
      ],
      "parallel_with": [
        "ASYNC-RELIABILITY-3",
        "ASYNC-RELIABILITY-6",
        "ASYNC-RELIABILITY-7"
      ]
    }
  ]
}
```
