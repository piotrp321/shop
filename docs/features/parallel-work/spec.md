# Praca równoległa: epiki naraz (19a) i zadania fali naraz (19b)

Krok 19 planu [Agentic Software House](../agentic-software-house/plan.md), komponent „Pula środowisk” z
[ADR](../../adr/agentic-software-house.md). Równoległość jest domyślna: kolejny przygotowany epik rusza naraz
z trwającym, gdy przejdzie bramkę ryzyka; wykonanie szeregowe zostaje rozwiązaniem awaryjnym, gdy bramka wykryje
realne ryzyko. 19a działa w `shop` (skill `/ao-run`, bez zmian w AO). 19b to zmiana w AO
(piotrp321/agentic-orchestrator#50); tu jest tylko jej kontrakt i warunek startu.

Miara główna: czas zegarowy epiku. Pilnujemy przy tym jakości, liczby napraw, interwencji człowieka i zużycia limitów.

## 19a: epiki naraz

### Pojęcia

- **Epik w toku**: jak w `next_epic.py` (gałąź `origin/epic/<katalog>` i otwarte issue z etykietą `epic`), z każdej
  sesji. Obejmuje epiki czekające na merge końcowego PR.
- **Epik aktywny**: epik w toku, którego ostatnie zdarzenie w `~/.local/state/ao-night/measurements.jsonl` to `start`
  albo który nie ma tam żadnego zdarzenia (uruchomiony poza `/ao-run`: liczony ostrożnie jako aktywny). Epik po
  `done` (po `epic_done`, czeka na merge) nie zajmuje zasobów, ale dalej liczy się przy plikach.
  Plik pomiarów jest wspólny dla wszystkich sesji `/ao-run`.
- **Pliki epiku**: ścieżki z linii `Zakres:` jego `spec.md` na `origin/main` i jego `docs/features/<katalog>`
  (reguły linii i porównania po segmentach: `docs/features/ao-run/spec.md`, sekcja „Zakres epiku”). Dla epiku w toku
  dochodzi `git diff --name-only origin/main...origin/epic/<katalog>`.
- **Część wspólna** dwóch epików: dla każdej pary ścieżek, z których jedna leży w drugiej, węższa z nich.

### Bramka

Bramka sprawdza kandydata względem wszystkich epików w toku, po kolei; pierwszy niespełniony warunek daje wynik.

1. **Brak epików w toku**: kandydat przechodzi bez dalszych warunków (dzisiejszy start szeregowy).
2. **Miejsce**: najwyżej jeden epik aktywny (razem z kandydatem najwyżej dwa). Inaczej: czekanie.
3. **Zależności**: sekcja `## Zależności` issue kandydata nie wymienia ID otwartego epiku (reguła listy nocy).
   Inaczej: kandydat pominięty.
4. **Zakres**: kandydat i każdy epik w toku mają czytelną linię `Zakres:`. Brak u kandydata: kandydat pominięty;
   brak u epiku w toku: czekanie (nie wiadomo, co zmienia).
5. **Pliki**: każda ścieżka części wspólnej kandydata z epikiem w toku jest plikiem dopuszczalnym. Inaczej: kandydat
   pominięty z powodem „wspólne pliki z X: <ścieżka>”.
   - **Dopuszczalne (sync i REPAIR rozwiązują je automatycznie):** pliki generowane `apps/api/openapi.json`,
     `apps/api/openapi-admin.json`, `apps/store/src/api/schema.d.ts`, `apps/admin/src/api/schema.d.ts` (sync i REPAIR
     generują je od nowa, zasady `/delivery`) oraz dokumentacja `docs/**` i `README.md` (konflikt tekstu rozwiązuje
     REPAIR; dokumenty nie wpływają na CI).
   - **Wymuszają kolejkę:** wspólne pliki edytowane ręcznie: CI (`.github/**`), każdy `CLAUDE.md`, `make/*.mk`
     i główny `Makefile`, menu panelu (`apps/admin/src/layout/AppMenu.vue`, `apps/admin/src/router/index.ts`) oraz
     każda inna wspólna ścieżka kodu, testów i konfiguracji (rozłączność jak w liście nocy).
6. **Pamięć Dockera**: `wolna = MemTotal − pamięć kontenerów spoza AO − max(pamięć kontenerów AO, rezerwa × liczba
   epików aktywnych)` jest co najmniej rezerwą jednego stosu. Rezerwa: 6 GiB (`--stack-gib`, pomiar: stos ok. 4,9 GB).
   Kontenery AO to kontenery projektów Compose o nazwie `ao-*` (`docker stats --no-stream` i `docker ps`, etykieta
   `com.docker.compose.project`; `MemTotal` z `docker info`). Rezerwa na epik aktywny działa także wtedy, gdy ten epik
   jest akurat między zadaniami i nie ma stosu. Błąd albo nieczytelne wyjście `docker`: czekanie z powodem.
7. **Zapas limitu**: stan limitów `ok`, jest pomiar czasu i zużycia (szacunek z sekcji „Szacunek” spec `/ao-run`),
   a każde okno, które nie odnowi się przed końcem szacunku czasu kandydata, spełnia
   `procent teraz + pozostały szacunek każdego epiku aktywnego + szacunek kandydata ≤ 80%`. Pozostały szacunek
   epiku aktywnego to jego szacunek minus przyrost tego okna od jego `start` (najmniej 0); okno odnowione od jego
   startu albo epik bez `start`: cały szacunek. Inaczej: czekanie.

„Pominięty” oznacza, że bramka sprawdza następnego przygotowanego kandydata z tablicy (niższy epik może ruszyć przed
wyższym, jak w liście nocy). „Czekanie” dotyczy każdego kandydata, więc bramka kończy szukanie: kandydat ruszy
później, równolegle albo szeregowo po zakończeniu epiku aktywnego.

### Gdzie działa

- **`night_queue.py gate [--dir <katalog>] [--stack-gib N]`** (stdin: obiekt `plan` z `get_usage`): bez `--dir`
  szuka pierwszego przygotowanego, nierozpoczętego epiku z tablicy, który przechodzi bramkę; z `--dir` sprawdza ten
  epik. Wynik (JSON, kod 0): `epic` (`dir`, `id`, `issue`, `title`, `tasks`, `minutes`) albo `null`, `reason`
  (powód czekania albo `null`), `parallel_with` (katalogi epików w toku, z którymi epik ruszy), `active`,
  `in_progress`, `skipped` (`issue`, `id`, `reason`), `docker` (`total_mib`, `other_mib`, `ao_mib`, `free_mib`,
  `required_mib` albo `null`). Kod 1: błąd `gh` albo `git`; kod 2: błąd stanu (nieczytelny plik pomiarów).
- **`/ao-run` bez argumentu i po merge'u (sekcja 8 skilla):** sesja uruchamia `gate` zamiast `next_epic.py`.
  `epic` jest: push „Zacząć epik X?” albo, przy niepustym `parallel_with`, „Zacząć epik X równolegle z Y?”; start
  dalej tylko po „tak” użytkownika. `epic` null z `reason`: push „Epik X czeka: <powód>”, bez pytania.
- **Po starcie epiku i po jego `epic_done`** (sekcje 4 i 6 skilla) sesja też uruchamia `gate`: tak proponuje drugi
  epik, póki pierwszy trwa.
- **`/ao-run <epik>`** (sekcja 1 skilla): `gate --dir <epik>` zastępuje dzisiejszy opis ryzyka „Inne epiki w toku”.
  Bramka niespełniona: sesja podaje powód i pyta „Uruchomić mimo bramki?”; start tylko po „tak”.
- **Noc:** `propose` stosuje regułę plików (krok 5) zamiast samej rozłączności, a epiki w toku nie opróżniają już
  listy: liczą się jak epiki już na liście (pliki i zależności). Suma szacunków dalej musi się zmieścić do `until`
  szeregowo, bo szeregowe wykonanie jest rozwiązaniem awaryjnym. `next` zwraca kolejny epik listy, gdy wszystkie
  rozpoczęte epiki nocy mają `done` (dzisiejsze warunki) albo gdy przechodzi bramkę (kroki 2–7) i warunek `until`.
  Gdy żaden z dwóch przypadków nie zachodzi, a jakiś epik nocy jest aktywny, `next` zwraca `epic: null`
  i `wait: true` z powodem i nie kończy nocy. Pierwszy epik nocy rusza po „tak” bez warunków tylko wtedy, gdy nic
  nie jest aktywne; inaczej przechodzi bramkę.
- **Ponowne sprawdzenie:** czekający kandydat (w dzień i w nocy) jest sprawdzany ponownie po każdym nowym zdarzeniu
  `done` w `measurements.jsonl` (także z innej sesji: Monitor sesji na nowe linie `done` tego pliku) i po końcu
  procesu AO tej sesji, ze świeżym `get_usage`. Bez odpytywania co kilka minut.

### Dwa epiki w jednej sesji

- Każdy epik ma własny `AO_HOME`, proces `ao run` w tle, Monitor zdarzeń i pętlę na końcowy PR; komendy dostają
  blok startowy swojego epiku. Push ma etykietę epiku (już dziś z `--epic-label`).
- Odpowiedź na bloker, gdy czekają blokery obu epików, musi wskazać epik (jak przy triage kilku przeglądów); bez
  tego sesja dopytuje.
- Kolejka przeglądów (`/sweep`, `/retro`) dalej nie działa razem z żadnym procesem AO tej sesji, a nowy epik dalej
  czeka na pustą kolejkę przeglądów. Przy dwóch epikach kolejka rusza po końcu obu procesów AO.
- `start` w `measurements.jsonl` dostaje pola `parallel_with` (epiki z otwartym `start` w chwili zapisu) i `docker`
  (stan pamięci jak w `gate` albo `null` przy błędzie `docker`; błąd nie przerywa zapisu). Stare linie bez tych pól
  są dalej poprawne. Szacunki nie zmieniają się: liczą czas i zużycie każdego epiku osobno, więc epiki równoległe
  zawyżają szacunek zużycia (bezpieczny kierunek).

### Pomiar pierwszych dwóch par

Para to dwa epiki, których przedziały `start`–`done` w `measurements.jsonl` się nakładają.

- **`night_queue.py pairs [--setup-diag DIR]`** (JSON, kod 0; brak par: pusta lista): pary w kolejności startu, dla
  każdej: oba epiki z czasem w minutach, część wspólna, rozpiętość (pierwszy `start` → ostatni `done`), suma czasów
  (wariant szeregowy), oszczędność `1 − rozpiętość/suma`, przyrost każdego okna limitu w rozpiętości (okno odnowione
  w trakcie: bez przyrostu), `docker` ze `start` drugiego epiku i liczba awarii stosu w rozpiętości (pliki
  `ao-*-<RRRRMMDD-GGMMSS>-*.log` w `--setup-diag`, domyślnie `~/.local/state/ao-setup`).
- **Komentarz pary.** Gdy końcowy PR drugiego epiku pary jest `MERGED` albo `CLOSED`, sesja dodaje do komentarza
  z pomiarem tego epiku (sekcja 7 skilla, po zgodzie użytkownika) blok `Para równoległa`: epiki, czas zegarowy pary
  i szeregowo, oszczędność; konflikty (zadania REPAIR obu epików z `ao report` i PR `sync/<epik>-main`); interwencje
  obu epików; zasoby (`docker` przy starcie drugiego, awarie stosu); koszt (USD z `ao report` obu epików i przyrost
  okien z `pairs`); werdykt „Krytyczny problem: brak” albo lista.
- **Krytyczny problem** (definicja z planu, krok 19): (1) CI workflowu `ci` na `main` czerwone dla commita merge'u
  końcowego PR któregoś epiku pary; (2) konflikt między epikami pary, którego sync i REPAIR nie rozwiązały bez ręcznej
  poprawki (pozycja „Ręczne poprawki” z powodu konfliktu); (3) AO zatrzymało epik pary (`FAILED`, `epic_blocked`,
  `queue_held`) z powodu stosu, pamięci, portów albo limitu (`ao status`); (4) epik pary ma więcej napraw (REPAIR
  i ręczne poprawki) albo interwencji na zadanie niż każdy z trzech ostatnich epików szeregowych z komentarzem
  pomiaru.
- Po werdykcie drugiej pary bez krytycznego problemu w obu parach sesja wysyła push „Krok 19b może ruszyć: dwie pary
  bez krytycznego problemu”. Krytyczny problem: push z problemem, epiki dalej ruszają równolegle tylko po decyzji
  użytkownika o zmianie bramki (w rozmowie albo w PR do tej specyfikacji).

### Ograniczenia

- Zakres to deklaracja. Epik, który zmieni plik spoza niej, może skonfliktować z drugim; naprawia to REPAIR albo
  `sync/<epik>-main` (jak przy liście nocy).
- CI końcowego PR drugiego epiku przeszło bez pierwszego. Reguła plików zmniejsza, ale nie wyklucza konfliktu
  znaczeniowego.
- Rezerwa pamięci jest stała (6 GiB na stos); pamięć szczytowa w trakcie epiku nie jest mierzona.
- Bramka nie widzi stosów spoza Dockera (np. procesów na hoście) ani pamięci hosta poza maszyną Dockera.

## 19b: zadania fali naraz (kontrakt AO)

Implementacja to osobna praca w repozytorium AO (piotrp321/agentic-orchestrator#50). Kontrakt:

- `ao run --max-parallel N` (domyślnie 1, bez zmiany dzisiejszego zachowania). Przy N > 1 AO uruchamia naraz do N
  prób zadań jednej fali z zielonymi zależnościami, każdą we własnym worktree i stosie (własny `AO_PORT_BASE`).
- Merge'e do `epic/<epik>` po kolei; integracja (`/integration`) po wszystkich zadaniach, jak dziś.
- Kontrola zasobów i limitów przed każdą kolejną próbą: rezerwa pamięci Dockera jak w kroku 6 bramki 19a i cooldown
  limitu, jak dziś dla jednej próby.
- Awaria jednej próby (`FAILED`, `BLOCKED`, awaria stosu) nie zatrzymuje drugiej.
- Po wydaniu AO z tą opcją `/ao-run` przekazuje `--max-parallel 2` (osobny, mały PR w `shop`), a bramka 19a rezerwuje
  na każdy epik aktywny `max-parallel` stosów.

Warunek startu: dwie pierwsze pary epików 19a bez krytycznego problemu (sekcja „Pomiar pierwszych dwóch par”). Wtedy
19b rusza bez kolejnej akceptacji użytkownika. Pierwsze dwa epiki z `--max-parallel 2` mierzymy tak jak pary 19a.

## Pliki

- `.claude/skills/ao-run/night_queue.py` i `test_night_queue.py`: `gate`, `pairs`, nowe pola `start`, zmiany
  `propose` i `next`.
- `.claude/skills/ao-run/next_epic.py` i `test_next_epic.py`: bez zmiany zachowania; wspólny odczyt tablicy zostaje
  w nim.
- `.claude/skills/ao-run/SKILL.md`: sekcje 1, 4, 6, 7, 7a, 8, „Noc” i „Czego nie robisz”.
- Dokumentacja: `docs/features/ao-run/spec.md` (sekcje, które dziś mówią „jeden epik naraz”), status kroku 19
  i rola „Praca równoległa” w planie Agentic Software House.

## Testy

`python3 -m unittest discover -s .claude/skills/ao-run -p 'test_*.py'` i `.github/scripts/script-tests.sh`,
z podstawionymi wynikami komend `gh`, `git` i `docker`, plikami stanu w katalogu tymczasowym i ustalonym „teraz”.
Przypadki `gate`: brak epików w toku (start szeregowy), dwa epiki aktywne, epik po `done` nie zajmuje miejsca, epik
w toku bez pomiaru liczony jako aktywny, zależność, brak `Zakres:` u kandydata i u epiku w toku, część wspólna tylko
z plików generowanych i dokumentacji (przechodzi), wspólny `CLAUDE.md`, `.github/workflows/ci.yml`, `make/*.mk`,
`Makefile` i ścieżka kodu (pominięty), plik spoza zakresu w diffie epiku w toku, niższy kandydat przed wyższym,
`--dir`; pamięć: wystarczająca, za mała, rezerwa epiku bez stosu, błąd `docker`, nieczytelne wyjście `docker`; limit:
brak pomiaru, stan inny niż `ok`, okno powyżej 80% z pozostałym szacunkiem epiku aktywnego, okno odnowione od startu
epiku aktywnego, okno odnawiane przed końcem; pusty i niepoprawny JSON na stdin; błąd `gh` albo `git` → kod 1,
nieczytelny plik pomiarów → kod 2. `propose`: epik w toku nie opróżnia listy, reguła plików. `next`: start
równoległy, czekanie bez końca nocy, pierwszy epik nocy przy epiku aktywnym innej sesji. `start`: pola
`parallel_with` i `docker`, błąd `docker` nie przerywa zapisu. `pairs`: brak par, para, trzy epiki w łańcuchu, okno
odnowione, awarie stosu w rozpiętości i poza nią, brak katalogu diagnostyki.
