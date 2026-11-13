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
  `gate.jsonl`, `gate.lock`), jeden dla wszystkich sesji `/ao-run` na tym komputerze.
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

1. **Brak epików w toku**: kandydat przechodzi bez dalszych warunków (dzisiejszy start szeregowy).
2. **Miejsce**: najwyżej jeden epik aktywny (razem z kandydatem najwyżej dwa). Inaczej: czekanie.
3. **Zależności**: sekcja `## Zależności` issue kandydata nie wymienia ID otwartego epiku (reguła listy nocy).
   Inaczej: kandydat pominięty.
4. **Zakres**: kandydat i każdy epik w toku mają czytelną linię `Zakres:`. Brak u kandydata: kandydat pominięty;
   brak u epiku w toku: czekanie (nie wiadomo, co zmienia).
5. **Pliki**: każda ścieżka części wspólnej kandydata z każdym epikiem w toku jest plikiem dopuszczalnym. Inaczej:
   kandydat pominięty z powodem „wspólne pliki z X: <ścieżki>” i polem `paths` ze ścieżkami blokującymi ze wszystkich
   epików w toku (nie tylko z pierwszego).
   - **Dopuszczalne:** pliki generowane `apps/api/openapi.json`, `apps/api/openapi-admin.json`,
     `apps/store/src/api/schema.d.ts`, `apps/admin/src/api/schema.d.ts` (sync i REPAIR generują je od nowa) oraz
     dokumentacja `docs/**` i `README.md` w katalogu głównym (sync i REPAIR sprawdzają spójność treści, sekcja
     „Spójność dokumentów”).
   - **Wymuszają kolejkę:** wspólne pliki edytowane ręcznie: CI (`.github/**`), każdy `CLAUDE.md`, `make/*.mk`
     i główny `Makefile`, menu panelu (`apps/admin/src/layout/AppMenu.vue`, `apps/admin/src/router/index.ts`), inne
     `README.md` (np. runbook `infra/k8s/README.md`) oraz każda inna wspólna ścieżka kodu, testów i konfiguracji
     (rozłączność jak w liście nocy). Czy dopuścić `CLAUDE.md`, rozstrzyga użytkownik po dwóch pierwszych parach
     (sekcja „Ocena `CLAUDE.md`”).
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

„Pominięty” oznacza, że bramka sprawdza następnego kandydata z tablicy (niższy epik może ruszyć przed wyższym, jak
w liście nocy). „Czekanie” dotyczy każdego kandydata, więc bramka kończy szukanie: kandydat ruszy później,
równolegle albo szeregowo po zakończeniu epiku aktywnego.

### Rezerwacja (dwie sesje naraz)

Sam odczyt stanu przed startem nie wystarcza: dwie sesje mogłyby jednocześnie zobaczyć wolne miejsce i obie ruszyć.
Dlatego decyzja o starcie i jej zapis są jedną operacją pod blokadą.

- **Blokada**: `fcntl.flock` na `gate.lock`. Trzymają ją wszystkie komendy, które czytają stan do decyzji albo
  zapisują stan wspólny: `gate --reserve`, `release`, `start`, `done`, `approve`, `close` i `next`, przez cały odczyt,
  sprawdzenie i zapis. Czekanie na blokadę najwyżej 120 s; potem kod 2 z powodem „blokada bramki zajęta”, bez zapisu.
  `claims.json` i `night.json` są zapisywane przez plik tymczasowy i `os.replace`, więc przerwany zapis nie zostawia
  nieczytelnego pliku.
- **Rezerwacja**: wpis w `claims.json` (`dir`, `claim` (losowy token), `at`, `expires_at`), ważny 30 minut. Bramka
  liczy epik z ważną rezerwacją jako epik w toku i aktywny (pliki z `Zakres:` jego `spec.md` na `origin/main`, rezerwa
  pamięci, pełny szacunek limitu). Wygasłe wpisy są pomijane i usuwane przy następnym zapisie.
