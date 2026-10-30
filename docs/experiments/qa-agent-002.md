# Eksperyment QA-002: audyt Transactional Outbox (Task 14)

Drugi eksperyment z agentami `qa-adversarial` i `architecture-reviewer` (definicje w `.claude/agents/`, bez zmian od
QA-001). Zasady jak w [QA-001](qa-agent-001.md); zmienił się tylko zakres.

## Warunki

- Data: 2026-10-30.
- Rewizja: `660f3f971aeb74be43f0d090ff7edb1892872b19` (`main`, czyste drzewo), ta sama co w QA-001. Outbox trafił
  do `main` w PR #10 (`2cc3473`) i od tego czasu był rozwijany; audytowano stan na tej rewizji.
- Zakres: Transactional Outbox, Task 14 w `docs/features/walking-skeleton/plan.md`.
- Oba agenty uruchomione równolegle, w tle, przez orkiestratora (główna sesja Claude Code, Opus 5.5).
- Bez wcześniejszych review, wyników drugiego agenta, raportu QA-001 ani wskazówek, czego szukać.
- Zmiana względem QA-001: agenty pracowały na osobnym, czystym checkoucie rewizji (`git worktree add --detach`
  w scratchpadzie), a nie w worktree orkiestratora. W ten sposób nie widziały niezacommitowanego raportu QA-001.
- Zakaz modyfikacji repozytorium; pliki tymczasowe w scratchpadzie (`qa2/`, `arch2/`).

Polecenie: identyczne jak w QA-001, z dwiema podmianami: „audyt systemu uwierzytelniania” → „audyt Transactional
Outbox (Task 14 w docs/features/walking-skeleton/plan.md)” i ścieżka repozytorium → czysty checkout.

## Zużycie

| Agent | Tokeny | Wywołania narzędzi | Czas |
|---|---|---|---|
| `qa-adversarial` | 148 060 | 73 | 9 min 24 s |
| `architecture-reviewer` | 78 093 | 23 | 4 min 1 s |

Dane z powiadomień o zakończeniu agentów. Bez kosztu weryfikacji przez orkiestratora.

## Środowisko i ograniczenia

- Checkout audytu bez `.env` i bez własnego stosu Docker.
- QA zbudował własne izolowane środowisko: kopia `apps/api` w scratchpadzie, kontenery `qa2-postgres` (postgres:18.6)
  i `qa2-rabbitmq` (4.4.0) w sieci `qa2net`, obraz `shop-t16-api`, `vendor` z wolumenu `shop-t16_api-vendor` tylko do
  odczytu (`composer.lock` z tym samym md5 co rewizja). Testy przez `docker run`, nie przez `make`. Po audycie usunął
  kontenery i sieć (orkiestrator sprawdził: brak `qa2-*` w `docker ps -a` i `docker network ls`).
- QA nie postawił Elasticsearcha; trasa sprawdzona do wywołania `IndexProduct`.
- Architekt nie uruchamiał testów. Oparł się na CI dla `660f3f9` (`gh run list`: success, `OK (591 tests, 3995
  assertions)`) i na kodzie vendor czytanym w kontenerze `shop-t16-api-1` (symfony/amqp-messenger 8.1.10).
- Nie uruchomiono: `make check`, `make test`, kroków weryfikacji Task 14 na pełnym stosie (`make up`, health check
  workera, restart workera po zatrzymaniu RabbitMQ), `EventFlowTest`, testów obciążeniowych.
- Repozytorium po audycie: `git status` czysty w obu checkoutach.

## Wyniki testów (QA)

- `phpunit tests/Unit/Shared/Messaging tests/Integration/Shared/Messaging/OutboxAtomicityTest.php
  tests/Integration/Shared/Messaging/OutboxRelayTest.php`: OK, 10 testów, 744 asercje.
