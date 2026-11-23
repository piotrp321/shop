# ASH-V1: Agentic Software House v1.0

Stabilna wersja procesu, w której epiki prowadzi główna sesja Claude Code z natywnymi subagentami, a AO pozostaje
zamrożonym rozwiązaniem awaryjnym. Zostają `/brainstorm`, `/planner`, `/retro`, obecni agenci i szczegółowe metryki.
Ta specyfikacja opisuje tylko zmiany potrzebne, żeby trzy kolejne epiki aplikacji dało się przeprowadzić bez
przebudowy procesu i ocenić metrykami oraz retrospektywami. Przebieg epiku opisuje `docs/adr/agentic-software-house.md`
(„Przebieg epiku”), pomiar `docs/features/metrics/spec.md`, retrospektywę `docs/features/retro/spec.md`.

Issues: #765, #679, #762
Zakres: `CLAUDE.md`, `tools/metrics`, `.claude/skills/retro`, `.claude/agents/retro-analyst.md`, `.claude/agents/qa-adversarial.md`, `docs/adr/agentic-software-house.md`, `docs/features/metrics/spec.md`, `docs/features/retro/spec.md`, `docs/features/telemetry/spec.md`, `docs/features/agentic-software-house/plan.md`, `apps/api/Makefile`, konfiguracja testów `apps/api`

## Cel i zakres

- Epik prowadzony natywnie ma rekord metryk w tym samym formacie co epik AO i da się go porównać z historią (M).
- `/retro` przyjmuje epik natywny i nie zmienia procesu w trakcie serii trzech epików (R).
- Luki procesu zaobserwowane w SMALL-FIXES są zamknięte (F).
- Przegląd całej zmiany zaczyna od sprawdzenia kryteriów akceptacji, a wynik widać w opisie końcowego PR (A).
- Telemetria w lokalnym stosie działa także dla sesji natywnych i pozwala rozpoznać epik, zadanie, model
  i subagenta (T).
- Wersja 1.0 ma znacznik i regułę zamrożenia na czas serii (V).

## Decyzje

- **D1. Jeden format rekordu.** Epik natywny dostaje rekord w `<state>/epics/<EPIK>.json` w dzisiejszym formacie
  z polem `source` (`native`; rekordy AO: `ao`). Pole bez źródła w przebiegu natywnym ma `null` i wpis w `gaps`,
  zgodnie z regułą „brak źródła daje `null`, nigdy zero”. Osobnego narzędzia ani drugiego formatu nie ma.
- **D2. Punkt odniesienia zapisuje narzędzie.** Początek i koniec epiku zapisują komendy `metrics.py start`
  i `metrics.py done`; opis PR powtarza je w formie czytelnej dla człowieka.
- **D3. Wartość w USD z cennika API w konfiguracji.** Transkrypt sesji natywnej nie ma kosztu policzonego przez CLI.
  `tools/metrics/config.json` dostaje cennik: dla każdego modelu osobna stawka za milion tokenów każdego rodzaju
  (wejście, wyjście, odczyt cache, zapis cache 5 min, zapis cache 1 h), ze źródłem i datą odczytu. Stawki są
  wpisywane z aktualnego cennika dostawcy przy implementacji, nie wyliczane z proporcji `token_weights`. Wartość jest
  zawsze oznaczona jako szacunek. Model albo rodzaj tokenów bez stawki daje `null` i wpis w `gaps`.
- **D4. Zadanie z opisu zlecenia.** Przebieg subagenta dzieli się na zlecenia (start i każde wznowienie); zadanie
  zlecenia pochodzi z ID w `description` wywołania (start) albo w `summary` wiadomości wznawiającej; rola to
  `agentType`. Start bez ID trafia do pozycji „bez zadania”, wznowienie bez ID do „zadania nieustalonego”. Reguły:
  `docs/features/metrics/spec.md`, „Rola i zadanie (D4)”.