- **Token**: `gate --reserve` zwraca `claim`. `gate --dir <epik> --reserve --claim <token>` odnawia własną rezerwację
  (nowe `expires_at`), a `start` i `release` przyjmują token. Ważna rezerwacja tego samego epiku z innym tokenem albo
  epik już w toku to odmowa „epik X zarezerwowany albo w toku”, której `--force` nie omija: dwie sesje nie ruszą
  jednego epiku.
- **Przebieg startu** (sekcja 1 skilla): `gate --dir <epik> --reserve` (albo z tokenem z `next`) przed
  `ao epic start --dry-run`; tuż przed właściwym `ao epic start` odnowienie tym samym tokenem (wygasła albo przejęta
  rezerwacja: brak startu). `night_queue.py start <epik> --claim <token>` (zaraz po udanym `ao epic start`) zapisuje
  zdarzenie `start` i usuwa rezerwację w tej samej blokadzie. `ao epic start` od razu wypycha `epic/<epik>`, więc po
  usunięciu rezerwacji epik jest w toku z gałęzi. Odmowa w sekcjach 1–2 po rezerwacji: `release <epik> --claim
  <token>`. Rezerwacja niezwolniona (np. przerwana sesja) wygasa sama.
- **Pytanie a rezerwacja**: `gate` bez `--reserve` tylko czyta (do pytania „Zacząć epik X?”), więc nie blokuje innych
  sesji na czas czekania na odpowiedź. „Tak” jest ważne tylko w chwili rezerwacji: gdy bramka już nie przechodzi,
  sesja podaje powód, a po późniejszym przejściu bramki pyta od nowa z aktualnym `parallel_with`.
- **Wymuszenie**: w dzień `gate --dir <epik> --reserve --force` zakłada rezerwację mimo niespełnionej bramki (wynik
  bramki zostaje w wyjściu), tylko po „tak” użytkownika na „Uruchomić mimo bramki?”. W nocy nie ma wymuszenia.
- **Wznowienie**: `gate --dir` dla epiku, który jest już w toku z gałęzi (np. nowa sesja przejmuje epik po przerwanej),
  zwraca `resumed: true` bez sprawdzeń i bez rezerwacji: epik już zajmuje swoje miejsce.

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
  - faktyczne zmiany obu stron mają wspólny plik dopuszczalny (dokument albo plik generowany):
    `git diff --name-only <merge-base>..origin/main` i `git diff --name-only origin/main...origin/epic/<Q>`. Wspólny
    plik niedopuszczalny (spoza deklaracji): sync też, bo to ryzyko konfliktu znaczeniowego.
  Gdy końcowy PR Q ma konflikt, a AO nie działa, ta sama procedura rozwiązuje konflikt. Zgodą na procedurę dla par
  (także przy konflikcie) jest akceptacja tej specyfikacji (`CLAUDE.md`: PR `sync/<epik>-main` merguje skill po zgodzie
  użytkownika na procedurę i zielonym CI). Końcowy PR Q `MERGED` albo `CLOSED` w trakcie syncu: sesja przerywa,
  sprząta (krok 6 sekcji 6a) i zapisuje to w bloku pary. Do końca syncu raport odbioru Q ma rekomendację „najpierw
  sprawdź: sync spójności w toku”. Sync spójności nie jest ręczną poprawką w pomiarze (sekcja 7 skilla); liczy się
  w bloku pary jako „sync automatyczny”.
- **Wykrycie merge'u P** (także z innej sesji): pętla w tle z sekcji 6 skilla, która co 5 minut sprawdza stan
  końcowego PR Q, kończy się też przy zmianie `origin/main` (`git ls-remote origin main`); sesja sprawdza wtedy
  warunki syncu i uruchamia pętlę ponownie.

### Gdzie działa

