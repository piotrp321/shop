# Audyt uproszczeń, część 2: pogłębiona analiza i ostateczna propozycja epików

- Repozytorium: `piotrp321/shop`, rewizja `5d1e9e1c` (`origin/main` z 2026-11-18). Od rewizji pierwszego raportu
  (`1aeb5076`) zmieniły się tylko narzędzia agentów, kod aplikacji jest ten sam.
- Ten dokument uzupełnia `simplification-audit-2026-11-18.md`. Tam, gdzie nowa analiza zmienia wcześniejszą
  rekomendację, obowiązuje ten dokument (lista korekt w sekcji 8).
- Niczego nie zmieniłem w repozytorium, nie powstały issues ani PR-y. Pomiar kolejek działał w osobnych kontenerach
  i własnej sieci Dockera, bez dotykania cudzych stosów; po pomiarze kontenery i sieć zostały usunięte.

## 1. Podsumowanie

Celem nie jest największa liczba usuniętych linii, tylko najniższy koszt utrzymania w długim okresie. Z tej
perspektywy wnioski są następujące.

1. **Najwięcej da zmiana reguł, nie refaktoryzacja.** Każda kopia kursora, zegara i stałej stronicowania pochodzi
   z innego epiku (kursory z 6 epików w 14 dni). Bez zmiany instrukcji kolejny epik dopisze następną kopię, a każda
   konsolidacja będzie jednorazowym sprzątaniem. Zmiana reguł to kilka zdań i powinna wejść jako pierwsza.
2. **Trzy ryzyka produkcyjne są realne, ale inne niż opisałem.** Komendy `demo-data:*` tylko dopisują dane. Groźniejszy
   jest skrypt `bin/seed-demo`, który w `prod` sam dokłada `--force-reset`: jedno polecenie kasuje katalog, ceny,
   zamówienia i stany, a kopii zapasowych nie ma. Poprawka to około 50 linii kodu.
3. **RabbitMQ: nie rekomenduję usunięcia dla samej prostoty.** Pomiar i lektura kodu pokazują jednak, że broker w obecnej
   konfiguracji nie odciąża bazy i nie daje nic ponad kolejkę w Postgresie, a pojedynczy relay jest wąskim gardłem całego
   potoku. Proponuję rozstrzygnąć wariant dwoma pomiarami na klastrze k3d, dopisanymi do eksperymentów, które
   ASYNC-RELIABILITY i tak wykonuje przed startem.
4. **Interfejsy z jedną implementacją są w większości uzasadnione.** Ze 116 interfejsów 107 zostaje (granice contextów
   wymuszone przez Deptrac, systemy zewnętrzne, szwy testowe, typy). Do likwidacji są 4, do scalenia 5 kopii zegara,
   i to bez osobnego PR-a.
5. **Redukcja netto to około 5,5 tys. linii kodu produkcyjnego (5,4%), z czego tylko około 1 tys. to czyste
   usunięcie.** Reszta wymaga przepisania około 8,8 tys. linii na około 4,1 tys. Po decyzjach właściciela dochodzi
   4,1–5,8 tys. linii.

### Decyzje wymagające akceptacji

| Nr | Decyzja | Rekomendacja | Co odblokowuje |
|---|---|---|---|
| D1 | Zmiany reguł Z1–Z3 i Z5–Z7 (sekcja 5.3) w `CLAUDE.md`, `apps/api/CLAUDE.md`, definicjach agentów `developer`, `code-reviewer`, `architecture-reviewer` i skillu `brainstorm` | przyjąć teraz; Z4 i Z9 po próbie na jednym epiku | wszystkie epiki; zatrzymuje dopisywanie kopii |
| D2 | Osłony produkcji: jawny `--force-reset` dla `bin/seed-demo`, blokada `demo-data:*` poza `dev`/`test` bez flagi, test zgodności zmiennych środowiska | przyjąć; jeden mały PR | bezpieczne dane demo |
| D3 | Kopie zapasowe w `prod`: włączyć (kroki użytkownika: bucket R2, token, hasło restic, `seal-secrets`) albo poprawić 4 zdania w dokumentach, które opisują kopie jako działające | włączyć; do tego czasu poprawić dokumenty | zgodność dokumentacji ze stanem, alerty kopii |
| D4 | Kolejka zdarzeń: bramka pomiarowa E8–E10 przed startem ASYNC-RELIABILITY i wybór wariantu według kryteriów z sekcji 2.6 | przyjąć bramkę; wariant wybrać po pomiarze | kształt ASYNC-RELIABILITY |
| D5 | Reguła portu (sekcja 4.3) i przeniesienie 4 handlerów czytających do Infrastructure | przyjąć | epik API-READ-SIDE |
| D6 | Zegar: `Psr\Clock\ClockInterface` w nowym kodzie; `symfony/clock` jawnie w `composer.json` | przyjąć dla nowego kodu, bez migracji istniejących 5 portów | koniec dopisywania kopii `Clock` |
| D7 | Jedna konwencja opisu odpowiedzi API (klasy `*Response` albo atrybuty na widokach) | odłożyć; najpierw prototyp na jednym module | usunięcie 400–1 100 linii i jednego z dwóch stylów |
| D8 | Zakres danych demo do czasu części 2: pula zdjęć w R2, rekoncyliacja kont Keycloak, weryfikator | zdecydować przed epikiem DEMO-DATA-TRIM | 2,5–3,3 tys. linii kodu, 4,4 tys. testów |
| D9 | Testy: kontrole ogólne (401, zły JSON, brak klucza) raz, z routera i OpenAPI; usuwanie testów migracji po wdrożeniu | przyjąć po próbie Z4 | epik TEST-CONSOLIDATION |
| D10 | Limit żądań: zostaje `RateLimitSubscriber` (wycofuję zamianę na `#[RateLimit]`) | przyjąć | ABUSE-LIMITS bez zmian |

## 2. RabbitMQ: trzy warianty

### 2.1 Warianty

- **V1, stan obecny:** zdarzenie trafia w transakcji agregatu do kolejki `outbox` (transport Doctrine, tabela
  `messenger_messages`), relay publikuje je do RabbitMQ (`events`, potwierdzenie wydawcy), handlery czytają z brokera.
- **V2, bez brokera:** handlery czytają zdarzenia wprost z kolejki Doctrine. Outbox jest kolejką. Podwarianty: jedna
  kolejka `events` albo kolejka na grupę konsumentów (wyszukiwarka, cache sklepu, poczta, synchronizacja contextów).
- **V3, broker wykorzystany:** jeden wiersz outboksu, w RabbitMQ wymiana z kolejką kworum na grupę konsumentów, osobny
  proces relaya, workery per grupa.

### 2.2 Fakty z kodu i pakietów (Symfony 8.1.11)

