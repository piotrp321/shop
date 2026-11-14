# Praca równoległa: epiki naraz (19a) i zadania fali naraz (19b)

Krok 19 planu [Agentic Software House](../agentic-software-house/plan.md), komponent „Pula środowisk” z
[ADR](../../adr/agentic-software-house.md). Równoległość jest domyślna: kolejny przygotowany epik rusza naraz
z trwającym, gdy przejdzie bramkę ryzyka; wykonanie szeregowe zostaje rozwiązaniem awaryjnym, gdy bramka wykryje
realne ryzyko. 19a działa w `shop` (skille `/ao-run` i `/delivery`, bez zmian w AO). 19b to zmiana w AO
(piotrp321/agentic-orchestrator#50); tu jest tylko jej kontrakt i warunek startu.

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
6. **Pamięć Dockera**: `wolna = MemTotal − pamięć kontenerów spoza AO − max(pamięć kontenerów AO, rezerwa × liczba
   epików aktywnych)` jest co najmniej rezerwą jednego stosu. Rezerwa: 6 GiB (`--stack-gib`, pomiar: stos ok. 4,9 GB).
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
- **Sync spójności w parze.** Para: epik P zmergowany pierwszy i epik Q z otwartym końcowym PR. Sesja Q uruchamia
  procedurę `sync/<epik>-main` (sekcja 6a skilla) dla Q, gdy jednocześnie:
  - Q ma `epic_done` (wcześniej zmiany `main` wprowadza AO: REPAIR przy konflikcie końcowego PR);
  - commit merge'u P nie jest przodkiem `origin/epic/<Q>` (`git merge-base --is-ancestor`), więc sync wykonany raz się
    nie powtarza, także po rundzie `DELTA` i ponownym `epic_done`;
  - faktyczne zmiany obu stron mają wspólny plik (dowolny, także spoza deklaracji):
    `git diff --name-only <merge-base>..origin/main` i `git diff --name-only origin/main...origin/epic/<Q>`.
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
(REPAIR, sync spójności, tryb poprawki) kończy się testami na połączonym stanie:

- lokalnie, przed wypchnięciem merge commitu (`/delivery`, „Konflikt z gałęzią docelową lub z `main`”): `make check`
  i testy każdej aplikacji, która ma plik zmieniony po obu stronach od merge-base, nie tylko pliki w konflikcie;
- w CI: PR REPAIR i `sync/<epik>-main` trafia do `epic/<epik>` dopiero po zielonych wymaganych checkach `checks`
  i `e2e` (ruleset `epic-merge`), potem CI biegnie jeszcze raz na HEAD gałęzi epiku, a końcowy PR do `main` wymaga
  tych samych checków.

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
      i wstrzymanie równoległości, rezerwa 6 GiB) i teraz + szacunek czasu nie przekracza `until` (inaczej powód
      `X nie zdąży do HH:MM`).
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
- **`pairs.py record <A> <B> [--state DIR]`** (stdin: obiekt JSON werdyktu) dopisuje do `<state>/pairs.jsonl` linię
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
  - `regressions`: czerwone checki `checks` albo `e2e` na połączonym stanie (sekcja „Testy po scaleniu”): runy PR
    REPAIR i `sync/<epik>-main`, HEAD gałęzi epiku po ich merge'u i `main` po merge'u końcowego PR;
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
- Push „Krok 19b może ruszyć: dwie pary bez krytycznego problemu” sesja wysyła po werdykcie drugiej pary, gdy obie
  pierwsze pary są zamknięte (żaden epik nie ma `open`) i żadna nie ma krytycznego problemu.

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
  sync (sekcja „Spójność dokumentów”, warunek z faktycznych zmian).
- CI końcowego PR drugiego epiku przeszło bez pierwszego. Reguła dla kodu aplikacji, sync spójności i testy po
  scaleniu zmniejszają, ale nie wykluczają konfliktu znaczeniowego; konfliktu znaczeniowego w konfiguracji,
  infrastrukturze albo CI bramka nie blokuje, łapią go dopiero testy po scaleniu.
- Rezerwacja i blokada działają między sesjami na jednym komputerze. Epik uruchomiony poza `/ao-run` widać dopiero po
  wypchnięciu jego gałęzi `epic/*`.
- Rezerwa pamięci jest stała (6 GiB na stos); pamięć szczytowa nie jest mierzona. REPAIR i sync epiku po `done` nie
  mają rezerwy pamięci ani limitu: widać je tylko w bieżącym pomiarze `docker`.
