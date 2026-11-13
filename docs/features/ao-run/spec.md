# `/ao-run`: przebieg epiku przez AO z powiadomieniami w aplikacji Claude

Krok 5 planu [Agentic Software House](../agentic-software-house/plan.md). Skill prowadzi nocny przebieg epiku
przez AO z sesji Claude Code w aplikacji: uruchamia AO, przekazuje zdarzenia jako powiadomienia push do aplikacji Claude,
przyjmuje odpowiedzi na blokery i kończy raportem odbioru. Po merge'u końcowego PR proponuje następny przygotowany epik
z tablicy backlogu (kolejka epików). Krok 8 planu dodaje noc: listę kilku epików zatwierdzoną jednym „tak”, które
ruszają bez czekania na merge (sekcja „Noc”). Krok 12 planu dodaje
przegląd po merge'u: sesja sama uruchamia `/sweep <epik>` po merge'u końcowego PR (sekcja „Przegląd po merge'u”).
Krok 15 planu dodaje do tej kolejki retrospektywę `/retro <epik>` po przeglądzie
([spec](../retro/spec.md), sekcja „Uruchamianie”). Krok 19a planu dodaje pracę równoległą: kolejny przygotowany epik,
także z listy nocy, rusza naraz z trwającym, gdy przejdzie bramkę ryzyka (najwyżej dwa epiki aktywne). Bramkę,
rezerwację, sync spójności i pomiar par opisuje [specyfikacja pracy równoległej](../parallel-work/spec.md); ta
specyfikacja tylko na nią wskazuje. Kod AO się nie zmienia.

## Wejście i warunki startu

`/ao-run <epik>`, gdzie `<epik>` to katalog planu w `docs/features/<epik>/` (np. `cart-fixes`).
`/ao-run` bez argumentu to prośba użytkownika o następny epik: skill wykonuje tylko krok „Następny epik” (punkt 7
przebiegu). `/ao-run --noc [HH:MM]` to prośba o listę epików na noc do godziny `HH:MM` (domyślnie 07:00): sekcja
„Noc”.

Skill odmawia startu i mówi dlaczego, gdy:

- `docs/features/<epik>/spec.md` albo `plan.md` nie ma na `origin/main` (plan wiążący to plan na `origin/main`);
- checkout AO (`$HOME/Development/claude/agentic-orchestrator`) nie jest czysty albo nie jest na `origin/main`;
- nie ma binarki Claude Code w wersji przypiętej przez AO (`claude_pin.py` kończy się kodem różnym od 0); odmowa
  podaje wersję i komendę instalacji (`claude install <wersja> --force`) i zapada przed założeniem `AO_HOME`
  i przed `ao epic start`;
- bramka pracy równoległej nie rezerwuje epiku ani go nie wznawia (`night_queue.py gate --dir <epik> --reserve`;
  reguły, rezerwacja i wznowienie: [spec pracy równoległej](../parallel-work/spec.md), „Bramka” i „Rezerwacja”).
  W dzień niespełniona bramka (kroki 2–7) daje pytanie „Uruchomić mimo bramki?”, a „tak” rezerwuje epik z `--force`;
  epik prowadzony przez inną sesję daje pytanie „Przejąć epik X od innej sesji?”, a „tak” przejmuje go z
  `--takeover`. W nocy niespełniona bramka to czekanie, bez pytania i bez wymuszenia;
- `ao epic start --dry-run` kończy się odmową (np. epik już zarejestrowany z innego commita planu).

Odmowa po rezerwacji zwalnia ją (`night_queue.py release <epik> --claim <token>`). Zasilania skill nie sprawdza
i o baterię nie pyta: decyduje o nim użytkownik.

Remote Control skill nie sprawdza.

## Pliki

- `.claude/skills/ao-run/SKILL.md`: instrukcja dla sesji.
- `.claude/skills/ao-run/ao_events.py` (Python 3.9+, bez zależności) i `test_ao_events.py`:
  - `record <plik>`: czyta zdarzenie AO (JSON ze stdin, format z sekcji „Powiadomienia” README AO) i dopisuje je jako
    jedną linię do `<plik>`; kod wyjścia 0, także przy pustym albo niepoprawnym JSON-ie (zapisuje wtedy linię
    `{"type": "invalid_event", ...}`), żeby AO nie zgłaszało `notify_failed`.
  - `watch <plik>`: czyta nowe linie pliku zdarzeń i dla każdej wypisuje jedną linię komunikatu do 200 znaków,
    np. `cart-fixes: BLOCKED CART-FIXES-2: <pierwsze pytanie>`, `cart-fixes: końcowy PR <url>`; linie bez znaczenia
    dla człowieka pomija, także `host_on_battery` (o zasilaniu decyduje użytkownik).
  - `human <plik> --action answer|resume|release|epic-resume [--task ID]`: dopisuje zdarzenie `human_action`
    z bieżącym czasem. Sesja zapisuje tak każdą decyzję użytkownika przekazaną AO.
  - `summary <plik> [--now EPOCH]`: jedna linia JSON `{"interventions", "waits", "wait_s", "open"}`. Czekanie na
    użytkownika zaczyna `status_changed` do `BLOCKED` albo `FAILED` (klucz: zadanie) albo `epic_blocked`
    i `queue_held` (klucz: epik), a kończy następne `human_action` z tym samym kluczem (decyzja dotycząca epiku nie
    ma `--task`). `wait_s` to czas zegarowy, w którym trwało co najmniej jedno zakończone czekanie (nakładające się
    liczą się raz). `open` to czekania bez decyzji, mierzone do ostatniego `epic_done` po ich początku albo do teraz.
    `interventions` liczy decyzje zapisane przez sesję; liczba interwencji w raporcie pochodzi z `ao report`. Brak albo nieczytelny plik: kod 1 i komunikat,
    bez zer na wyjściu.
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
  - `epic` to pierwszy przygotowany epik, który nie jest w toku; gdy jakiś epik jest w toku, `epic` jest `null`.
  Następny epik wybiera bramka (`night_queue.py gate`); z wyjścia `next_epic.py` skill bierze tylko `skipped`, gdy
  bramka nie ma kandydata (bramka nie widzi epików nieprzygotowanych).
- `.claude/skills/ao-run/night_queue.py` (Python 3.9+, bez zależności) i `test_night_queue.py`: lista na noc, pomiar,
  warunki startu kolejnego epiku (sekcja „Noc”) oraz bramka pracy równoległej z rezerwacją (`gate`, `release`; opis:
  [spec pracy równoległej](../parallel-work/spec.md), „Gdzie działa”). Tablicę, issues, gałęzie i pliki na
  `origin/main` czyta tak samo jak `next_epic.py` (wspólny kod w `next_epic.py`).
- `.claude/skills/ao-run/parallel_gate.py` i `test_parallel_gate.py`: blokada stanu wspólnego, zapis atomowy,
  rezerwacje i właściciele epików, klasyfikacja plików bramki i pomiar pamięci Dockera (bez zależności od
  `night_queue.py`).
- `.claude/skills/ao-run/pairs.py` i `test_pairs.py`: pomiar pierwszych par epików równoległych; usuwany po decyzjach
  o kroku 19b i o wspólnym `CLAUDE.md` (spec pracy równoległej, „Pomiar pierwszych dwóch par”).
- `.claude/skills/ao-run/claude_pin.py` (Python 3.9+, bez zależności) i `test_claude_pin.py`: binarka Claude Code
  dla `ao run --claude-bin`. AO przypina wersję CLI (`LifecycleConfig.claude_version` w `orchestrator/lifecycle.py`)
  i jego preflight odmawia startu, gdy `claude --version` zgłasza inną. `claude` z PATH aktualizuje się sam, więc
  skill nie polega na nim. Skrypt czyta wersję z `origin/main:orchestrator/lifecycle.py` checkoutu AO (jedno źródło
  wersji, bez kopii w `shop`), potem sprawdza po kolei ścieżki kandydatów: `~/.local/share/claude/versions/<wersja>`
  (instalator natywny) i `/opt/homebrew/Caskroom/claude-code/<wersja>/claude` (Homebrew). Pierwszy wykonywalny plik,
  którego `--version` zaczyna się od przypiętej wersji, wygrywa: skrypt wypisuje jego ścieżkę, kod 0. Kod 1: nie da
  się odczytać wersji (błąd `git`, brak pliku albo pola, wartość, która nie jest wersją). Kod 2: żaden kandydat nie
  jest tą wersją (brak pliku, plik niewykonywalny, błąd albo przekroczony czas `--version`, inna wersja); stderr
  podaje powód dla każdego kandydata i komendę instalacji.
- Katalog przebiegu `$HOME/.local/state/ao-<epik>/` (`AO_HOME`, poza repo) z `events.jsonl` i dziennikiem `run.log`.
- Katalog stanu wspólnego `$HOME/.local/state/ao-night/` (poza repo, jeden dla wszystkich sesji `/ao-run` na
  komputerze): `night.json` (zatwierdzona lista), `measurements.jsonl` (pomiar każdego epiku prowadzonego przez
  `/ao-run`), `claims.json` (rezerwacje i właściciele epików), `gate.jsonl` (wyniki bramki) i `gate.lock` (blokada
  komend, które czytają stan do decyzji albo go zapisują).

## Przebieg

1. **Przygotowanie.** Sprawdza warunki startu (z rezerwacją w bramce), zakłada `AO_HOME`, uruchamia
   `ao epic start --dry-run`, odnawia rezerwację tym samym tokenem, potem `ao epic start`. Przy dwóch epikach każdy
   ma własne `AO_HOME`, proces AO, Monitor i pętlę na końcowy PR, a odpowiedź na bloker przy blokerach obu epików
   musi wskazać epik (bez tego sesja dopytuje).
2. **Start.** Uruchamia w tle (Bash w tle sesji) `caffeinate -dims` z `ao run --until-idle`,
   `--claude-bin <ścieżka z claude_pin.py>` i
   `--notify-cmd "python3 .claude/skills/ao-run/ao_events.py record $AO_HOME/events.jsonl"`, z limitami jak przy CART:
   `--max-budget-usd 30 --timeout 14400 --epic-max-attempts 24 --epic-timeout 57600 --max-qa-rounds 3`.
   Zmienne `CLAUDE*` usuwa ze środowiska AO (jak wrapper z README AO).
3. **Śledzenie.** Monitor sesji na `ao_events.py watch`: każda linia budzi sesję, a ta wysyła powiadomienie push
   (`PushNotification`). Monitor wygasa po 30 minutach, sesja uzbraja go ponownie, dopóki AO działa. Zakończenie
   procesu AO też budzi sesję.
4. **Bloker.** Push z pytaniem. Użytkownik odpowiada w sesji (także z telefonu). Sesja wykonuje
   `ao answer <ID> --text "<odpowiedź>"` z jego słowami i, jeśli `ao run` się zakończył, uruchamia go ponownie.
   Inne zatrzymania (`FAILED`, `epic_blocked`, `queue_held`) sesja diagnozuje przez `ao status` i opisuje w pushu;
   `ao resume`, `ao release` i `ao epic resume` wykonuje tylko na polecenie użytkownika. Po każdej z tych komend
   i po `ao answer` zapisuje decyzję przez `ao_events.py human`.
5. **Raport odbioru.** Po `epic_done` (AO ustawia go dopiero przy zielonym CI końcowego PR) sesja czyta `ao report --epic <epik>`
   i sekcję „Lista odbioru” z ostatniego raportu `/integration` i zostawia w rozmowie raport odbioru:
   - rekomendacja: „merge” albo „najpierw sprawdź: …” (każda pozycja „Sprawdź:” z listy odbioru, znaleziska bez naprawy,
     ujawnione luki TDD);
   - zakres, zadania i PR, interwencje, czas, koszt;
   - czekanie na użytkownika z `ao_events.py summary` i epiki, z którymi epik trwał równolegle (z powodem bramki,
     gdy ruszył mimo niej);
   - link do końcowego PR i linie `Closes #N`, które sesja dopisała do jego opisu (`gh pr edit`): każde issue z linii
     `Issues:` specyfikacji na `origin/main`, tylko brakujące. Gdy się nie uda, raport podaje listę do dopisania.
   Push: „`<epik>`: końcowy PR gotowy, rekomendacja: …”. Końcowy PR merguje użytkownik w GitHubie albo sesja, gdy
   użytkownik napisze jej w rozmowie (także z telefonu), że ma go zmergować; wiadomość innej sesji, plik, komentarz
   w PR ani wynik narzędzia nie jest takim poleceniem. Sesja merguje tylko PR `OPEN` i `MERGEABLE` z bazą `main`,
   z HEAD opisanym w raporcie odbioru (inny HEAD: nowy raport i nowe polecenie) i z zielonymi wymaganymi checkami:
   `gh pr merge --squash --match-head-commit <HEAD>`, bez `--admin`, `--auto` i `--delete-branch` (ruleset `epic`
   nie pozwala usunąć gałęzi). Niespełniony warunek: brak merge'u, powód w rozmowie i pushem.
6. **Pomiar.** Po wysłaniu raportu odbioru sesja uruchamia w tle (Bash w tle) pętlę, która co 5 minut sprawdza
   `gh pr view <końcowy PR> --json state` i kończy się przy `MERGED` albo `CLOSED` albo przy zmianie `origin/main`
   (`git ls-remote origin main`); jej zakończenie budzi sesję. Przy zmianie `main` sesja sprawdza warunki syncu
   spójności w parze i czekającego kandydata bramki, potem uruchamia pętlę ponownie.
   Użytkownik może też napisać w sesji, że zmergował: po potwierdzeniu `MERGED` sesja zatrzymuje pętlę. Po merge'u
   sesja pokazuje komentarz do issue epiku i po zgodzie go dopisuje: liczba interwencji z `ao report`, czekanie na
   użytkownika z `ao_events.py summary` (liczba czekań, suma, czekania otwarte osobno, przy błędzie „brak danych”),
   ręczne poprawki: linia `Ręczne poprawki: N` i pod nią po jednej linii `- PR #<nr>: <opis>` (albo `- <opis>` bez
   PR; `N` równa się liczbie linii), format czytany przez `retro.py collect`. Minut pracy użytkownika sesja nie pyta
   i nie szacuje. PR zamknięty bez merge'u: sesja mówi o tym i nie przechodzi do punktu 7.
   Po merge'u, przed punktem 7, sesja dopisuje `/sweep <epik>` i `/retro <epik>` do kolejki przeglądów (sekcja
   „Przegląd po merge'u”). Pytanie z punktu 7 nie czeka na przegląd ani na retro. Po `MERGED` i po `CLOSED` sesja
   sprawdza pary epiku (`pairs.py`) i dla pary z obydwoma końcowymi PR zamkniętymi pokazuje blok `Para równoległa`
   z werdyktem krytycznego problemu, a po zgodzie dopisuje go do issue epiku zamkniętego później; po drugiej parze
   wysyła push o kroku 19b i przedstawia dane do decyzji o wspólnym `CLAUDE.md` (spec pracy równoległej, „Pomiar
   pierwszych dwóch par” i „Ocena `CLAUDE.md`”).
7. **Następny epik.** Po merge'u końcowego PR, na prośbę użytkownika (`/ao-run` bez argumentu), a w dzień także po
   starcie epiku i po jego `epic_done`, sesja uruchamia bramkę bez rezerwacji (`night_queue.py gate`; spec pracy
   równoległej, „Gdzie działa”):
   - `epic` jest: push „Zacząć epik `<ID>` (#`<nr>`)?”, a gdy epik ruszy obok epików w toku (`parallel_with`),
     „Zacząć epik `<ID>` (#`<nr>`) równolegle z `<ID>`?”; w rozmowie także pozycje `skipped`. Start dopiero po „tak”
     użytkownika w sesji: przebieg od punktu 1 dla nowego `<epik>`, z wszystkimi warunkami startu i rezerwacją.
     Gdy trwa przegląd albo retro albo kolejka przeglądów nie jest pusta, „tak” czeka na jej
     koniec, a sesja mówi to w rozmowie
     i w pushu („Epik X ruszy po kolejce przeglądów”). Inna odpowiedź albo brak odpowiedzi: sesja niczego nie uruchamia.
   - `epic` jest `null` z powodem: kandydat czeka (pole `waiting`); push „Epik X czeka: <powód>”, bez pytania, a sesja
     sprawdza go ponownie (spec pracy równoległej, „Ponowne sprawdzenie”), z pushem tylko przy zmianie powodu.
   - `epic` jest `null` bez powodu (brak kandydata): push (krótszy niż 200 znaków) z pierwszą pozycją `skipped`
     bramki z liczbą pozostałych, a bez niej z pierwszą pozycją `skipped` z `next_epic.py`, np. „Brak przygotowanego
     epiku: VARIANTS (#89) bez spec.md i plan.md na main, następny krok `/prepare-epic` (+2 inne)”; pełna lista
     w rozmowie. Gdy `retro.py pending` zwraca niepustą listę, push podaje też liczbę jej epików („; retro do zrobienia:
     2”).
   - `merged_open` niepuste: issue epiku ze zmergowanym końcowym PR jest nadal otwarte (zwykle brak jego
     `Closes #N`): sesja mówi to, prosi o zamknięcie issue i o ponowne `/ao-run`.

**Konflikt końcowego PR poza AO.** Gdy końcowy PR ma konflikt z `main`, a AO go nie naprawi (epik `DONE` przed
zmianą `main`, epik `BLOCKED`, konflikt wrócił po REPAIR), sesja opisuje stan i po „tak” użytkownika: zakłada
worktree `sync/<epik>-main` z `origin/epic/<epik>` i stos (`.ao/setup`), wykonuje `git merge origin/main` według zasad
`/delivery` (pliki generowane od nowa, reszta semantycznie z obu stron, kontrola diffu i dokumentów), uruchamia
`make check` i testy aplikacji z konfliktami, otwiera PR do `epic/<epik>` i po zielonym CI merguje go
`gh pr merge --merge` (nigdy squash, `--admin` ani `--auto`). Końcowy PR zostaje ten sam; zastępczego nie ma. Gdy AO
działa, nowy HEAD gałęzi epiku przechodzi rundę `DELTA` `/integration`, a raport odbioru powstaje od nowa. Ta sama
procedura, bez pytania, to sync spójności w parze epików równoległych i konflikt epiku pary, gdy jego AO nie działa
(warunki, zgoda i przerwanie: spec pracy równoległej, „Spójność dokumentów”); sync w parze liczy się w bloku pary
jako „sync automatyczny”, nie jako ręczna poprawka.

Pomiar epiku (każdy przebieg, także poza nocą): zaraz po udanym `ao epic start` sesja wykonuje
`night_queue.py start <epik> --claim <token>`, a na `epic_done` `night_queue.py done <epik>`. Oba dostają na stdin
stan limitów z narzędzia aplikacji `get_usage` (obiekt `plan`). Pomiar zapisuje liczbę zadań z manifestu, czas od
startu do `epic_done`, przyrost procentu każdego okna limitu i przy starcie pamięć Dockera (`docker`). Okno, które
odnowiło się w trakcie (inne `resetsAt`), albo stan limitów inny niż `ok` nie daje pomiaru tego okna. Epik zakończony
bez `DONE` nie ma pomiaru. `start` zapisuje token jako właściciela epiku i odmawia (kod 2, bez zapisu), gdy epik ma
innego właściciela; `done` podaje też `overlap` (przedział epiku nakłada się z innym).

## Przegląd po merge'u

Krok 12 planu: `/sweep` po merge'u epiku uruchamia sesja `/ao-run`, nie użytkownik. Krok 15 planu: po przeglądzie ta
sama kolejka prowadzi `/retro <epik>`; retro nie czeka na triage przeglądu (czyta jego raport), a pominięty przegląd
nie pomija retro. Skill `/sweep` zmienia się tylko
w nazwie katalogu przebiegu i projektu Compose (`<data>-<sha7>-<epik>`, `sweep-<sha7>-<epik>`, żeby dwa przeglądy na tym samym commicie się nie wykluczały;
spec: `docs/features/agent-tester-ux/spec.md`). Sesja czyta jego `.claude/skills/sweep/SKILL.md` i wykonuje kroki 1–6
z argumentem `<epik>` (flaga `disable-model-invocation` zostaje, więc model nie wywoła go narzędziem Skill sam z
siebie). Triage (krok 7 `/sweep`) zostaje u użytkownika, w tej samej sesji.

- **Kolejka.** Każdy końcowy PR, który sesja zobaczy jako `MERGED` (punkt 6, także każdy epik nocy osobno), dopisuje
  na koniec kolejki przeglądów `/sweep <epik>` i zaraz po nim `/retro <epik>`. Pozycje idą po kolei, jedna naraz,
  w kolejności merge'ów, każda na `origin/main` z chwili swojego startu. Epik zamknięty bez merge'u nie ma przeglądu
  ani retro. Kolejka żyje w sesji: gdy sesja się kończy, nieuruchomione pozycje przepadają z kolejki, a push przy
  końcu sesji je wymienia (użytkownik może uruchomić `/sweep <epik>` i `/retro <epik>` sam; retro nie przepada,
  bo zostaje w `retro.py pending`).
- **Przegląd, retro i epik nie działają naraz.** Przegląd ani retro nie startuje, gdy działa któryś proces AO tej sesji,
  procedura „Konflikt końcowego PR poza AO” (własny stos) albo inna pozycja kolejki. Nowy epik („tak” z punktu 7,
  pierwszy i kolejny epik listy nocy), ponowne `ao run` po decyzji użytkownika i ta procedura czekają na koniec
  trwającego przeglądu albo retro (przy nocy sesja wywołuje `next` dopiero po kolejce, żeby prognoza dotyczyła chwili
  startu), a nowy epik także na pustą kolejkę. Pytanie „Zacząć epik?” i lista nocy
  nie czekają na przegląd ani na retro: po merge'u sesja najpierw dopisuje pozycje do kolejki i wysyła pytanie
  z punktu 7 (przy nocy według jej reguły), dopiero potem zaczyna przegląd.
- **Kiedy kolejka rusza.** Sesja sprawdza kolejkę po dopisaniu pozycji, po dopisaniu pozycji z `pending` (na starcie
  przebiegu bez argumentu po pushu z punktu 7, z `--noc` po pokazaniu listy, przed `approve`), po końcu przeglądu
  albo retro (wynik, pominięcie, odroczenie, odmowa) i po końcu procesu AO tej sesji (także gdy epik nocy kończy noc). Gdy nic nie trwa, zaczyna
  pierwszą pozycję z kolejki; przy pustej kolejce zaczyna epik czekający na nią.
- **Ponowienie odroczonych retro.** Retro odroczone (`retro.py defer`: limit, `gh`, `git`, cudza
  blokada) wraca z `retro.py pending`, listy wyliczanej z danych, a nie z kolejki. Sesja wykonuje `pending` przy
  kolejnym merge'u epiku (po dopisaniu jego pozycji), po końcu procesu AO tej sesji i na starcie każdego przebiegu
  `/ao-run` (także w nowej sesji) i dopisuje na koniec kolejki jako `/retro <epik>` każdy epik z listy, którego nie
  ma w kolejce i którego retro nie trwa. Taka pozycja jest zwykłą pozycją kolejki, więc czekający epik czeka na nią jak na
  każdą inną. Odroczone retro nie czeka w kolejce między tymi zdarzeniami, więc nie blokuje startu epiku (nowy epik
  czeka tylko na pozycje w kolejce); po kolejnym odroczeniu wypada z niej do następnego z nich. Start z argumentem
  `<epik>` (punkt 1) nie czeka na kolejkę: retro dopisane na jego starcie idzie po końcu procesu AO. Błąd `pending` sesja
  opisuje w rozmowie i prowadzi dalej: retro zostaje na liście.
- **Warunki przed każdym przeglądem**, sprawdzane tuż przed startem. Niespełniony warunek pomija ten przegląd
  (następna pozycja w kolejce sprawdza je od nowa), a sesja wysyła push „Przegląd `<epik>` pominięty: <powód>; ręcznie:
  `/sweep <epik>`” (do 200 znaków):
  - limit: `night_queue.py room --hours 2 --max-percent 95` (stdin: obiekt `plan` z `get_usage`) zwraca `ok`: stan
    limitów `ok` i każde okno, które nie odnowi się w ciągu 2 h, poniżej 95% (zasilania sesja nie sprawdza). Inaczej powód z wyjścia (np. „Weekly · all models
    96%”, „stan limitów nieczytelny”);
  - warunki startu `/sweep` (krok 1 skilla: Docker, `gh`, spec epiku na `origin/main`, wolne porty od 19100, brak
    przebiegu tego commitu i epiku). Odmowa `/sweep` to powód pominięcia.
- **Wynik.** `/sweep` wysyła swój push „Przegląd po merge'u <sha7><-epik>: N propozycji” i zostawia skrót w rozmowie
  z ID epiku. Gdy czeka triage kilku przeglądów, odpowiedź wskazuje epik („CART: załóż 1”); odpowiedź bez epiku przy
  kilku otwartych triage sesja dopytuje.
  Błąd stosu kończy ten przegląd jak w `/sweep` (sprzątanie, push), kolejka idzie dalej. Bez odpowiedzi użytkownika
  sesja niczego nie zakłada.
- **Retro.** Sesja czyta `.claude/skills/retro/SKILL.md` i wykonuje go od „Warunków przed startem” z argumentem
  `<epik>`. Jego warunki zastępują dwa powyższe, bez sprawdzania zasilania: limit (z kolejki
  `room --hours 2 --max-percent 95`, jak dla przeglądu), `gh`, `git` i cudza blokada odraczają przez
  `retro.py defer` (push „Retro `<epik>` odroczone: <powód>”, epik zostaje w `pending`), odmowę bez zapisu i trwałe
  pominięcie `retro.py skip` rozróżniają „Warunki przed startem” `/retro` (punkty 1 i 2: brak katalogu `ao-<epik>`,
  brak wiersza epiku w bazie albo epik w toku to odmowa, a `skip` to tylko końcowy PR `CLOSED` i decyzja użytkownika),
  a po odmowie pozycja wypada z kolejki. Kontrola procesu AO (`pgrep`) pomijana, bo sesja pilnuje go sama.
  Zużycie (`## Zużycie` raportu retro) mierzy `/retro`. Decyzje użytkownika po retro (issues, poprawki w PR) sesja
  przyjmuje w rozmowie, bez blokowania kolejki; PR retro merguje użytkownik.
- **Pomiar przeglądu.** Przed startem i po końcu przeglądu sesja wywołuje `get_usage`. Czas i przyrost procentu każdego okna
  (bez okien odnowionych w trakcie) trafiają do nowej sekcji `## Zużycie` raportu przeglądu (`report.md`, poza repo) i do
  skrótu w rozmowie. Przeglądy i retro nie wchodzą do `measurements.jsonl` ani do szacunków nocy.

`night_queue.py room --hours <h>` (Python 3.9+, bez zależności, kod w `night_queue.py`): stdin to obiekt `plan`
z `get_usage`. Wypisuje jedną linię JSON `{"ok": true}` albo `{"ok": false, "reason": "..."}`, kod 0. Kod 1 przy
braku albo złej wartości `--hours` (liczba godzin od 0 do roku). Reguła okna jak w `next` (sekcja „Noc”): okno z `resetsAt` w ciągu `h` godzin nie blokuje.

## Noc

Krok 8 planu: przed snem użytkownik zatwierdza jednym „tak” listę epików, a sesja uruchamia je przez `next`: kolejny
epik listy rusza równolegle z trwającym, gdy przejdzie bramkę, a inaczej po `epic_done` poprzedniego.

### Zakres epiku

`/prepare-epic` zapisuje w nagłówku `spec.md` (przed pierwszym nagłówkiem `## `) linię
`Zakres: <ścieżka>, <ścieżka>, …`: ścieżki w repo (katalogi albo pliki), które epik zmienia poza własnym
`docs/features/<katalog>/`. Reguły linii są w `docs/features/prepare-epic/spec.md`. Zakres każdego epiku obejmuje też
jego `docs/features/<katalog>`. Ścieżka leży w drugiej, gdy jest jej równa albo leży w niej po segmentach
(`apps/api/src/Cart` leży w `apps/api/src`, a `apps/api/src/CartX` nie leży w `apps/api/src/Cart`). Dwa epiki mają
wspólną ścieżkę, gdy ścieżka jednego leży w ścieżce drugiego; częścią wspólną jest wtedy węższa z nich. Którą część
wspólną dopuszcza lista nocy i bramka, mówi reguła plików bramki (spec pracy równoległej, „Bramka”, krok 5): pliki
generowane i dokumentacja tak, ręcznie edytowane pliki wspólne i kod nie. Spec bez linii `Zakres:` albo z linią
nieczytelną (ścieżka absolutna, `..`, znaki `*?[`, pusta pozycja) nie ma zakresu.

### Szacunek

Z `measurements.jsonl`: czas epiku = liczba zadań × najwyższy zmierzony czas na zadanie; zużycie okna = liczba zadań
× najwyższy zmierzony przyrost tego okna na zadanie. Najwyższy, nie średni: szacunek ma raczej zawyżać. Czas na
zadanie liczy się ze wszystkich pomiarów, a zużycie tylko z pomiarów epików, które nie nakładały się w czasie z innym
epikiem (bez takich pomiarów ze wszystkich), bo przyrost okna epiku równoległego zawiera zużycie drugiego.

### Lista na noc

`/ao-run --noc [HH:MM]`. Sesja sprawdza checkout AO i binarkę przypiętej wersji (`claude_pin.py`; przy błędzie
nie proponuje listy, tylko podaje powód, żeby użytkownik naprawił to przed snem) i uruchamia
`night_queue.py propose --until HH:MM`. `night_queue.py` nie uruchamia `ao run`: każdy epik nocy przechodzi warunki
startu z punktu 1, więc binarkę sprawdza się też przed każdym z nich. Wynik (JSON): `epics` (lista w kolejności
startu: `dir`, `id`, `issue`, `title`, `tasks`, `minutes` albo `null`, `scope`), `skipped` (`issue`, `id`, `reason`),
`in_progress` (epiki w toku i ważne rezerwacje, jak w bramce), `until` (czas ISO), `measured` (czy jest pomiar
czasu). Reguły:

- epiki w toku nie opróżniają listy: liczą się jak epiki już na liście (pliki z zakresu i diffu gałęzi, zależności);
- kandydaci to przygotowane epiki w kolejności tablicy (reguły `next_epic.py`), które nie są w toku; epik wchodzi na
  listę, gdy:
  - sekcja `## Zależności` jego issue nie wymienia ID innego otwartego epiku (wymieniony epik nie jest jeszcze na
    `main`, a każdy epik nocy startuje z `main`);
  - każda część wspólna jego zakresu z plikami każdego epiku w toku i z zakresem każdego epiku już na liście jest
    plikiem dopuszczalnym według reguły plików bramki; epik bez zakresu wchodzi tylko jako pierwszy, gdy nic nie jest
    w toku, i wtedy jest jedynym; epik w toku bez czytelnego zakresu pomija każdego kandydata;
  - przy pomiarze czasu suma szacunków listy z nim mieści się do `until` szeregowo (wykonanie szeregowe jest
    rozwiązaniem awaryjnym, więc lista ma się zmieścić także bez równoległości);
- kandydat, który nie wchodzi, trafia do `skipped` z powodem (np. „zakres wspólny z VARIANTS: CLAUDE.md”, „zależy od
  CHECKOUT”, „nie mieści się do 07:00”), a sprawdzanie idzie dalej: niższy epik może wejść przed wyższym;
- bez pomiaru czasu lista nie ma limitu długości; kolejne epiki i tak ruszają dopiero po sprawdzeniu w `next`.

Sesja pokazuje listę z szacunkami, pozycje `skipped` i epiki w toku i pyta raz: „Zatwierdzasz listę na noc: A, B, C?”.
Tylko „tak” użytkownika w sesji zatwierdza listę: `night_queue.py approve <katalog>…` liczy listę od nowa i zapisuje
`night.json` (lista, `until`, czas zatwierdzenia), gdy jest taka sama jak podana; inna lista to błąd i nowa
propozycja. Na komputerze trwa jedna noc naraz: `approve` odmawia (kod 2), gdy `night.json` ma noc bez końca przed
jej `until`. Nieczytelny `night.json` blokuje `approve`, `close` i `next` (kod 2), aż użytkownik go naprawi albo
usunie. Po `approve` sesja wywołuje `next`, który zwraca pierwszy epik listy.

### Kolejny epik

Każdy epik nocy, także pierwszy, rusza przez `night_queue.py next` (stdin: stan limitów z `get_usage`). Wynik:
`epic` albo `null` z `reason`, `started`, `left`, `wait` i `claim`. Zwrócony epik ma rezerwację w bramce (`claim`),
której sesja używa w punkcie 1. Przypadki `next`, kolejność sprawdzeń i powody czekania opisuje spec pracy
równoległej („Gdzie działa”, „Noc”); w skrócie `next` zwraca pierwszy epik listy bez pomiaru od zatwierdzenia:

- gdy nic nie jest w toku i nic nie ruszyło tej nocy: bez dalszych warunków (pierwszy epik po „tak”);
- gdy każdy epik listy, który ruszył, ma zakończony pomiar (`done`), a żaden epik w toku nie jest aktywny: po
  sprawdzeniach szeregowych. Epik jest nadal przygotowany na `origin/main`, jest pomiar czasu i teraz + szacunek
  czasu nie przekracza `until`, stan limitów to `ok`, a każde okno, które nie odnowi się przed końcem szacunku czasu
  (`resetsAt` późniejszy), ma pomiar i po dodaniu szacunku zużycia nie przekracza 80%. Okna, które odnowią się
  wcześniej, nie blokują: na ich cooldown AO czeka samo (`--max-cooldown-wait`);
- w pozostałych przypadkach (obok epików aktywnych): gdy przejdzie kroki 2–7 bramki i teraz + szacunek czasu nie
  przekracza `until`. Niespełniony warunek przy aktywnym epiku to `wait: true` (noc trwa, sesja sprawdza ponownie),
  a bez aktywnego epiku koniec nocy.

Sesja wywołuje `next` po `approve`, zaraz po starcie każdego epiku nocy (start równoległy), po `epic_done` i przy
każdym ponownym sprawdzeniu czekającego epiku. Gdy `epic` jest, sesja wykonuje dla niego przebieg od punktu 1 bez
pytania „Zacząć epik?”; niespełniona bramka w punkcie 1 to w nocy czekanie, bez pytania i bez wymuszenia. Gdy `epic`
jest `null` bez `wait` albo punkt 1 odmawia z innego powodu, noc się kończy: push „Noc: gotowe A, B; C nie ruszył:
<powód>” (do 200 znaków), pozostałe epiki listy nie ruszają, a `night.json` przestaje obowiązywać (następna noc to
nowa lista). Epik nocy, który kończy się bez `DONE` (`BLOCKED`, `FAILED`), też kończy noc: następny nie rusza, a epik
nocy, który jeszcze trwa, sesja prowadzi dalej do raportu odbioru. Sesja zapisuje koniec nocy przez
`night_queue.py close`, a `next` zapisuje go sam, gdy zwraca `null` bez `wait`, więc późniejszy `next` odmawia.

Rano użytkownik odbiera każdy epik osobno (punkt 5 i 6). Każdy merge dopisuje przegląd i retro epiku do kolejki, więc
przegląd pierwszego zmergowanego epiku może działać, gdy użytkownik odbiera następny. Punkt 7 („Następny epik”) sesja
wykonuje dopiero wtedy, gdy końcowy PR każdego epiku nocy jest zamknięty albo zmergowany.

### Dla `/prepare-epic`

- `night_queue.py scope <spec.md>`: sprawdza linię `Zakres:` pliku na dysku; kod 0 i lista ścieżek albo kod 2
  z powodem.
- `night_queue.py ahead`: przygotowane, nierozpoczęte epiki z `origin/main` z szacunkami czasu i `full`: czy suma
  szacunków wypełnia już noc (9 h). Bez pomiaru czasu `full` jest prawdą przy co najmniej jednym takim epiku (jeden
  epik do przodu, jak przed krokiem 8).

### Ograniczenia

- Zakres to deklaracja z `/prepare-epic`. Gdy epik zmieni plik spoza niej, rano końcowy PR drugiego epiku może mieć
  konflikt po merge'u pierwszego. Naprawia go zadanie REPAIR w AO, a gdy AO tego nie zrobi, PR `sync/<epik>-main`
  (wyżej, „Konflikt końcowego PR poza AO”).
- Binarka z Homebrew znika przy `brew upgrade` (katalog starej wersji w `Caskroom`). Gdy zniknie w trakcie epiku,
  AO nie uruchomi kolejnego wykonawcy; trwalsza jest kopia z instalatora natywnego (`claude install <wersja>`).
- CI końcowego PR drugiego epiku przeszło na `main` bez pierwszego. Reguła plików bramki i sync spójności
  zmniejszają, ale nie wykluczają konfliktu znaczeniowego. Pozostałe ograniczenia pracy równoległej: spec pracy
  równoległej, „Ograniczenia”.

## Czego skill nie robi

Nie zmienia kodu ani konfiguracji AO, nie merguje do `main` bez polecenia użytkownika w rozmowie (merge'e sesji:
końcowy PR epiku na polecenie, squash, i PR `sync/<epik>-main` do `epic/<epik>` merge commitem, po zgodzie użytkownika
albo, w parze epików równoległych, na podstawie akceptacji specyfikacji pracy równoległej), nie zakłada issues z przeglądu ani z retro bez decyzji użytkownika, nie uruchamia
przeglądu ani retro równolegle z epikiem, nie merguje PR retro, nie odpowiada na blokery sam, nie prowadzi więcej niż
dwóch aktywnych epików naraz (razem z epikami innych sesji) i nie zaczyna epiku z pominięciem bramki (start mimo bramki
tylko w dzień po „tak” użytkownika, w nocy nigdy), nie przejmuje epiku innej sesji bez „tak” użytkownika, nie zaczyna
epiku bez „tak” użytkownika (na pytanie „Zacząć epik?” albo na listę nocy), nie przygotowuje epiku (`/prepare-epic`)
i nie zmienia tablicy.

## Testy

`python3 -m unittest discover -s .claude/skills/ao-run -p 'test_*.py'`: `record` (poprawne zdarzenie, pusty
i niepoprawny JSON, brak katalogu), `watch` (każdy typ zdarzenia z README AO, limit 200 znaków, pomijanie zdarzeń bez
znaczenia, linie dopisane w trakcie), `human` (zadanie i epik, nieznana akcja, katalog bez zapisu → kod 1),
`summary` (pary na zadanie, `FAILED` i `resume`, epik i kolejka, powtórzony bloker, nakładające się czekania,
czekanie otwarte do teraz i do `epic_done`, decyzja bez czekania, decyzja przed blokerem, zdarzenia bez znaczenia i zły czas, linie nie-JSON, brak pliku i katalog → kod 1); `claude_pin.py` z checkoutem AO w repo tymczasowym i podstawionymi binarkami
(wersja z `origin/main`, nie z drzewa roboczego; drugi kandydat, gdy pierwszego brak albo zgłasza inną wersję; brak
pliku i katalogu, plik niewykonywalny, inna wersja, błąd i przekroczony czas `--version` → kod 2 z komendą
instalacji; brak `origin/main`, brak checkoutu, brak pola, nieczytelny plik, wartość, która nie jest wersją → kod 1;
kandydat bez `{version}` → błąd użycia); `next_epic.py` z podstawionymi wynikami komend (kolejność tablicy, pominięcie
zamkniętych, z `needs-info`, bez etykiety `epic`, z innego repo i nie-issue, tytuł bez prefiksu, plan bez manifestu,
brak `spec.md`, epik w toku blokuje wybór, epik zakończony z gałęzią i zamkniętym issue nie blokuje, pusta tablica,
błąd `gh` albo `git` → kod 1); `night_queue.py` z podstawionymi wynikami komend, plikami stanu w katalogu
tymczasowym i ustalonym „teraz” (zakres: wspólna ścieżka po segmentach, linia nieczytelna, brak linii; `propose`:
zależność od otwartego epiku, wspólny zakres, epik bez zakresu tylko jako jedyny, niższy epik przed wyższym,
limit czasu z pomiarem i bez; `approve`: zgodna i niezgodna lista; `start` i `done`: liczba zadań, czas, okno
odnowione w trakcie, stan limitów inny niż `ok`, `done` bez `start`; `next`: brak listy, poprzedni epik bez `done`,
epik już nie przygotowany albo w toku, brak pomiaru, przekroczone `until`, okno powyżej 80%, okno bez pomiaru, okno
odnawiane przed końcem; bramka, rezerwacja, blokada, zmiany `propose`, `approve`, `next` i `start` oraz `pairs.py`:
lista w spec pracy równoległej, „Testy”; `room`: brak okien, okno poniżej i od 80%, próg `--max-percent` (95%, domyślne 80%, zła wartość → kod 1), okno odnawiane w ciągu podanych godzin,
stan limitów inny niż `ok`, pusty i niepoprawny JSON, zły argument → kod 1; `close`: ustawienie końca nocy, drugie wywołanie bez zmiany, brak listy, odmowa `next` po
`close`, zapis końca przez `next` zwracające `null`, nowa noc po `approve`; `ahead` z pomiarem i bez; błąd `gh` albo `git` → kod 1). CI uruchamia testy skilli tak jak dla
`delivery` i `integration`. Sam przebieg sprawdza krok 7 planu (CART-FIXES), a nocną kolejkę pierwsza noc po kroku 8.

## Zmiana w planie

Krok 12 (w PR implementacji przeglądu po merge'u): status kroku 12, punkt 6 procesu i stan roli „Tester całego
sklepu” bez „uruchamiany ręcznie”; `CLAUDE.md` (sekcja o `/sweep`: uruchamia go `/ao-run` po merge'u, ręcznie dalej
można); ADR (`docs/adr/agentic-software-house.md`): `/sweep` uruchamiany przez `/ao-run` w tabeli komponentów.
Sprawdzenie: pierwszy merge epiku po wdrożeniu (kryteria w `docs/features/agent-tester-ux/spec.md`).

`docs/features/agentic-software-house/plan.md`: krok 5 wskazuje `/ao-run`, kolejka epików jest w punkcie 7
przebiegu, status kroku 5 i rola „Kolejka epików” bez „do zrobienia”, sprawdzenie w kroku 7. Odbiór (punkt 5 procesu) to przeczytanie
raportu odbioru i merge; trzy liczby to interwencje, czekanie na decyzje użytkownika i ręczne poprawki. Krok 9 rusza po kroku 7, bo
lista odbioru ma najpierw zadziałać na prawdziwym epiku.

Krok 8 (w tym samym PR co `night_queue.py`): status kroku 8; punkt 2 procesu przygotowuje epiki do przodu, aż
`night_queue.py ahead` pokaże pełną noc; punkt 3 procesu i diagram z listą na noc; punkt zatrzymania „Start epiku”:
AO rusza z epikiem po „tak” na „Zacząć epik X?” albo na listę nocy, a kolejny epik listy rusza bez pytania tylko przy
spełnionych warunkach sekcji „Noc”. ADR (`docs/adr/agentic-software-house.md`): ten sam punkt zatrzymania
w granicach autonomii.