| Fakt | Dowód | Znaczenie |
|---|---|---|
| Każde zdarzenie przechodzi przez Postgresa w każdym wariancie: INSERT w transakcji komendy, pobranie z `FOR UPDATE SKIP LOCKED` i `UPDATE delivered_at`, DELETE po potwierdzeniu | `doctrine-messenger/Transport/Connection.php:131-266` | broker nie zdejmuje z bazy kosztu kolejki |
| Topologia brokera to jedna klasyczna kolejka `events` i jeden konsument; brak fan-outu, kolejek kworum, DLX, strumieni | `messenger.yaml:40-51`, brak opcji `queues`/`exchange` | broker nie robi dziś nic, czego nie robi tabela |
| Messenger odbiera z AMQP przez `basic.get` (odpytywanie), nie `basic.consume` | `amqp-messenger/Transport/Connection.php:462-480` | dokumentacja RabbitMQ odradza ten tryb; zalety brokera po stronie odbioru są niedostępne bez własnego konsumenta |
| Ponowienie nie uruchamia handlerów, które już się udały, na obu transportach | `HandleMessageMiddleware.php:58-63,134` | izolację konsumentów daje liczba kolejek, nie broker |
| Kolejka błędów `failed` jest w Postgresie w każdym wariancie | `messenger.yaml:102-105` | DLQ nie różnicuje wariantów |
| Opóźnienia i ponowienia: Doctrine przez `available_at`, AMQP przez osobną kolejkę na każdą wartość opóźnienia | `Connection.php:136-140`; `amqp…/Connection.php:421-455` | w V1 po zmianach ASYNC-RELIABILITY około 7 kolejek opóźnień |
| Odtworzenie z kopii czyści kolejki RabbitMQ; `pg_dump` zapisuje oczekujące zdarzenia spójnie z agregatami | `restore-job.sh:174-185` | w V1 i V3 zdarzenia przekazane do brokera przed kopią przepadają po odtworzeniu |
| Transport Doctrine wysyła `pg_notify` przy każdym zapisie, także przy `use_notify: false` | `Connection.php:479-484` | dotyczy wszystkich wariantów; do zmierzenia przy wielu równoległych zapisach |
| Wiadomość zabijająca proces wraca bez końca: Doctrine co 300 s, klasyczna kolejka od razu | brak licznika dostarczeń; limit mają dopiero kolejki kworum | dotyczy V1 i V2 |
| Chart nie ustawia limitu pamięci brokera (`vm_memory_high_watermark`), wolumen ma 2 Gi | `templates/rabbitmq.yaml`, `values.yaml:164` | ryzyko V1: OOM-kill zamiast alarmu |
| Kod wyłącznie dla ścieżki AMQP: około 280 linii PHP, 340 linii testów w całości i około 150 częściowo | `Shared/Infrastructure/Messaging`, sonda, statystyki | koszt V1 i V3 |

### 2.3 Model obciążenia przy skali docelowej

Założenia są jawne i celowo zawyżone. To rzędy wielkości, nie prognoza.

| Źródło | Zdarzenia |
|---|---|
| Klienci: 60 tys. aktywnych, konwersja 3%, szczyt ×5, około 3,5 zdarzenia na zamówienie | stale 5–20/s, w szczycie około 50/s |
| Pracownicy: około 100 aktywnych | 2–5/s |
| Promocja: do 1 000 wariantów w jednej transakcji, także na każdej granicy czasu | serie 10³–10⁴ w sekundy |
| Uzgodnienie VAT przy 10 mln produktów: jedno zdarzenie na cenę | około 10⁷ zdarzeń w tempie 10²–10³/s przez godziny |
| Zaległość po godzinie przestoju konsumenta | 70 tys. (stale), 180 tys. (szczyt), do 1,8 mln przy uzgodnieniu VAT |

Wniosek z modelu: obciążenie, które różnicuje warianty, pochodzi z operacji masowych, nie z klientów.

### 2.4 Pomiar porównawczy

Mikrobenchmark na prawdziwych klasach transportów Symfony, PostgreSQL 18.6 i RabbitMQ 4.4.0 w kontenerach z limitami
(4 CPU baza, 2 CPU broker). Mediany z 3 przebiegów. K to liczba procesów konsumentów, h to czas handlera.

| Scenariusz | V2 (Doctrine) | V1 (1 relay + K konsumentów) | V1 w jednym procesie, jak dziś |
|---|---:|---:|---:|
| h = 0, K = 1 | 495/s | 256/s | 120/s |
| h = 0, K = 4 | 1 927/s | 326/s | – |
| h = 0, K = 8 | 3 009/s | 299/s | – |
| h = 5 ms, K = 8 | 955/s | 257/s | – |
| h = 50 ms, K = 8 | 147/s | 154/s | – |

| Pomiar | Wynik |
|---|---|
| Sam relay (potwierdzenie po każdej wiadomości) | 1 proces 259/s, 4 procesy 1 091/s |
| Koszt w Postgresie na wiadomość | 3 instrukcje i 2 commity w obu wariantach (w V2 konsument, w V1 relay), około 580 B WAL |
| Zapis zdarzenia w transakcji komendy | +4 instrukcje, +830 B WAL; 905 zamiast 1 840 transakcji/s w tym pomiarze |
| Zaległość 1 mln gotowych wierszy w kolejce | brak spadku tempa pobierania (1 084/s wobec 858/s odniesienia) |
| Zaraz po usunięciu 1 mln wierszy, bez VACUUM | 246/s, pierwsze pobranie 987 ms i 83 tys. buforów |
| `kill -9` w trakcie obsługi: ponowna widoczność | AMQP 0,055 s; Doctrine 299 s (`redeliver_timeout`) |
| Zatrzymanie brokera podczas pracy relaya | bez utraty wiadomości, 1 duplikat w 2 próbach; jedno `send()` zawisło na 19 s mimo `confirm_timeout: 5` (przyczyna niepotwierdzona) |

Co z tego wynika i co jest odporne na zmianę maszyny:

- **Wąskim gardłem V1 jest relay, nie broker i nie baza.** Jeden proces z potwierdzeniem po każdej wiadomości daje
  około 260/s, a dzisiejszy worker, który na zmianę przekazuje i obsługuje, około 120/s. Dodawanie konsumentów tego nie
  zmienia. Uzgodnienie VAT w tempie 200–1 000 zdarzeń/s budowałoby zaległość w outboksie.
- **V2 skaluje się liczbą konsumentów** do granicy czasu handlera. Przy handlerach 50 ms oba warianty kończą na tej
  samej granicy K/h.
- **Baza płaci tyle samo w obu wariantach.** V1 dokłada trwały zapis w brokerze.
- **Degradacja po masowym usuwaniu dotyczy obu wariantów**, bo relay czyta tę samą tabelę tym samym zapytaniem.
  W V2 zaległość leży w tabeli dłużej, więc strojenie autovacuum albo osobna tabela kolejki zdarzeń jest warunkiem.
- **Po twardej awarii procesu AMQP oddaje wiadomość natychmiast, Doctrine po 5 minutach.** To realna przewaga brokera
  dla handlerów. Relay w V1 ma to samo opóźnienie 5 minut.

Ograniczenia pomiaru: laptop z Docker Desktop (koszt fsync zależy od wirtualnego dysku), brak opóźnień sieci, jeden
węzeł, handlery symulowane przez `usleep`, maszyna współdzielona z innymi stosami (rozrzut do 2× przy h = 0),
serializer PHP zamiast JSON. Wartości bezwzględne nie opisują produkcji. Projekt wymaga testu obciążeniowego, zanim
uzna jakikolwiek wariant za spełniający wymagania.

### 2.5 Porównanie

