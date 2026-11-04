# `/ao-run`: przebieg epiku przez AO z powiadomieniami w aplikacji Claude

Krok 5 planu [Agentic Software House](../agentic-software-house/plan.md). Skill prowadzi nocny przebieg jednego epiku
przez AO z sesji Claude Code w aplikacji: uruchamia AO, przekazuje zdarzenia jako powiadomienia push do aplikacji Claude,
przyjmuje odpowiedzi na blokery i kończy raportem odbioru. Po merge'u końcowego PR proponuje następny przygotowany epik
z tablicy backlogu (kolejka epików). Krok 8 planu dodaje noc: listę kilku epików zatwierdzoną jednym „tak”, które
ruszają po kolei, każdy po `epic_done` poprzedniego, bez czekania na merge (sekcja „Noc”). Kod AO się nie zmienia.

## Wejście i warunki startu

`/ao-run <epik>`, gdzie `<epik>` to katalog planu w `docs/features/<epik>/` (np. `cart-fixes`).
`/ao-run` bez argumentu to prośba użytkownika o następny epik: skill wykonuje tylko krok „Następny epik” (punkt 7
przebiegu). `/ao-run --noc [HH:MM]` to prośba o listę epików na noc do godziny `HH:MM` (domyślnie 07:00): sekcja
„Noc”.

Skill odmawia startu i mówi dlaczego, gdy:

- `docs/features/<epik>/spec.md` albo `plan.md` nie ma na `origin/main` (plan wiążący to plan na `origin/main`);
- checkout AO (`$HOME/Development/claude/agentic-orchestrator`) nie jest czysty albo nie jest na `origin/main`;
- `ao epic start --dry-run` kończy się odmową (np. epik już zarejestrowany z innego commita planu);
- sesja nie ma Remote Control albo komputer pracuje na baterii (ostrzeżenie, start dopiero po potwierdzeniu).

## Pliki

- `.claude/skills/ao-run/SKILL.md`: instrukcja dla sesji.
- `.claude/skills/ao-run/ao_events.py` (Python 3.9+, bez zależności) i `test_ao_events.py`:
  - `record <plik>`: czyta zdarzenie AO (JSON ze stdin, format z sekcji „Powiadomienia” README AO) i dopisuje je jako
    jedną linię do `<plik>`; kod wyjścia 0, także przy pustym albo niepoprawnym JSON-ie (zapisuje wtedy linię
    `{"type": "invalid_event", ...}`), żeby AO nie zgłaszało `notify_failed`.
  - `watch <plik>`: czyta nowe linie pliku zdarzeń i dla każdej wypisuje jedną linię komunikatu do 200 znaków,
    np. `cart-fixes: BLOCKED CART-FIXES-2: <pierwsze pytanie>`, `cart-fixes: końcowy PR <url>`; linie bez znaczenia
    dla człowieka pomija.
- `.claude/skills/ao-run/next_epic.py` (Python 3.9+, bez zależności) i `test_next_epic.py`: wybór następnego epiku.
  Sam wykonuje `gh project item-list 1 --owner piotrp321` (pozycje w kolejności tablicy „Backlog sklepu”),
  `gh issue list --label epic --state open`, `git ls-remote --heads origin 'epic/*'`, `git ls-tree` i `git show`
  na `origin/main` (po `git fetch origin main`). Wypisuje jedną linię JSON:
  `{"epic": {"dir", "id", "issue", "title"} | null, "in_progress": [...], "skipped": [...]}`; kod wyjścia 0, a 1
  (komunikat na stderr), gdy komenda `gh` albo `git` się nie powiedzie. Reguły, po pozycjach tablicy od góry:
  - liczą się tylko otwarte issues z `piotrp321/shop` z etykietą `epic` i bez `needs-info`; ID i katalog jak
    w `/prepare-epic` (prefiks tytułu przed dwukropkiem, katalog małymi literami); tytuł bez prefiksu trafia do
    `skipped` z powodem;
  - epik w toku: istnieje `origin/epic/<katalog>`, a issue jest otwarte (gałęzi epików nie usuwamy, ruleset `epic`);
  - epik przygotowany: na `origin/main` są `docs/features/<katalog>/spec.md` i `plan.md`, a plan ma manifest
    (`"schema_version"`); otwarte epiki nad nim, nieprzygotowane, trafiają do `skipped` z powodem;
  - `epic` to pierwszy przygotowany epik, który nie jest w toku; gdy jakiś epik jest w toku, `epic` jest `null`
    (na raz trwa jeden epik).