- **`night_queue.py gate [--dir <katalog>] [--reserve [--claim TOKEN] [--force]] [--stack-gib N]`** (stdin: obiekt
  `plan` z `get_usage`): bez `--dir` szuka pierwszego kandydata z tablicy, który przechodzi bramkę; z `--dir`
  sprawdza ten epik (epik spoza tablicy albo nieprzygotowany: odmowa z powodem). Wynik (JSON, kod 0): `epic` (`dir`,
  `id`, `issue`, `title`, `tasks`, `minutes`) albo `null`, `reason` (powód czekania albo odmowy, inaczej `null`),
  `reserved`, `claim` (token albo `null`), `resumed`, `parallel_with` (katalogi epików w toku, z którymi epik ruszy),
  `active`, `in_progress`, `merged_open`, `skipped` (`issue`, `id`, `reason`, `paths`), `docker` (`total_mib`,
  `other_mib`, `ao_mib`, `free_mib`, `required_mib` albo `null`). Każde wywołanie dopisuje wynik do `gate.jsonl`
  (czas, kandydaci z wynikiem i `paths`, bez treści issues). Kod 1: błąd `gh` albo `git`; kod 2: błąd stanu
  (nieczytelny plik pomiarów albo rezerwacji, zajęta blokada).
- **`night_queue.py release <katalog> --claim TOKEN`**: usuwa własną rezerwację; brak jej: kod 0 bez zmiany.
- **`/ao-run` bez argumentu i po merge'u (sekcja 8 skilla):** sesja uruchamia `gate` zamiast `next_epic.py`.
  `epic` jest: push „Zacząć epik X?” albo, przy niepustym `parallel_with`, „Zacząć epik X równolegle z Y?”; start
  dalej tylko po „tak” użytkownika. `epic` null z `reason`: push „Epik X czeka: <powód>”, bez pytania.
- **Po starcie epiku i po jego `epic_done`** (sekcje 4 i 6 skilla) sesja w dzień też uruchamia `gate`: tak proponuje
  drugi epik, póki pierwszy trwa.
- **`/ao-run <epik>`** (sekcja 1 skilla): `gate --dir <epik> --reserve` zastępuje dzisiejszy opis ryzyka „Inne epiki
  w toku”. Bramka niespełniona: w dzień sesja podaje powód i pyta „Uruchomić mimo bramki?” (start tylko po „tak”,
  z `--force`).
- **Noc:**
  - Na komputerze trwa jedna noc naraz: `approve` (pod blokadą) odmawia, gdy `night.json` ma noc bez `ended_at`
    i przed `until`.
  - `propose` stosuje regułę plików (krok 5) zamiast samej rozłączności, a epiki w toku nie opróżniają już listy:
    liczą się jak epiki już na liście (pliki i zależności). Suma szacunków dalej musi się zmieścić do `until`
    szeregowo, bo szeregowe wykonanie jest rozwiązaniem awaryjnym.
  - Każdy epik nocy, także pierwszy, rusza przez `next`. `next` zwraca kolejny epik listy z rezerwacją (`claim`), gdy
    nic nie jest w toku (pierwszy epik po „tak”: bez dalszych warunków), gdy wszystkie rozpoczęte epiki nocy mają
    `done` (dzisiejsze warunki) albo gdy epik przechodzi bramkę (kroki 2–7) i warunek `until`. Gdy żaden przypadek
    nie zachodzi, a coś jest aktywne, `next` zwraca `epic: null` i `wait: true` z powodem i nie kończy nocy.
  - `next` sesja wywołuje po „tak” na listę, zaraz po `start` każdego epiku nocy (start równoległy), po `epic_done`
    i przy każdym ponownym sprawdzeniu. Sekcja 1 dla epiku nocy używa tokenu z `next`; niespełniony warunek w nocy to
    czekanie (jak `wait`), bez pytania i bez wymuszenia.