| Kryterium | V1 dziś | V2 bez brokera | V3 broker z kolejką na grupę |
|---|---|---|---|
| Atomowość z zapisem agregatu | outbox w transakcji | ta sama | ta sama |
| Okna utraty | dysk brokera bez kopii; odtworzenie gubi zdarzenia już przekazane | brak poza bazą | jak V1 |
| Duplikaty | relay padł po potwierdzeniu; handler padł | handler padł; dzierżawa 300 s | jak V1 |
| Retry | kolejki opóźnień w brokerze | kolumna `available_at` | per kolejka grupy |
| DLQ | `failed` w Postgresie | `failed` w Postgresie | `failed` w Postgresie |
| Wiadomość zabijająca proces | wraca od razu, bez limitu | wraca co 300 s, bez limitu | limit dostarczeń kolejki kworum (domyślnie 20) |
| Izolacja wolnego konsumenta | brak (jedna kolejka, jeden proces) | brak; pełna przy kolejce na grupę (koszt: N wierszy w transakcji komendy) | pełna, fan-out po stronie brokera |
| Przepustowość potoku | ograniczona relayem | rośnie z liczbą konsumentów | ograniczona relayem, chyba że jest kilka procesów relaya |
| Zaległość godzinowa | w outboksie albo w brokerze (wolumen 2 Gi) | w bazie OLTP | w brokerze, baza chroniona |
| Wiek najstarszej wiadomości | outbox tak, `events` nie | SQL dla każdej kolejki | brak wiarygodnego w API zarządzania |
| Kopia i odtworzenie | niespójne z kolejką | spójne | niespójne z kolejką |
| Koszt operacyjny | StatefulSet, wolumen, sekret, 0,5–1 GiB RAM, aktualizacje RabbitMQ i Erlanga, rozszerzenie `amqp`, usługa w CI | brak dodatkowego | jak V1 plus topologia, metryki, więcej workerów |
| Kod własny | około 280 linii dla AMQP | −280 | +topologia i konsumenci grup |
| Niezależni konsumenci, inne języki, odtwarzanie strumienia | możliwe | wymaga powrotu brokera | natywne |
| Odwracalność | – | powrót do V1 lub V3 to kilka dni: outbox, idempotentne handlery i `failed` są wspólne | – |

### 2.6 Ocena i rekomendacja

Nie rekomenduję usunięcia RabbitMQ dlatego, że tak jest prościej. Rekomenduję rozstrzygnięcie na podstawie tego, co
broker faktycznie daje.

- W obecnej konfiguracji V1 **nie daje** ochrony bazy, izolacji konsumentów ani większej przepustowości. Daje szybkie
  ponowne dostarczenie po twardej awarii konsumenta i trzyma zaległość handlerów poza bazą, ale tylko do pojemności
  wolumenu 2 Gi.
- V1 **kosztuje**: usługę do utrzymania, okno utraty przy odtworzeniu, relay jako wąskie gardło i osobną klasę awarii,
  wokół której zaprojektowano znaczną część ASYNC-RELIABILITY.
- V3 jest właściwy, gdy pojawi się choć jedno z: konsument spoza monolitu, potrzeba izolacji co najmniej 3 grup przy
  trwałym dużym ruchu, odtwarzanie strumienia, wymóg, żeby zaległość nie leżała w bazie. Mapa drogowa wymienia
  integracje z kurierami i faktury jako „poza zakresem na razie”, bez dat.
- V2 jest właściwy, gdy potwierdzą się dwa warunki pomiarowe poniżej. Dotychczasowe dowody wskazują, że się potwierdzą,
  ale pochodzą z laptopa.

**Proponowana ścieżka (decyzja D4):** dopisać do eksperymentów „wymaganych przed startem” ASYNC-RELIABILITY trzy
pomiary na klastrze k3d z obrazami produkcyjnymi i wybrać wariant przed implementacją zadań. Progi w tabeli są
propozycją do uzgodnienia.

| Eksperyment | Pomiar | Próg dla V2 |
|---|---|---|
| E8 | przepustowość konsumenta Doctrine (1, 4, 8 workerów; handler pusty i 10 ms), WAL i martwe krotki na wiadomość | co najmniej 5× szczyt z modelu, czyli co najmniej 5 tys. zdarzeń/s przy pustym handlerze na 8 workerach albo skalowanie liniowe do tej wartości |
| E9 | zaległość 1 mln wierszy przy trwającym `pg_dump` i ruchu k6 sklepu | p99 zapytań katalogu rośnie o nie więcej niż 20%, martwe krotki wracają do normy po autovacuum |
| E10 | V1: limit pamięci brokera, zachowanie przy zaległości 2 mln i wolumenie 2 Gi, zawieszenie `send()` przy zatrzymanym brokerze | informacyjnie: koszt pozostania przy V1 |

Jeśli E8 i E9 przejdą i w horyzoncie roku nie ma niezależnego konsumenta: **V2**, z osobną tabelą kolejki zdarzeń
i własnymi progami autovacuum. Jeśli nie przejdą albo niezależny konsument jest w planie: **V3**. Pozostanie przy V1
bez zmian jest najsłabszą opcją: płaci koszt brokera i nie korzysta z niego.

Niezależnie od wariantu warto naprawić: pętlę wiadomości zabijającej proces i zmierzyć wpływ `pg_notify`. Jeśli V1
zostaje choćby przejściowo: limit pamięci brokera i przyczynę zawieszenia `send()`.

### 2.7 Wpływ wariantu na ASYNC-RELIABILITY

Około 80% epiku nie zależy od wariantu: uzupełnianie wiadomości przez CronJob (D1), zamykanie płatności (D5), limity
(W2), wygaszanie zamówień i płatności (W5, W6), cele `failed*` (W10), zadania 3 i 7.

| Element epiku | V1 | V2 | V3 |
|---|---|---|---|
| Dwa workery (D2, W1) | bez zmian | prostsze: bez zależności od brokera; powodem staje się izolacja komend od serii zdarzeń | trudniejsze: relay i konsumenci grup |
| Eksperyment E1, kryteria 1 i 3 („bez brokera”) | bez zmian | zbędne w obecnej postaci | bez zmian |
| Ponowienia relaya (W3, 200 prób), kryterium 7 | bez zmian | zbędne | strategia dla N transportów |
| Serializer i zgodność wersji (D3, W11) | bez zmian | prostsze: znika JSON na `events` | bez zmian |
| Statystyki i alert zatoru (D4, W7, W9) | luka: wiek wiadomości w `events` niedostępny | prostsze: zdarzenia w tej samej liście SQL; kontrakt pulpitu traci `queues` i `outbox.pending` | trudniejsze: brak wieku albo nowa metryka brokera |
| Odtworzenie (`restore.sh`) | bez zmian | znika etap `rabbitmq` | bez zmian |
| Nowe zadanie | – | migracja: wydanie przejściowe opróżniające `events`, usunięcie kodu AMQP i usługi, dokumenty, ADR | topologia kolejek kworum z migracją, limit pamięci brokera |
| Zmiana zakresu | 0 | około −10% i jedno zadanie migracji, netto podobnie | +30–40%, 2–3 zadania więcej |

Największe ryzyko ze specyfikacji („outbox, RabbitMQ, jeden worker”): V1 łagodzi je podziałem procesu. V2 usuwa ogniwo
relay i broker, a ciężar przenosi na vacuum i WAL bazy. V3 rozbija jeden worker na grupy i trzyma zaległość poza bazą,
kosztem większej powierzchni operacyjnej na jednym węźle.

## 3. Bezpieczeństwo produkcji

### 3.1 Komendy danych demo

**Werdykt: potwierdzone częściowo, z istotną korektą.**

- Moduł `DemoData` jest w obrazie `prod` (`.dockerignore` go nie wyklucza, `services.yaml:45-48` rejestruje cały `src`)
  i nie ma żadnej blokady środowiska. Hasło administratora Keycloaka trafia do poda przez `envFrom`, więc profil
  `small` przeszedłby w `prod` w całości.
- Komendy `demo-data:*` są **addytywne**: `COPY` i `INSERT`. `demo-data:accounts` usuwa wyłącznie członków własnej
  grupy `demo-data-generated`. Moje wcześniejsze sformułowanie o usuwaniu kont było przesadzone.