- `.claude/skills/ao-run/night_queue.py` (Python 3.9+, bez zależności) i `test_night_queue.py`: lista na noc, pomiar
  i warunki startu kolejnego epiku (sekcja „Noc”). Tablicę, issues, gałęzie i pliki na `origin/main` czyta tak samo
  jak `next_epic.py` (wspólny kod w `next_epic.py`).
- Katalog przebiegu `$HOME/.local/state/ao-<epik>/` (`AO_HOME`, poza repo) z `events.jsonl` i dziennikiem `run.log`.
- Katalog nocy `$HOME/.local/state/ao-night/` (poza repo): `night.json` (zatwierdzona lista) i `measurements.jsonl`
  (pomiar każdego epiku prowadzonego przez `/ao-run`).

## Przebieg

1. **Przygotowanie.** Sprawdza warunki startu, zakłada `AO_HOME`, uruchamia `ao epic start --dry-run`, potem
   `ao epic start`.
2. **Start.** Uruchamia w tle (Bash w tle sesji) `caffeinate -dims` z `ao run --until-idle` i
   `--notify-cmd "python3 .claude/skills/ao-run/ao_events.py record $AO_HOME/events.jsonl"`, z limitami jak przy CART:
   `--max-budget-usd 30 --timeout 14400 --epic-max-attempts 24 --epic-timeout 57600 --max-qa-rounds 3`.
   Zmienne `CLAUDE*` usuwa ze środowiska AO (jak wrapper z README AO).
3. **Śledzenie.** Monitor sesji na `ao_events.py watch`: każda linia budzi sesję, a ta wysyła powiadomienie push
   (`PushNotification`). Monitor wygasa po 30 minutach, sesja uzbraja go ponownie, dopóki AO działa. Zakończenie
   procesu AO też budzi sesję.
4. **Bloker.** Push z pytaniem. Użytkownik odpowiada w sesji (także z telefonu). Sesja wykonuje
   `ao answer <ID> --text "<odpowiedź>"` z jego słowami i, jeśli `ao run` się zakończył, uruchamia go ponownie.
   Inne zatrzymania (`FAILED`, `epic_blocked`, `queue_held`) sesja diagnozuje przez `ao status` i opisuje w pushu;
   `ao resume`, `ao release` i `ao epic resume` wykonuje tylko na polecenie użytkownika.
5. **Raport odbioru.** Po `epic_done` (AO ustawia go dopiero przy zielonym CI końcowego PR) sesja czyta `ao report --epic <epik>`
   i sekcję „Lista odbioru” z ostatniego raportu `/integration` i zostawia w rozmowie raport odbioru:
   - rekomendacja: „merge” albo „najpierw sprawdź: …” (każda pozycja „Sprawdź:” z listy odbioru, znaleziska bez naprawy,
     ujawnione luki TDD);
   - zakres, zadania i PR, interwencje, czas, koszt;
   - link do końcowego PR i lista `Closes #N` do dopisania (issue epiku i issues, które epik realizuje).
   Push: „`<epik>`: końcowy PR gotowy, rekomendacja: …”. Sesja nigdy nie merguje do `main`.
6. **Pomiar.** Po wysłaniu raportu odbioru sesja uruchamia w tle (Bash w tle) pętlę, która co 5 minut sprawdza
   `gh pr view <końcowy PR> --json state` i kończy się przy `MERGED` albo `CLOSED`; jej zakończenie budzi sesję.
   Użytkownik może też napisać w sesji, że zmergował: po potwierdzeniu `MERGED` sesja zatrzymuje pętlę. Po merge'u sesja pyta o minuty użytkownika (spec, blokery,
   triage, raport) i po zgodzie dopisuje komentarz do issue epiku: minuty, liczba interwencji z `ao report`, liczba
   ręcznych poprawek. PR zamknięty bez merge'u: sesja mówi o tym i nie przechodzi do punktu 7.
