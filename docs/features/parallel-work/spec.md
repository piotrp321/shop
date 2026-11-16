# Praca równoległa: epiki naraz (19a) i zadania fali naraz (19b)

Krok 19 planu [Agentic Software House](../agentic-software-house/plan.md), komponent „Pula środowisk” z
[ADR](../../adr/agentic-software-house.md). Równoległość jest domyślna: kolejny przygotowany epik rusza naraz
z trwającym, gdy przejdzie bramkę ryzyka; wykonanie szeregowe zostaje rozwiązaniem awaryjnym, gdy bramka wykryje
realne ryzyko. 19a działa w `shop` (skille `/ao-run` i `/delivery`, bez zmian w AO). 19b to zmiana w AO
(piotrp321/agentic-orchestrator#50) z kontraktem i częścią w `shop` opisanymi w sekcji „19b”.

Miara główna: czas zegarowy epiku. Pilnujemy przy tym jakości, liczby napraw, interwencji człowieka i zużycia limitów.

## 19a: epiki naraz

### Pojęcia

- **Stan wspólny**: katalog `~/.local/state/ao-night/` (`measurements.jsonl`, `night.json`, `claims.json`,
  `gate.jsonl`, `gate.lock`, `parallel-hold.json`), jeden dla wszystkich sesji `/ao-run` na tym komputerze.
- **Epik w toku**: epik z gałęzią `origin/epic/<katalog>` i otwartym issue z etykietą `epic` (jak w `next_epic.py`),
  bez zmergowanego końcowego PR `epic/<katalog>` → `main` (`gh pr list --head epic/<katalog> --base main --state
  merged`), albo epik z ważną rezerwacją (sekcja „Rezerwacja”). Obejmuje epiki czekające na merge końcowego PR.
  Epik ze zmergowanym końcowym PR i otwartym issue nie jest w toku; bramka podaje go w `merged_open` (sesja prosi
  o zamknięcie issue, jak w sekcji 8 skilla).
- **Epik aktywny**: epik z ważną rezerwacją albo epik w toku, którego ostatnie zdarzenie w `measurements.jsonl` to
  `start` albo który nie ma tam żadnego zdarzenia (uruchomiony poza `/ao-run`: liczony ostrożnie jako aktywny). Epik
  po `done` (po `epic_done`, czeka na merge) nie zajmuje miejsca ani zasobów, ale dalej liczy się przy plikach.
- **Pliki epiku**: ścieżki z linii `Zakres:` jego `spec.md` na `origin/main` i jego `docs/features/<katalog>`
  (reguły linii i porównania po segmentach: `docs/features/ao-run/spec.md`, sekcja „Zakres epiku”). Dla epiku
  z gałęzią dochodzi `git diff --name-only origin/main...origin/epic/<katalog>` (po `git fetch origin
  'refs/heads/epic/*:refs/remotes/origin/epic/*'`).
- **Część wspólna** dwóch epików: dla każdej pary ścieżek, z których jedna leży w drugiej, węższa z nich.
- **Kandydat**: przygotowany epik z tablicy (reguły `next_epic.py`), który nie jest w toku. Bramka nigdy nie
  porównuje kandydata z nim samym ani z jego rezerwacją.

### Bramka

Bramka sprawdza kandydata względem wszystkich epików w toku; pierwszy niespełniony warunek daje wynik.

1. **Brak epików w toku**: kandydat przechodzi bez dalszych warunków (start szeregowy).
2. **Miejsce**: najwyżej jeden epik aktywny (razem z kandydatem najwyżej dwa). Inaczej: czekanie, którego
   wymuszenie (`--force`) nie omija.
3. **Zależności**: sekcja `## Zależności` issue kandydata nie wymienia ID otwartego epiku (reguła listy nocy).
   Inaczej: kandydat pominięty.
4. **Zakres**: kandydat i każdy epik w toku mają czytelną linię `Zakres:`. Brak u kandydata: kandydat pominięty;
   brak u epiku w toku: czekanie (nie wiadomo, co zmienia).
5. **Pliki**: część wspólna kandydata z każdym epikiem w toku nie zawiera kodu aplikacji. Inaczej: kandydat pominięty
   z powodem „wspólne pliki z X: <ścieżki>” i polem `paths` ze ścieżkami blokującymi ze wszystkich epików w toku (nie
   tylko z pierwszego). Wspólny plik sam w sobie nie jest ryzykiem: Git łączy zmiany w różnych miejscach jednego
   pliku, a konflikt tekstowy i niespójność dokumentów rozwiązują sync i REPAIR (sekcje „Spójność dokumentów”
   i „Testy po scaleniu”).
   - **Kod aplikacji (blokuje):** ścieżka w `apps/<aplikacja>/src` albo `apps/<aplikacja>/app` (po segmentach:
     `docs/features/ao-run/spec.md`, „Zakres epiku”) i ścieżka, która taki katalog zawiera (`apps`,
     `apps/<aplikacja>`), bo deklaracja szersza niż kod obejmuje kod. Dwa epiki zmieniające ten sam kod aplikacji
     mogą zmienić to samo zachowanie, a merge bez konfliktu tekstowego i zielone CI tego nie wykluczają. Tej oceny
     bramka nie umie zrobić wiarygodnie z deklaracji, więc reguła dla kodu zostaje konserwatywna; jej koszt mierzy
     `code_blocked` w `pairs.py` (sekcja „Metryki par”).
   - **Wyjątki w kodzie aplikacji (nie blokują):** pliki generowane `apps/store/src/api/schema.d.ts`,
     `apps/admin/src/api/schema.d.ts` (sync i REPAIR generują je od nowa), pliki rejestracji, do których epiki
     dopisują pozycje (menu panelu `apps/admin/src/layout/AppMenu.vue`, `apps/admin/src/router/index.ts`), i każdy
     `CLAUDE.md` oraz `README.md`.
   - **Wszystko inne (nie blokuje):** CI (`.github/**`), `CLAUDE.md`, `README.md` (także runbook
     `infra/k8s/README.md`), `make/*.mk`, główny `Makefile`, dokumentacja `docs/**`, testy (`apps/*/tests`, `e2e`,
     `infra/k8s/tests`), konfiguracja aplikacji (`apps/api/config`, `composer.json`, `package.json`, lockfile'y),
     infrastruktura (`infra/**`), narzędzia repo (`.claude/**`, `.ao/**`, `tools/**`), pliki generowane
     `apps/api/openapi.json` i `apps/api/openapi-admin.json`.
   - Rzeczywista zależność epików to krok 3, nie reguła plików. Użytkownik może uruchomić kandydata mimo wspólnego
     kodu (`--force`, „Uruchomić mimo bramki?”).
6. **Pamięć Dockera**: `wolna = MemTotal − pamięć kontenerów spoza AO − max(pamięć kontenerów AO, rezerwa epiku ×
   liczba epików aktywnych)` jest co najmniej rezerwą epiku. Rezerwa epiku to `max-parallel` stosów (AO prowadzi do
   tylu prób zadań epiku naraz, sekcja „19b”; stała `MAX_PARALLEL = 2` w `parallel_gate.py`, ta sama wartość, którą
   `/ao-run` przekazuje AO), czyli 2 × 6 GiB. Rezerwa stosu: 6 GiB (`--stack-gib`, pomiar: stos ok. 4,9 GB).
   Kontenery AO to kontenery projektów Compose o nazwie `ao-*` (etykieta `com.docker.compose.project` z `docker ps`,
   pamięć z `docker stats --no-stream`, `MemTotal` z `docker info`). Rezerwa na epik aktywny działa także wtedy, gdy
   ten epik jest akurat między zadaniami i nie ma stosu. Błąd albo nieczytelne wyjście `docker`: czekanie z powodem.
7. **Zapas limitu**: stan limitów `ok`, jest szacunek czasu i zużycia kandydata (sekcja „Szacunek” spec `/ao-run`),
   a każde okno, które nie odnowi się przed końcem szacunku czasu kandydata, spełnia
   `procent teraz + pełny szacunek każdego epiku aktywnego + szacunek kandydata ≤ 80%`. Pełny, nie pozostały: część
   zużycia aktywnego epiku jest już w „procencie teraz”, więc wzór zawyża (bezpieczny kierunek). Szacunek zużycia
   liczy się tylko z pomiarów epików, które nie nakładały się w czasie z innym epikiem (przyrost okna epiku
   równoległego zawiera zużycie drugiego); bez takich pomiarów ze wszystkich. Szacunek czasu: ze wszystkich pomiarów.
   Inaczej: czekanie.

**Wstrzymanie równoległości**: gdy istnieje `parallel-hold.json` (sekcja „Metryki par”), zaraz po
kroku 2 każdy kandydat obok epiku w toku (także czekającego na merge) czeka z powodem `równoległość wstrzymana:
<powód>`. Krok 1 działa dalej, więc praca szeregowa trwa. Odnowienie rezerwacji założonej przed wstrzymaniem go nie
sprawdza (odnowienie nie uruchamia kroków bramki), więc taki epik może ruszyć jeszcze w ciągu 30 minut od rezerwacji
lub ostatniego odnowienia.

„Pominięty” oznacza, że bramka sprawdza następnego kandydata z tablicy (niższy epik może ruszyć przed wyższym, jak
w liście nocy). „Czekanie” dotyczy każdego kandydata, więc bramka kończy szukanie: kandydat ruszy później,
równolegle albo szeregowo po zakończeniu epiku aktywnego.

### Rezerwacja (dwie sesje naraz)

Sam odczyt stanu przed startem nie wystarcza: dwie sesje mogłyby jednocześnie zobaczyć wolne miejsce i obie ruszyć.
Dlatego decyzja o starcie i jej zapis są jedną operacją pod blokadą.

- **Blokada**: `fcntl.flock` na `gate.lock`. Trzymają ją wszystkie komendy, które czytają stan do decyzji albo
  zapisują stan wspólny: `gate --reserve`, `release`, `start`, `done`, `approve`, `close`, `next`, `hold` i `unhold`,
  przez cały odczyt, sprawdzenie i zapis. Czekanie na blokadę najwyżej 120 s; potem kod 2 z powodem „blokada bramki
  zajęta”, bez zapisu. Każda komenda `gh`, `git` i `docker` tych komend trwa najwyżej 60 s, więc zawieszona komenda nie
  trzyma blokady: po limicie jest przerywana i kończy się jak błąd komendy (`gh` i `git`: kod 1; `docker`: jak błąd
  `docker`). Sesja wywołuje te komendy z limitem Bash co najmniej 300000 ms, a po kodzie 2 z „blokada bramki zajęta”
  powtarza komendę raz, zanim uzna ją za błąd.
  `claims.json`, `night.json` i `parallel-hold.json` są zapisywane przez plik tymczasowy i `os.replace`, więc
  przerwany zapis nie zostawia nieczytelnego pliku.
- **Rezerwacja**: wpis w `claims.json` (`dir`, `claim` (losowy token), `at`, `expires_at`), ważny 30 minut. Bramka
  liczy epik z ważną rezerwacją jako epik w toku i aktywny (pliki z `Zakres:` jego `spec.md` na `origin/main`, rezerwa
  pamięci, pełny szacunek limitu). Wygasłe wpisy są pomijane i usuwane przy następnym zapisie.
- **Token**: `gate --reserve` zwraca `claim`. `gate --dir <epik> --reserve --claim <token>` z tokenem ważnej rezerwacji
  tego epiku odnawia ją (nowe `expires_at`) bez kroków bramki: decyzję podjęto pod blokadą przy zakładaniu rezerwacji,
  a sama rezerwacja zajmuje miejsce, pamięć i limit; obowiązują tylko odmowy samego epiku (spoza tablicy, zmergowany,
  nieprzygotowany). Wynik: `epic`, `reserved: true`, `claim` (ten token), `reason` `null`. Z tokenem rezerwacji
  wygasłej albo nieistniejącej bramka sprawdza epik jak nową rezerwację (z tym tokenem). `start` i `release` przyjmują
  token. Ważna rezerwacja tego samego epiku przez inną sesję (inny token) to odmowa „epik X zarezerwowany albo
  w toku”, której `--force` nie omija: dwie sesje nie ruszą jednego epiku. Epik w toku z gałęzi idzie ścieżką
  wznowienia („Wznowienie” niżej).
- **Przebieg startu** (sekcja 1 skilla): `gate --dir <epik> --reserve` (albo z tokenem z `next`) przed
  `ao epic start --dry-run`; tuż przed właściwym `ao epic start` odnowienie tym samym tokenem (bez ponownych kroków
  bramki, więc epik nocy zarezerwowany przez `next` w przypadku (b) nie odpada na plikach epików czekających na merge;
  wygasła rezerwacja: bramka od nowa, a gdy nie przechodzi albo rezerwację ma inna sesja, brak startu). `night_queue.py
  start <epik> --claim <token>` (zaraz po udanym `ao epic start`) zapisuje zdarzenie `start` i usuwa rezerwację w tej
  samej blokadzie. `ao epic start` od razu wypycha `epic/<epik>`, więc po usunięciu rezerwacji epik jest w toku
  z gałęzi. Odmowa w sekcjach 1–2 po rezerwacji: `release <epik> --claim <token>`. Rezerwacja niezwolniona (np.
  przerwana sesja) wygasa sama. `start` odmawia (kod 2, `epik X ma innego właściciela`, bez zapisu), gdy epik ma
  zapisanego właściciela innego niż `--claim` (także bez `--claim`), więc ponowny `start` epiku wymaga tokenu
  właściciela.
- **Pytanie a rezerwacja**: `gate` bez `--reserve` tylko czyta (do pytania „Zacząć epik X?”), więc nie blokuje innych
  sesji na czas czekania na odpowiedź. „Tak” jest ważne tylko w chwili rezerwacji: gdy bramka już nie przechodzi,
  sesja podaje powód, a po późniejszym przejściu bramki pyta od nowa z aktualnym `parallel_with`.
- **Wymuszenie**: w dzień `gate --dir <epik> --reserve --force` zakłada rezerwację mimo niespełnionych kroków 3–7
  i wstrzymania równoległości (wynik bramki zostaje w wyjściu: `reason` i `waiting`, a `skipped` może wymieniać ten
  sam epik, zwrócony z `reserved: true`), tylko po „tak” użytkownika na „Uruchomić mimo bramki?”. Sesja rozpoznaje
  rezerwację po `reserved`, nie po pustym `skipped`. Wymuszenie nigdy nie omija kroku 2 (dwa epiki aktywne: odmowa
  `dwa epiki aktywne: …` bez rezerwacji) ani odmów samego epiku (spoza tablicy, zmergowany, nieprzygotowany,
  zarezerwowany). W nocy nie ma wymuszenia.
- **Wznowienie**: `gate --dir` dla epiku, który jest już w toku z gałęzi (np. nowa sesja przejmuje epik po przerwanej),
  zwraca `resumed: true` bez sprawdzeń bramki i bez rezerwacji: epik już zajmuje swoje miejsce. Wznowienie nie może
  uruchomić drugiego procesu AO dla epiku innej sesji:
  - proces AO epiku działa (blokada `orchestrator.lock` w `~/.local/state/ao-<epik>` jest zajęta; sprawdzenie
    współdzieloną blokadą bez czekania): odmowa „epik X prowadzi działający proces AO”, bez wyjątków (`--force` ani
    `--takeover` jej nie omijają);
  - `start` zapisuje właściciela epiku (token rezerwacji) w `claims.json`. Wznowienie z innym tokenem niż właściciel
    to odmowa „epik X prowadzi inna sesja”, np. gdy tamta czeka na odpowiedź na bloker i jej `ao run` się zakończył.
    `--takeover` przejmuje epik (nowy token właściciela) tylko w dzień, po „tak” użytkownika na „Przejąć epik X od
    innej sesji?”. Epik bez zapisanego właściciela (uruchomiony poza `/ao-run`) wznawia się z nowym tokenem.
  - Właściciela (nowy token, przejęcie) wznowienie zapisuje tylko z `--reserve`. `gate --dir` bez `--reserve` dla
    epiku w toku tylko czyta: `resumed: true` z `claim: null` i z tymi samymi odmowami, bez zapisu w `claims.json`.
  - Po `resumed: true` sesja nie uruchamia ponownie `night_queue.py start`: właściciel jest już zapisany, a epik
    zachowuje swój pomiar (otwarty `start`).
  - Sesja, która prowadzi epik, przy ponownym `ao run` (sekcja 5 skilla) nie woła bramki: `ao run` sam odmawia, gdy
    na tym `AO_HOME` działa już inny proces.

### Spójność dokumentów

Konflikt dokumentacji bywa semantyczny: Git łączy dwa opisy bez konfliktu, a razem przeczą sobie albo opisują stan
sprzed zmiany drugiego epiku. Dlatego:

- **`/delivery`, sekcja „Konflikt z gałęzią docelową lub z `main`”** (REPAIR w AO, tryb poprawki, synchronizacja
  i sync z `/ao-run`): kontrola po merge commicie obejmuje każdy dokument (`docs/**`, każdy `README.md` i `CLAUDE.md`)
  zmieniony po obu stronach od merge-base, nie tylko pliki w konflikcie. Dla każdego: obie zmiany zostają, tekst nie
  przeczy sobie ani kodowi po merge'u, opisuje stan obecny z obu stron (bez „wcześniej”), a odwołania, listy i liczby
  zgadzają się z obu zmianami. Niespójność naprawia ten sam merge commit; niespójność wymagająca decyzji o zachowaniu:
  `BLOCKED` jak przy konflikcie. Pliki generowane zmienione po obu stronach powstają od nowa także bez konfliktu.
  Wynik („Spójność dokumentów: <plik>: zgodne / poprawione: …”) trafia do opisu PR i `integration_notes`.
- **Sync spójności w parze.** Para: epik P zmergowany pierwszy i epik Q z otwartym końcowym PR. Rulesety nie wymagają gałęzi
  „up to date”, więc bez syncu CI końcowego PR Q nie widziało zmian P, także gdy oba epiki zmieniły różne pliki tej
  samej aplikacji albo konfiguracji. Sesja Q uruchamia procedurę `sync/<epik>-main` (sekcja 6a skilla) dla Q, gdy
  jednocześnie:
  - Q ma `epic_done` (wcześniej zmiany `main` wprowadza AO: REPAIR przy konflikcie końcowego PR);
  - commit merge'u P nie jest przodkiem `origin/epic/<Q>` (`git merge-base --is-ancestor`), więc sync wykonany raz się
    nie powtarza, także po rundzie `DELTA` i ponownym `epic_done`.
  Wspólny plik nie jest warunkiem: sync bez konfliktu też kończy się testami na połączonym stanie (sekcja „Testy po
  scaleniu”), a o kontroli spójności decyduje lista plików zmienionych po obu stronach.
  Gdy końcowy PR Q ma konflikt, a AO nie działa, ta sama procedura rozwiązuje konflikt. Zgodą na procedurę dla par
  (także przy konflikcie) jest akceptacja tej specyfikacji (`CLAUDE.md`: PR `sync/<epik>-main` merguje skill po zgodzie
  użytkownika na procedurę i zielonym CI). Końcowy PR Q `MERGED` albo `CLOSED` w trakcie syncu: sesja przerywa,
  sprząta (krok 6 sekcji 6a) i zapisuje to w bloku pary. Do końca syncu raport odbioru Q ma rekomendację „najpierw
  sprawdź: sync spójności w toku”. Sync spójności nie jest ręczną poprawką w pomiarze (sekcja 7 skilla); liczy się
  w bloku pary jako „sync automatyczny”.
- **Wykrycie merge'u P** (także z innej sesji): pętla w tle z sekcji 6 skilla, która co 5 minut sprawdza stan
  końcowego PR Q, kończy się też przy zmianie `origin/main` (`git ls-remote origin main`); sesja sprawdza wtedy
  warunki syncu i uruchamia pętlę ponownie.

### Testy po scaleniu

Merge bez konfliktu tekstowego nie dowodzi, że połączony kod działa. Dlatego każde scalenie zmian drugiego epiku
(REPAIR, sync spójności, tryb poprawki) sprawdza zintegrowany stan epiku, czyli epik razem ze zmianami z `main`,
a nie tylko poprawność samego merge'u Git:

- lokalnie, przed wypchnięciem merge commitu (`/delivery`, „Konflikt z gałęzią docelową lub z `main`”): `make check`
  i testy każdej aplikacji, którą zmienia epik od merge-base (`git diff --name-only <merge-base> HEAD` sprzed merge'u),
  oraz każdej aplikacji z plikiem zmienionym po obu stronach, nie tylko pliki w konflikcie; do tego kontrola migracji
  (niżej);
- w CI: PR REPAIR i `sync/<epik>-main` trafia do `epic/<epik>` dopiero po zielonych wymaganych checkach `checks`
  (`make check` i testy wszystkich aplikacji na świeżym stosie) i `e2e` (ruleset `epic-merge`), potem CI biegnie
  jeszcze raz na HEAD gałęzi epiku, a końcowy PR do `main` wymaga tych samych checków.

**Migracje.** Wspólne migracje nie blokują startu (reguła plików). Kolizję wykrywa scalenie:

- wykrycie: migracje dodane albo zmienione po obu stronach od merge-base (`git diff --name-only "$b" origin/<gałąź>
  -- apps/api/migrations` i `git diff --name-only "$b" HEAD -- apps/api/migrations` przed merge'em, obie niepuste);
- kolizja: migracje obu stron zmieniają tę samą tabelę (nazwy tabel z ich SQL), mają tę samą wersję albo strona
  epiku zmienia migrację, która jest już na `main` (migracja z `main` jest niezmienna, bo mogła być wdrożona). Przy
  tej samej tabeli wynik nie może zależeć od kolejności: na produkcji migracje epiku wykonają się po migracjach
  z `main`, na świeżej bazie po wersjach, więc migracja epiku starsza od migracji z `main` wykona się w innej
  kolejności niż od zera. Kolizję usuwa poprawka migracji epiku w tym samym merge commicie; kolizja wymagająca decyzji
  o schemacie: `BLOCKED` jak przy konflikcie;
- weryfikacja na połączonym kodzie, w obu kolejnościach: lokalnie `make -C apps/api test` (`init-test` wykonuje na
  bazie testowej stosu migracje jeszcze niewykonane, jak na produkcji) i w CI `checks` (świeża baza, wszystkie
  migracje od zera, testy zgodności schematu z mapowaniem `*SchemaTest`);
- wynik w opisie PR i `integration_notes`: linia `Migracje: <pliki z main> / <pliki epiku>; kolizje: brak / <tabela>:
  <rozstrzygnięcie>` albo `Migracje: brak migracji po obu stronach`.

Czerwony check na połączonym stanie to regresja w metrykach par (sekcja „Metryki par”), także gdy REPAIR ją naprawił.
Regresja naprawiona automatycznie nie zatrzymuje innych epików; krytyczny problem to dopiero czerwone CI na `main`.

### Gdzie działa

- **`night_queue.py gate [--dir <katalog>] [--reserve [--claim TOKEN] [--force] [--takeover]] [--stack-gib N]
  [--ao-root DIR]`** (stdin: obiekt `plan` z `get_usage`; `--ao-root`: katalog z `ao-<epik>` do sprawdzenia procesu
  AO przy wznowieniu, domyślnie `~/.local/state`): bez `--dir` szuka pierwszego kandydata z tablicy, który przechodzi
  bramkę; z `--dir` sprawdza ten epik (epik spoza tablicy albo nieprzygotowany: odmowa z powodem). Wynik (JSON, kod 0): `epic`
  (`dir`, `id`, `issue`, `title`, `tasks`, `minutes`) albo `null`, `reason` (powód czekania albo odmowy, inaczej
  `null`), `reserved`, `claim` (token albo `null`), `resumed`, `parallel_with` (katalogi epików w toku, z którymi epik
  ruszy), `active`, `in_progress`, `merged_open`, `skipped` (`issue`, `id`, `reason`, `paths`), `docker` (`total_mib`,
  `other_mib`, `ao_mib`, `free_mib`, `required_mib` albo `null`), `waiting` (`dir`, `id`, `issue`, `title` kandydata,
  którego kroki bramki skończyły się czekaniem, także przy `--force`, inaczej `null`). Każde wywołanie dopisuje wynik do
  `gate.jsonl` (czas, kandydaci z wynikiem i `paths`, `waiting` jako katalog albo `null`, bez treści issues). Kod 1:
  błąd `gh` albo `git` (także komenda dłuższa niż 60 s); kod 2: błąd stanu (nieczytelny plik pomiarów, rezerwacji albo
  wstrzymania, zajęta blokada).
- **`night_queue.py release <katalog> --claim TOKEN`**: usuwa własną rezerwację; brak jej: kod 0 bez zmiany.
- **`night_queue.py hold --reason TEXT`**: zapisuje (pod blokadą, atomowo) `parallel-hold.json` z `at` i `reason`
  i wypisuje go; istniejące wstrzymanie zostaje bez zmiany. **`night_queue.py unhold`**: usuwa je i wypisuje
  `{"removed": true|false}` (kod 0 także bez wstrzymania). Pusty albo biały `--reason`: kod 2. Nieczytelny
  `parallel-hold.json`: kod 2 w `hold` i w każdej komendzie, która czyta stan bramki (`gate`, `next`, `propose`,
  `approve`); `unhold` go usuwa. `propose` podaje wstrzymanie w polu `hold` (`{at, reason}` albo `null`), a sesja mówi
  o nim przed pytaniem o listę nocy (noc idzie wtedy szeregowo).
- **`/ao-run` bez argumentu i po merge'u (sekcja 8 skilla):** sesja uruchamia `gate` zamiast `next_epic.py`.
  `epic` jest: push „Zacząć epik X?” albo, przy niepustym `parallel_with`, „Zacząć epik X równolegle z Y?”; start
  tylko po „tak” użytkownika. `epic` null z `reason`: push „Epik X czeka: <powód>”, bez pytania; X to `waiting`
  z wyniku bramki. `epic` null bez `reason` (brak kandydata): push z pierwszą pozycją `skipped` bramki, a gdy jej brak,
  z pierwszą pozycją `skipped` z `next_epic.py` (bramka nie widzi epików nieprzygotowanych).
- **Po starcie epiku i po jego `epic_done`** (sekcje 4 i 6 skilla) sesja w dzień też uruchamia `gate`: tak proponuje
  drugi epik, póki pierwszy trwa.
- **`/ao-run <epik>`** (sekcja 1 skilla): `gate --dir <epik> --reserve` przed startem. Bramka niespełniona: w dzień
  sesja podaje powód i pyta „Uruchomić mimo bramki?” (start tylko po „tak”, z `--force`), poza brakiem miejsca
  (krok 2), przy którym epik czeka bez pytania. O starcie decydują pola
  `reserved` i `resumed`, nie `reason` ani `skipped`.
- **Noc:**
  - Na komputerze trwa jedna noc naraz: `approve` (pod blokadą) odmawia, gdy `night.json` ma noc bez `ended_at`
    i przed `until`. Nieczytelny `night.json` blokuje `approve`, `close` i `next` (kod 2, bez zapisu), aż użytkownik
    go naprawi albo usunie.
  - `propose` stosuje regułę plików (krok 5), a epiki w toku nie opróżniają listy: liczą się jak epiki już na liście
    (pliki i zależności). Suma szacunków musi się zmieścić do `until` szeregowo, bo szeregowe wykonanie jest
    rozwiązaniem awaryjnym.
  - Każdy epik nocy, także pierwszy, rusza przez `next`. `next` bierze pierwszy epik listy bez zdarzenia `start` od
    zatwierdzenia i zwraca go z rezerwacją (`claim`):
    - (a) gdy nic nie jest w toku i nic nie ruszyło tej nocy (pierwszy epik po „tak”): bez dalszych warunków;
    - (b) gdy coś ruszyło tej nocy, wszystkie rozpoczęte epiki nocy mają `done`, a żaden epik w toku nie jest
      aktywny: po sprawdzeniach szeregowych (szacunek czasu do `until`, stan limitów `ok`, okna do 80%, jak w spec
      `/ao-run`, „Kolejny epik”);
    - (c) w pozostałych przypadkach, także gdy epik innej sesji jest aktywny: gdy przechodzi bramkę (kroki 2–7
      i wstrzymanie równoległości, rezerwa 2 × 6 GiB na epik) i teraz + szacunek czasu nie przekracza `until`
      (inaczej powód `X nie zdąży do HH:MM`).
    Odmowa w (c) (czekanie albo pominięcie w bramce, `nie zdąży`) przy czymkolwiek aktywnym to `epic: null`
    i `wait: true` z powodem: noc trwa, `next` niczego nie zapisuje. Bez aktywnego epiku ta sama odmowa kończy noc.
    Gdy wszystkie epiki listy ruszyły, a któryś jeszcze trwa, `next` czeka z powodem `wszystkie epiki listy ruszyły,
    w toku: …`, a po ostatnim `done` kończy noc z `lista wykonana`. Ważna rezerwacja kandydata przez inną sesję to
    czekanie z powodem `epik X zarezerwowany albo w toku`. Każde sprawdzenie bramką w (c) `next` dopisuje do
    `gate.jsonl` jak `gate --dir <kandydat>` (przed końcowym zapisem).
  - `next` sesja wywołuje po „tak” na listę, zaraz po `start` każdego epiku nocy (start równoległy), po `epic_done`
    i przy każdym ponownym sprawdzeniu. Sekcja 1 dla epiku nocy używa tokenu z `next`; niespełniony warunek w nocy to
    czekanie (jak `wait`), bez pytania i bez wymuszenia.
- **Ponowne sprawdzenie** czekającego kandydata (w dzień i w nocy), ze świeżym `get_usage`, przy: nowym zdarzeniu
  `done` w `measurements.jsonl` (także z innej sesji: Monitor na nowe linie `done`), końcu procesu AO tej sesji,
  merge'u albo zamknięciu końcowego PR (także cudzego: zmiana `origin/main` z pętli z sekcji 6), najbliższym `resetsAt`
  okna, które blokuje krok 7, i `expires_at` cudzej rezerwacji, która blokuje (jednorazowy budzik), od razu po
  `unhold`, a poza tym co 30 minut, póki kandydat czeka. Monitor uzbraja sesja, która ma czekającego kandydata, także
  bez własnego procesu AO. Push tylko przy zmianie powodu czekania, nie przy każdym sprawdzeniu.

### Dwa epiki w jednej sesji

- Każdy epik ma własny `AO_HOME`, proces `ao run` w tle, Monitor zdarzeń i pętlę na końcowy PR; komendy dostają
  blok startowy swojego epiku. Push ma etykietę epiku (`--epic-label`).
- Odpowiedź na bloker, gdy czekają blokery obu epików, musi wskazać epik (jak przy triage kilku przeglądów); bez
  tego sesja dopytuje.
- Kolejka przeglądów (`/sweep`, `/retro`) nie działa razem z żadnym procesem AO tej sesji ani z procedurą 6a,
  a nowy epik czeka na pustą kolejkę przeglądów. Przy dwóch epikach kolejka rusza po końcu obu procesów AO.
- `start` w `measurements.jsonl` ma pole `docker`: pamięć Dockera `{total_mib, ao_mib, other_mib}` (pomiar jak
  w kroku 6 bramki, bez `free_mib` i `required_mib`, które zależą od liczby epików aktywnych) albo `null` przy błędzie
  `docker`; błąd nie przerywa zapisu. Nakładanie się epików wynika z przedziałów `start`–`done`, bez osobnego pola;
  wynik `done` podaje je jako `overlap`: przedział epiku przecina zamknięty przedział innego epiku albo w przedziale
  (`start` ≤ chwila < `done`) zaczyna się `start` innego epiku bez późniejszego `done`. `start` bez `done` sprzed
  przedziału go nie oznacza, więc porzucony `start` nie psuje późniejszych szacunków. Linie bez `docker` są
  poprawne.

### Metryki par

Para to dwa epiki, których przedziały w `measurements.jsonl` się nakładają, a późniejszy `start` z tych dwóch ma pole
`docker` (zapis 19a, także `null`); epik bez `done` ma przedział otwarty (do teraz, `open: true`). Nakładania sprzed
19a nie są parami, a otwarty przedział, którego `start` nie ma pola `docker` (epik sprzed 19a bez zapisanego `done`),
jest pomijany w całości. Metryki zbieramy dla każdej pary, nie tylko dla dwóch pierwszych.

- **`pairs.py [--state DIR] [--setup-diag DIR]`** (skrypt w `.claude/skills/ao-run/`; JSON, kod 0; brak par: pusta
  lista): `pairs` w kolejności startu, dla każdej: oba epiki z czasem w minutach i `open`, część wspólna, rozpiętość
  (pierwszy `start` → ostatni `done`), suma czasów (wariant szeregowy), oszczędność `1 − rozpiętość/suma` (`null` przy
  parze otwartej), przyrost każdego okna limitu w rozpiętości (okno odnowione w trakcie: bez przyrostu), `docker` ze
  `start` drugiego epiku, liczba awarii stosu w rozpiętości (pliki `ao-*-<RRRRMMDD-GGMMSS>-*.log` w `--setup-diag`,
  domyślnie `~/.local/state/ao-setup`) i `record`: ostatni zapis werdyktu tej pary z `pairs.jsonl` (kolejność epików
  bez znaczenia) albo `null`. Dalej `claude_md_only`: epiki, które `gate.jsonl` pominął wyłącznie z powodu wspólnych
  plików `CLAUDE.md` (ID, pierwsze i ostatnie pominięcie, liczba; z linii sprzed obecnej reguły plików);
  `code_blocked`: epiki, które `gate.jsonl` pominął z powodu ścieżki blokującej według obecnej reguły (kod aplikacji;
  te same pola), czyli koszt konserwatywnej reguły dla kodu; oraz `summary` (niżej). Kod 2: nieczytelny plik stanu.
- **`pairs.py record <A> <B> [--state DIR]`** (`record` jako pierwszy argument; stdin: obiekt JSON werdyktu)
  odmawia, gdy katalogi `A` i `B` nie są parą raportu (w dowolnej kolejności), i dopisuje do `<state>/pairs.jsonl` linię
  `{at, epics: [A, B], …werdykt}` pod blokadą bramki i wypisuje ją (kod 0). Werdykt ma dokładnie pola:
  - `shared`: pliki zmienione przez oba epiki (`git diff --name-only <merge>^1 <merge>` dla commitu merge'u końcowego
    PR każdego epiku, część wspólna list) albo `null`, gdy któryś końcowy PR jest `CLOSED`;
  - `conflicts`: scalenia z konfliktem tekstowym w epikach pary (zadania REPAIR i PR `sync/<epik>-main`, których
    opis wymienia plik w konflikcie);
  - `auto_resolved`: ile z nich REPAIR albo sync rozwiązał bez ręcznej poprawki i bez odpowiedzi człowieka
    (najwyżej `conflicts`);
  - `doc_fixes`: niespójności dokumentów naprawione automatycznie (pozycje „poprawione” w liniach „Spójność
    dokumentów” opisów PR obu epików);
  - `interventions`: interwencje obu epików (`ao report`);
  - `regressions`: czerwone checki `checks` albo `e2e` na połączonym stanie (sekcja „Testy po scaleniu”): run CI na
    merge commicie każdego PR REPAIR i `sync/<epik>-main` (pierwszy run po scaleniu, przed poprawkami), run na
    commicie jego merge'u do gałęzi epiku i run na `main` na commicie merge'u końcowego PR;
  - `critical`: numery krytycznych problemów (niżej), pusta lista przy braku.
  Liczby to nieujemne całkowite. Brak lub nadmiar pola, zła wartość, `A` równe `B`, nieczytelny JSON albo zajęta
  blokada: kod 2 bez zapisu. Ponowny zapis tej samej pary jest poprawką: liczy się ostatni.
- **`summary`**: `admitted` (liczba par, czyli epików dopuszczonych obok innego), `recorded` (par z zapisem),
  sumy `conflicts`, `auto_resolved`, `needed_intervention` (`conflicts − auto_resolved`), `doc_fixes`,
  `interventions`, `regressions`, `critical_pairs` (par z niepustym `critical`) z zapisów par; oraz te same sumy
  osobno dla par, których `shared` zawiera `CLAUDE.md` (`claude_md`), i dla par, których `shared` zawiera kod
  aplikacji według reguły plików (`code`; kod spoza deklaracji, bo deklarowany wspólny kod blokuje start), każda
  z polem `pairs`.
- **Blok pary.** Gdy oba końcowe PR pary są `MERGED` albo `CLOSED`, sesja zbiera werdykt, pokazuje w rozmowie blok
  `Para równoległa`: epiki, czas zegarowy pary i szeregowo, oszczędność; konflikty (wystąpiło, rozwiązane
  automatycznie, wymagały człowieka) i niespójności dokumentów naprawione automatycznie; regresje; interwencje obu
  epików; wspólne pliki (liczba, w tym `CLAUDE.md` i kod aplikacji); zasoby (`docker` przy starcie drugiego, awarie
  stosu); koszt (USD z `ao report` obu epików i przyrost okien z `pairs.py`); werdykt „Krytyczny problem: brak” albo
  lista. Zapis `pairs.py record` nie wymaga zgody (stan lokalny); po zgodzie użytkownika sesja dopisuje blok do issue
  epiku zamkniętego później (komentarz z pomiarem z sekcji 7 skilla albo, przy `CLOSED`, osobny komentarz z samym
  blokiem).
- **Krytyczny problem** (definicja z planu, krok 19):
  1. CI workflowu `ci` na `main` czerwone dla commita merge'u końcowego PR któregoś epiku pary;
  2. konflikt między epikami pary, którego sync i REPAIR nie rozwiązały bez ręcznej poprawki (pozycja „Ręczne
     poprawki” z powodu konfliktu albo niespójności dokumentów wykrytej po merge'u);
  3. AO zatrzymało epik pary (`FAILED`, `epic_blocked`, `queue_held`, epik bez `DONE`) z powodu stosu, pamięci,
     portów albo limitu (`ao status --epic` w `~/.local/state/ao-<epik>`);
  4. epik pary ma więcej ręcznych poprawek (z komentarza pomiaru) albo interwencji (`ao report`) na zadanie (liczba
     zadań ze `start`) niż każdy z trzech ostatnich epików szeregowych z komentarzem pomiaru, liczonych tak samo.
     Zadania REPAIR i sync automatyczny się nie liczą: konflikt albo regresja naprawione automatycznie nie są
     problemem.
- Krytyczny problem to jedyny powód wstrzymania: push z problemem i `night_queue.py hold --reason "<para
  i problem>"`, więc epiki dalej ruszają równolegle dopiero po decyzji użytkownika o zmianie bramki (w rozmowie albo
  w PR do tej specyfikacji); `unhold` sesja uruchamia tylko po wyraźnej decyzji użytkownika w rozmowie. Konflikt,
  niespójność albo regresja rozwiązane automatycznie trafiają tylko do metryk.

### Ocena reguły plików

Po werdykcie każdej pary sesja pokazuje `summary` i `code_blocked` z `pairs.py`. Dane do decyzji użytkownika:

- **`CLAUDE.md` i inne wspólne pliki poza kodem** (dopuszczone): czy równoległe zmiany powodują problemy. Wiersz
  `claude_md` pokazuje, ile par zmieniało wspólny `CLAUDE.md` i ile z nich miało niespójności naprawione
  automatycznie, konflikty wymagające człowieka, regresje albo krytyczny problem; `claude_md_only` pokazuje, ile
  czekania kosztowała poprzednia reguła.
- **Kod aplikacji** (blokowany): korzyść ze złagodzenia to `code_blocked` (epiki, które czekały przez wspólny kod),
  ryzyko to wiersz `code` (pary, które zmieniły wspólny kod spoza deklaracji, i ich problemy).

Decyzja należy do użytkownika; zmiana reguły to PR do tej specyfikacji i `parallel_gate.py`.

### Ograniczenia

- Zakres to deklaracja. Epik, który zmieni plik spoza niej, może skonfliktować z drugim; naprawia to REPAIR albo
  sync spójności w parze (sekcja „Spójność dokumentów”).
- Reguła dla kodu aplikacji, sync spójności w parze i testy po scaleniu zmniejszają, ale nie wykluczają konfliktu
  znaczeniowego: testy łapią tylko to, co sprawdzają. Konfliktu znaczeniowego w konfiguracji, infrastrukturze albo CI
  bramka nie blokuje; łapią go testy syncu spójności w parze (lokalne i CI PR syncu), a gdy ich nie ma, CI na `main`
  (krytyczny problem 1).
- Epik zmergowany pierwszy w parze nie jest synchronizowany: jego CI przeszło bez zmian drugiego, które jeszcze nie
  były na `main`. Drugi epik przechodzi sync spójności w parze, więc połączony stan sprawdza CI PR syncu.
- Rezerwacja i blokada działają między sesjami na jednym komputerze. Epik uruchomiony poza `/ao-run` widać dopiero po
  wypchnięciu jego gałęzi `epic/*`.
- Rezerwa pamięci jest stała (6 GiB na stos, `max-parallel` stosów na epik, także gdy epik nie ma zadań do
  prowadzenia naraz); pamięć szczytowa nie jest mierzona. REPAIR i sync epiku po `done` nie
  mają rezerwy pamięci ani limitu: widać je tylko w bieżącym pomiarze `docker`.
- AO wybiera blok portów tylko spośród stosów własnego `AO_HOME`; dwa procesy AO mogą rzadko trafić w ten sam blok
  przed startem stosu. `.ao/setup` sprawdza porty i kończy się błędem, który AO ponawia; powtarzające się awarie
  stosu z tego powodu to krytyczny problem 3.
- Bramka nie widzi pamięci hosta poza maszyną Dockera.

## 19b: zadania fali naraz (kontrakt AO)

AO prowadzi naraz zadania jednej fali, które plan dopuścił do pracy równoległej. Implementacja w AO to osobna praca
w repozytorium AO (piotrp321/agentic-orchestrator#50); w `shop` są pola manifestu, reguła ich dopuszczenia, start AO
z `/ao-run` i rezerwa pamięci bramki 19a.

- **Manifest planu** (`/planner`, `plan_manifest.py`) ma dwa opcjonalne pola zadania:
  - `paths`: niepusta lista ścieżek względem katalogu głównego repo, które zadanie zmieni: plik albo katalog
    zakończony `/` (bez ścieżek absolutnych, segmentów `.` i `..`, znaków globa `*?[]{}`, `~` na początku
    i powtórzeń; pierwszy segment istnieje w katalogu głównym repo, z tą samą wielkością liter, bez `.git`). Plik,
    który zadanie na pewno zmieni (np. `spec.md` epiku, `CLAUDE.md`), `/planner` wpisuje jako plik; katalog tylko dla
    plików nieznanych z góry, a przy niepewności szerszy katalog.
  - `parallel_with`: ID zadań, z którymi zadanie może trwać naraz. `/planner` wpisuje tu wynik `plan_manifest.py
    parallel --blocked`: wszystkie pary dopuszczone przez regułę niżej. Wynik z `--blocked` to
    `{"parallel_with": {ID: [ID]}, "blocked": [...]}`; `blocked` (`{"tasks": [A, B], "paths": [...]}`)
    wymienia pary zadań jednej fali, które obie mają `paths`, a nie mogą iść naraz, bo reguła poniżej zwraca niepustą
    część wspólną (`paths`); kolejność: fala, potem plan; brak par to `[]`.
    Bez `--blocked` wynik to sama mapa `{ID: [ID]}`. `/planner` preferuje w jednej fali rozłączne pliki
    (`.claude/skills/planner/SKILL.md`).
  Zadanie bez `parallel_with` (także cały manifest bez tych pól, np. sprzed 19b) idzie szeregowo; `schema_version`
  zostaje 1.
- **Reguła pary** (`plan_manifest.py validate` odrzuca manifest, który ją łamie): relacja jest symetryczna (każde
  zadanie pary wymienia drugie), oba zadania mają `paths`, żadne nie zależy od drugiego bezpośrednio ani przez
  `depends_on` innych zadań, a część wspólna ich `paths` (jak w „Pojęciach” 19a) nie zawiera kodu wykonywanego
  (`parallel_gate.is_executable_code`), `CLAUDE.md` ani pliku. Kod wykonywany to kod aplikacji (`is_app_code`, jak
  w kroku 5 bramki) oraz ścieżki w katalogach `tools`, `.claude/skills`, `.ao`, `.github/scripts` i `infra/scripts`
  (po segmentach, bez rozróżniania wielkości liter) i ścieżki, które taki katalog zawierają (`.claude`, `.github`,
  `infra`; tak jak `apps` zawiera kod aplikacji); pliki generowane i rejestracji, `CLAUDE.md` i `README.md`
  kodem nie są.
  Bramka 19a (`is_app_code`, `is_allowed`) nie zmienia się. Ścieżka części wspólnej jest katalogiem
  tylko wtedy, gdy obie strony deklarują ją i każdą ścieżkę, która ją zawiera, jako katalog. Wyjątki kroku 5 zostają
  dla katalogów: dwa nakładające się katalogi poza kodem wykonywanym (workflowy CI, testy, konfiguracja,
  `infra/k8s`, dokumentacja, `.claude/agents`) nie blokują, chyba że wspólny katalog zawiera `CLAUDE.md` śledzony
  w repo (`git ls-files`, np.
  `infra/k8s/` albo `e2e/`); wtedy ten plik blokuje parę. Gdy git nie poda listy śledzonych `CLAUDE.md`, `validate`
  odrzuca każdą parę z `parallel_with`, a `parallel` kończy się kodem 2. Brak danych albo niepewność oznacza pracę
  szeregową. Regułę ma jedno miejsce: `parallel_gate.task_conflicts`.
- `ao run --max-parallel N` (domyślnie 1, bez zmiany zachowania). Przy N > 1 AO uruchamia drugą próbę tylko dla
  zadania typu `plan`, którego zależności są zielone, gdy kandydat i każde trwające zadanie mają się nawzajem na
  listach `parallel_with`. AO sprawdza w `parallel_with` tylko typ i znane ID; reguły plików nie powtarza. Każda próba
  ma własny worktree i stos (własny `AO_PORT_BASE`; blok portów sprawdzany względem stosów wszystkich prób tego
  procesu).
- Merge'e do `epic/<epik>` po kolei; PR zadania w konflikcie z gałęzią epiku po merge'u innego rozwiązuje istniejąca
  ścieżka `/delivery` („Konflikt z gałęzią docelową lub z `main`”). Integracja (`/integration`) po wszystkich
  zadaniach, jak dla zadań szeregowych.
- AO nie sprawdza pamięci Dockera przed kolejną próbą. Pamięć na `max-parallel` stosów rezerwuje bramka 19a: krok 6
  liczy tyle stosów na każdy epik aktywny i wymaga tylu wolnych dla kandydata. Cooldown limitu AO stosuje jak dla
  jednej próby.
- Awaria jednej próby (`FAILED`, `BLOCKED`, awaria stosu) nie zatrzymuje drugiej.
- `/ao-run` przekazuje `--max-parallel 2` w każdym starcie AO (sekcja 4 skilla); wymaga to AO z tą opcją w `AO_DIR`.
  Wartość równa się `MAX_PARALLEL` w `parallel_gate.py`, co sprawdza test.
- Pomiar każdego epiku po merge'u: `task_pairs.py` (niżej, „Pomiar zadań naraz”), blok „Zadania naraz (19b)”
  w komentarzu pomiaru (`/ao-run`, sekcja 7).

### Pomiar zadań naraz

`python3 .claude/skills/ao-run/task_pairs.py <epik> [--ao-home DIR] [--repo DIR] [--setup-diag DIR]` tylko raportuje:
niczego nie blokuje ani nie zapisuje. Bazę AO (`*.db` w `--ao-home`, domyślnie `~/.local/state/ao-<epik>`, pierwsza
z wierszem epiku w `epics`) otwiera tylko do odczytu (`sqlite3` z URI `mode=ro`). `git` i `gh` uruchamia w `--repo`
z limitem czasu 60 s. Wynik to jeden wiersz JSON, kod 0; brak bazy, baza nieczytelna albo bez epiku → kod 2. Błąd
`git`/`gh` przy odczycie PR daje `null` w tej pozycji, nie błąd. Plan nieczytelny przy `plan_sha` (błąd `git show`,
np. commit spoza `--repo`, albo brak bloku manifestu) też daje kod 0: `audit.declarations` to `{"value": null,
"note": "<powód>"}` (przy czytelnym planie `{"value": "<plan_sha>", "note": null}`), każde `declared`, `undeclared`
i `declared_conflicts` to `null`, a pozostałe metryki są liczone.

- `audit.tasks`, dla każdego zadania typu `plan` z PR: `declared` to `paths` zadania z manifestu przy `plan_sha` epiku
  w bazie AO (`git show <plan_sha>:<plan_path>`, deklaracja obowiązująca w trakcie wykonania, nie bieżący plan),
  inaczej `null` z `declared_note`: zadanie bez `paths`, zadania nie ma w manifeście przy `plan_sha` albo plan
  nieczytelny; `changed` to rzeczywisty diff PR: `git diff --name-only <merged_sha>^1 <merged_sha>`, gdy commit
  jest w historii (pierwszy rodzic to gałąź epiku przed merge'em, przy squashu i merge commicie), inaczej
  `gh pr view <nr> --json files`, a gdy oba zawiodą `null`; `source` to `squash` (1 rodzic), `merge_commit`
  (2 i więcej), `gh` albo `null`; `undeclared` to pliki `changed` poza każdą ścieżką `declared` (po segmentach, bez
  wielkości liter), `null` przy braku jednej ze stron. Przy zmianie nazwy pliku oba źródła podają tylko nową ścieżkę,
  a lista z `gh pr view --json files` może być obcięta przy bardzo dużym PR.
- `audit.pairs`: zadania, które w bazie AO mają się nawzajem w `parallel_with`: `declared_conflicts` to
  `parallel_gate.task_conflicts` na deklaracjach (ze śledzonymi `CLAUDE.md` przy `plan_sha`, `git ls-tree`),
  `actual_conflicts` to ta sama funkcja na `changed` obu stron (każdy plik jako plik); `null` bez danych.
- `concurrency`, z prób zadań typu `plan` (próba bez `ended_at`, `open: true`, trwa do teraz, a w epiku już
  zakończonym do końca epiku): `attempts_sum_s` (suma czasów prób), `busy_s` (czas z co najmniej jedną próbą),
  `concurrent_s` (zmierzony czas z co najmniej dwiema próbami naraz: rzeczywista współbieżność), `pairs` (pary zadań
  z nakładającymi się próbami i `overlap_s`; przy trzech próbach naraz suma `overlap_s` par przekracza
  `concurrent_s`), `theoretical_saving` = `(attempts_sum_s - busy_s) / attempts_sum_s` (dokładne przy każdej liczbie
  prób naraz): teoretyczna oszczędność przy założeniu, że szeregowo próby trwałyby tyle samo, nie przyspieszenie;
  `epic_wall_s` od `created_at` epiku do ostatniego `epic_done`, a bez niego do `updated_at` epiku `DONE` albo
  `BLOCKED` lub do teraz (`epic_open: true`); `attempts_to_wall` = `attempts_sum_s / epic_wall_s` to wskaźnik
  obciążenia (średnia liczba prób naraz w całym czasie epiku, także bez prób), nie przyspieszenie.
- `conflicts`: zdarzenia `fix_requested` z `reason: conflict` zadań epiku, na zadanie i razem; `fix_rounds`: wszystkie
  `fix_requested` według `reason`.
- `repairs`: liczba zadań `repair` i `fix` (QA) i ich prób.
- `interventions`: `human` jak `ao report` (w zadaniach `answer_recorded`, `status_changed` z `reason: resumed`,
  `hold_released` przez operatora, oraz `epic_resumed` epiku); `operational`: `config_changed` w oknie epiku
  i `state_file_edited` jego zadań.
- `waits`: `queue` dla zadań z niepustym `parallel_with`: od wejścia w `PENDING` (`status_changed`) do następnego
  `attempt_started` (`first` z `WAITING_DEPS`, `retry` w innych przypadkach; zadania jeszcze w kolejce w `waiting`),
  bez zgadywania przyczyny; `cooldowns` i `host_pauses`: liczba i suma sumy mnogościowej przedziałów (`cooldown_set`
  skrócony przez `cooldown_released`, jak w AO) w oknie epiku, bo AO zapisuje zdarzenie na próbę (`host_pause`) albo
  na zadanie (`cooldown_set`), więc równoległe próby zapisują tę samą przerwę kilka razy, a nakładające się
  przedziały liczą się raz; `usage_warnings`: liczba zdarzeń (na próbę, nie różnych ostrzeżeń); `resources`: zawsze
  `null` z notatką „AO nie zapisuje czekania na zasoby” (AO nie sprawdza Dockera); `setup_failures`: logi awarii
  stosu tego domu AO (`ao-<home>-…-RRRRMMDD-GGMMSS-….log`, `<home>` jak `lifecycle.home_id` bazy) w `--setup-diag`
  w oknie epiku; starsze logi bez przedrostka `ao-<home>-` nie są liczone; bez katalogu 0.
- Brak danych to `null`, nie 0. `run_started`, `config_changed`, `state_file_edited`, `cooldown_set`, `host_pause`
  i `usage_warning` AO zapisuje od tego samego wydania. Gdy w bazie nie ma `run_started` najpóźniej przy pierwszej
  próbie epiku (bez prób: przy jego końcu), interwencje operacyjne, `cooldowns`, `host_pauses` i `usage_warnings` są
  `null`; z takim zdarzeniem brak zdarzeń danego typu oznacza 0.

## Pliki

- `.claude/skills/ao-run/parallel_gate.py` i `test_parallel_gate.py`: blokada, zapis atomowy, rezerwacje z tokenem,
  pomiar pamięci Dockera i kod aplikacji blokuje (19a i 19b), kod narzędzi tylko w 19b (`is_executable_code`); bez
  zależności od `night_queue.py`.
- `.claude/skills/ao-run/night_queue.py` i `test_night_queue.py`: komendy `gate`, `release`, `hold` i `unhold`
  (algorytm bramki na tablicy, zakresach i szacunkach), blokada w komendach zapisujących, pole `docker` w `start`,
  szacunek zużycia bez pomiarów nakładających się, zmiany `propose`, `approve` i `next`.
- `.claude/skills/ao-run/pairs.py` i `test_pairs.py`: metryki par i zapis werdyktu (`record`).
- `.claude/skills/ao-run/task_pairs.py` i `test_task_pairs.py`: audyt `paths` i pomiar zadań naraz jednego epiku
  (19b), z bazy AO tylko do odczytu.
- `.claude/skills/ao-run/next_epic.py`: bez zmiany zachowania; wspólny odczyt tablicy zostaje w nim.
- `.claude/skills/ao-run/SKILL.md`: sekcje 1, 2, 4 (także `--max-parallel 2`), 5, 6, 6a, 7, 7a, 8, „Noc”
  i „Czego nie robisz”.
- `.claude/skills/planner/plan_manifest.py`, `test_plan_manifest.py` i `SKILL.md`: pola `paths` i `parallel_with`,
  reguła pary (z `parallel_gate.task_conflicts`) i komenda `parallel` (z `--blocked`: także pary zablokowane).
- `.claude/skills/delivery/SKILL.md`: kontrola spójności dokumentów, pliki generowane bez konfliktu, kontrola migracji
  i testy stanu zintegrowanego w sekcji „Konflikt z gałęzią docelową lub z `main`”.
- Dokumentacja: `docs/features/ao-run/spec.md` (warunki startu, przebieg, „Noc”, „Czego skill nie robi”: wskazują
  tę specyfikację), plan Agentic Software House (status kroku 19, rola „Praca równoległa”, punkt 3 procesu).

## Testy

`python3 -m unittest discover -s .claude/skills/ao-run -p 'test_*.py'` i `.github/scripts/script-tests.sh`,
z podstawionymi wynikami komend `gh`, `git` i `docker`, plikami stanu w katalogu tymczasowym i ustalonym „teraz”.

- `gate`: brak epików w toku (start szeregowy), dwa epiki aktywne, epik po `done` nie zajmuje miejsca, epik w toku
  bez pomiaru liczony jako aktywny, epik ze zmergowanym końcowym PR i otwartym issue (`merged_open`, nie w toku),
  zależność, brak `Zakres:` u kandydata i u epiku w toku, część wspólna z CI, `CLAUDE.md`, `Makefile`, `make/*.mk`,
  runbooka, testów, konfiguracji, infrastruktury, dokumentacji, plików generowanych i rejestracji (przechodzi),
  wspólny kod aplikacji, także przez deklarację szerszą (`apps`, `apps/<aplikacja>`) i katalog z plikiem rejestracji
  (pominięty), próby obejścia (ścieżka podobna do kodu po prefiksie znaków, plik generowany jako prefiks), `paths` z dwóch epików w toku, plik spoza zakresu w diffie epiku w toku, niższy kandydat
  przed wyższym, `--dir` (epik spoza tablicy, nieprzygotowany, w toku → `resumed`); pamięć: wystarczająca, za mała,
  rezerwa epiku bez stosu, błąd `docker`, nieczytelne wyjście `docker`; limit: brak pomiaru, stan inny niż `ok`, pełny
  szacunek epiku aktywnego przekracza 80%, okno odnawiane przed końcem, szacunek zużycia bez pomiarów nakładających się;
  pusty i niepoprawny JSON na stdin; błąd `gh` albo `git` → kod 1, nieczytelny plik pomiarów → kod 2; wpis
  w `gate.jsonl`; `waiting` przy każdym czekaniu (także z `--dir` i `--force`), `null` przy przejściu i samych
  pominięciach.
- Rezerwacja: `--reserve` zapisuje wpis z tokenem; druga sesja widzi rezerwację jako epik w toku i aktywny (miejsce,
  pliki, pamięć, limit); kandydat nie jest porównywany z własną rezerwacją; odnowienie tym samym tokenem bez kroków
  bramki (także gdy pliki, pamięć i limit by odmówiły) i z odmowami samego epiku, wygasła rezerwacja z tokenem
  sprawdzana jak nowa; ten sam epik
  z innym tokenem i z `--force` → odmowa; dwa procesy `gate --reserve` uruchomione naraz (prawdziwa blokada, osobne
  procesy): rezerwację dostaje dokładnie jeden; zajęta blokada dłużej niż limit → kod 2 bez zapisu; rezerwacja wygasła
  po 30 minutach; `start` z tokenem usuwa rezerwację; `release` z rezerwacją i bez; nieczytelny `claims.json` → kod 2;
  zapis atomowy (przerwany zapis nie psuje pliku); wznowienie: działający proces AO innej sesji (zajęta
  `orchestrator.lock`, także z `--force` i `--takeover`) → odmowa, właściciel z innym tokenem → odmowa, `--takeover`
  zmienia właściciela, ten sam token i epik bez właściciela → `resumed: true`; bez `--reserve` wznowienie tylko czyta
  (`claim: null`, bez zapisu właściciela, te same odmowy).
- Limit komend: wolna komenda (podstawiony proces dłuższy niż limit) w `gate` i `start` → kod 1 bez wyjścia, blokada
  bramki wolna; `night_queue.py` przekazuje limit 60 s; `next_epic.make_runner` bez limitu działa jak dotąd.
- `propose`: epik w toku nie opróżnia listy, reguła plików. `approve`: odmowa przy trwającej nocy, zgoda po
  `ended_at` i po `until`. `next`: pierwszy epik z rezerwacją, pierwszy epik przy epiku aktywnym innej sesji (bramka),
  start równoległy z rezerwacją, czekanie bez końca nocy, przypadki szeregowe bez zmian.
- Wstrzymanie równoległości: `gate` czeka przy epiku w toku (aktywnym, czekającym na merge, rezerwacji innej sesji,
  także z `--dir --reserve`), przechodzi bez epików w toku; `next` (c) czeka, a przypadek szeregowy (b) rusza; `hold`
  drugi raz zostawia pierwsze, pusty `--reason` → kod 2, `unhold` także bez pliku; nieczytelny plik → kod 2 (`unhold`
  go usuwa); zapis atomowy; zajęta blokada → kod 2 bez zapisu; `hold` w wyniku `propose`.
- Wymuszenie: `--force` rezerwuje mimo pamięci, wspólnych plików i wstrzymania, a przy dwóch epikach aktywnych (także
  ze wstrzymaniem) odmawia bez rezerwacji.
- `start`: pole `docker`, błąd `docker` nie przerywa zapisu, token.
- `pairs.py`: brak par, para, trzy epiki w łańcuchu, para otwarta, okno odnowione, awarie stosu w rozpiętości i poza
  nią, brak katalogu diagnostyki, linie sprzed 19a (nakładanie bez `docker` nie jest parą, zamknięty epik sprzed 19a
  z późniejszym `start` 19a jest, otwarty `start` sprzed 19a jest pomijany), `claude_md_only` z `gate.jsonl` (tylko
  `CLAUDE.md` tak, `CLAUDE.md` z inną ścieżką nie), `code_blocked` (ścieżka kodu tak, sama ścieżka dopuszczona nie),
  `record` przy parze (także w odwrotnej kolejności epików, ostatni zapis wygrywa), `summary` z wierszami `claude_md`
  i `code`, brak `gate.jsonl` i `pairs.jsonl`, nieczytelny plik → kod 2. `record`: poprawny zapis, brak i nadmiar pola,
  para spoza raportu, `record` nie jako pierwszy argument, liczba ujemna, ułamkowa albo logiczna, `auto_resolved` większe od `conflicts`, zły numer w `critical`, `shared` nie
  będące listą ścieżek ani `null`, `A` równe `B`, nieczytelny JSON, zajęta blokada → kod 2 bez zapisu.
- `task_pairs.py` (baza SQLite w katalogu tymczasowym, podstawiony `git`/`gh`): zadanie zgodne z deklaracją, plik
  spoza `paths`, porównanie po segmentach bez wielkości liter, zadanie bez `paths`, squash i merge commit z tym samym
  diffem `^1`, brak commitu w historii i brak `merged_sha` (`gh`), oba źródła zawodzą (`null`), deklaracja z `plan_sha`,
  nie z HEAD, tylko zadania `plan` z PR, para `parallel_with` z `actual_conflicts` mimo pustych `declared_conflicts`,
  śledzony `CLAUDE.md` przy `plan_sha` w `declared_conflicts`, `null` w parze bez danych, nieczytelny plan
  i manifest bez bloku (kod 0, `declarations` z powodem, metryki liczone),
  zadanie spoza manifestu odróżnione od zadania bez `paths`;
  współbieżność: nakładanie 30 min (`concurrent_s` 1800), trzy próby z jedną parą, próby rozłączne, trzy próby
  naraz (oszczędność z `busy_s`), próba otwarta (także w epiku zakończonym: do końca epiku),
  tylko próby `plan`, brak prób, czas epiku z `epic_done`, do teraz i do `updated_at`; konflikty i rundy z
  `fix_requested`, naprawy, interwencje (także `null` operacyjnych bez `run_started`); `queue`, `resources` zawsze
  `null` z notatką, `null` przy braku `run_started` przed pierwszą próbą, 0 przy zapisującym AO, przedziały cooldown
  i przerw w oknie epiku (te same przerwy z równoległych prób liczone raz), awarie stosu tego domu w oknie i brak
  katalogu; brak bazy, nieczytelna baza, epik spoza bazy (kod 2), baza bez zmian i bez zapisu, limit czasu komend.
- Testy skryptów `/delivery` w `.github/scripts/script-tests.sh` (jeśli sprawdzają treść sekcji) dalej przechodzą.
- Rezerwa `max-parallel`: wolna pamięć na jeden stos, ale nie na dwa → czekanie; na dwa → przejście; rezerwa
  epiku aktywnego bez stosu i zmierzona pamięć AO powyżej rezerwy; `MAX_PARALLEL` równe 2 i każde polecenie startu
  AO w `SKILL.md` `/ao-run` z `--max-parallel` o tej wartości; `task_conflicts` (kod aplikacji, także przez szerszy
  katalog, kod narzędzi: `tools/` z `tools/` i z `tools/metrics/`, `.claude/` z `.claude/skills/x/`, `.ao/`,
  `.github/scripts/`, `infra/` z `infra/scripts/` odrzucone, a `.claude/skills/ao-run/` z `.claude/skills/retro/`,
  `.github/workflows/`, `.claude/agents/`, `docs/` i `infra/k8s/` dozwolone, `toolsx/` z `tools/` dozwolone,
  `Tools/metrics/` z `tools/metrics/` odrzucone, `tools/README.md` nie jest kodem wykonywanym, `is_app_code` i
  `is_allowed` bez zmian dla `tools/`; `CLAUDE.md`, ten sam plik, plik w katalogu drugiej strony, pliki generowane
  i rejestracji, nakładające się katalogi poza kodem dozwolone, wspólny katalog ze śledzonym `CLAUDE.md`
  w obu kierunkach odrzucony, bez niego dozwolony).
- Obecny plan METRICS (`docs/features/metrics/plan.md`) przechodzi `validate` z nową regułą pary.
- `python3 -m unittest discover -s .claude/skills/planner -p 'test_*.py'`: manifest bez nowych pól poprawny; zły
  typ `paths` i `parallel_with`, ścieżka absolutna, `..`, `.`, pusty segment, znak sterujący, znak globa, `~` na
  początku, pierwszy segment spoza katalogu głównego (także inna wielkość liter i `.git`), powtórzenie; nieznane ID,
  samo siebie, powtórzenie w `parallel_with`; brak symetrii; para bez `paths` po jednej stronie; para z zależnością
  bezpośrednią i przechodnią; wspólny kod aplikacji, ten sam plik dokumentacji, wspólny `CLAUDE.md`, wspólny katalog
  ze śledzonym `CLAUDE.md` (odrzucone), nakładające się katalogi dokumentacji, testów, CI i infrastruktury, także
  z nieśledzonym `CLAUDE.md` (dozwolone); błąd `git ls-files` (każda para odrzucona, `parallel` z kodem 2, manifest
  bez par poprawny); `parallel --blocked`: wspólny plik jednej fali i wspólny kod aplikacji w `blocked`, para
  dozwolona poza `blocked` i w `parallel_with`, zadania z różnych fal i zadanie bez `paths` poza `blocked`, kolejność
  fala-plan, `[]` bez par, wynik z flagą w osobnych kluczach `parallel_with` i `blocked`, wynik bez flagi jako sama
  mapa; `parallel`: lista maksymalna i symetryczna, zadanie bez `paths` z `[]`, wynik wpisany do planu przechodzi
  `validate`, obecne `parallel_with` bez wpływu, odmowa przy innym błędzie manifestu, brak pliku i zły JSON (kod 2).