- **Właściwe znalezisko:** `apps/api/bin/seed-demo:11-13` sam dopisuje `--force-reset`, gdy `APP_ENV=prod`. Blokada
  seedów działa więc tylko przy ręcznym `bin/console X:seed-demo`. Jedno `kubectl exec deploy/api -- bin/seed-demo`
  wykonuje TRUNCATE katalogu, cen, promocji, zamówień, płatności i stanów, bez pytania.
- Warunek w seedach `'prod' === $this->environment` przepuszcza każde inne środowisko, także wymyślone `--env=demo`
  (pewność średnia, nie uruchamiałem).
- Żaden Job, CronJob ani workflow nie wywołuje tych komend na serwerze. Ścieżką pomyłki jest operator albo agent
  z dostępem do `kubectl exec`. Zasady na to nie pozwalają, ale technicznej blokady nie ma.

| Polecenie | Skutek na żywym demo | Ryzyko |
|---|---|---|
| `demo-data:*` (profil `small`) | około 2 000 produktów, 5 000 zamówień i 1 020 wyłączonych kont więcej; bez utraty danych; cofnięcie tylko pełnym resetem | niskie |
| `bin/seed-demo` przez `exec` | utrata zamówień gości i edycji z panelu; bez kopii nieodwracalna | niskie do średniego (rzadkie, ale skutek wysoki przy wyłączonych kopiach) |

**Minimalne rozwiązanie (jeden PR, około 60 linii kodu i testy):**

1. `bin/seed-demo` przyjmuje `--force-reset` jako argument i w `prod` bez niego odmawia. `initialize.sh`
   i `reinitialize.sh` podają go jawnie.
