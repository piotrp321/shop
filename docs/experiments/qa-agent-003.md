# Eksperyment QA-003: `qa-adversarial` na Sonnet High i Opus High

Pytanie: czy w roli `qa-adversarial` Opus High daje lepszą jakość i opłacalność niż Sonnet High? Badany zakres był ten
sam co w QA-002: Transactional Outbox (Task 14).

## Warunki

- Data: 2026-10-30. Rewizja: `660f3f971aeb74be43f0d090ff7edb1892872b19`, ta sama co w QA-001 i QA-002.
- Zakres: Transactional Outbox, Task 14 w `docs/features/walking-skeleton/plan.md`.
- Agent A: `qa-adversarial`, model `sonnet` (`claude-sonnet-5-5`), effort `high`.
- Agent B: `qa-adversarial`, model `opus` (`claude-opus-5-5`), effort `high`.
- Definicja agenta bez zmian: `.claude/agents/qa-adversarial.md`. Model i effort ustawione parametrami wywołania
  `Agent`, więc tymczasowe kopie definicji nie były potrzebne.
- Agenty uruchomione równolegle, w tle, o 10:57:00 CET, przez orkiestratora (główna sesja Claude Code, Opus 5.5).
- Polecenie identyczne. Różniły się tylko trzy rzeczy:
  - ścieżka checkoutu: `qa3a/repo` dla A, `qa3b/repo` dla B;
  - prefiks zasobów Docker: `qa3a-` dla A, `qa3b-` dla B;
  - katalog roboczy: `qa3a/work` dla A, `qa3b/work` dla B.
- Każdy agent miał osobny, czysty checkout rewizji (`git worktree add --detach` w scratchpadzie orkiestratora). Nie było
  w nim `docs/experiments/`, raportów QA-001 i QA-002 ani wyników review.
- Budżet w poleceniu, taki sam dla obu: około 40 minut i około 120 wywołań narzędzi.
- Zasady w poleceniu:
  - własne kontenery bez portów na hoście;
  - nie dotykać cudzych stosów (`shop-t16-*`, `fd-*`, `m3-*`);
  - sprzątnąć po sobie;
  - zostawić czysty `git status`.
- Agenty nie dostały żadnych wskazówek, czego szukać.

## Pomiary

Źródło: transkrypty agentów (JSONL), przeliczone skryptem orkiestratora. Wiadomości API deduplikowane po `message.id`.
Czas narzędzia to różnica między znacznikiem czasu `tool_use` i odpowiadającego mu `tool_result`. „Tokeny (harness)” to
wartość `subagent_tokens` z powiadomienia o zakończeniu, ta sama miara co w QA-002.

| | A: Sonnet High | B: Opus High | B / A |
|---|---|---|---|
| Czas (wall clock) | 6 min 34 s | 12 min 14 s | 1,86× |
| Czas modelu (wall − narzędzia) | ok. 220 s | ok. 477 s | 2,2× |
| Wywołania API | 38 | 41 | 1,08× |
| Tokeny (harness) | 89 087 | 104 137 | 1,17× |
| Tokeny wyjściowe | 26 776 | 46 864 | 1,75× |
| Odczyt z cache | 2 129 710 | 2 702 810 | 1,27× |
| Zapis do cache (5 min) | 88 784 | 99 945 | 1,13× |
| Wejście bez cache | 78 | 82 | |
| **Szacowany koszt** | **0,92 USD** | **1,98 USD** | **2,15×** |
| Wywołania narzędzi | 44 (Bash 38, Write 4, Edit 1, handback 1) | 52 (Bash 47, Write 4, handback 1) | 1,18× |
| Łączny czas narzędzi | 175 s | 257 s | 1,47× |
| Mediana / maks. Bash | 1,5 s / 91 s | 1,5 s / 45 s | |
| Bash zakończone błędem | 7 | 6 | |

Koszt policzony według cennika API (USD za MTok):

| | Wejście | Wyjście | Odczyt cache | Zapis cache 5 min |
|---|---|---|---|---|
| Sonnet 5.5 | 2 | 10 | 0,20 | 2,50 |
| Opus 5.5 | 4 | 20 | 0,20 | 5,00 |