- `doctrine:schema:update --dump-sql`: „Nothing to update”; `doctrine:migrations:status`: New = 0.
- Testy i komendy własne QA (tylko w scratchpadzie):
  - T1 (atomowość, duplikat sluga): zielony, dokładnie jeden produkt i jeden wiersz outboxa.
  - T2 (wiersz z nieistniejącą klasą zdarzenia): wiersz trafia do `failed` (outbox 0, failed 1), worker się nie wiesza.
  - T3 (`occurredAt` z mikrosekundami): czerwony (M2).
  - `Qa2LostTest` (usunięcie kolejki `events`): zielony jako test informacyjny, zdarzenie przepada (M3).
  - `qa2:publish` + `qa2:queues`: 300 produktów bez Elasticsearcha, dashboard zwraca 583 kolejki (M1).
  - Trzy równoległe workery, 300 produktów: relay 99 + 98 + 103 = 300, bez duplikatów i utraty (`SKIP LOCKED`).
  - Restart Postgresa przy działającym workerze: proces kończy się kodem 1 (wznawia go `restart: unless-stopped`).
  - `docker stop` workera: zakończenie w ułamku sekundy, kod 0.

## Zgłoszenia

### Potwierdzone testem (QA)

**M1. Minor. Dashboard pokazuje setki kolejek opóźnienia `delay_events_events_<ms>_retry`.**

- `apps/api/config/packages/messenger.yaml:36-39`: strategia ponowień `events` bez ustawionego `jitter`
  (domyślnie ±10%); transport AMQP tworzy kolejkę opóźnienia dla każdej dokładnej wartości opóźnienia w ms.
- `apps/api/src/Backoffice/Infrastructure/MessagingStatisticsAdapter.php:62-76`: adapter zwraca wszystkie kolejki
  vhosta bez filtrowania.
- Scenariusz: 300 produktów, worker działa, Elasticsearch niedostępny → `IndexProduct` zawodzi i wiadomości idą do
  ponowień. Wynik: adapter zwrócił 583 kolejki (w RabbitMQ 626 kolejek opóźnienia; próba z 400 produktami: 369).
  Spec pokazuje w dashboardzie jedną kolejkę `events`.
- Dodatkowo licznik `messages` kolejki `events` nie obejmuje 269-286 wiadomości czekających w kolejkach opóźnienia;
  panel może pokazywać 0.
- Kolejki znikają same (`x-expires`). Problem raportowania, nie utraty danych.
- Propozycja QA: filtrować `delay_*` w adapterze (lub doliczać je do `events`) albo `jitter: 0` dla `events`.
- Orkiestrator potwierdził lekturą kodu brak filtra i brak `jitter`; testu nie powtarzał (wymaga RabbitMQ i workera).

**M2. Minor. `occurredAt` traci mikrosekundy na trasie outbox → RabbitMQ.**

- `apps/api/config/packages/messenger.yaml:30` (`symfony_serializer` dla `events`),
  `RelayDomainEventToBroker.php:25`.
- Wejście `2026-10-29T12:00:00.123456+00:00`, po odczycie z `events` `…12:00:00.000000`.
- Dziś żaden handler nie używa `occurredAt`; testy repozytorium używają czasu bez mikrosekund.
- Dowód: `Qa2OutboxTest::testT3OccurredAtSurvivesRelay` czerwony. Orkiestrator testu nie powtarzał.

**M3. Minor (wąskie okno). Zdarzenie przepada, gdy kolejka `events` zniknie w trakcie pracy.**

- `RelayDomainEventToBroker.php:25` i `confirm_timeout` w `messenger.yaml`: publikacja bez flagi `mandatory`, więc
  RabbitMQ potwierdza wiadomość, której nie ma dokąd skierować. Spec i komentarz w `messenger.yaml:37-38` mówią, że
  ack outboxa oznacza „RabbitMQ ma wiadomość”; w tym przypadku to nieprawda.
- Scenariusz: relay zdarzenia 1, `DELETE /api/queues/test/events`, relay zdarzenia 2. Wynik: `outbox rows after
  relay: 0 failed rows: 0`, kolejka 404.