- **Ponowne sprawdzenie** czekającego kandydata (w dzień i w nocy), ze świeżym `get_usage`, przy: nowym zdarzeniu
  `done` w `measurements.jsonl` (także z innej sesji: Monitor na nowe linie `done`), końcu procesu AO tej sesji,
  merge'u albo zamknięciu końcowego PR (także cudzego: zmiana `origin/main` z pętli z sekcji 6), najbliższym `resetsAt`
  okna, które blokuje krok 7, i `expires_at` cudzej rezerwacji, która blokuje (jednorazowy budzik), a poza tym co
  30 minut, póki kandydat czeka. Monitor uzbraja sesja, która ma czekającego kandydata, także bez własnego procesu AO.
  Push tylko przy zmianie powodu czekania, nie przy każdym sprawdzeniu.

### Dwa epiki w jednej sesji

- Każdy epik ma własny `AO_HOME`, proces `ao run` w tle, Monitor zdarzeń i pętlę na końcowy PR; komendy dostają
  blok startowy swojego epiku. Push ma etykietę epiku (już dziś z `--epic-label`).
- Odpowiedź na bloker, gdy czekają blokery obu epików, musi wskazać epik (jak przy triage kilku przeglądów); bez
  tego sesja dopytuje.
- Kolejka przeglądów (`/sweep`, `/retro`) dalej nie działa razem z żadnym procesem AO tej sesji ani z procedurą 6a,
  a nowy epik dalej czeka na pustą kolejkę przeglądów. Przy dwóch epikach kolejka rusza po końcu obu procesów AO.
- `start` w `measurements.jsonl` dostaje pole `docker` (stan pamięci jak w `gate` albo `null` przy błędzie `docker`;
  błąd nie przerywa zapisu). Nakładanie się epików wynika z przedziałów `start`–`done`, bez osobnego pola. Stare linie
  bez `docker` są dalej poprawne.

### Pomiar pierwszych dwóch par

Para to dwa epiki, których przedziały w `measurements.jsonl` się nakładają; epik bez `done` ma przedział otwarty (do
teraz, `open: true`).

- **`pairs.py [--state DIR] [--setup-diag DIR]`** (osobny skrypt w `.claude/skills/ao-run/`, do usunięcia po decyzji
  o 19b i `CLAUDE.md`; JSON, kod 0; brak par: pusta lista): `pairs` w kolejności startu, dla każdej: oba epiki
  z czasem w minutach i `open`, część wspólna, rozpiętość (pierwszy `start` → ostatni `done`), suma czasów (wariant
  szeregowy), oszczędność `1 − rozpiętość/suma` (`null` przy parze otwartej), przyrost każdego okna limitu
  w rozpiętości (okno odnowione w trakcie: bez przyrostu), `docker` ze `start` drugiego epiku i liczba awarii stosu
  w rozpiętości (pliki `ao-*-<RRRRMMDD-GGMMSS>-*.log` w `--setup-diag`, domyślnie `~/.local/state/ao-setup`); oraz
  `claude_md_only`: epiki, które `gate.jsonl` pominął wyłącznie z powodu wspólnych plików `CLAUDE.md` (ID, pierwsze
  i ostatnie pominięcie, liczba). Kod 2: nieczytelny plik stanu.
- **Blok pary.** Gdy oba końcowe PR pary są `MERGED` albo `CLOSED`, sesja pokazuje w rozmowie i po zgodzie
  użytkownika dopisuje do issue epiku zamkniętego później (komentarz z pomiarem z sekcji 7 skilla albo, przy `CLOSED`,
  osobny komentarz z samym blokiem) blok `Para równoległa`: epiki, czas zegarowy pary i szeregowo, oszczędność;
  konflikty (zadania REPAIR obu epików z `ao report`, sync spójności i inne PR `sync/<epik>-main`) i niespójności
  dokumentów naprawione w nich („Spójność dokumentów” z opisów PR); interwencje obu epików; zasoby (`docker` przy
  starcie drugiego, awarie stosu); koszt (USD z `ao report` obu epików i przyrost okien z `pairs.py`); werdykt
  „Krytyczny problem: brak” albo lista.