Stawka zapisu cache to założenie: 1,25 × stawka wejścia, standardowy mnożnik. Koszt to szacunek. Nie obejmuje pracy
orkiestratora ani faktycznego rozliczenia subskrypcji.

## Wyniki

### A: Sonnet High, werdykt PASS

- **Potwierdzone błędy:** brak.
- **Podejrzenia:** 3, wszystkie Minor:
  1. `DbalCatalogReset` kasuje wiersze `outbox` w trakcie pracy workera.
  2. Usunięcie kolejki `events` przy działającym workerze może po cichu gubić zdarzenia (brak `mandatory`).
     Agent wprost napisał, że tego nie sprawdzał.
  3. Wiersz outboxa, którego nie da się zdekodować, trafia do `failed`. Agent sam zamknął to testem.
- **Testy własne:**
  - 300 zdarzeń i 3 równoległe workery: 300 unikalnych wiadomości, 0 duplikatów, 0 wierszy w outboxie.
  - 500 zdarzeń i jeden worker: bez strat i duplikatów.
  - Atomowość przez `command.bus` z duplikatem sluga: `products=1 outbox=1`.
  - Wiersz z nieistniejącą klasą trafia do `failed`, a worker się nie wiesza.
- **Testy repozytorium:** 17 testów, 15 OK. 2 błędy w `EventFlowTest`, bo agent nie postawił Elasticsearcha
  („Failed to connect to localhost port 9200”).
- **`check` przez Makefile z wrapperem `RUN`:** PHPStan bez błędów, Deptrac 0 naruszeń, CS Fixer 0 plików do poprawy.
- **Sposób uruchamiania:** testy przez `docker exec`, nie przez Makefile (tłumaczy to brakiem ES); `check` przez
  Makefile.

### B: Opus High, werdykt PASS

- **Potwierdzone błędy:** 1, z testem. Agent dał wagę Minor, orkiestrator po weryfikacji Important (sekcja „Waga
  błędu”). Zdarzenie bez kolejki docelowej jest potwierdzane przez brokera, a wiersz
  outboxa usuwany.
  - Lokalizacja: `messenger.yaml`, transport `events` (brak `mandatory` i `alternate-exchange`), oraz
    `RelayDomainEventToBroker.php`.
  - Test: `QaScratchTest::testUnroutablePublishAcksOutboxRow`. Przebieg: publikacja, `DELETE /api/queues/test/events`,
    `messenger:consume outbox`.
  - Wynik oczekiwany: 1 wiersz w outboxie.
  - Wynik rzeczywisty: `outbox rows after relay: 0, events queue exists: no`, `Failed asserting that 0 is identical to 1`.
  - Agent wskazał niezgodność ze `spec.md:311` („wiersz outboxa dostaje ack dopiero, gdy RabbitMQ ma wiadomość”).
  - Agent przeanalizował też mechanizm w vendor: Messenger 8.1.9 deklaruje topologię raz na połączenie
    (`autoSetupExchange`).
- **Podejrzenia:** 3, opisane jako ryzyka bez dowodu:
  1. Głodzenie `events` przez `outbox` w jednym workerze.
  2. Pierwsze ponowienie bywa natychmiastowe (jitter i sekundowa precyzja `available_at`); agent uznał wpływ za
     pomijalny.
  3. `DbalCatalogReset` w trakcie przekazywania.
- **Testy własne:** `testEventsRetriesReachFailedWithRealAmqp` przeszedł. Zdarzenie przechodzi 3 ponowienia przez
  kolejki opóźniające AMQP z serializerem JSON i trafia do `failed`.
- **Testy repozytorium przez `make -C apps/api test RUN=<wrapper>`:** `OK (21 tests, 849 assertions)`. Objęły
  `OutboxRelayTest`, `OutboxAtomicityTest`, `EventFlowTest` (agent postawił Elasticsearch), `MessengerRoutingTest`,
  `MessagingStatisticsAdapterTest` i `GetDashboardHandlerTest`.
- **`DbalCatalogResetTest`:** 2 błędy środowiskowe, brak Garage.
- **Nie uruchomił:** `make check`.
- **Analiza statyczna bez testu:** współbieżności (`SKIP LOCKED` w kodzie vendor) nie testował obciążeniem, w
  przeciwieństwie do A.