2. Jeden listener `ConsoleEvents::COMMAND` odmawia komend `demo-data:*` zapisujących dane, gdy środowisko nie jest
   `dev` ani `test`, chyba że podano flagę. Plan uruchomienia generatora na serwerze (#484) działa dalej przez
   `generate-demo --force-reset`.
3. Warunek w 6 seedach odwrócony na `!in_array($env, ['dev', 'test'])`.

Wyłączenie modułu w `prod` odpada, bo blokuje udokumentowany plan generatora na serwerze. Weryfikacja: testy komend
z kernelem `prod` (bez flagi kod 1 i brak wierszy, z flagą działa), test skryptu bez argumentu, `make -C apps/api check
test`, `make -C infra/k8s check`.

### 3.2 Kopie zapasowe

**Werdykt: potwierdzone. Kopie w `prod` nigdy nie działały.**

- Od #448 (2026-11-11) adres repozytorium był przykładowy, a sekretu `shop-backup` nie ma. `enabled: false` jest od
  #651 (2026-11-16).
- Ten sam przełącznik wyłącza 4 alerty kopii i CronJob `restore`. `make restore` i `make backups` w `prod` nie mają na
  czym działać i nic nie przypomina o braku kopii.
- Runbook i specyfikacja deploy-ops opisują lukę jako tymczasową. `docs/adr/k3s-argo-cd.md:67-69`
  i `docs/vision.md:363,370` opisują kopie jako działające, a `docs/features/deploy/spec.md:321` podaje błędnie
  `enabled: true` w `prod`.

Co przepada przy utracie dysku: zamówienia gości, edycje katalogu i cen z panelu, konta personelu z sekretami TOTP,
zdjęcia wgrane w panelu. Katalog demo, realmy i indeks da się odtworzyć z repozytorium.

Ryzyko: dla demo niskie do średniego. Przy prawdziwych zamówieniach wysokie. Największa bieżąca szkoda to dokumentacja,
która twierdzi, że kopie są.

**Minimalne rozwiązanie, po kolei:**

1. Użytkownik: bucket R2, token ograniczony do bucketu, hasło restic poza serwerem.
2. Agent po zgodzie: sprawdzenie, że na serwerze był `bootstrap` z poprawką zdrowia PVC.
3. Użytkownik: `make -C infra/k8s seal-secrets WHAT=backup`.
4. Agent: PR z adresem repozytorium, `enabled: true` i sekretem. Merge i wdrożenie na polecenie użytkownika.
5. Weryfikacja następnego dnia: `make -C infra/k8s backups`, 7 reguł alertów zamiast 3.
6. Przed prawdziwymi danymi: ćwiczenie `make restore`.

Jeśli kopie zostają wyłączone: agent poprawia 4 wskazane zdania w dokumentach.

### 3.3 Zmienne środowiska w compose i w chartcie

**Werdykt: potwierdzone liczbowo, ryzyko niższe, niż sugerowałem.**

- Wspólnych nazw jest dokładnie 51. Żaden test nie porównuje zbiorów.
- Wszystkie różnice są zamierzone albo nieszkodliwe i mają w kodzie wartości domyślne. Wspólne stałe (hosty, bucket,
  adresy wewnętrzne) są identyczne.
- W historii nie ma defektu z tego powodu.
- Braku zmiennej nie wykrywa build ani start. API rozwiązuje `%env()%` leniwie (błąd 500 przy pierwszym użyciu), sklep
  też sprawdza konfigurację przy pierwszym użyciu. Test dymny łapie tylko ścieżki, które przechodzi, i dopiero przy
  wdrożeniu.

Ryzyko: niskie. **Minimalne rozwiązanie:** jeden test w `infra/k8s/tests/test_workloads.py` (60–80 linii)
porównujący zbiory nazw i stałe wartości z jawną listą wyjątków. Wspólne źródło prawdy (plik env, generowanie chartu)
byłoby przerostem formy.

## 4. Abstrakcje: interfejsy z jedną implementacją

### 4.1 Kryteria

Liczba implementacji nie jest kryterium. Każdy z 116 interfejsów oceniłem w tej kolejności:

1. **Reguła zależności.** Czy bez interfejsu warstwa naruszyłaby `deptrac.yaml` (inny context, Domain → framework,
   UI → Infrastructure)?
2. **Ukrywanie informacji.** Czy interfejs chowa system zewnętrzny albo niedeterminizm za typami domeny, czy przepisuje
   wiersz SQL 1:1?
3. **Wartość szwu testowego.** Ile testów używa dublera, czy sprawdzają decyzje handlera, czy logikę odtworzoną
   w dublerze, i czy te same przypadki ma test integracyjny?
4. **Prawdopodobieństwo drugiej implementacji** z mapy drogowej.
5. **Koszt:** interfejs, DTO, dubler, alias, dodatkowy skok dla czytelnika.

### 4.2 Wynik

| Werdykt | Liczba | Kto |
|---|---:|---|
| Uzasadniony port architektoniczny | 73 | 26 portów do innych contextów (wymusza Deptrac), 41 portów do systemów zewnętrznych (repozytoria z blokadami, Elasticsearch, Garage, poczta, Messenger, Keycloak, Varnish), blokady i sekwencje, punkty rozszerzeń |
| Uzasadniony szew testowy | 23 | 18 portów modeli odczytu, których testy sprawdzają decyzje handlera (kursor, licznik tylko pierwszej strony, odmowa bez odczytu), a nie SQL |
| Typ albo znacznik | 11 | kategorie wyjątków, zdarzenia, kroki backfillu, sondy |
| Do scalenia | 5 | pięć identycznych portów `Clock` |
| Do likwidacji | 4 | `ProductEditSource`, `ProductDetailsSource`, `VariantSource` (Catalog), `ExpiredCarts` (Cart) |

Warstwa dublerów jako całość się opłaca: 1 215 metod testowych działa bez bazy, a dublery wyścigów
(`ConcurrentlyEditedVariantPriceRepository`, `VersionedOrders`) odtwarzają konflikty wersji, których na bazie nie da
się tanio wywołać.

**Cztery interfejsy do likwidacji** mają wspólną cechę: handler tylko czyta i mapuje, a dubler odtwarza zapytanie.
Handler przechodzi do `<Context>/Infrastructure`, zapytanie i widok zostają w Application jako kontrakt. Deptrac nie
wymaga zmian.

| Interfejs | Kod: usunięte → dopisane | Netto | Testy | Ochrona po zmianie |
|---|---|---:|---:|---|
| `ProductEditSource` | 182 → 105 | −77 | 0 | brak dublera już dziś; testy integracyjne i funkcjonalne |
| `ProductDetailsSource` | 213 → 150 | −63 | −110 | 9 testów integracyjnych pokrywa oba przypadki jednostkowe |
| `VariantSource` | 422 → 310 | −110 | −396 | 16 testów jednostkowych powiela 14 integracyjnych |
| `ExpiredCarts` | 90 → 58 | −32 | −99 | 7 testów integracyjnych, w tym `SKIP LOCKED` |
| Razem | 907 → 623 | **−284** | **−605** | |

**Zegar:** pięć portów to ta sama granica, więc scalenie na `Psr\Clock\ClockInterface` jest słuszne. Zmiana dotyka
jednak około 105 plików dla około 180 linii, a zegary w Ordering i Payment obcinają czas do sekundy, co wymaga dwóch
implementacji wiązanych konfiguracją. Rekomendacja: reguła dla nowego kodu i przepinanie przy okazji zmian modułu,
bez osobnego PR-a.

Wycofane z wcześniejszych propozycji: sześć portów pulpitu Backoffice (pięciu różnych sąsiadów, wymusza Deptrac),
`PersistenceSession`, pięć portów `Demo*Source` i pięć `*Reset` (wymusza reguła UI → Infrastructure, koszt 15 linii
każdy).

### 4.3 Reguła decyzyjna do wytycznych

> **Wprowadź port, gdy** spełniony jest co najmniej jeden warunek: (1) bez niego warstwa naruszyłaby Deptrac;
> (2) chowa system zewnętrzny albo niedeterminizm za typami domeny; (3) handler podejmuje decyzje, które test na
> dublerze sprawdza taniej niż test na bazie, a dubler nie odtwarza SQL; (4) druga implementacja jest w planie albo już
> istnieje.
> **Nie wprowadzaj portu, gdy** handler tylko czyta i mapuje wiersze (wtedy handler w Infrastructure i test
> integracyjny), gdy interfejs przepisuje kształt wiersza 1:1, albo gdy jedynym testem dublera byłby test powielający
> test integracyjny. Liczba implementacji sama nie rozstrzyga w żadną stronę.

## 5. Przyczyny powstawania zbędnej złożoności

### 5.1 Przyczyny w kolejności wagi

Udziały są szacunkiem jakościowym.

| Nr | Przyczyna | Dowód | Udział |
|---|---|---|---|
| 1 | **Zadanie ma pozostać lokalne, a wydzielenie wspólnego kodu jest karane.** Developer ma „trzymać się zakresu” i „nie zmieniać innych modułów”, reviewer daje `IMPORTANT` za pliki spoza `scope`, planner wymaga rozłącznych ścieżek. Najtańsza zgodna droga to skopiować sąsiada. | `.claude/agents/developer.md:11,13,20`; `code-reviewer.md:16`; `planner/SKILL.md:29-31` | około 35% razem z nr 4 |
| 2 | **Reguły architektury robią z każdego klocka technicznego własność contextu.** Zakaz wspólnych value objectów nie odróżnia pojęć domenowych od technicznych; „porty jako interfejsy” i testy aplikacji na adapterach in-memory dają port, adapter i dubler każdemu czytnikowi SQL. | `apps/api/CLAUDE.md:68,71,427` | około 25% |
| 3 | **Szczegółowe, sprawdzalne reguły wygrywają z ogólną regułą prostoty.** Test 401 i złych danych przy każdym endpoincie, ścieżka błędu każdego skryptu, próby obejścia każdej reguły, polski komunikat błędu typu (`mixed`, potem `\assert`), opis wszystkich błędów każdego endpointu. | `apps/api/CLAUDE.md:243-245,253,430`; `CLAUDE.md:98-99,106-107` | około 25% |
| 4 | **Duplikacja nie ma wagi ani właściciela.** Przegląd architektury jest raz na epik, tylko przy nowym module, na diffie i z limitem 5 uwag. Drobne uwagi są odraczane i nikt do nich nie wraca. Plan znika po epiku. | `docs/adr/agentic-software-house.md:84,90`; `architecture-reviewer.md:24` | w nr 1 |
| 5 | **Specyfikacje projektują implementację osobno dla każdej funkcji.** Zlecają „własny kursor”, zostawiają „komunikaty błędów” każdej liście, a `/brainstorm` dopisuje zmiany `CLAUDE.md` jako zadanie każdej funkcji. Zakres powstaje przed ścieżką, która go uruchamia. | `docs/features/inventory/spec.md:315-316`; `admin-design/spec.md:283-284`; `brainstorm/SKILL.md:33-36,73-74` | około 10% |
| 6 | **Nic mechanicznie nie wykrywa kopii ani martwego kodu.** PHPStan na poziomie `max` przy szynie zwracającej `mixed` wymusza `\assert`. | `phpstan.dist.neon`; `make check` | około 5%, ale to ona ukrywa pozostałe |

Historia gita potwierdza mechanizm:

| Artefakt | Epiki, które dodały kopię |
|---|---|
| Kursor keyset | PRICING, INVENTORY, ORDER-MANAGEMENT, PROMOTIONS, DISCOUNT-CODES, ADMIN-FEATURES (6 epików w 14 dni) |
| `Clock` + `SystemClock` | CART, CHECKOUT, PAYMENT, SEARCH, PROMOTIONS |
| `*ResponseCacheSubscriber` | CART, CHECKOUT, PAYMENT, ADMIN-FEATURES |
| `PER_PAGE_OPTIONS` w panelu | 7 epików, w tym dwie kopie w jednym |

Jedyne wydzielenia wspólnego kodu w historii to te, które wprost zleciła specyfikacja (`useCursorPager`,
`useNameSearch`). Dwa epiki, których celem było ujednolicenie list, wydzieliły tylko to, co specyfikacja nazwała.

### 5.2 Dlaczego dobre reguły przegrywają

Projekt ma już reguły, które mówią właściwą rzecz: „nie wprowadzaj przedwcześnie abstrakcji”, „najpierw natywne”,
„`CLAUDE.md` zawiera wyłącznie zasady”, reviewer ma szukać „zbędnej złożoności”. Przegrywają, bo są ogólne
i niesprawdzalne, a konkurujące z nimi reguły są konkretne i mają bramkę: jest albo nie ma testu 401, plik jest w
`scope` albo poza nim. Agent w świeżym kontekście optymalizuje to, co zostanie sprawdzone. Reguła „najpierw natywne”
dotyczy dziś tylko narzędzi agentów, nie kodu aplikacji.

### 5.3 Proponowane zmiany

Kolejność od najtańszej i najbardziej opłacalnej. Pełne brzmienia są gotowe do wklejenia; poniżej istota.

| Nr | Zmiana | Gdzie | Zapobiega | Ryzyko i zabezpieczenie |
|---|---|---|---|---|
| Z1 | Zawęzić zakaz wspólnych value objectów do pojęć domenowych. Klocki techniczne bez znaczenia biznesowego (kursor, zegar, klucz idempotencji, format UUID, odpowiedź cache, klient szyny) są w `Shared` w jednym egzemplarzu. | `apps/api/CLAUDE.md:71` | kopie między contextami | `Shared` jako śmietnik: zdanie „bez znaczenia biznesowego” i reguła biznesowa w `Shared` jako `IMPORTANT` w przeglądzie |
| Z2 | Reuse najpierw: przed napisaniem klasy, funkcji albo stałej technicznej `git grep` po nazwie i kluczowym wywołaniu oraz katalogi wspólne. Próg: druga kopia z dopiskiem w raporcie, trzecia oznacza wydzielenie w tym samym PR. Wydzielenie nie jest zmianą spoza zakresu. | `developer.md:13`, `code-reviewer.md:16`, `CLAUDE.md` („Sposób pracy”) | kopiuj-wklej per epik | kolizje z równoległymi zadaniami: wydziela zadanie, które pierwsze trafiło na próg, pozostałe zgłaszają kopię do Z5 |
| Z3 | Czwarta stała kontrola reviewera, „Powielenie i pośrednicy”: trzecia kopia to `IMPORTANT`, reszta `SUGGESTION` (nowy port bez decyzji, `\assert` po walidacji, komentarz powtarzający specyfikację). `architecture-reviewer` porównuje z całym repo i działa przy każdym epiku zmieniającym kod. | `code-reviewer.md`, `architecture-reviewer.md:15`, ADR | kopie przechodzące przegląd | pętle przeglądu: blokuje tylko trzecia kopia |
| Z5 | Na koniec epiku sesja prowadząca porównuje nowe pliki z repo i w razie kopii zleca jedno zadanie konsolidacji. Specyfikacja dostaje sekcję „Istniejące klocki”. Bez nowego agenta, skryptu i hooka. | ADR („Przebieg epiku”), `brainstorm/SKILL.md` | kopie z pracy równoległej | rytuał bez treści: zadanie powstaje tylko, gdy `git grep` coś znajdzie |
| Z6 | Komentarz mówi, dlaczego kod jest nieoczywisty, w jednym lub dwóch zdaniach; nie opisuje, co robi kod, i nie powtarza specyfikacji. `CLAUDE.md` aplikacji: tylko zasady przekrojowe, limit 150 linii sprawdzany w `make check`. | `CLAUDE.md:173-175`, `brainstorm/SKILL.md:73-74` | 17,4% komentarzy, rozrost plików instrukcji | zgubienie zasad przy przenoszeniu: pułapki Doctrine zostają |
| Z7 | Zatwierdzone wymaganie, którego ścieżki nic w epiku nie uruchamia, trafia do specyfikacji jako „Później” z warunkiem startu, bez kodu i testów, po pytaniu do użytkownika. | `brainstorm/SKILL.md:55` | kod pod przyszłość | jedno pytanie więcej na specyfikację |
| Z4 | Reguła portu z sekcji 4.3. Reguła poziomu testu: jedna decyzja ma test na jednym poziomie; kontrole ogólne (401, format błędów, nagłówki cache) mają jeden test przekrojowy po trasach. | `apps/api/CLAUDE.md:68,427-430`, `docs/vision.md:286` | porty czytników, potrójne asercje, 33 testy 401 | endpoint z nietypowym uprawnieniem traci test: „co endpoint robi inaczej” obejmuje jego role |
| Z9 | Próby obejścia tylko dla reguł bezpieczeństwa. Ścieżka błędu tylko dla skryptów uruchamianych w CI, przy wdrożeniu albo na danych produkcji. | `CLAUDE.md:98-99,106-107` | testy 3× większe od skryptu, 10 tys. linii testów chartu | mniej siatki dla `infra/k8s`: chart zachowuje test przypadku podstawowego |
| Z8 | Mechaniczne bramki bez nowych zależności: typowany klient szyny w `Shared` (usuwa `\assert` po szynie), reguła ESLint przeciw lokalnym kopiom stałych list, `grep` w istniejącym celu `check` z liczbą bazową. Detektor kopii albo martwego kodu jako nowa zależność dopiero, gdy po dwóch epikach kopie dalej powstają. | kod i konfiguracja | powrót objawów | nowa zależność wymaga zgody |

Do usunięcia albo skrócenia, bo dłuższe instrukcje same są przyczyną:

- reguła o plikach z vercel/shop w `apps/store/CLAUDE.md:460-467` (0 takich plików);
- około 150 linii opisu działania w `apps/api/CLAUDE.md` (dane demo, listy panelu, łańcuch partii) do specyfikacji;
- `developer.md:19` do dwóch zdań;
- `code-reviewer.md:13`: zostaje „nie narzucaj preferencji stylistycznych”, resztę zastępuje Z3.

**Kolejność wdrożenia:** Z1, Z2, Z3, Z5, Z6, Z7 razem, jako same zmiany tekstu, z próbą na najbliższym epiku natywnym.
Miarą jest liczba nowych plików technicznych poza `Shared` i `composables` oraz liczba kopii znalezionych na końcu
epiku. Z4 i Z9 po próbie, bo zmieniają oczekiwania wobec testów. Z8 jako osobne zadanie po decyzji.

Ograniczenie: transkrypty agentów są niedostępne, więc nie da się wykazać, czy developer widział istniejącą kopię.
Daty i podobieństwo plików wskazują na kopiowanie sąsiada, ale to wniosek pośredni.

## 6. Szacunki redukcji: usuwane a zastępowane

Pierwszy raport podawał jedną liczbę. Tu rozdzielam kod, który znika bez śladu, od kodu, który jest zastępowany inną
implementacją. Linie z `wc -l`, z komentarzami. Kolumna „dopisane” jest szacunkiem (kod niezaimplementowany), kolumna
„usunięte” pochodzi z pomiaru plików.

### 6.1 Kod produkcyjny, bez decyzji właściciela

| Pozycja | Rodzaj | Usunięte | Dopisane | Netto |
|---|---|---:|---:|---:|
| Panel: 35 nieużywanych prymitywów | czyste usunięcie | 800 | 0 | −800 |
| API: martwe metody, klasa wyjątku, shim, stałe | czyste usunięcie | 160 | 0 | −160 |
| Sklep: `checkbox.tsx` | czyste usunięcie | 32 | 0 | −32 |
| **Czyste usunięcia razem** | | **992** | **0** | **−992** |
| API: kodek kursora | zastąpienie | 904 | 400 | −504 |
| API: wspólne odpowiedzi OpenAPI | zastąpienie | 555 | 270 | −285 |
| API: handlery e-maili i porty mailera | zastąpienie | 349 | 160 | −189 |
| API: czytniki treści i `Idempotency-Key` | zastąpienie | 262 | 150 | −112 |
| API: klient szyny zamiast 3 helperów i 49 bloków `catch` | zastąpienie | 160 | 40 | −120 |
| API: jeden subskrybent `no-store` | zastąpienie | 139 | 35 | −104 |
| API: 4 handlery czytające do Infrastructure | zastąpienie | 907 | 623 | −284 |
| API: plastry „historia” i „lista” Pricing i Inventory | zastąpienie | 700 | 300 | −400 |
| API: DTO portu kopiowane do DTO zapytania (Ordering) | zastąpienie | 146 | 45 | −101 |
| API: drobne (publikacja produktu, lokator dostawców, sonda Redisa, `Vary`) | zastąpienie | 431 | 163 | −268 |
| Dane demo: walidacja YAML, parser tar, komenda resetu | zastąpienie | 2 463 | 1 150 | −1 313 |
| Panel: listy, problem details, formularze, historia, uprawnienia | zastąpienie | 1 316 | 560 | −756 |
| Sklep: wywołania BFF, klient katalogu, wrappery, walidacja kształtu | zastąpienie | 446 | 232 | −214 |
| **Zastąpienia razem** | | **8 778** | **4 128** | **−4 650** |
| Osłony produkcji (sekcja 3.1) | nowy kod | 0 | 110 | +110 |
| **Razem bez decyzji** | | **9 770** | **4 238** | **−5 532 (5,4%)** |

Wniosek: z około 5,5 tys. linii netto **tylko około 1 tys. to czyste usunięcie**. Pozostałe 4,65 tys. wymaga
przepisania 8,8 tys. linii na 4,1 tys. To praca i ryzyko regresji, nie kasowanie plików.

### 6.2 Dodatkowo po decyzjach

| Pozycja | Rodzaj | Netto |
|---|---|---:|
| Dane demo: pula zdjęć w R2, rekoncyliacja kont, weryfikator (D8) | w większości czyste usunięcie | −2 500 do −3 300 |
| Jedna konwencja odpowiedzi API (D7) | zastąpienie, bilans niepewny | −400 do −1 100 |
| Wariant V2 kolejki (D4) | czyste usunięcie kodu AMQP; infrastruktura osobno, około 150 linii | −280 |
| Wspólny widok katalogu dla Pricing i Inventory (zmiana zasady ACL) | zastąpienie | około −300 |
| Walidacja zapisu produktu po normalizatorze komunikatów (Z8) | zastąpienie | około −225 |
| Sklep: historia zamówień jako Server Components (zmiana zdania w wizji) | zastąpienie | −300 do −500 |
| Zegar PSR-20 w istniejącym kodzie (przy okazji) | zastąpienie | około −100 |
| **Razem po decyzjach** | | **−4 100 do −5 800** |

Łącznie z decyzjami: 9,6–11,3 tys. linii, czyli 9–11% kodu produkcyjnego.

### 6.3 Testy

| Pozycja | Rodzaj | Netto |
|---|---|---:|
| Testy 401 panelu powielające test z routera, trywialne testy wyjątków | czyste usunięcie | −360 |
| Testy jednostkowe 4 zlikwidowanych portów | czyste usunięcie (ochrona w testach integracyjnych) | −605 |
| Duplikaty między poziomami domena, handler, HTTP | czyste usunięcie; ekstrapolacja z próby 384 testów | −2 500 do −4 000 |
| Panel: zachowania pagera powtórzone w 8 plikach | czyste usunięcie | −1 500 do −2 500 |
| Wspólny trait pomocników, metody różniące się wartością, testy generyczne z OpenAPI | zastąpienie | około −960 |
| Testy idące za zmianami kodu (kursory, dane demo, historia w panelu, E2E) | zastąpienie | około −2 400 |
| **Razem bez decyzji** | | **−8 300 do −10 800 (5–7%)** |
| Po decyzjach: testy migracji (2 087), dane demo (około 4 400), V2 (około 490), sklep (około 800) | | −7 800 |

### 6.4 Czego liczby nie pokazują

- Zmiany reguł (sekcja 5.3) nie usuwają ani jednej linii kodu, a mają największy wpływ na koszt utrzymania: bez nich
  kod wraca do stanu sprzed konsolidacji w tempie jednej kopii na epik.
- Pliki instrukcji skracają się o około 550 linii, które przechodzą do specyfikacji. To przeniesienie, nie redukcja,
  ale zmniejsza kontekst wczytywany przez każdego agenta.
- Migracja zegara dałaby −100 linii przy 105 zmienionych plikach. To przykład zmiany, której **nie** warto robić dla
  samej liczby.

## 7. Ostateczna propozycja epików

### 7.1 Zasady doboru

- Najpierw to, co zatrzymuje narastanie kosztu (reguły) i zamyka ryzyko (osłony), potem konsolidacja.
- Epik obejmuje jedną aplikację albo jeden rodzaj zmiany, żeby mógł iść równolegle z innym bez wspólnego kodu.
- Zmiana, która daje mało linii przy dużej liczbie plików, nie jest epikiem.

### 7.2 Epiki i małe PR-y

| Nr | Nazwa | Zakres | Netto (kod / testy) | Ryzyko | Wymaga |
|---|---|---|---|---|---|
| P0 | **PROD-GUARDS** (jeden PR) | jawny `--force-reset` w `bin/seed-demo`, listener dla `demo-data:*`, odwrócony warunek w seedach, test zgodności zmiennych środowiska; osobno poprawka dokumentów o kopiach albo włączenie kopii | +110 / +200 | STANDARD | D2, D3 |
| E1 | **RULES** (PR dokumentacyjny) | Z1, Z2, Z3, Z5, Z6, Z7, reguła portu, reguła zegara, usunięcie martwych reguł, przeniesienie opisów działania z `CLAUDE.md` aplikacji do specyfikacji | 0 / 0; instrukcje około −550 linii | FAST, ale to zmiana zasad | D1, D5, D6 |
| E2 | **API-SHARED-KIT** | kodek kursora, klient szyny, jeden subskrybent `no-store`, wspólne odpowiedzi OpenAPI, czytnik treści i `Idempotency-Key`, techniczne formaty (`UuidFormat`, format klucza idempotencji), handlery e-maili, martwy kod API; testy: 401 panelu, wspólny trait, metody różniące się wartością | −1 470 / −1 150 | STANDARD | E1 (Z1) |
| E3 | **ADMIN-CONSOLIDATION** | usunięcie 35 prymitywów, `listPage`, `problem.ts`, `useCursorHistory`, `hasRole` i `meta` w routerze, `RouterLink custom`; testy pagera w jednym miejscu | −1 560 / −1 700 do −2 700 | STANDARD | brak |
| E4 | **Kolejka zdarzeń w ASYNC-RELIABILITY** (nie osobny epik) | pomiary E8–E10 jako eksperymenty przed startem, ADR z wyborem wariantu, epik realizowany w wybranym wariancie; przy V2 zadanie migracji z wydaniem przejściowym | V2: −280 / −490; V3: dodatnie | CRITICAL (dostarczanie, transakcje) | D4 |
| E5 | **API-READ-SIDE** | 4 handlery czytające do Infrastructure, generyki strony i zapytania dla plastrów Pricing i Inventory, DTO odczytu w Ordering, drobne zamiany natywne; po decyzji D7 osobna fala z konwencją kontraktu | −1 050 / −605 | STANDARD | E1 (reguła portu), E2 |
| E6 | **DEMO-DATA-TRIM** | Serializer dla YAML, jedna komenda resetu, pula zdjęć jako obiekty; zakres zdjęć, kont i weryfikatora według D8 | −1 310 / −1 700; z D8 do −4 600 / −6 100 | STANDARD | D8, P0 |
| E7 | **TEST-CONSOLIDATION** | testy generyczne z routera i OpenAPI (401 tras sklepu, zły JSON, brak klucza), potem usunięcie duplikatów między poziomami z porównaniem pokrycia per moduł; zasada dla testów migracji | 0 / −2 800 do −4 300; z migracjami −2 087 więcej | STANDARD | D9, próba Z4 |

Małe PR-y poza epikami: konsolidacje w sklepie (−214 linii), composite action dla obrazów w CI, zawężenie filtra smoke
w `deploy.yml`, Dependabot, `garage-init` przez admin API, Mailpit w `prod` na transport `null`.

### 7.3 Kolejność i zależności

Ta propozycja jest wkładem do jednej roadmapy, która ma powstać po zakończeniu wszystkich audytów. Nic z niej nie
startuje bez akceptacji. Kolejność poniżej pokazuje zależności między epikami uproszczeń, a nie ich miejsce względem
epików funkcjonalnych, bezpieczeństwa i wydajności. Trzy rzeczy warto uwzględnić przy syntezie:

- **P0 jest jedyną pozycją, której nie warto odkładać do roadmapy:** to około 60 linii osłony przed nieodwracalnym
  skasowaniem danych przy wyłączonych kopiach.
- **Wybór wariantu kolejki zależy od roadmapy.** Jeśli znajdą się w niej niezależne usługi albo integracje, które same
  konsumują zdarzenia, warunek dla V3 jest spełniony niezależnie od pomiarów. Integracja realizowana jako adapter HTTP
  wewnątrz monolitu tego warunku nie spełnia.
- **Zakres danych demo (D8) i epik E6 zależą od tego, czy roadmapa nadal inwestuje w pomiary dużej skali.**

```
teraz        P0 PROD-GUARDS  ∥  E1 RULES
potem        E2 API-SHARED-KIT  ∥  E3 ADMIN-CONSOLIDATION        (różne aplikacje, bez wspólnego kodu)
             pomiary E8–E10 → ADR o kolejce zdarzeń (D4)
następnie    ASYNC-RELIABILITY w wybranym wariancie               (pierwsza próba reguł Z1–Z3, Z5)
             E5 API-READ-SIDE
na końcu     E6 DEMO-DATA-TRIM  ∥  E7 TEST-CONSOLIDATION
```

- **E1 przed wszystkim innym.** Każdy epik uruchomiony wcześniej dopisze kopie według starych reguł. E1 to zmiany
  tekstu, więc nie opóźnia pozostałych prac.
- **E2 i ABUSE-LIMITS nie równolegle.** ABUSE-LIMITS dopisuje limity do tras i rozbudowuje `RateLimitSubscriber`,
  a E2 zmienia kontrolery i `Shared`. Kolejność między nimi jest decyzją priorytetu: ABUSE-LIMITS jest zmianą
  bezpieczeństwa, E2 porządkiem. Jeśli ABUSE-LIMITS idzie pierwszy, powinien iść już po E1 i stać się pierwszą próbą
  nowych reguł.
- **E3 może iść równolegle z każdym epikiem API**, o ile ten nie zmienia panelu.
- **E5 po E2**, bo korzysta z kodeka kursora i klienta szyny.
- **E7 po próbie Z4**, żeby nie usuwać testów przed uzgodnieniem, który poziom jest właścicielem decyzji.

### 7.4 Czego świadomie nie proponuję

| Pozycja | Powód |
|---|---|
| Migracja 5 portów `Clock` osobnym PR-em | 105 plików dla 180 linii; reguła dla nowego kodu wystarcza |
| Zamiana `RateLimitSubscriber` na `#[RateLimit]` | natywny atrybut działa na innym etapie żądania, a ABUSE-LIMITS buduje na subskrybencie; to kontrola bezpieczeństwa |
| Likwidacja portów pulpitu Backoffice | wymusza je Deptrac, a testy sprawdzają reguły dostępu |
| Likwidacja portów modeli odczytu poza czterema wskazanymi | ich testy sprawdzają decyzje handlera |
| Mapowanie domeny przez Doctrine | wysokie ryzyko, niepewny zysk |
| Wspólne źródło zmiennych środowiska dla compose i chartu | test na 60–80 linii daje tę samą ochronę |
| Server Actions w sklepie, biblioteka stanu serwera w panelu, wymiana bibliotek logowania | zysk nie pokrywa ryzyka; uzasadnienie w pierwszym raporcie |

## 8. Korekty względem pierwszego raportu

| Temat | Było | Jest |
|---|---|---|
| Komendy `demo-data:*` | „`demo-data:accounts` usuwa członków grupy”, pilne ryzyko | komendy są addytywne; realnym ryzykiem jest `bin/seed-demo`, który sam dokłada `--force-reset` |
| RabbitMQ | „REPLACE_NATIVE, rozstrzygnąć przed ASYNC-RELIABILITY” | trzy warianty z warunkami; rozstrzygnięcie pomiarem E8–E10 przed startem epiku; bez rekomendacji usunięcia dla prostoty |
| Interfejsy | 95 z jedną implementacją, część portów czytników i pulpit do uproszczenia | 103 z jedną implementacją (liczone po pełnych nazwach), z czego 4 do likwidacji i 5 do scalenia; pulpit i większość czytników zostają |
| Zegar | zmiana z pierwszej piątki | reguła dla nowego kodu, bez migracji |
| `#[RateLimit]` | REPLACE_NATIVE | wycofane |
| `PersistenceSession` | do uproszczenia | zostaje |
| Zmienne środowiska | „nic tego nie pilnuje”, test około 30 linii | potwierdzone, ale wszystkie różnice są zamierzone i ryzyko jest niskie; test 60–80 linii |
| Kopie zapasowe | „wyłączone w `prod`” | nigdy nie działały; wyłączone są też alerty kopii i narzędzia odtwarzania |
| Szacunek API | 2,8–3,4 tys. linii netto | około 2,4 tys. netto bez danych demo; rozdzielone na usunięcia i zastąpienia |

## 9. Ograniczenia

- Pomiar kolejek to mikrobenchmark na laptopie. Pokazuje strukturę kosztów i relacje, nie wydajność produkcji.
- Model obciążenia opiera się na założeniach o konwersji, liczbie wariantów i częstości operacji masowych.
- Linie „dopisane” w sekcji 6 są szacunkiem, bo kod nie został napisany.
- Udziały przyczyn w sekcji 5 są jakościowe. Transkrypty agentów i GitHub nie były dostępne.
- Nie dało się sprawdzić z repozytorium: czy backup VPS-a u dostawcy jest włączony, czy generator danych działał kiedyś
  na serwerze, czy użytkownik `admin` w realmie `master` nadal istnieje.
- Testów API nie uruchamiałem. Werdykty „ochrona w testach integracyjnych” opierają się na porównaniu nazw i treści
  testów, nie na pokryciu.

## 10. Źródła

Kod i pakiety: ścieżki w tekście; `symfony/messenger`, `doctrine-messenger`, `amqp-messenger` w wersji 8.1.11.

- RabbitMQ, konsumenci (`basic.get` odradzany): https://www.rabbitmq.com/docs/consumers
- RabbitMQ, potwierdzenia wydawcy: https://www.rabbitmq.com/docs/confirms
- RabbitMQ, kolejki kworum i limit dostarczeń: https://www.rabbitmq.com/docs/quorum-queues
- RabbitMQ, pamięć i alarmy: https://www.rabbitmq.com/docs/memory, https://www.rabbitmq.com/docs/alarms
- RabbitMQ, aktualizacje i zarządzanie: https://www.rabbitmq.com/docs/upgrade, https://www.rabbitmq.com/docs/management
- PostgreSQL, `SKIP LOCKED` i `NOTIFY`: https://www.postgresql.org/docs/current/sql-select.html,
  https://www.postgresql.org/docs/current/sql-notify.html
- PgBouncer, `LISTEN` w trybie transakcji: https://www.pgbouncer.org/features.html
- Symfony Messenger: https://symfony.com/doc/current/messenger.html
- Symfony Clock: https://symfony.com/doc/current/components/clock.html
- Wpisy inżynierskie, każdy z zastrzeżeniem autora:
  - Recall.ai o `LISTEN/NOTIFY` (jeden przypadek produkcyjny): https://recall.ai/blog/postgres-listen-notify-does-not-scale
  - DBOS, polemika (dostawca): https://dbos.dev/blog/postgres-listen-notify-scalability
  - PlanetScale o kolejce w Postgresie (dostawca, własny benchmark): https://planetscale.com/blog/keeping-a-postgres-queue-healthy
  - 37signals, Solid Queue (liczby produkcyjne, MySQL): https://dev.37signals.com/introducing-solid-queue/
