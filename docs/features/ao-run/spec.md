# `/ao-run`: przebieg epiku przez AO z powiadomieniami w aplikacji Claude

Krok 5 planu [Agentic Software House](../agentic-software-house/plan.md). Skill prowadzi nocny przebieg jednego epiku
przez AO z sesji Claude Code w aplikacji: uruchamia AO, przekazuje zdarzenia jako powiadomienia push do aplikacji Claude,
przyjmuje odpowiedzi na blokery i kończy raportem odbioru. Po merge'u końcowego PR proponuje następny przygotowany epik
z tablicy backlogu (kolejka epików). Kod AO się nie zmienia.

## Wejście i warunki startu

`/ao-run <epik>`, gdzie `<epik>` to katalog planu w `docs/features/<epik>/` (np. `cart-fixes`).
`/ao-run` bez argumentu to prośba użytkownika o następny epik: skill wykonuje tylko krok „Następny epik” (punkt 7
przebiegu).

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
- Katalog przebiegu `$HOME/.local/state/ao-<epik>/` (`AO_HOME`, poza repo) z `events.jsonl` i dziennikiem `run.log`.

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

## Czego skill nie robi

Nie zmienia kodu ani konfiguracji AO, nie merguje do `main`, nie odpowiada na blokery sam, nie uruchamia kilku epików
naraz, nie zaczyna epiku bez „tak” użytkownika, nie przygotowuje epiku (`/prepare-epic`) i nie zmienia tablicy.

## Testy

`python3 -m unittest discover -s .claude/skills/ao-run -p 'test_*.py'`: `record` (poprawne zdarzenie, pusty
i niepoprawny JSON, brak katalogu), `watch` (każdy typ zdarzenia z README AO, limit 200 znaków, pomijanie zdarzeń bez
znaczenia, linie dopisane w trakcie); `next_epic.py` z podstawionymi wynikami komend (kolejność tablicy, pominięcie
zamkniętych, z `needs-info`, bez etykiety `epic`, z innego repo i nie-issue, tytuł bez prefiksu, plan bez manifestu,
brak `spec.md`, epik w toku blokuje wybór, epik zakończony z gałęzią i zamkniętym issue nie blokuje, pusta tablica,
błąd `gh` albo `git` → kod 1). CI uruchamia testy skilli tak jak dla `delivery` i `integration`. Sam przebieg
sprawdza krok 7 planu (CART-FIXES).

## Zmiana w planie

`docs/features/agentic-software-house/plan.md`: krok 5 wskazuje `/ao-run`, kolejka epików jest w punkcie 7
przebiegu, status kroku 5 i rola „Kolejka epików” bez „do zrobienia”, sprawdzenie w kroku 7. Odbiór (punkt 5 procesu) to przeczytanie
raportu odbioru i merge; trzy liczby obejmują minuty na spec, blokery, triage i raport. Krok 8 rusza po kroku 7, bo
lista odbioru ma najpierw zadziałać na prawdziwym epiku.