## Potwierdzone testami a podejrzenia

| Ustalenie | A: Sonnet | B: Opus | QA-002 (Sonnet High) |
|---|---|---|---|
| Ack zdarzenia bez kolejki `events`, zdarzenie przepada | podejrzenie, niesprawdzone | **potwierdzone testem** | **potwierdzone testem** (M3) |
| Setki kolejek opóźnienia w dashboardzie (brak `jitter`, brak filtra) | nie | nie | **potwierdzone testem** (M1) |
| Utrata mikrosekund `occurredAt` na trasie do RabbitMQ | nie | nie | **potwierdzone testem** (M2) |
| Głodzenie `events` przez `outbox` w jednym workerze | nie | podejrzenie | podejrzenie |
| `DbalCatalogReset` w trakcie przekazywania | podejrzenie | podejrzenie | nie |
| Wiersz z nieznaną klasą trafia do `failed`, nie ginie | sprawdzone testem, brak błędu | nie | sprawdzone testem, brak błędu |
| 3 workery bez duplikatów (`SKIP LOCKED`) | sprawdzone testem (300 zdarzeń) | tylko kod vendor | sprawdzone testem (300 zdarzeń) |
| Ponowienia `events` na prawdziwym AMQP do `failed` | nie | sprawdzone testem | nie |
| Pierwsze ponowienie w tej samej sekundzie | nie | podejrzenie, wpływ pomijalny | nie |

**Fałszywe alarmy:** żaden agent nie zgłosił jako potwierdzonego błędu niczego, co okazało się nieprawdą. Obaj poprawnie
oddzielili podejrzenia od dowodów. Obaj poprawnie zaklasyfikowali swoje błędy testów jako środowiskowe: A (brak
Elasticsearcha) i B (brak Garage).

**Weryfikacja przez orkiestratora:**

- Potwierdził w `messenger.yaml` brak `mandatory` i `alternate-exchange` oraz komentarz obiecujący ack „only once
  RabbitMQ has the message”.
- Potwierdził treść `spec.md:311`.
- Nie powtarzał testu B, bo wymaga Postgresa i RabbitMQ.
- Ten sam błąd niezależnie odtworzył QA-002 (M3) innym testem. Są więc dwa niezależne dowody.

## Waga błędu: ack zdarzenia bez kolejki `events`

Zarówno QA-002 (M3), jak i B w QA-003 dały temu błędowi wagę Minor. Po weryfikacji orkiestrator ocenia go na
**Important**.

**Za wyższą wagą:**

- Błąd łamie podstawową gwarancję tego komponentu:
  - `docs/vision.md:191`: outbox daje dostarczenie „co najmniej raz”;
  - `spec.md:311`: wiersz outboxa dostaje ack dopiero, gdy RabbitMQ ma wiadomość;
  - komentarz przy `confirm_timeout` w `messenger.yaml` obiecuje to samo.
- Utrata jest trwała i cicha:
  - wiersz outboxa jest usuwany;
  - nie powstaje wpis w `failed`;
  - nie ma błędu w logu workera;
  - `messenger:failed:retry` nie ma czego ponowić.
- Skala nie jest ograniczona do jednego zdarzenia. Worker obsługuje `outbox` przed `events`, więc po zniknięciu kolejki
  wysyła w próżnię całą zaległość outboxa, zanim przy odpytywaniu `events` dostanie 404 i się zrestartuje. To analiza B,
  orkiestrator jej osobno nie testował.
- Realne wyzwalacze nie wymagają złej woli:
  - pomyłka operatora w panelu RabbitMQ (usunięcie lub odwiązanie kolejki);
  - polityka albo import definicji brokera;
  - migracja lub ponowne postawienie brokera, gdy exchange powstaje przed kolejką.
- Docelowo zdarzenia mają łączyć contexty (`docs/vision.md:160`). Zgubione zdarzenie między contextami, np. przy
  zamówieniach lub stanach magazynowych, oznacza trwałą niespójność danych, a nie tylko przestarzały indeks.
- Przy planowanym wdrożeniu na klaster Kubernetes (`docs/vision.md:257`) dochodzą scenariusze klastra RabbitMQ.
  Dopóki nie zostanie to sprawdzone, to tylko hipoteza.