- Wymaga zewnętrznej ingerencji (ręczne usunięcie kolejki, polityka, reset brokera); worker po 404 sam deklaruje
  kolejkę ponownie. Symulacja w jednym kernelu, nie w osobnym procesie workera.

### Potwierdzone lekturą kodu i komendami (architekt)

**P1. Średnia. Zasada „każdy handler `event.bus` ma jawny `fromTransport`” nie jest niczym pilnowana.**

- `RelayDomainEventToBroker.php:16`, `apps/api/src/Catalog/Application/EventHandler/IndexProduct.php:21`.
- `grep -rn -i "fromTransport\|from_transport"` w `src tests config` (oraz Deptrac i PHPStan u architekta): tylko dwa
  atrybuty w `src`, żadnego testu ani reguły. `MessengerRoutingTest:60` sprawdza tylko `IndexProduct`.
- Skutek: nowy handler bez `fromTransport` zadziała dwa razy (przy `outbox` i `events`), a jego błędy przy `outbox`
  będą ponawiane strategią outboxa (30 prób, ok. 25 min). Testy przejdą. CLAUDE.md wymaga sprawdzania prób
  obejścia reguł.
- Propozycja: test refleksyjny po klasach z `#[AsMessageHandler(bus: 'event.bus')]` plus sonda negatywna.
- Orkiestrator powtórzył grep: wynik zgodny.

**P2. Niska. Dwa mechanizmy konfiguracji środowisk testowych i ręczne kopie plików.**

- `apps/api/config/packages/test_messaging/dama_doctrine_test_bundle.yaml` jest identyczny z wersją w `test/`,
  `test_messaging/security.yaml` różni się tylko komentarzem (`diff`, powtórzony przez orkiestratora).
- Inne pliki (`framework.yaml:23`, `doctrine.yaml:25`, `monolog.yaml:22`, `services.yaml`, `security.yaml:66`)
  używają kotwic `when@…: *test`. Nowa konfiguracja `test` musi być ręcznie skopiowana do `test_messaging`.
- Propozycja: jeden mechanizm (`when@test_messaging: *test`), katalog `test_messaging/` tylko z DSN `events`.

**P3. Niska, kosmetyka. Nieaktualny komentarz w `apps/api/config/services.yaml:171`.**

- „Unused by application code yet” nad aliasami `ProductImageStorage`, `ImageUrlGenerator`, `DomainEventPublisher`,
  które są już używane (np. `CreateProductHandler`, `ListProductsHandler`, `DemoCatalogLoader`).
- Orkiestrator potwierdził treść komentarza.

### Przypuszczenia

- P4 (architekt) i ryzyko 1 (QA), **niezależnie oba**: klasa zdarzenia domenowego jest formatem na łączu.
  `outbox` i `failed` używają `PhpSerializer`, `events` wpisuje FQCN do nagłówka `type`. Zmiana nazwy lub
  namespace `ProductCreated` psuje zapisane wiersze i wiadomości w RabbitMQ. QA sprawdził częściowo (T2): wiersz
  z nieistniejącą klasą trafia do `failed`, więc nie ginie. Obaj: akceptowalne w szkielecie, warto opisać jako
  świadomy kompromis.
- QA: zdarzenia bez własnego `eventId`; przyszli odbiorcy nie zdeduplikują po identyfikatorze zdarzenia
  (`IndexProduct` jest idempotentny dzięki wersji produktu).
- QA: worker (`outbox events`) obsługuje `events` dopiero, gdy `outbox` jest pusty; przy dużej zaległości możliwe
  głodzenie `events`. W teście 400 produktów nie wystąpiło. Zgodne z komentarzem w compose.
- QA: po awarii bazy wiersze pobrane bez ack wracają dopiero po `redeliver_timeout` (300 s). Udokumentowany
  kompromis w spec.
- Architekt: zastąpienie przekaźnika natywną opcją Symfony 8.2 (Status planu) niesprawdzone, bo w `vendor` jest 8.1.