- **D5. `/retro` czyta rekord i opis końcowego PR.** Incydenty epiku natywnego pochodzą z rekordu metryk i z sekcji
  opisu końcowego PR. Progi, limity, rejestry i walidacja propozycji zostają bez zmian.
- **D6. Zamrożenie na czas serii.** W trakcie serii `/retro` zapisuje propozycje, ale nie wprowadza poprawek procesu.
  Wyjątek: poprawka blokady albo zagrożenia, po „tak” użytkownika wskazującym pozycję.
- **D7. Odbiór przed szukaniem błędów.** Przegląd całej zmiany zaczyna od kryteriów akceptacji specyfikacji; wynik jest
  tabelą w opisie końcowego PR.
- **D8. Znacznik wersji.** Po merge'u zmian z tej specyfikacji `main` dostaje tag `ash-v1.0`. Seria to trzy epiki:
  ABUSE-LIMITS (#181), ASYNC-RELIABILITY (#768), DELIVERY etap 1 (#120).

## Poza zakresem

- Uprawnienia, tokeny i rulesety GitHuba. Sesje agentów działają dalej z tokenem właściciela; to ryzyko przyjęte przez
  właściciela na czas v1.0 (2026-11-18), a #570 zostaje otwarte.
- Reguła rozmiaru zadania w `/planner` (#639) i archiwizacja AO (krok P6 planu).
- Zmiany reguł projektu z audytu uproszczeń, redukcja agentów i skilli, hooki, nowe narzędzia pomiaru.
- Błędy pomiaru dotyczące tylko danych AO (#703, #704) i eksport rekordów do stosu telemetrii (#689).
- `/sweep`, `/delivery`, `/integration` i `/ao-run`: bez zmian, zamrożone.

## M: metryki epiku natywnego

### M1. Plik przebiegu

`metrics.py start <EPIK>` zapisuje `<state>/runs/<EPIK>.json`: czas startu, SHA `origin/main`, gałąź epiku, numer
draft PR, katalog roboczy i identyfikator sesji prowadzącej, liczbę zadań manifestu `plan.md` i odczyt limitów podany
na wejściu (obiekt z `get_usage`, jak dziś w `night_queue.py start`). `metrics.py done <EPIK>` dopisuje czas
oznaczenia PR jako gotowy i drugi odczyt limitów. Wznowienie epiku w nowej sesji dopisuje jej identyfikator
i katalog (`start --resume`).

Identyfikator sesji prowadzącej `start` bierze z najnowszego transkryptu w katalogu sesji dla bieżącego katalogu
roboczego; opcja `--session` podaje go wprost. Opcje `--at`, `--worktree`, `--branch`, `--pr`, `--tasks`
i `--session` pozwalają zapisać plik po fakcie, także gdy `plan.md` już nie istnieje; bez odczytu limitów koszt
subskrypcji ma `null` i wpis w `gaps`.

### M2. Wybór źródła

`metrics.py epic <EPIK>` buduje rekord z przebiegu AO, gdy istnieje katalog `ao-<EPIK>`; inaczej z pliku przebiegu
natywnego. Gdy nie ma żadnego, kończy się kodem 2 z komunikatem, którego źródła brakuje. `backfill` dotyczy dalej
tylko przebiegów AO.

### M3. Pola rekordu natywnego

Transkrypty epiku to pliki sesji zapisanych w pliku przebiegu, razem z ich subagentami. Inna sesja w tym samym
katalogu roboczym nie należy do epiku. Liczą się wiadomości od startu do merge'u końcowego PR; praca tej samej sesji
po merge'u nie wchodzi do rekordu. Każda wiadomość jest liczona raz: gdy ten sam identyfikator wiadomości występuje
w kilku plikach (sesja wznowiona albo rozgałęziona), liczy się jego pierwsze wystąpienie.

| Pole | Źródło natywne | Gdy brak |
|---|---|---|
| `tasks`, `started_at`, `done_at` | plik przebiegu | `null`, wpis w `gaps` |
| `final_pr`, `issue`, `merged_at`, `wall` | numer PR z pliku przebiegu i GitHub | jak dziś |
| `value` (`total`, `by_role`, `by_model`, `by_task`, `by_role_model`, `by_task_role`) i tokeny | `message.usage` wiadomości `assistant`; rola prowadzącego `orchestrator`, subagenta `agentType`; zadanie według D4; cena według D3 | `null` dla modelu bez ceny |
| `attempts` | jeden wpis na przebieg subagenta: zadanie, rola, model, effort, czas realizacji (suma czasu zleceń) i odstępy między zleceniami, zlecenia, tokeny, wartość, czy zakończony | – |
| `by_task` | suma czasu zleceń, liczba przebiegów i liczba zleceń na zadanie | – |
| `breakdown` | `work`: suma przedziałów, w których działa prowadzący albo subagent; `human_wait`: przerwy zakończone wiadomością człowieka; `idle`: reszta czasu do `done_at` | `limit_wait`, `ci_wait`, `host_pause`, `github_wait`: `null`; `breakdown_incomplete: true` |
| `human` | `interventions`: liczba wiadomości człowieka po starcie, bez polecenia startu; `waits`, `wait_s` z przerw jak wyżej; lista zdarzeń z czasem i długością czekania, bez treści; `manual_fixes` z `--manual-fixes` | – |
| `integration` | czas i liczba przebiegów ról `architecture-reviewer`, `qa-adversarial`, `ux-reviewer`; `rounds`: największa liczba raportów jednej z tych ról | `null` bez takich przebiegów |
| `retries` | `fix`: liczba przeglądów zadań z werdyktem blokującym; `failed_by_outcome`: przebiegi subagentów niezakończone | `repair`, `sync_prs`: `null` |
| `reviews` (nowe) | lista przeglądów według M4 | pusta |
| `acceptance` (nowe) | liczby z tabeli kryteriów według M5 | `null`, wpis w `gaps` |
| `subscription` | przyrost każdego okna między odczytami `start` i `done`; `share`: udział tokenów epiku w tokenach wszystkich lokalnych sesji z tego przedziału, ze wszystkich projektów w `~/.claude/projects` (limity są na konto), oznaczony jako szacunek; użycia z innych urządzeń nie widać | `null` bez odczytów; okno, które odnowiło się między odczytami, ma `null` i wpis w `gaps` |
| `ci` | przebiegi CI gałęzi epiku z pliku przebiegu; `tasks`: `null` | – |
| `in_attempts`, `versions` | polecenia powłoki i wersja CLI z transkryptów | – |
| `regressions`, `found_after_merge`, `bottlenecks` | jak dziś | – |
| `parallel`, `gate_wait`, `waste` | – | `null`, wpis w `gaps` |

`interventions` liczy każdą wiadomość człowieka, także pytanie o postęp, więc jest górną granicą interwencji
koniecznych; rekord oznacza ją jako szacunek. Rekord nie zawiera treści wiadomości ani zleceń.

### M4. Przeglądy

Dla przebiegów ról przeglądowych rekord zapisuje każdy raport: zadanie, identyfikator i przypisanie zlecenia, w którym
jest raport, rolę, tryb, werdykt i czy blokuje. Raport to treść, którą subagent oddaje sesji prowadzącej na końcu pracy
(wywołanie `SubagentHandback`). Tryb: `FULL` dla pierwszego raportu przebiegu z danym zadaniem, `DELTA` dla kolejnych,
`null` w zleceniu z zadaniem nieustalonym (`docs/features/metrics/spec.md`, „Przeglądy (M4)”). Werdykty według kontraktów agentów: `code-reviewer` `PASS` / `CHANGES_REQUIRED`, `qa-adversarial` `PASS` /
`INVESTIGATE` / `BLOCK`, `architecture-reviewer` `KEEP` / `SIMPLIFY` / `REWORK`. Blokują `CHANGES_REQUIRED`, `BLOCK`
i `REWORK`. Raport bez rozpoznanego werdyktu (dziś `ux-reviewer`) ma werdykt `null` i wpis w `gaps`. Rekord zapisuje
sam werdykt, bez treści raportu.

### M5. Kryteria akceptacji

Rekord czyta tabelę „Kryteria akceptacji” z opisu końcowego PR (A2) przez bramkę pochodzenia i zapisuje same
liczby: wszystkie, sprawdzone na stosie, sprawdzone w CI, do sprawdzenia po wdrożeniu, niezweryfikowane.

### M6. Trend i raport

`trend` i `render` pokazują rekordy AO i natywne w jednej tabeli z oznaczeniem źródła. Kolumny bez danych w rekordzie
natywnym mają „brak danych”, nie zero. Przed serią historię AO uzupełnia jednorazowe `metrics.py backfill`.

Mediany, pozycję epiku na tle historii i wąskie gardła liczy się osobno dla każdego źródła. Między źródłami
porównywalne są tylko pola o tej samej definicji: liczba zadań, czas do gotowego PR i do merge'u, CI końcowego PR,
regresje. Pozostałe pola mają przy rekordzie natywnym oznaczenie, że liczy się je inaczej niż w AO: wartość w USD
(cennik zamiast kosztu z CLI), interwencje (każda wiadomość człowieka zamiast odpowiedzi na blokadę), rozkład czasu,
rundy przeglądu i koszt subskrypcji. Raport nie zestawia ich jako równoważnych.

### M7. Testy i dane testowe

Testy używają syntetycznych transkryptów z samymi polami, które czyta narzędzie. Treść prawdziwych transkryptów nie
trafia do repozytorium. Format transkryptów nie jest stabilnym interfejsem CLI: pole, którego brakuje, daje `null`
i wpis w `gaps`, nie błąd.

## R: `/retro` dla epiku natywnego

- **R1. Wykrywanie.** `pending` i `collect` znają epik natywny, gdy istnieje jego plik przebiegu i końcowy PR jest
  zmergowany. `collect` dla epiku natywnego najpierw odświeża rekord (`metrics.py epic`). Odmowa zostaje dla epiku,
  który nie ma ani przebiegu AO, ani pliku przebiegu natywnego.
- **R2. Incydenty.** Dla epiku natywnego:

  | Rodzaj | Źródło |
  |---|---|
  | `wait` | zdarzenie czekania z rekordu (`human`), z działaniem człowieka |
  | `finding` | przegląd z werdyktem blokującym z `reviews` |
  | `failed_attempt` | niezakończony przebieg subagenta |
  | `escaped` | zgłoszenie `bug` z okna regresji rekordu |
  | `unasked` | sekcja „Decyzje bez pytania” opisu końcowego PR |
  | `reported` (nowy) | każdy punkt sekcji „Problemy i zastoje” i „Luki w instrukcjach” opisu końcowego PR |
  | `optimization` | `metrics.py decide`, jak dziś |

  `repair`, `manual_fix` i `no_sweep` nie powstają dla epiku natywnego. Brak sekcji albo pola to fakt braku danych,
  nie błąd. Opis PR jest czytany przez bramkę pochodzenia.
- **R3. Progi bez zmian.** `reported` liczy się do progu jak każdy incydent; klasę naprawczą może mieć nadal tylko
  `wait` z działaniem człowieka, `manual_fix` i `repair`.
- **R4. Skrypt pomiaru z worktree (#679).** `collect` uruchamia `metrics.py` z worktree `origin/main`, nie
  z głównego checkoutu.
- **R5. Pliki procesu.** Lista plików, które może zmienić pozycja `fix`, obejmuje też
  `docs/adr/agentic-software-house.md`, bo tam jest przebieg epiku.
- **R6. Zamrożenie (D6).** Gdy „Status” planu ASH pokazuje serię w toku, sesja `/retro` nie wprowadza pozycji `fix`:
  zapisuje je przez `observe` i wymienia w raporcie jako odroczone do końca serii. Commit „Statusu” i propozycje
  zgłoszeń działają jak dziś. Pozycję, którą raport oznacza jako blokadę kolejnego epiku albo zagrożenie, sesja
  wprowadza dopiero po „tak” użytkownika wskazującym tę pozycję.
- **R7. Agent i specyfikacja.** `retro-analyst` i `docs/features/retro/spec.md` opisują incydenty epiku natywnego.
  `/retro <epik>` uruchamia użytkownik po merge'u końcowego PR.
- **R8. `/retro` nie jest skillem zamrożonym.** Główny `CLAUDE.md`, decyzja o zamrożeniu w ADR i „Status” planu ASH
  wymieniają jako zamrożone tylko `/ao-run`, `/delivery` i `/sweep`.

## F: poprawki po SMALL-FIXES

Osobny commit testu RED, odblokowanie worktree przy sprzątaniu i miejsce punktu odniesienia są już w PR #764; ta
specyfikacja zakłada jego merge i ich nie powtarza.

- **F1. Punkt odniesienia w kroku 1.** Sesja prowadząca po otwarciu draft PR uruchamia `metrics.py start`, a po
  oznaczeniu PR jako gotowy `metrics.py done` (kroki 1 i 7 „Przebiegu epiku”).
- **F2. Opis końcowego PR ma stałe sekcje:** „Zakres” (zadanie, ryzyko, przegląd), „Kryteria akceptacji” (A2), „Testy”,
  „Ujawnienie TDD”, „Odroczone uwagi”, „Decyzje bez pytania”, „Problemy i zastoje”, „Luki w instrukcjach”, „Punkt
  odniesienia i przebieg”. Sekcja bez treści zostaje z wpisem „brak”.
- **F3. ID zadania w zleceniu.** `description` każdego wywołania subagenta zaczyna się od ID zadania; przegląd całej
  zmiany ma w opisie nazwę epiku i rolę.
- **F4. Zasoby przed falą.** Przed falą sesja sprawdza wolną pamięć Dockera i działające projekty Compose innych
  sesji. Gdy pamięci nie wystarcza na planowaną liczbę stosów, zmniejsza równoległość i zapisuje to w „Problemach
  i zastojach”.
- **F5. Testy API przy zimnym cache (#762).** `make -C apps/api test` przechodzi po usunięciu `var/cache/test*`, bez
  przekroczenia limitu pamięci.

## A: odbiór kryteriów akceptacji

- **A1. Kolejność.** Przegląd całej zmiany (krok 6) zaczyna od numerowanych kryteriów akceptacji specyfikacji epiku.
  `qa-adversarial` dostaje je w zleceniu i sprawdza przed szukaniem błędów: na działającym stosie, a kryteria
  narzędzia jego komendami na danych i stanie tymczasowym. Epik bez QA (dokumentacja, instrukcje, narzędzie niskiego
  ryzyka): kryteria sprawdza sesja prowadząca. QA zmiany narzędzia sesja dobiera proporcjonalnie do ryzyka (krok 6
  „Przebiegu epiku”).
- **A2. Tabela w opisie PR.** Dla każdego kryterium: numer, sposób sprawdzenia i wynik. Sposoby: „stos” (polecenie
  albo scenariusz), „CI” (nazwa testu i zielony przebieg na czole gałęzi), „po wdrożeniu”, „niezweryfikowane”
  (z powodem).
- **A3. Tryb stosu.** Kryterium dotyczące zachowania sklepu w obrazie produkcyjnym sprawdza się na stosie
  `STORE_MODE=production` albo testem E2E z CI, wskazanym z nazwy.
- **A4. Otwarte kryteria.** Kryterium „niezweryfikowane” jest na początku opisu PR jako decyzja dla użytkownika.
  Kryteria „po wdrożeniu” sesja sprawdza po wdrożeniu zleconym przez użytkownika i dopisuje wynik w komentarzu PR.

## T: telemetria sesji natywnej

Subagenci działają w procesie sesji prowadzącej, więc nie da się im nadać własnych atrybutów zasobu. Powiązanie
daje stos: `session_id` i `model` są etykietami metryk i polami zdarzeń, a zdarzenia w Lokim mają `agent_id`;
`agent_id` nie jest etykietą metryk w Prometheusie. Sesje aplikacji desktop eksportują jako `claude-code-desktop`
bez atrybutu `shop.source` (szczegóły: `docs/features/telemetry/spec.md`, R8).

- **T1. Eksport z sesji natywnych.** Włącza go istniejący blok z `python3 tools/telemetry/telemetry.py snippet`
  w `~/.claude/settings.json` (krok użytkownika), sprawdzany przez `telemetry.py check`. Sesje mają
  `shop.source=interactive`.
- **T2. Epik po sesji.** Epik rozpoznaje się w stosie po `session_id` sesji zapisanych w pliku przebiegu; rekord
  metryk je wymienia. Etykiety `shop.epic` nie ustawia się w ustawieniach użytkownika, bo objęłaby wszystkie
  równoległe sesje.
- **T3. Zadanie i subagent po `agent_id`.** Wpis przebiegu subagenta w rekordzie (`attempts`) ma jego identyfikator,
  zadanie, rolę, model i effort; po `agent_id` łączy się go ze zdarzeniami w Lokim. Model jest etykietą natywną.
- **T4. Opis.** `docs/features/telemetry/spec.md` opisuje, jak rozpoznać epik, zadanie, model i subagenta dla sesji
  natywnej.

Bez zmian w dashboardach, w konfiguracji stosu i bez eksportu rekordów do stosu (#689).

## V: wersja 1.0 i seria

- **V1. Tag.** `ash-v1.0` na commicie `main` zawierającym wszystkie zmiany tej specyfikacji; zakłada go sesja na
  polecenie użytkownika.
- **V2. Reguła zamrożenia w ADR.** Do końca serii pliki procesu (`CLAUDE.md` w części o procesie, `.claude/`,
  „Przebieg epiku”, `tools/metrics`, `/retro`) zmienia tylko poprawka blokady albo zagrożenia, po zgodzie
  użytkownika. Pozostałe wnioski zbiera `/retro`.
- **V3. „Status” planu ASH** pokazuje stan serii i jej zmergowane epiki. Seria jest w toku od założenia tagu
  `ash-v1.0` do retrospektywy trzeciego epiku. `/retro small-fixes` (odbiór) biegnie przed tagiem, więc bez
  zamrożenia.
- **V4. Ocena po serii.** Z rekordów: czas do gotowego PR i do merge'u, czas czekania na człowieka i liczba jego
  wiadomości, wartość na zadanie, rolę i model, koszt subskrypcji, przeglądy z werdyktem blokującym, kryteria
  akceptacji według sposobu sprawdzenia, regresje w oknie 14 dni. Z retrospektyw: incydenty i odroczone propozycje.
  Punkt odniesienia: rekordy AO i rekord SMALL-FIXES.

## Kryteria akceptacji epiku

1. Po zapisaniu pliku przebiegu po fakcie `metrics.py epic small-fixes` kończy się kodem 0 i zapisuje rekord
   z `source: native`: 8 zadań, 16 przebiegów subagentów (8 `developer`, 5 `code-reviewer`, po jednym `ux-reviewer`,
   `qa-adversarial` i `general-purpose`), modele zgodne z plikami `meta.json`, wartość z oznaczeniem szacunku, siedem
   raportów `code-reviewer`, w tym dwa `CHANGES_REQUIRED` (zadania 4 i 7), koszt subskrypcji `null` z wpisem w `gaps`.
2. `metrics.py epic <EPIK>` bez przebiegu AO i bez pliku przebiegu kończy się kodem 2 i nie zapisuje rekordu.
3. `metrics.py trend` po `backfill` pokazuje epiki AO i SMALL-FIXES w jednej tabeli z oznaczeniem źródła, z medianami
   liczonymi osobno dla każdego źródła i z oznaczeniem pól liczonych inaczej niż w AO.
4. Rekord zbudowany z transkryptu bez pola `usage`, bez `meta.json` albo z nieznanym modelem ma `null` i wpisy w `gaps`,
   a komenda kończy się kodem 0.
5. `retro.py pending` wymienia `small-fixes`, a `retro.py collect small-fixes` kończy się kodem 0; `input.json` ma
   incydenty `finding` dla dwóch przeglądów blokujących i fakt braku sekcji „Luki w instrukcjach” w opisie PR #752.
6. `retro.py collect` dla epiku bez przebiegu AO i bez pliku przebiegu kończy się kodem 2.
7. Test regresyjny #679: `collect` z nieaktualnym głównym checkoutem i aktualnym worktree ma dane pomiaru.
8. Skill `/retro` opisuje zamrożenie: przy serii w toku przebieg kończy się raportem i commitem „Statusu”, bez
   commitów pozycji `fix`. Pierwsze sprawdzenie w praktyce to retrospektywa pierwszego epiku serii.
9. „Przebieg epiku” w ADR zawiera F1–F4, A1–A4 i V2; `qa-adversarial.md` wymaga kryteriów akceptacji w zleceniu.
10. `make -C apps/api test` przechodzi po usunięciu `var/cache/test*` (F5).
11. `make check` i testy skryptów przechodzą; specyfikacje metryk i retro opisują źródło natywne.
12. Rekord dwóch sesji z nakładającymi się wiadomościami (sesja wznowiona) liczy każdą wiadomość raz, a sesja spoza
    pliku przebiegu w tym samym katalogu roboczym nie wchodzi do rekordu.
13. Rekord z odczytami limitów, między którymi okno się odnowiło, ma dla tego okna `null` i wpis w `gaps`.
14. Po włączeniu eksportu (T1) sesja prowadząca epik jest widoczna w Prometheusie pod swoim `session_id`,
    a zdarzenia subagenta w Lokim mają `agent_id` równy identyfikatorowi z rekordu. Gdy próba pokaże inaczej, wynik
    i ograniczenie trafiają do specyfikacji telemetrii.

## Ryzyka i ograniczenia

- Format transkryptów może się zmienić z wersją CLI. Narzędzie czyta minimalny zestaw pól, a rekord zapisuje wersję
  CLI; zmianę formatu widać jako wpisy w `gaps`.
- Wartość w USD to szacunek z cennika, nieporównywalny co do centa z kosztem liczonym przez CLI w epikach AO.
- Transkrypty mają retencję ustawioną lokalnie; rekord trzeba zbudować przed ich wygaśnięciem.
- Zamrożenie procesu, ID zadania w zleceniu i sekcje opisu PR to instrukcje, nie blokady narzędzia. Ich pominięcie
  widać w rekordzie jako „bez zadania” albo wpis w `gaps`.
- Pierwsze `/retro` na epiku natywnym jest zarazem pierwszym sprawdzeniem tej ścieżki na prawdziwych danych.

## Decyzje użytkownika (2026-11-18)

- Wartość w USD według cennika API (D3), ze stawkami za wszystkie rodzaje tokenów.
- `/retro small-fixes` jest odbiorem ścieżki natywnej: po wdrożeniu zmian uruchamia je użytkownik.
- Uprawnienia GitHuba bez zmian, AO zamrożony, bez dodatkowych mechanizmów tam, gdzie wystarczają istniejące.