**Przeciw Critical:**

- Wymaga zewnętrznej zmiany topologii brokera przy działającym workerze. Restart workera ponownie deklaruje kolejkę.
  Gdy brakuje exchange'a, publikacja kończy się błędem 404 i ponowieniem, więc zdarzenie nie ginie.
- Dziś jedynym odbiorcą jest `IndexProduct`, a jego skutki da się odtworzyć przebudową indeksu (`ReindexCommand`,
  `ProductSearchIndexRebuilder`).

**Ocena:** Important zgodnie z definicją agenta („błędne działanie w realnym scenariuszu”). Waga wzrośnie do Critical,
gdy pojawią się odbiorcy, których stanu nie da się odbudować z bazy.

**Obserwacja o kalibracji:** trzy przebiegi (QA-002 Sonnet, A jako podejrzenie, B) zgodnie ważyły ten błąd zbyt nisko.
Patrzyły na prawdopodobieństwo wyzwalacza, a pomijały, że utrata jest trwała i niewykrywalna. Skala wag w
`qa-adversarial.md` nie mówi, jak ważyć cichą utratę przy rzadkim wyzwalaczu. Definicji agenta na razie nie zmieniano.

## Środowisko i sprzątanie

Sprawdził orkiestrator po zakończeniu agentów.

- **A (Sonnet):**
  - Postawił:
    - sieć `qa3a-net`;
    - kontenery `qa3a-pg`, `qa3a-rmq` i `qa3a-api` (obraz `shop-t16-api`);
    - wolumen `qa3a-vendor`.
  - Usunął nazwane zasoby. `git status` czysty.
  - **Zostawił dwa anonimowe wolumeny.** Usunął kontenery przez `docker rm -f` bez `-v`, a w raporcie zadeklarował
    pełne sprzątanie.
    - `6c6d7b8e…`: RabbitMQ, węzeł `rabbit@cbaebed025cc`. Identyfikator kontenera `qa3a-rmq` występuje w transkrypcie A.
    - `c687a834…`: dane Postgresa, utworzony w tej samej sekundzie, 09:57:55 UTC.
  - Pochodzenie potwierdzone, wolumeny usunięte za zgodą użytkownika:
    - RabbitMQ: po identyfikatorze kontenera w transkrypcie A.
    - Postgres: orkiestrator uruchomił bazę na kopii wolumenu (`--network none`, kopia usunięta po sprawdzeniu).
      W `shop_test` był produkt `0192f0c4-7c1a-7c4e-9a6e-bbbbbbbbbbbb` ze slugiem `p-1`, czyli dane ze skryptu
      `atomic.php` agenta A. Tego identyfikatora nie ma w transkrypcie B.
  - Bez globalnego `docker volume prune`.
  - Inne wiszące wolumeny utworzone w czasie eksperymentu nie zostały usunięte, bo ich pochodzenia nie dało się
    potwierdzić: trzy puste (09:57:48, 09:58:53 UTC) i `rabbit@m3rabbit`, który należy do innej sesji.
- **B (Opus):**
  - Postawił:
    - sieć `qa3b-net`;
    - kontenery `qa3b-pg`, `qa3b-rmq`, `qa3b-es` i `qa3b-api` (obraz `shop-t19-api`);
    - wolumen `qa3b-vendor`.
  - Usunął wszystko, łącznie z dwoma anonimowymi wolumenami swoich kontenerów. `git status --short --ignored` pusty.
  - **Uwaga procesowa:** wolumeny anonimowe wskazał heurystycznie, po zawartości (`rabbit@qa3b-rmq`) i czasie
    utworzenia (dane Postgresa). Wynik był poprawny, ale usuwanie po heurystyce we wspólnym demonie Dockera jest
    ryzykowne. Bezpieczniej uruchamiać kontenery z `--rm` albo nazwanymi wolumenami `qa3b-*` i usuwać je przez
    `docker rm -fv`.
- Obaj agenci pracowali bez portów na hoście i nie dotknęli cudzych stosów. Domyślne pule adresów Dockera były
  wyczerpane, więc obaj sami nadali sieci jawną podsieć.