## Sprawdzone bez zastrzeżeń

- Atomowość: transport Doctrine na tym samym połączeniu co agregaty, w transakcji `doctrine_transaction`; rollback
  usuwa produkt i wiersz outboxa (testy repozytorium, T1 QA, analiza architekta).
- Pętla relayu zablokowana przez `fromTransport`; `TransportNamesStamp(['events'])` w relayu.
- Publisher confirms: nack rzuca `TransportException` (architekt w `Connection.php:383`; QA: test `reject-publish`
  w `OutboxRelayTest` przechodzi).
- `SKIP LOCKED` i trzy równoległe workery bez duplikatów i utraty (QA).
- Trwałość AMQP: `delivery_mode=2`, durable kolejka i exchange (QA).
- Migracja zgodna z mapowaniem; `auto_setup=false`, `use_notify: false`.
- `DbalCatalogReset` czyści `outbox` w tej samej transakcji co TRUNCATE, zostawia `failed`.
- Asymetryczne strategie ponowień (`outbox` 30 prób, `events` 3), osobny kernel `test_messaging` z prawdziwymi
  transportami.

Werdykty: QA – PASS (brak Critical i Important, M1 warto rozważyć przed udostępnieniem dashboardu);
architekt – KEEP (P1 domknąć testem, P2 i P3 to porządki).

## Porównanie agentów

| | `qa-adversarial` | `architecture-reviewer` |
|---|---|---|
| Metoda | dynamiczna: własne Postgres i RabbitMQ, testy scratch, obciążenie 300-400 zdarzeń, 3 workery, restart bazy | statyczna: kod, spec, konfiguracja, vendor, `grep`, `diff`, wynik CI |
| Zgłoszenia potwierdzone | 3 (M1-M3, testem) | 3 (P1-P3, kodem i komendami) |
| Przypuszczenia | 4 | 1 (P4) |
| Wspólne | ryzyko serializacji klasy zdarzenia (= P4) | |
| Koszt | 148 tys. tokenów, 9,5 min | 78 tys. tokenów, 4 min |

Obserwacje:

- Potwierdzone zgłoszenia obu agentów są rozłączne. QA znajduje zachowanie w czasie działania (kolejki opóźnienia,
  precyzja serializacji, ack bez routingu); architekt znajduje brak strażników reguł i duplikację konfiguracji.
- Jedyna zbieżność (serializacja klasy zdarzenia) to przypuszczenie u obu; QA częściowo je sprawdził.
- W QA-001 najmocniejsze ustalenie (rotacja kluczy) znaleźli obaj. Tu żaden problem nie został potwierdzony przez
  obu, co wzmacnia wniosek, że agenty się uzupełniają, a nie dublują.
- QA tym razem postawił własne usługi (Postgres, RabbitMQ) i po sobie posprzątał; w QA-001 korzystał tylko z
  `--network none`. Daje to mocniejsze dowody, ale większy koszt.
- Architekt nie uruchamiał testów i oparł się na CI; w QA-001 nie miał nawet tego.
- Oba agenty zmieściły się w ograniczeniach: brak zmian w repozytorium, stos `shop-t16` nietknięty.
- Koszt obu agentów spadł względem QA-001 (QA −26%, architekt −41%), przy mniejszym zakresie kodu.

## Weryfikacja przez orkiestratora

- Powtórzone: `grep` dla `fromTransport`, `diff` plików `test_messaging/`, odczyt komentarza w `services.yaml`.
- Przeczytane: `messenger.yaml` (brak `jitter`, `symfony_serializer` dla `events`), `MessagingStatisticsAdapter`
  (brak filtra kolejek), `RelayDomainEventToBroker`, `ProductCreated` (`occurredAt` jako `\DateTimeImmutable`).
- Sprawdzone sprzątanie po QA: brak kontenerów i sieci `qa2*`; `git status` czysty.
- Nie powtarzane: testy M1-M3 (wymagają Postgresa, RabbitMQ i workera) ani przypuszczenia.