7. **Następny epik.** Po merge'u końcowego PR albo na prośbę użytkownika (`/ao-run` bez argumentu) sesja uruchamia
   `next_epic.py`:
   - `epic` jest: push „Zacząć epik `<ID>` (#`<nr>`)?”, a w rozmowie także pozycje `skipped` (np. epik wyżej na
     tablicy bez planu). Start dopiero po „tak” użytkownika w sesji: przebieg od punktu 1 dla nowego `<epik>`, z
     wszystkimi warunkami startu. Inna odpowiedź albo brak odpowiedzi: sesja niczego nie uruchamia.
   - `epic` jest `null`: push (krótszy niż 200 znaków) z powodem: pierwsza pozycja `in_progress` albo pierwsza
     `skipped` z liczbą pozostałych, np. „Brak przygotowanego epiku: VARIANTS (#89) bez spec.md i plan.md na main,
     następny krok `/prepare-epic` (+2 inne)”; pełna lista w rozmowie. Jeśli `in_progress` to epik właśnie
     zmergowanego PR, issue epiku jest nadal otwarte (brak `Closes #N`): sesja mówi to, prosi o zamknięcie issue
     i o ponowne `/ao-run`.

Pomiar epiku (każdy przebieg, także poza nocą): zaraz po udanym `ao epic start` sesja wykonuje
`night_queue.py start <epik>`, a na `epic_done` `night_queue.py done <epik>`. Oba dostają na stdin stan limitów
z narzędzia aplikacji `get_usage` (obiekt `plan`). Pomiar zapisuje liczbę zadań z manifestu, czas od startu do
`epic_done` i przyrost procentu każdego okna limitu. Okno, które odnowiło się w trakcie (inne `resetsAt`), albo stan
limitów inny niż `ok` nie daje pomiaru tego okna. Epik zakończony bez `DONE` nie ma pomiaru.

## Noc

Krok 8 planu: przed snem użytkownik zatwierdza jednym „tak” listę epików, a sesja uruchamia je po kolei.

### Zakres epiku

`/prepare-epic` zapisuje w nagłówku `spec.md` (przed pierwszym nagłówkiem `## `) linię
`Zakres: <ścieżka>, <ścieżka>, …`: ścieżki w repo (katalogi albo pliki), które epik zmienia poza własnym
`docs/features/<katalog>/`. Reguły linii są w `docs/features/prepare-epic/spec.md`. Zakres każdego epiku obejmuje też
jego `docs/features/<katalog>`. Dwa epiki mają rozłączny zakres, gdy żadna ścieżka jednego nie jest równa ścieżce
drugiego ani nie leży w niej (porównanie po segmentach: `apps/api/src/Cart` leży w `apps/api/src`, a
`apps/api/src/CartX` nie leży w `apps/api/src/Cart`). Spec bez linii `Zakres:` albo z linią nieczytelną (ścieżka
absolutna, `..`, znaki `*?[`, pusta pozycja) nie ma zakresu.

### Szacunek

Z `measurements.jsonl`: czas epiku = liczba zadań × najwyższy zmierzony czas na zadanie; zużycie okna = liczba zadań
× najwyższy zmierzony przyrost tego okna na zadanie. Najwyższy, nie średni: szacunek ma raczej zawyżać.

### Lista na noc

`/ao-run --noc [HH:MM]`. Sesja pyta o Remote Control (raz na całą noc) i uruchamia
`night_queue.py propose --until HH:MM`. Wynik (JSON): `epics` (lista w kolejności startu: `dir`, `id`, `issue`,
`title`, `tasks`, `minutes` albo `null`, `scope`), `skipped` (`issue`, `id`, `reason`), `in_progress`, `until`
(czas ISO), `measured` (czy jest pomiar czasu). Reguły:

- gdy jakiś epik jest w toku (jak w `next_epic.py`: gałąź `origin/epic/<katalog>` i otwarte issue), lista jest pusta:
  noc zaczyna się, gdy poprzednie epiki są zmergowane;
- kandydaci to przygotowane epiki w kolejności tablicy (reguły `next_epic.py`); epik wchodzi na listę, gdy:
  - sekcja `## Zależności` jego issue nie wymienia ID innego otwartego epiku (wymieniony epik nie jest jeszcze na
    `main`, a każdy epik nocy startuje z `main`);
  - ma zakres rozłączny z zakresem każdego epiku już na liście; epik bez zakresu wchodzi tylko jako pierwszy
    i wtedy jest jedynym;
  - przy pomiarze czasu suma szacunków listy z nim mieści się do `until`;
- kandydat, który nie wchodzi, trafia do `skipped` z powodem (np. „zakres wspólny z VARIANTS:
  apps/api/openapi.json”, „zależy od CHECKOUT”, „nie mieści się do 07:00”), a sprawdzanie idzie dalej: niższy epik
  o rozłącznym zakresie może wejść przed wyższym;
- bez pomiaru czasu lista nie ma limitu długości; kolejne epiki i tak ruszają dopiero po sprawdzeniu w `next`.

Sesja pokazuje listę z szacunkami i pozycje `skipped` i pyta raz: „Zatwierdzasz listę na noc: A, B, C?”. Tylko „tak”
użytkownika w sesji zatwierdza listę: `night_queue.py approve <katalog>…` liczy listę od nowa i zapisuje
`night.json` (lista, `until`, czas zatwierdzenia), gdy jest taka sama jak podana; inna lista to błąd i nowa
propozycja. Potem sesja wykonuje przebieg (punkty 1–5) pierwszego epiku listy.

### Kolejny epik

Na `epic_done` epiku z listy, po raporcie odbioru i `done`, sesja uruchamia `night_queue.py next` (stdin: stan
limitów z `get_usage`). Wynik: `epic` albo `null` z `reason`, oraz `started` i `left`. `next` bierze pierwszy epik
listy bez pomiaru od zatwierdzenia i zwraca go, gdy wszystkie warunki są spełnione:

- każdy epik listy, który ruszył tej nocy, ma zakończony pomiar (`done`);
- epik jest nadal przygotowany na `origin/main`, nie jest w toku (brak `origin/epic/<katalog>`) i ma otwarte issue;
- jest pomiar czasu i teraz + szacunek czasu nie przekracza `until`;
- stan limitów to `ok`, a każde okno, które nie odnowi się przed końcem szacunku czasu (`resetsAt` późniejszy), ma
  pomiar i po dodaniu szacunku zużycia nie przekracza 80%. Okna, które odnowią się wcześniej, nie blokują:
  na ich cooldown AO czeka samo (`--max-cooldown-wait`).

Pierwszy epik nocy rusza po „tak” bez tych warunków, jak dziś. Gdy `epic` jest, sesja wykonuje dla niego przebieg
od punktu 1 bez pytania „Zacząć epik?”. W punkcie 1 nocą zasilanie z baterii to odmowa (nikt nie potwierdzi),
a Remote Control jest potwierdzone przy liście. Gdy `epic` jest `null` albo punkt 1 odmawia, noc się kończy: push
„Noc: gotowe A, B; C nie ruszył: <powód>” (do 200 znaków), pozostałe epiki listy nie ruszają, a `night.json`
przestaje obowiązywać (następna noc to nowa lista). Epik nocy, który kończy się bez `DONE` (`BLOCKED`, `FAILED`),
też kończy noc: następny nie rusza. Sesja zapisuje koniec nocy przez `night_queue.py close`, a `next` zapisuje go sam,
gdy zwraca `null`, więc późniejszy `next` odmawia.

Rano użytkownik odbiera każdy epik osobno (punkt 5 i 6). Punkt 7 („Następny epik”) sesja wykonuje dopiero wtedy,
gdy końcowy PR każdego epiku nocy jest zamknięty albo zmergowany.

### Dla `/prepare-epic`

- `night_queue.py scope <spec.md>`: sprawdza linię `Zakres:` pliku na dysku; kod 0 i lista ścieżek albo kod 2
  z powodem.
- `night_queue.py ahead`: przygotowane, nierozpoczęte epiki z `origin/main` z szacunkami czasu i `full`: czy suma
  szacunków wypełnia już noc (9 h). Bez pomiaru czasu `full` jest prawdą przy co najmniej jednym takim epiku (jeden
  epik do przodu, jak przed krokiem 8).

### Ograniczenia

- Zakres to deklaracja z `/prepare-epic`. Gdy epik zmieni plik spoza niej, rano końcowy PR drugiego epiku może mieć
  konflikt po merge'u pierwszego. Rozwiązanie to poprawka przez PR do `epic/<epik>` (punkt 5 procesu).
- CI końcowego PR drugiego epiku przeszło na `main` bez pierwszego. Rozłączny zakres zmniejsza, ale nie wyklucza
  konfliktu znaczeniowego.

## Czego skill nie robi

Nie zmienia kodu ani konfiguracji AO, nie merguje do `main`, nie odpowiada na blokery sam, nie uruchamia kilku epików
naraz (epiki nocy idą jeden po drugim), nie zaczyna epiku bez „tak” użytkownika (na pytanie „Zacząć epik?” albo na
listę nocy), nie przygotowuje epiku (`/prepare-epic`) i nie zmienia tablicy.

## Testy

`python3 -m unittest discover -s .claude/skills/ao-run -p 'test_*.py'`: `record` (poprawne zdarzenie, pusty
i niepoprawny JSON, brak katalogu), `watch` (każdy typ zdarzenia z README AO, limit 200 znaków, pomijanie zdarzeń bez
znaczenia, linie dopisane w trakcie); `next_epic.py` z podstawionymi wynikami komend (kolejność tablicy, pominięcie
zamkniętych, z `needs-info`, bez etykiety `epic`, z innego repo i nie-issue, tytuł bez prefiksu, plan bez manifestu,
brak `spec.md`, epik w toku blokuje wybór, epik zakończony z gałęzią i zamkniętym issue nie blokuje, pusta tablica,
błąd `gh` albo `git` → kod 1); `night_queue.py` z podstawionymi wynikami komend, plikami stanu w katalogu
tymczasowym i ustalonym „teraz” (zakres: rozłączność po segmentach, linia nieczytelna, brak linii; `propose`: epik
w toku, zależność od otwartego epiku, wspólny zakres, epik bez zakresu tylko jako jedyny, niższy epik przed wyższym,
limit czasu z pomiarem i bez; `approve`: zgodna i niezgodna lista; `start` i `done`: liczba zadań, czas, okno
odnowione w trakcie, stan limitów inny niż `ok`, `done` bez `start`; `next`: brak listy, poprzedni epik bez `done`,
epik już nie przygotowany albo w toku, brak pomiaru, przekroczone `until`, okno powyżej 80%, okno bez pomiaru, okno
odnawiane przed końcem; `close`: ustawienie końca nocy, drugie wywołanie bez zmiany, brak listy, odmowa `next` po
`close`, zapis końca przez `next` zwracające `null`, nowa noc po `approve`; `ahead` z pomiarem i bez; błąd `gh` albo `git` → kod 1). CI uruchamia testy skilli tak jak dla
`delivery` i `integration`. Sam przebieg sprawdza krok 7 planu (CART-FIXES), a nocną kolejkę pierwsza noc po kroku 8.

## Zmiana w planie

`docs/features/agentic-software-house/plan.md`: krok 5 wskazuje `/ao-run`, kolejka epików jest w punkcie 7
przebiegu, status kroku 5 i rola „Kolejka epików” bez „do zrobienia”, sprawdzenie w kroku 7. Odbiór (punkt 5 procesu) to przeczytanie
raportu odbioru i merge; trzy liczby obejmują minuty na spec, blokery, triage i raport. Krok 9 rusza po kroku 7, bo
lista odbioru ma najpierw zadziałać na prawdziwym epiku.

Krok 8 (w tym samym PR co `night_queue.py`): status kroku 8; punkt 2 procesu przygotowuje epiki do przodu, aż
`night_queue.py ahead` pokaże pełną noc; punkt 3 procesu i diagram z listą na noc; punkt zatrzymania „Start epiku”:
AO rusza z epikiem po „tak” na „Zacząć epik X?” albo na listę nocy, a kolejny epik listy rusza bez pytania tylko przy
spełnionych warunkach sekcji „Noc”. ADR (`docs/adr/agentic-software-house.md`): ten sam punkt zatrzymania
w granicach autonomii.