- Harness utworzył dla A dodatkowy worktree `agent-a85d259cbc69316dd`, wynikający z `isolation: worktree` w definicji.
  Agent pracował na wskazanym checkoucie, więc worktree był pusty: czysty `git status`, brak commitów ponad `660f3f9`.
  Orkiestrator usunął go razem z gałęzią `worktree-agent-a85d259cbc69316dd`. Dla B worktree nie powstał.

## Ocena

**Jakość.**

- B znalazł i udowodnił testem jedyny realny błąd w tej sesji. A doszedł do tej samej hipotezy, ale jej nie sprawdził.
- Lepiej odpowiada roli QA, że B uruchamiał testy przez Makefile. Postawił też Elasticsearch, dzięki czemu przeszedł
  `EventFlowTest`: pełna trasa outbox → RabbitMQ → indeks. Do tego przetestował ponowienia na prawdziwym AMQP.
- A dał mocniejsze dowody negatywne dla współbieżności i atomowości: testy obciążeniowe 300 i 500 zdarzeń, 3 workery.
  Uruchomił też `check`, czego B nie zrobił.
- Raport A jest rzetelny, ale dowodzi głównie braku błędów. Raport B zawiera jeden twardy dowód i analizę mechanizmu w
  vendor.

**Opłacalność.**

- B kosztował ok. 2,15× więcej (1,98 USD wobec 0,92 USD) i trwał 1,86× dłużej.
- Różnica wynika głównie z dłuższego myślenia: 1,75× tokenów wyjściowych, 2,2× czasu modelu. Liczba kroków jest
  podobna (41 wobec 38 wywołań API).
- Na jeden potwierdzony błąd: B ok. 2 USD, A brak potwierdzonych błędów przy koszcie 0,92 USD.

**Na tle QA-002.**

- Sonnet High w QA-002 (ten sam zakres i rewizja, inne polecenie: bez budżetu i z równoległym architektem) potwierdził
  testami 3 błędy Minor (M1–M3), w tym ten sam, który teraz znalazł B.
- Sonnet w QA-003 nie potwierdził żadnego. Wynik Sonnet High ma więc dużą wariancję między uruchomieniami: od 0 do 3
  potwierdzonych błędów przy podobnym koszcie (QA-002: 148 tys. tokenów harnessu i 9,5 min; QA-003: 89 tys. i 6,5 min).
- Opus w QA-003 znalazł 1 z 3 błędów QA-002 i żadnego nowego. Nie przewyższył najlepszego uruchomienia Sonneta.
- Żaden agent w QA-003 nie odtworzył M1 (kolejki opóźnienia) ani M2 (mikrosekundy).

**Wniosek.**

- W tym jednym porównaniu Opus High dał lepszy raport niż Sonnet High: 1 potwierdzony błąd wobec 0, testy przez
  Makefile, pełniejsze środowisko. Kosztował przy tym ok. 2× więcej i trwał 2× dłużej.
- Różnica jakości mieści się w zmienności Sonneta między QA-002 i QA-003. Jedna para uruchomień nie wystarczy, by
  stwierdzić, że Opus High jest w tej roli opłacalniejszy.
- Przy tej wariancji dwa niezależne uruchomienia Sonnet High (ok. 1,8 USD łącznie) kosztują tyle co jedno Opus High.
  W sumie QA-002 i QA-003 dały więcej potwierdzonych błędów (3 unikalne) niż Opus w QA-003 (1).
- Rekomendacja: zostawić `sonnet`/`high` jako domyślny model `qa-adversarial`. Przy zmianach krytycznych (transakcje,
  bezpieczeństwo) uruchamiać dwa niezależne przebiegi Sonnet zamiast jednego Opus.
- Rozstrzygnięcie wymaga kilku powtórzeń każdego modelu na tym samym poleceniu, z policzeniem średniej i rozrzutu
  potwierdzonych błędów.

**Ograniczenia.**

- Jedno uruchomienie na model.
- Polecenie inne niż w QA-002: budżet, prefiksy, jawny wymóg oznaczania POTWIERDZONE/PODEJRZENIE.
- Koszt szacunkowy według cennika API.
- Orkiestrator nie powtórzył testów agentów.
- Nie uruchomiono pełnego stosu, `make test`, `make e2e` ani testów obciążeniowych w rozumieniu `docs/vision.md`.