- AO wybiera blok portów tylko spośród stosów własnego `AO_HOME`; dwa procesy AO mogą rzadko trafić w ten sam blok
  przed startem stosu. `.ao/setup` sprawdza porty i kończy się błędem, który AO ponawia; powtarzające się awarie
  stosu z tego powodu to krytyczny problem 3.
- Bramka nie widzi pamięci hosta poza maszyną Dockera.

## 19b: zadania fali naraz (kontrakt AO)

Implementacja to osobna praca w repozytorium AO (piotrp321/agentic-orchestrator#50). Kontrakt:

- `ao run --max-parallel N` (domyślnie 1, bez zmiany dzisiejszego zachowania). Przy N > 1 AO uruchamia naraz do N
  prób zadań jednej fali z zielonymi zależnościami i rozłącznym `scope` w manifeście, każdą we własnym worktree
  i stosie (własny `AO_PORT_BASE`; blok portów sprawdzany względem stosów wszystkich prób tego procesu).
- Merge'e do `epic/<epik>` po kolei; PR zadania w konflikcie z gałęzią epiku po merge'u innego rozwiązuje istniejąca
  ścieżka `/delivery` („Konflikt z gałęzią docelową lub z `main`”). Integracja (`/integration`) po wszystkich
  zadaniach, jak dziś.
- Przed każdą kolejną próbą AO sprawdza tylko, czy wolna pamięć Dockera mieści jeden stos (rezerwa jak w kroku 6
  bramki), i cooldown limitu, jak dziś dla jednej próby. Rezerwacje innych epików zna tylko bramka 19a.
- Awaria jednej próby (`FAILED`, `BLOCKED`, awaria stosu) nie zatrzymuje drugiej.
- Po wydaniu AO z tą opcją `/ao-run` przekazuje `--max-parallel 2` (osobny, mały PR w `shop`), a bramka 19a rezerwuje
  `max-parallel` stosów na każdy epik aktywny i wymaga tylu wolnych dla kandydata.
- Pomiar pierwszych dwóch epików z `--max-parallel 2`: czas zegarowy epiku wobec sumy czasów prób (`ao report`)
  i wobec epików szeregowych o podobnej liczbie zadań; konflikty zadań, interwencje, awarie stosu i koszt jak w bloku
  pary.

Warunek startu: dwie pierwsze pary epików 19a zamknięte i bez krytycznego problemu (sekcja „Pomiar pierwszych dwóch
par”). Wtedy 19b rusza bez kolejnej akceptacji użytkownika.

## Pliki

- `.claude/skills/ao-run/parallel_gate.py` i `test_parallel_gate.py`: blokada, zapis atomowy, rezerwacje z tokenem,
  pomiar pamięci Dockera i klasyfikacja plików (kod aplikacji blokuje, reszta nie); bez zależności od `night_queue.py`.
- `.claude/skills/ao-run/night_queue.py` i `test_night_queue.py`: komendy `gate`, `release`, `hold` i `unhold`
  (algorytm bramki na tablicy, zakresach i szacunkach), blokada w komendach zapisujących, pole `docker` w `start`,
  szacunek zużycia bez pomiarów nakładających się, zmiany `propose`, `approve` i `next`.
- `.claude/skills/ao-run/pairs.py` i `test_pairs.py`: metryki par i zapis werdyktu (`record`).
- `.claude/skills/ao-run/next_epic.py`: bez zmiany zachowania; wspólny odczyt tablicy zostaje w nim.
- `.claude/skills/ao-run/SKILL.md`: sekcje 1, 2, 4, 5, 6, 6a, 7, 7a, 8, „Noc” i „Czego nie robisz”.
- `.claude/skills/delivery/SKILL.md`: kontrola spójności dokumentów, pliki generowane bez konfliktu i testy
  aplikacji z plikami zmienionymi po obu stronach w sekcji „Konflikt z gałęzią docelową lub z `main`”.
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
  liczba ujemna, ułamkowa albo logiczna, `auto_resolved` większe od `conflicts`, zły numer w `critical`, `shared` nie
  będące listą ścieżek ani `null`, `A` równe `B`, nieczytelny JSON, zajęta blokada → kod 2 bez zapisu.
- Testy skryptów `/delivery` w `.github/scripts/script-tests.sh` (jeśli sprawdzają treść sekcji) dalej przechodzą.