- **Krytyczny problem** (definicja z planu, krok 19):
  1. CI workflowu `ci` na `main` czerwone dla commita merge'u końcowego PR któregoś epiku pary;
  2. konflikt między epikami pary, którego sync i REPAIR nie rozwiązały bez ręcznej poprawki (pozycja „Ręczne
     poprawki” z powodu konfliktu albo niespójności dokumentów wykrytej po merge'u);
  3. AO zatrzymało epik pary (`FAILED`, `epic_blocked`, `queue_held`, epik bez `DONE`) z powodu stosu, pamięci,
     portów albo limitu (`ao status --epic` w `~/.local/state/ao-<epik>`);
  4. epik pary ma więcej napraw (zadania REPAIR z `ao report` i ręczne poprawki z komentarza pomiaru) albo interwencji
     (`ao report`) na zadanie (liczba zadań ze `start`) niż każdy z trzech ostatnich epików szeregowych z komentarzem
     pomiaru, liczonych tak samo.
- Push „Krok 19b może ruszyć: dwie pary bez krytycznego problemu” sesja wysyła po werdykcie drugiej pary, gdy obie
  pierwsze pary są zamknięte (żaden epik nie ma `open`) i żadna nie ma krytycznego problemu. Krytyczny problem: push
  z problemem, a epiki dalej ruszają równolegle dopiero po decyzji użytkownika o zmianie bramki (w rozmowie albo
  w PR do tej specyfikacji).

### Ocena `CLAUDE.md`

Po werdykcie drugiej pary sesja przedstawia użytkownikowi dane do decyzji, czy dopuścić wspólne `CLAUDE.md`
w kroku 5: korzyść (`claude_md_only` z `pairs.py`: epiki, które czekały tylko przez `CLAUDE.md`) i ryzyko
(niespójności dokumentów znalezione w sync i REPAIR obu par oraz ewentualne krytyczne problemy). Decyzja należy do
użytkownika; zmiana reguły to PR do tej specyfikacji i `parallel_gate.py`.

### Ograniczenia

- Zakres to deklaracja. Epik, który zmieni plik spoza niej, może skonfliktować z drugim; naprawia to REPAIR albo
  sync (sekcja „Spójność dokumentów”, warunek z faktycznych zmian).
- CI końcowego PR drugiego epiku przeszło bez pierwszego. Reguła plików i sync spójności zmniejszają, ale nie
  wykluczają konfliktu znaczeniowego w kodzie.
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
  pomiar pamięci Dockera i klasyfikacja plików (dopuszczalne, blokujące); bez zależności od `night_queue.py`.
- `.claude/skills/ao-run/night_queue.py` i `test_night_queue.py`: komendy `gate` i `release` (algorytm bramki na
  tablicy, zakresach i szacunkach), blokada w komendach zapisujących, pole `docker` w `start`, szacunek zużycia bez
  pomiarów nakładających się, zmiany `propose`, `approve` i `next`.
- `.claude/skills/ao-run/pairs.py` i `test_pairs.py`: pomiar par, usuwany po decyzjach z sekcji „Pomiar…”
  i „Ocena `CLAUDE.md`”.
- `.claude/skills/ao-run/next_epic.py`: bez zmiany zachowania; wspólny odczyt tablicy zostaje w nim.
- `.claude/skills/ao-run/SKILL.md`: sekcje 1, 2, 4, 5, 6, 6a, 7, 7a, 8, „Noc” i „Czego nie robisz”.
- `.claude/skills/delivery/SKILL.md`: kontrola spójności dokumentów i pliki generowane bez konfliktu w sekcji
  „Konflikt z gałęzią docelową lub z `main`”.
- Dokumentacja: `docs/features/ao-run/spec.md` (warunki startu, przebieg, „Noc”, „Czego skill nie robi”: dziś „jeden
  epik naraz”), plan Agentic Software House (status kroku 19, rola „Praca równoległa”, zdanie „Na raz trwa jeden epik
  z jednej sesji” w punkcie 3 procesu).

## Testy

`python3 -m unittest discover -s .claude/skills/ao-run -p 'test_*.py'` i `.github/scripts/script-tests.sh`,
z podstawionymi wynikami komend `gh`, `git` i `docker`, plikami stanu w katalogu tymczasowym i ustalonym „teraz”.

- `gate`: brak epików w toku (start szeregowy), dwa epiki aktywne, epik po `done` nie zajmuje miejsca, epik w toku
  bez pomiaru liczony jako aktywny, epik ze zmergowanym końcowym PR i otwartym issue (`merged_open`, nie w toku),
  zależność, brak `Zakres:` u kandydata i u epiku w toku, część wspólna tylko z plików generowanych i dokumentacji
  (przechodzi), wspólny `CLAUDE.md`, `.github/workflows/ci.yml`, `make/*.mk`, `Makefile`, `infra/k8s/README.md`
  i ścieżka kodu (pominięty), `paths` z dwóch epików w toku, plik spoza zakresu w diffie epiku w toku, niższy kandydat
  przed wyższym, `--dir` (epik spoza tablicy, nieprzygotowany, w toku → `resumed`); pamięć: wystarczająca, za mała,
  rezerwa epiku bez stosu, błąd `docker`, nieczytelne wyjście `docker`; limit: brak pomiaru, stan inny niż `ok`, pełny
  szacunek epiku aktywnego przekracza 80%, okno odnawiane przed końcem, szacunek zużycia bez pomiarów nakładających się;
  pusty i niepoprawny JSON na stdin; błąd `gh` albo `git` → kod 1, nieczytelny plik pomiarów → kod 2; wpis
  w `gate.jsonl`.
- Rezerwacja: `--reserve` zapisuje wpis z tokenem; druga sesja widzi rezerwację jako epik w toku i aktywny (miejsce,
  pliki, pamięć, limit); kandydat nie jest porównywany z własną rezerwacją; odnowienie tym samym tokenem; ten sam epik
  z innym tokenem i z `--force` → odmowa; dwa procesy `gate --reserve` uruchomione naraz (prawdziwa blokada, osobne
  procesy): rezerwację dostaje dokładnie jeden; zajęta blokada dłużej niż limit → kod 2 bez zapisu; rezerwacja wygasła
  po 30 minutach; `start` z tokenem usuwa rezerwację; `release` z rezerwacją i bez; nieczytelny `claims.json` → kod 2;
  zapis atomowy (przerwany zapis nie psuje pliku).
- `propose`: epik w toku nie opróżnia listy, reguła plików. `approve`: odmowa przy trwającej nocy, zgoda po
  `ended_at` i po `until`. `next`: pierwszy epik z rezerwacją, pierwszy epik przy epiku aktywnym innej sesji (bramka),
  start równoległy z rezerwacją, czekanie bez końca nocy, dzisiejsze przypadki bez zmian.
- `start`: pole `docker`, błąd `docker` nie przerywa zapisu, token.
- `pairs.py`: brak par, para, trzy epiki w łańcuchu, para otwarta, okno odnowione, awarie stosu w rozpiętości i poza
  nią, brak katalogu diagnostyki, `claude_md_only` z `gate.jsonl` (tylko `CLAUDE.md` tak, `CLAUDE.md` z inną ścieżką
  nie), brak `gate.jsonl`, nieczytelny plik → kod 2.
- Testy skryptów `/delivery` w `.github/scripts/script-tests.sh` (jeśli sprawdzają treść sekcji) dalej przechodzą.
