# Audyt wydajności epików ADMIN-FEATURES i METRICS

Rewizja: `main` `0e82a93` (oba końcowe PR zmergowane: ADMIN-FEATURES #623 2026-11-16 16:15, METRICS #674 2026-11-17
12:09). AO: ADMIN-FEATURES na `a178eaf` (bez `--max-parallel`), METRICS na `f68ff2f` (`--max-parallel 2`). Claude Code
2.1.311 we wszystkich próbach. Godziny w CET.

Oznaczenia: **[z]** zmierzone (baza AO, transkrypty, API GitHuba), **[s]** szacowane; liczby szacowane mają też „≈”.

## 1. Executive summary

1. **METRICS stracił około 9 h z 16,7 h na jednym wzorcu [z/s].** Zasada przekrojowa specyfikacji („liczby szacowane
   zawsze z ≈”) była sprawdzana próbkowo i poprawiana po jednym miejscu. 8 z 11 znalezisk Important w 5 rundach
   `/integration` należało do tej jednej klasy. Od rundy 3 każda runda kończyła się na limicie rund. Limit zatrzymywał
   epik i czekał na „tak” człowieka: raz przez noc (4 h 24 min), raz rano (2 h 13 min, w tym 68 min, gdy sesja koordynująca sama miała
   wyczerpany limit tygodniowy). Same próby AO trwały w METRICS tylko 8,5 h.
2. **ADMIN-FEATURES był wolny przez szeregowe wykonanie [z/s].** 28 prób szło jedna po drugiej i zajęło 25,0 h z 29,9 h.
   20% czasu prób to czekanie `/delivery` na CI PR, na ścieżce krytycznej [z]. Plan miał 7 fal i niezależne zadania.
   Symulacja z prawdziwymi czasami prób daje fazę zadań 20,4 h szeregowo, 12,3 h przy 2 próbach naraz i 10,1 h przy 3
   [s]. `--max-parallel` jest w AO od 2026-11-15 (AO #53), a w `/ao-run` od 2026-11-16 (#597), ale:
   - ADMIN-FEATURES wystartował przed nim, a jego plan nie miał `paths`;
   - plan METRICS skupiał 5 z 7 zadań na jednym pliku `tools/metrics/metrics.py` (4218 linii), więc współbieżność
     wyniosła tylko 38 min.
3. **Zatrzymania z mechaniczną odpowiedzią kosztowały około 8 h bezczynności AO [z].** Było 11 zatrzymań (bez merge'y
   i bramki). 7 z nich miało odpowiedź typu „tak, jeszcze jedna runda” albo „podnieś limit”. Dwa nocne zatrzymały AO
   na 459 min. Reakcja koordynatora na odpowiedź użytkownika zawsze trwała poniżej 20 s. Problemem jest polityka
   zatrzymań, nie czas reakcji.
4. **Poprawki procesu nie docierają do epiku w toku [z].**
   - ADMIN-FEATURES do rundy 4 włącznie używał starego `/integration` z gałęzi epiku: 116 min lokalnych bramek
     powtarzających CI, e2e przerwane po 570 s, przegląd UX, który nie ruszył.
   - #595 trafił do `main` 4 h po starcie epiku i do epiku dopiero przez REPAIR (merge `main`) przed rundą 5.
   - W METRICS #595 działa: lokalnie tylko `make check`, 3,5–4 min na rundę.
   - #595 przesunął jednak problem: `/integration` czyta CI pusha, zanim AO zrobi swój jedyny rerun, więc niestabilny
     test staje się znaleziskiem Important (METRICS runda 2, F1).
5. **Wiele podejrzanych obszarów nie jest przyczyną spowolnienia [z]:**
   - wznowienia po blokerze: 0,4–1,2 min i 0,25–0,67 mln tokenów przed pierwszą produktywną akcją;
   - pętle retry: 0 nieudanych prób poza jednym crashem wywołanym restartem;
   - błędy API: 0;
   - kolejka runnerów GitHuba: mediana 3 s;
   - sonda bezpieczeństwa: około 35 s na start;
   - powtórzone testy w próbach: 5 min w ADMIN-FEATURES i 22 min w METRICS.

   Wybór Opus/Sonnet zmienia koszt, nie czas. Developer Opus nie miał mniej znalezisk FULL (5 z 6 zadań wobec 4 z 9
   na Sonnecie, mała próba) i kosztował 2,6 razy więcej na minutę.

## 2. Metodologia i źródła

**Źródła pierwotne (tylko odczyt):**

| Źródło | Ścieżka / identyfikator | Do czego |
|---|---|---|
| Baza AO | `~/.local/state/ao-{admin-features,metrics}/orchestrator.db` (`mode=ro`): `attempts`, `events`, `tasks` | próby, zdarzenia ze znacznikiem czasu, zależności |
| Logi AO | `run.log`, `events.jsonl`, `probe-runs.jsonl`, `manual-fixes.txt`, `comment.md` w tych katalogach | przebieg, zatrzymania, sondy |
| Raporty AO | `epics/<epik>/report.md`, `epics/<epik>/integration/round-1..5.md`, `gate-*.log`, `ci-wait-*.json` | rundy QA, bezczynność, bramki |
| Bramka | `~/.local/state/ao-night/gate.jsonl` | odmowy i start |
| Transkrypty prób | `~/.claude/projects/-Users-pp-Development-claude-shop-<ZADANIE>/<session_id>.jsonl` + `subagents/`; rundy: `…-admin-features-integration`, `…-metrics-integration` | czas ról, poleceń, modele, tokeny (46 prób) |
| Sesje koordynujące | `337d8e20-40ba-4e9c-af13-6a86b3a30ea8` (ADMIN-FEATURES), `04c711ef-996e-482e-8f2a-4f3811611b92` (METRICS, do 2026-11-17 12:13) | decyzje, czekanie na człowieka, ręczne prace |
| Retro | `~/.local/state/shop-retro/{admin-features,metrics}/report.md`; PR #626 (zmergowany), #676 (otwarty) | wnioski retro |
| GitHub (`gh`, tylko GET) | PR #593–#624, #649–#676; runy CI zadań, pusha `epic/*` i końcowych PR | CI, ponowienia, kolejka |
| Kod | AO `f68ff2f` (`README.md`, `orchestrator/core.py`, `epic.py`); `.claude/skills/{ao-run,delivery,integration,planner}/SKILL.md`; otwarte issues AO #38–#52 | stan obecny |
| Pomiar | `tools/metrics/metrics.py epic` i `render` z tymczasowym `--state` | kategorie poleceń, wartość tokenów, pp okna |

**Metoda:**
- Czas wywołania narzędzia to różnica znaczników `tool_use` i `tool_result`, a dla poleceń w tle czas powiadomienia.
- Czas subagenta to suma jego aktywnych odcinków (także wznowień przez SendMessage).
- **Podział czasu prób:** każda sekunda próby trafia do jednej kategorii o najwyższym priorytecie, w kolejności
  developer → `code-reviewer` → agenci integracji → czekanie na CI → e2e → testy → `make check` → stos → reszta Basha →
  tury modelu orkiestratora. Dzięki temu nic nie liczy się podwójnie, a suma zgadza się z `wall_s` prób co do 1 min.
- **Czas poza próbami:** przypisanie z `ao report` (człowiek / CI epiku / inne), rozbite według osi czasu sesji
  koordynującej.
- **Symulacja równoległości:** prawdziwe czasy prób, zależności z bazy AO, 1,5 min narzutu na start próby, odpowiedź
  na AF-1 po tych samych 53 min. Symulacja nie uwzględnia obciążenia hosta 2–3 stosami Dockera, konfliktów w fali
  ani szybszego zużycia limitu, więc daje **górną granicę** zysku.
- **Koszt:** szacunek CLI (`total_cost_usd`) po cenach katalogowych, nie opłata w subskrypcji. Koszt sesji
  koordynującej METRICS jest szacowany.
- Surowe dane i skrypty zostały poza repozytorium, w katalogu roboczym sesji audytu.

**Ograniczenia:**
- To tylko dwa epiki.
- Klasyfikacja poleceń jest heurystyczna.
- Czas „człowieka” w METRICS obejmuje odcinki pracy koordynatora; rozbijam je w sekcji 4.
- Rekord `metrics.py` dla METRICS nie ma `done_at`, bo w AO epik został `BLOCKED`, więc rozkład liczę sam.

**Rozbieżności między źródłami:**

| Twierdzenie | Źródło | Dane pierwotne | Wyjaśnienie |
|---|---|---|---|
| METRICS: „czekanie w bramce 33,1 h”, wąskie gardło `gate_wait` | `metrics.py render metrics` | Pierwsza odmowa bramki 11-15 10:22. Użytkownik odłożył METRICS 11-15 16:05 („zapomnij o METRICS”). Brak wpisów bramki 11-15 15:56 → 11-16 18:23. | Miara liczy od pierwszej odmowy, także okres świadomie odłożony. To czas przed startem, nie strata wykonania. |
| METRICS: „około 16 h aktywności AO” | notatka po przebiegu | Okno 19:26 → 11:27 to 16 h 01 min, z tego otwarte próby 8 h 28 min [z] | „16 h” to okno, nie praca. |
| METRICS: „około 2 h czekania na reset, który już nastąpił” | notatka | 08:48:39 sesja koordynująca sama dostaje błąd limitu; reset był nieplanowany, między 08:48 a 09:56. Błędne przekonanie trwało 15,5 min (09:56:38 → 10:12:05) [z]. | Około 68 min to rzeczywisty limit (użytkownik prosił też czekać do resetu), a 15,5 min to błąd koordynatora. |
| ADMIN-FEATURES: „5 czekań, razem 528 min” | komentarz pomiaru | Bezczynność AO z powodu człowieka 214 min [z] | Przez większość czekania na AF-12 AO wykonywał AF-13–15. |
| METRICS: „6 czekań, razem 400 min” | `comment.md` | Z tego około 126 min to czas koordynatora (68 limit, 15,5 przekonanie, 42 PR #668) | Miara przypisuje człowiekowi wszystko, co nie jest próbą. |
| ADMIN-FEATURES: „3 blokady limitów” | notatka | Limity AO: 2 × `max_qa_rounds` i 1 × `epic_max_attempts`. Limitu użycia nie było (cooldown 0). | Chodzi o limity procesu, nie Claude. |
| Koszt ADMIN-FEATURES | `ao report` $252,25; `metrics.py` ≈ $253,38; transkrypty $252,30 | AF-7#1 (crash) nie ma wyniku CLI | Różnica to wycena tej jednej próby. |

## 3. Chronologia ADMIN-FEATURES (#461)

| Czas | Zdarzenie | Dowód |
|---|---|---|
| 11-15 10:20–10:22 | Bramka odmawia (wspólne pliki z wstrzymanym DEMO-DATA: `apps/api/src/Catalog/*/Search`). Użytkownik: „Tak, mimo bramki”. Gałąź od `a964e94`, AO `a178eaf`: jedna próba naraz. | `gate.jsonl` 10:20:57, 10:22:06; `run_started` bez `max_parallel` |
| 10:27–10:52 | AF-1#1 BLOCKED: sekcja 0 specyfikacji (403) przeczy 3 istniejącym testom. Odpowiedź po 52,7 min, ale AO nie stał (AF-2 10:54–12:03). | `events` 10:53:02, `answer_recorded` 11:45:57 |
| 12:05–18:15 | AF-1#2, AF-5 (112,8 min, CRITICAL, Opus, 2 rundy poprawek), AF-3, AF-4, AF-6, każde po kolei | `attempts` |
| 18:12–18:20 | Użytkownik pyta, czemu tak wolno. Koordynator proponuje restart z `--max-parallel 3` („około 6–7 h zamiast 13 h”); użytkownik odmawia i prosi o wyższy limit czasu epiku. `TaskStop` zabija AF-7#1 (kod 143), restart z `epic_timeout` 16 h → 36 h. | sesja 337d8e20 18:13:34, 18:19:30, 18:19:48; `config_changed` 18:20:15 |
| 18:21–05:35 | AF-7#2 … AF-15 szeregowo. Push CI epiku czerwony 3 razy (21:45, 23:06, 02:58), za każdym razem zielony po rerunie. Ostrzeżenia limitu tygodniowego 75% i 77%. | `epic_ci_rerun`, `usage_warning` |
| 11-16 00:00–01:08 | AF-12#1 BLOCKED: wymagany e2e czerwony przez test z AF-8 (`e2e/tests/staff-dashboard.spec.ts:64`, regex kwoty), 2/2 rundy poprawek | `events` 01:09:03 |
| 05:35–08:50 | Epik bezczynny (`epic_idle` 05:50); odpowiedź „1” o 08:49. **3 h 15 min bezczynności AO.** | `epic_idle`, `answer_recorded` 08:49:12 |
| 09:14–10:41 | INT-1 FULL, 87 min: lokalne bramki z e2e (27 min plus 9,5 min e2e przerwanego `timeout 570`), QA 46 min. UX: `mounts denied … not shared from the host`. Wynik: 2 Important. | `round-1.md`, sesja d38a1a87 10:11:20 |
| 10:43–13:04 | FIX-1 → INT-2 (F7: kolejny angielski komunikat tej samej walidacji) → FIX-2 → INT-3 (F8: dokumentacja tej walidacji) → BLOCKED `max_qa_rounds` | `report.md` (Integracja) |
| 13:14–13:51 | +1 runda → FIX-3 (3,5 min, dokumentacja) → BLOCKED `epic_max_attempts` 24/24 → +4 → INT-4 PASSED (29 min, w tym 24 min lokalnych bramek) | `epic_blocked` 13:19:41 |
| 13:52–14:42 | FINALIZE (20,9 min). Końcowy PR #623 o 14:14. CI czerwone na `test_delta` (wagi shardów e2e: epik wobec #569 w `main`), rerun też czerwony. | run 43873838971 |
| 14:43–16:08 | REPAIR-1 (#624, merge `main`, 37,7 min) → BLOCKED `max_qa_rounds` (DELTA po REPAIR) → użytkownik wybiera rundę wbrew rekomendacji pominięcia → INT-5 PASSED (41,7 min, pierwsza runda z bramkami z CI pusha) → DONE | `epic_blocked` 15:21:27, sesja 337d8e20 15:23:45 |
| 16:15 | Merge #623 przez użytkownika. Potem `/sweep` (#627–#631) i `/retro` (#626). | |

**Liczby [z]:**
- Start → DONE: 29 h 46 min; start → merge: 29 h 53 min.
- Próby: 28 (25 udanych, 2 BLOCKED, 1 crash). Zadania: 15 planu, 5 rund QA, 3 FIX, FINALIZE, REPAIR.
- Przebiegi `ao run`: 6. Wznowienia epiku: 3.
- Suma prób: 25,0 h; praca agentów (developer, `code-reviewer`, agenci integracji): 14,7 h.
- Koszt: wykonawcy $252,25 i sesja koordynująca $35,89 (z `/sweep` i `/retro`). Tokeny: 3,9 mln wyjścia, 715 mln
  odczytu cache, 18,4 mln zapisu cache.
- Okno tygodniowe +34 pp [s: `share` 0,73 przy nakładaniu z EVALS].

**Rozkład czasu kalendarzowego, start → merge (1793 min, bez nakładania) [z]:**

| Kategoria | min | % | Skład |
|---|---|---|---|
| 1. Implementacja | 534 | 29,8 | pierwszy przebieg developera w zadaniach planu (w tym 154 min jego własnych testów, check i e2e) |
| 2. Review i poprawki | 291 | 16,3 | `code-reviewer` 136, developer w rundach poprawek i FIX 99, architekt/QA/UX 56 |
| 3. Testy i CI | 455 | 25,4 | czekanie `/delivery` na CI PR 285, lokalne testy i bramki 162 (w tym bramki `/integration` 116), CI epiku 9 |
| 4. Limity i zasoby | 0 | 0 | brak cooldownu i przerw hosta |
| 5. Blokady współbieżności | 0 | 0 | rzeczywistej bezczynności nie było; koszt szeregowości to kontrfakt: −490…−620 min [s] (sekcja 6) |
| 6. Konflikty i synchronizacja | 109 | 6,1 | czerwone CI końcowego PR 30, REPAIR-1 38, INT-5 42 |
| 7. Orkiestracja, przygotowanie, ponowienia | 181 | 10,1 | tury modelu orkiestratora i Bash 130, FINALIZE 21, przerwy między próbami 31, crash AF-7#1 2 |
| 8. Inne przestoje | 221 | 12,3 | czekanie na człowieka 214 (z tego noc 195), merge przez użytkownika 7 |

Przekrojowo: dodatkowe rundy QA (FIX-2, INT-3, FIX-3, INT-4) to 100 min prób i 15 min blokad [z].

## 4. Chronologia METRICS (#496)

| Czas | Zdarzenie | Dowód |
|---|---|---|
| 11-15 10:22–15:56 | Bramka odmawia („dwa epiki aktywne: ADMIN-FEATURES, EVALS”). 16:05 użytkownik odkłada METRICS. | `gate.jsonl` |
| 11-16 18:23–19:26 | Bramka odmawia tylko z powodu okna tygodniowego (92–93% > 80%). Użytkownik zgadza się pominąć ten krok. Start 19:26:47 od `9d21253`, AO `f68ff2f`, `--max-parallel 2`, sonda 33,8 s. | `gate.jsonl` 19:26:04, 19:26:33; `probe-runs.jsonl` |
| 19:28–20:21 | M-1 (52 min). Ostrzeżenie: okno tygodniowe 93%. | `usage_warning` 20:21:16 |
| 20:22–21:25 | M-2#1 BLOCKED: skaner fixture'ów bierze dwie nazwy kluczy JSON z `modelUsage` za base64. Odpowiedź po 1,9 min, M-2#2 17 min. | `events` 21:04:19, 21:06:18 |
| 21:27–23:27 | M-3#1 BLOCKED po 2 rundach poprawek (każda poprawka wprowadzała kolejne Important w liczeniu regresji przez `gh`). Odpowiedź po 1,9 min. W tym czasie M-4 (czekał wcześniej 1 h 08 min w kolejce, bo dzieli `metrics.py` z M-3). Potem M-3#2. CI #655 pełne (26 min, 2 runy), bo zadanie dotknęło `.github/ISSUE_TEMPLATE/bug.yml`. | `events` 22:33:26; PR #655 |
| 23:29–00:54 | M-5, potem M-6 ∥ M-7 (38 min równolegle). Merge M-7 bez odziedziczonego CI: 14 min CI pusha przed INT-1. | `run.log` („no inherited CI”) |
| 01:09–03:30 | INT-1 FULL (3 Important) → FIX-1 (2 rundy) → INT-2 (F1: deadlock w `VariantStockMigrationTest` na CI pusha, F2: „≈”) → AO robi rerun dopiero o 02:52, po rundzie → FIX-2 → INT-3 (2 × „≈”) → BLOCKED `max_qa_rounds` | `round-2.md`; `epic_ci_rerun` 02:52:04 |
| 03:30–07:54 | Noc: epik stoi **4 h 24 min**. 07:52 użytkownik: „Tak, +1 runda QA”. | `epic_resumed` 07:52:24 |
| 07:54–08:42 | FIX-3#1 BLOCKED (trzecia runda?) → „Tak, trzecia runda” → FIX-3#2 → INT-4 (2 × „≈”) → BLOCKED `max_qa_rounds` | `events` 08:14:30, 08:42:34 |
| 08:42–10:55 | 08:48 użytkownik: piąta, ostatnia runda, najpierw audyt, czekać do resetu 18.11. 08:48:39 sesja koordynująca trafia na limit tygodniowy (68 min). Nieplanowany reset między 08:48 a 09:56. 09:56–10:12 koordynator dalej czeka na 18.11 (15,5 min). 10:12–10:54 ręczny audyt reguły i PR #668: 10 kolejnych naruszeń A1–A10. | sesja 04c711ef 08:48:39, 10:08:30, 10:12:05 |
| 10:55–11:27 | Wznowienie → `integration_stale` → 14 min CI pusha → INT-5: 2 Important, jedno wprowadzone przez #668 → BLOCKED | `integration_stale` 10:55:17 |
| 11:29–12:09 | Ręczne domknięcie: #671 (11:37–11:41), #672 synchronizacja z `main` (konflikt w `ao-run/SKILL.md`, 11:43–11:56), końcowy PR #674 (11:57), merge 12:09:08 | PR #671, #672, #674 |
| 12:13–12:20 | `/retro` na kopii stanu → PR #676 (otwarty). `/sweep` pominięty decyzją użytkownika. | `shop-retro/metrics/report.md` |

**Liczby [z]:**
- Start → merge: 16 h 42 min (do zatrzymania AO 16 h 01 min).
- Próby: 18 (15 udanych, 3 BLOCKED, 0 nieudanych). Zadania: 7 planu, 5 rund QA, 3 FIX. Ręcznie: 3 PR i końcowy PR.
- Przebiegi `ao run`: 5. Wznowienia epiku: 2.
- Suma prób 9,1 h, a z nakładaniem M-6/M-7 8,5 h kalendarzowo. Praca agentów: 5,7 h.
- Koszt: wykonawcy $118,00; sesja koordynująca ≈ $24,5 [s]. Tokeny: 2,4 mln wyjścia, 269 mln odczytu cache,
  10,5 mln zapisu cache.
- Przyrost okna tygodniowego: brak danych, bo w trakcie był nieplanowany reset okna.

**Rozkład czasu kalendarzowego, start → merge (1002 min, bez nakładania) [z, odcinki koordynatora z jego osi czasu]:**

| Kategoria | min | % | Skład |
|---|---|---|---|
| 1. Implementacja | 148 | 14,8 | pierwszy przebieg developera w zadaniach planu (w tym około 68 min jego testów) |
| 2. Review i poprawki | 245 | 24,5 | `code-reviewer` 89, developer w poprawkach i FIX 46, architekt/QA 57, ręczny audyt i PR #668 42, PR #671 11 |
| 3. Testy i CI | 144 | 14,4 | czekanie `/delivery` na CI 59, lokalne testy i `make check` 46, CI epiku 28, CI końcowego PR 12 |
| 4. Limity i zasoby | 84 | 8,3 | koordynator z wyczerpanym limitem 68, błędne przekonanie o braku resetu 16 |
| 5. Blokady współbieżności | 0 | 0 | M-4 czekał 68 min w kolejce, ale słusznie (wspólny plik), a równolegle szło M-3 |
| 6. Konflikty i synchronizacja | 15 | 1,5 | PR #672 (synchronizacja z `main`) |
| 7. Orkiestracja, przygotowanie, ponowienia | 127 | 12,6 | tury i Bash orkiestratora 99 (w tym nadmiarowe odpytywanie 29), przerwy, restarty i sondy 28 |
| 8. Inne przestoje | 276 | 27,5 | czekanie na człowieka (z tego noc 264) |
| Nakładanie prób M-6/M-7 | −36 | −3,6 | odjęte, bo sumy kategorii są liczone po próbach |

Przekrojowo: rundy QA 2–5 i FIX-2/FIX-3 to 97 min prób, a wraz z wywołanymi nimi przestojami, ręcznym domknięciem
i CI ≈ 9,1 h [z/s].

## 5. Porównanie wydajności

| Miara | ADMIN-FEATURES | METRICS |
|---|---|---|
| Zadania planu / fale | 15 / 7 | 7 / 5 |
| Start → merge | 29,9 h | 16,7 h |
| Na zadanie planu | 2,0 h | 2,4 h |
| Suma prób / kalendarz | 25,0 h / 84% | 9,1 h / 54% |
| Praca agentów | 14,7 h | 5,7 h |
| Współbieżność zrealizowana | 0 (AO bez opcji) | 38 min (M-6 ∥ M-7) |
| Czekanie `/delivery` na CI PR | 308 min, 15,4 min/PR (pełne CI 10–15 min) | 58 min, 5,9 min/PR (CI narzędzi około 2,5 min) |
| Lokalne bramki `/integration` | 116 min (stary skill) | 19 min (#595) |
| Rundy QA / Important | 5 / 4 (3 w jednym obszarze walidacji `sort`/`productId`) | 5 / 11 (8 w klasie „wartość nieznana albo szacowana pokazana jak dokładna”) |
| Zatrzymania / z mechaniczną odpowiedzią | 5 / 4 | 6 / 3 |
| Bezczynność AO przez człowieka (noc) | 214 min (195) | ≈ 399 min (264), w tym ≈ 126 min koordynatora |
| Koordynator na ścieżce krytycznej | ≈ 4 min | ≈ 97 min, plus 68 min limitu |
| Koszt wykonawców / na zadanie planu | $252 / $16,8 | $118 / $16,9 |
| Nieudane próby | 1 (crash przez restart) | 0 |
| Czerwone CI: PR / push epiku | 1 / 4 z 21 (3 niestabilne, 1 prawdziwa wada zamaskowana rerunem) | 0 / 1 z 14 (niestabilny) |
| Przegląd UX | nie ruszył (Docker) | niepotrzebny (bez frontów) |

**Wspólne dla obu:**
- Kolejne rundy QA znajdowały kolejne miejsca tej samej reguły.
- Limity rund (QA i poprawek taska) wymagały „tak” człowieka.
- Konflikt z `main` przy końcowym PR: `test_delta` w ADMIN-FEATURES, `ao-run/SKILL.md` w METRICS.
- Niestabilne testy API na CI pusha (planer zapytań, deadlock, granica czasu).
- Developer uruchamiany asynchronicznie, a orkiestrator odpytywał go co 9–10 min z opóźnieniem.

**Tylko ADMIN-FEATURES:**
- Szeregowe AO i czekanie na CI na ścieżce krytycznej.
- Stare skille w epiku (lokalne e2e, `timeout 570`).
- Test e2e zależny od danych zablokował AF-12 na noc.
- Restart zabił próbę.

**Tylko METRICS:**
- Monolityczny plik wymusił łańcuch zadań.
- Skaner fixture'ów kolidował z danymi CLI.
- Koordynator sam wyczerpał limit.
- Ręczne domknięcie epiku `BLOCKED`.

**Największa strata:**
- ADMIN-FEATURES: szeregowość, kontrfaktycznie −8…−10 h [s]. Do tego zmierzone 9 h strat z sekcji 6.
- METRICS: wzorzec rund QA z zatrzymaniami, ≈ 9 h.

**Czy wcześniejsze poprawki usuwały problemy, czy je przenosiły:**
- **#595 (bramki z CI pusha):** usunął powtarzanie e2e (METRICS 3,5–4 min na rundę wobec 17–27 min). Przeniósł jednak
  ryzyko na CI pusha: runda czyta czerwony run przed rerunem AO (sekcja 7, P4).
- **AO #53 i #597 (`--max-parallel`):** narzędzie działa (38 min równolegle, bez konfliktu). Zysk ograniczył plan.
  Równoległy merge M-7 stracił dziedziczenie CI (+14 min), więc część zysku się przeniosła.
- **#626 (limity zależne od rozmiaru planu):** usuwa blokadę `epic_max_attempts` takich jak w ADMIN-FEATURES; teraz
  max(24, 2·15+4) = 34.
- **#561 (kontrakt wyniku):** usunął `invalid_output`; w obu epikach 0.
- **Rekomendacja z ADMIN-DESIGN (celowane e2e developera):** działa. Developer w ADMIN-FEATURES spędził w e2e 26 min,
  a w ADMIN-DESIGN około 187 min lokalnego e2e w całym epiku.

## 6. Ranking największych strat czasu

Pozycje nie sumują się wprost: przy innym harmonogramie przesunęłyby się w czasie, np. noc.

| # | Strata | Epik | Czas | Uniknione? | Dowód |
|---|---|---|---|---|---|
| 1 | Szeregowe zadania niezależnych fal | AF | **−8,1 h** (2 próby) / −10,3 h (3) [s, górna granica] | tak, przy planie z `paths` i hoście, który to udźwignie | symulacja; zależności w `tasks.depends_on`; kolejka AF-10 8 h 56 min, AF-14 6 h 04 min (`report.md`) |
| 2 | Rundy QA 2–5 z wywołanymi przestojami (noc, rano, ręczne domknięcie) | M | **≈ 9,1 h** [z/s] | w większości | `round-1..5.md`; `events` 03:30, 08:42, 11:27 |
| 3 | Czekanie `/delivery` na CI PR w szeregowej ścieżce | AF | **5,1 h** [z] (pokrywa się z #1) | tak, przy przekazaniu CI do AO albo równoległości | transkrypty: `gh pr checks --watch` w porcjach po 9,9 min |
| 4 | Nocne zatrzymanie AF-12 (test e2e z AF-8, limit 2/2) | AF | **3,25 h** [z] | tak | `events` 01:09, 05:50, 08:49 |
| 5 | Lokalne bramki `/integration` powtarzające CI (rundy 1–4) i e2e przerwane po 570 s | AF | **≈ 1,7 h** [z] | tak, już naprawione (#595) | `r{1..4}-gate-*.log`; sesja d38a1a87 10:11:20 |
| 6 | Dodatkowe rundy QA (FIX-2, INT-3, FIX-3, INT-4) i blokady limitów | AF | **1,9 h** [z] | w większości | `report.md` (Integracja) |
| 7 | Konflikt końcowego PR z `main`: czerwone CI, REPAIR-1, DELTA po REPAIR | AF | **1,8 h** [z] | częściowo | run 43873838971; PR #624; `epic_blocked` 15:21 |
| 8 | Koordynator METRICS: własny limit i błędne przekonanie o resecie | M | 84 min [z] (część #2) | 16 min tak | sesja 04c711ef 08:48:39–10:12:05 |
| 9 | Opóźnione odpytywanie developera uruchomionego asynchronicznie | oba | 42 min [z] | tak | 11 wywołań bez `run_in_background: false`, np. M-7 (8f2ca31d) 00:38–00:47 |
| 10 | Drobne: CI M-7 bez dziedziczenia; pełne CI #655 przez `bug.yml`; blokada skanera M-2; restart AF-7 | oba | 14 + ≈ 21 + ≈ 21 + 4 min | tak | `run.log`; PR #655; `events` 21:04 |

**Nie było strat [z]:**
- Kolejka runnerów: mediana 3 s, maksimum 130 s.
- Sondy `ao-prepare`: 31–36 s na start.
- Ponowienia API: 0.
- Wznowienia nie odbudowywały kontekstu.
- Odmowy uprawnień i blokady sandboxa: AF 4, METRICS 33 według `metrics.py`, koszt rzędu minut [s].

## 7. Analiza przyczyn źródłowych

Każda przyczyna (P) z objawami, warstwą i dowodami.

**P1. Zasady przekrojowe weryfikowane próbką i poprawiane punktowo.** Warstwa: proces (planner, `/integration`,
`qa-adversarial`, zlecenie FIX). Nie AO.
- Objawy:
  - METRICS: w każdej rundzie 2 nowe Important tej samej klasy (`report.md`: rundy 3–5).
  - ADMIN-FEATURES: F2 → F7 → F8 w jednej walidacji.
  - Ręczny audyt #668 znalazł 10 kolejnych miejsc naraz. Poprawka #668 sama wprowadziła nowe Important (sesja
    04c711ef 11:28:22: „skutek uboczny mojej poprawki M4”).
- Przyczyna:
  - Specyfikacja METRICS ma regułę „≈” w 8 miejscach (`docs/features/metrics/spec.md` l. 156–541), ale kryteria zadań
    jej nie powtarzały.
  - QA zgłasza 2–3 przykłady, a FIX naprawia tylko wskazane.
- To nie jest wada implementacji jednego agenta, tylko brak kroku „znajdź wszystkie miejsca”. Retro METRICS doszło do
  tego samego (PR #676, otwarty).

**P2. Polityka zatrzymań: twarde limity zamieniane w pytania z mechaniczną odpowiedzią.** Warstwa: `/ao-run`
(`--max-qa-rounds 3`), `/delivery` (2 rundy poprawek, krok 7), AO (DELTA po REPAIR lub FINALIZE zużywa rundę: AO #45,
#46 otwarte).
- Objawy:
  - 7 z 11 zatrzymań miało odpowiedź „tak” albo „+N” (AF-12, AF QA, AF próby, AF QA po REPAIR, M-3, M QA3, M FIX-3).
  - ≈ 8 h bezczynności AO, w tym 459 min w nocy [z].
- Limity same w sobie są potrzebne (chronią przed pętlą). Kosztowny jest brak jednego automatycznego przedłużenia,
  gdy znaleziska są nowe, a nie powtórzone, i brak trybu nocnego.

**P3. Szeregowość i CI na ścieżce krytycznej.** Warstwa: AO (do 2026-11-15 jedna próba naraz), plan, `/delivery`.
- Objawy:
  - ADMIN-FEATURES: 28 prób jedna po drugiej, 20% czasu prób to `gh pr checks --watch`.
  - METRICS: kolejka M-4 68 min i łańcuch 1→2→3→5→6/7.
- Przyczyny:
  - ADMIN-FEATURES ruszył na AO bez `--max-parallel`, a plan nie miał `paths`. Restart z równoległością odrzucił
    użytkownik o 18:19.
  - Plan METRICS dał 5 zadaniom ten sam plik (`paths` w `docs/features/metrics/plan.md`), więc `parallel_with` 4↔7
    było nieosiągalne przez zależności.
  - `/delivery` czeka na CI w próbie, choć AO obsługuje wynik `CI_PENDING` i sam pilnuje CI
    (`orchestrator/delivery.py` `POLICY_STATUSES`, README krok 2).

**P4. Niestabilne testy i obsługa sygnału CI.** Warstwa: jakość testów, AO i kolejność w `/integration`.
- Objawy:
  - ADMIN-FEATURES: 4 z 21 runów pusha czerwone (`DbalBackofficeOrderReaderTest` ×2, staff-dashboard,
    `PromotionsEndpointTest`).
  - METRICS: deadlock `VariantStockMigrationTest`.
- Rerun AO ukrył prawdziwą wadę: run pusha 43766233657 (22:54) padł na teście kwoty z AF-8 i przeszedł po rerunie, a
  2 h później ten sam test zablokował #614 na 7,7 h.
- `/integration` startuje na HEAD z odziedziczonym zielonym CI, a czerwień pusha AO zauważa dopiero po próbie:
  - METRICS INT-2 02:29–02:50, rerun 02:52 → F1 Important, zbędny fragment FIX-2;
  - ADMIN-FEATURES INT-1, rerun 80 min po czerwieni.

**P5. Rozjazd wersji procesu i konflikty z `main`.** Warstwa: `/ao-run`.
- Epik wykonuje skille ze swojej gałęzi. ADMIN-FEATURES dostał #595 dopiero z REPAIR, więc 116 min lokalnych bramek
  i nieudany UX.
- Końcowe PR obu epików zderzyły się z `main`:
  - ADMIN-FEATURES: wagi shardów z #569 i AF-15;
  - METRICS: `ao-run/SKILL.md`.
- Synchronizacja odbywa się dopiero, gdy końcowy PR jest czerwony.

**P6. Drobne defekty orkiestracji.** Warstwa: skille i koordynator.
- 11 wywołań developera poszło asynchronicznie wbrew `/delivery` krok 4 („bez `run_in_background`”), stąd 42 min
  opóźnionego odpytywania.
- Koordynator nie sprawdził `get_usage`, choć jego wywołania już przechodziły (15,5 min).
- `TaskStop` zabił całe drzewo procesów AO zamiast poczekać na koniec próby (4 min).
- Ponownie pytał o bramkę mimo zgody z góry (4,4 min przed startem).

**Klasyfikacja:**
- Organizacja pracy: P1, P2, decyzja o starcie przy 92% okna.
- Harness AO: P3 w części AO, P4 kolejność rerunu, AO #45/#46.
- Jakość implementacji: niestabilne testy, regresje rund M-3 i #668.
- Infrastruktura: brak udostępnienia `~/.local/state/ao-*` w Docker Desktop (udostępnione jest tylko
  `~/.local/state/shop-sweep`; ustawienia Docker Desktop, `settings-store.json`).
- Specyfikacja:
  - AF-1: sekcja 0 wobec testów (naprawione przez retro #626 #2);
  - M-2: reguła base64 skanera wobec nazw kluczy CLI.

**Czego nie potwierdzam:**
- Agenci nie analizowali prostych problemów zbyt długo, z dwoma wyjątkami: INT-5 w ADMIN-FEATURES (QA 35 min na
  DELTA, której różnicą był merge `main`) i FINALIZE (developer 17 min na usunięcie planu).
- Odbudowa kontekstu po wznowieniu była tania.
- Modele nie były przyczyną spowolnienia.
- Reguły bezpieczeństwa (sonda, sandbox, bramka pochodzenia) dodały minuty, nie godziny. Wyjątek to blokada M-2, która
  wynikała z błędu specyfikacji skanera.

## 8. Problemy już rozwiązane i nadal aktualne

| Problem | Stan | Dowód |
|---|---|---|
| `/integration` powtarza testy CI lokalnie, e2e przerwane po 570 s | **rozwiązany** dla nowych epików | #595 (`6ab4c861`); METRICS: bramki 3,5–4 min na rundę |
| Jedna próba naraz | **rozwiązany w narzędziu, nieskuteczny w praktyce** | AO #53 i #597; METRICS: 38 min równolegle; plany bez rozdzielnych `paths` |
| `epic_max_attempts` 24 dla dużych planów | **rozwiązany** | #626: max(24, 2N+4), czas max(16 h, 2,4 h·N) |
| `invalid_output` | **rozwiązany** | 0 w obu epikach |
| Sprzeczność kryteriów planu ze specyfikacją | **rozwiązany** (planner) | #626 #2 |
| Zgłoszenia jednej reguły w kolejnych rundach | **aktualny**; poprawka czeka | PR #676 otwarty |
| Limity rund wymagają „tak” człowieka, także w nocy | **aktualny** | `ao-run/SKILL.md` krok 4 (`--max-qa-rounds 3`), `/delivery` krok 7 |
| DELTA po REPAIR lub FINALIZE zużywa rundę QA | **aktualny** | AO #45, #46 otwarte; AF 15:21 |
| `/delivery` czeka na CI w próbie | **aktualny** | `/delivery` „Checki CI”; AF 308 min |
| Developer uruchamiany asynchronicznie, opóźnione odpytywanie | **aktualny** | 11 wywołań w obu epikach |
| `/integration` czyta czerwony push przed rerunem AO; rerun maskuje wadę | **aktualny** | METRICS INT-2; run 43766233657 |
| Niestabilne testy API (`PromotionsEndpointTest`, `VariantStockMigrationTest`) | **aktualny** | runy 43788303074, 43995822812 |
| `DbalBackofficeOrderReaderTest`, test kwoty staff-dashboard | **naprawione w epiku** | FIX-1 (#619), AF-12#2 (#614) |
| Przegląd UX w `/integration` nie działa (Docker) | **aktualny** (jakość, nie czas) | `round-1.md`; ustawienia Docker Desktop |
| Konflikt końcowego PR z `main` | **aktualny** | REPAIR-1 (#624), #672 |
| Koordynator nie sprawdza limitu przed czekaniem | **aktualny** | sesja 04c711ef 10:08:30 |
| Epik zamknięty ręcznie bez FINALIZE zostawia plan | **aktualny** (porządek) | `docs/features/metrics/plan.md` nadal na `main` `0e82a93` |
| `metrics.py`: `gate_wait` od pierwszej odmowy, brak rozkładu bez `epic_done` | **aktualny** (pomiar) | rekord METRICS; #673 |

## 9. Rekomendacje optymalizacyjne

Główna miara: czas od startu epiku do poprawnego merge'u. Zysk podaję dla epików podobnych do tych dwóch.

**R1. Pełny audyt zasady przy pierwszym znalezisku (P0).**
- Problem i dowód: P1; METRICS 8 z 11 Important w jednej klasie przez 5 rund; ADMIN-FEATURES F2 → F7 → F8.
- Przyczyna: QA podaje przykłady, a FIX poprawia przykłady.
- Zmiana:
  - zmergować PR #676 (zasada przekrojowa w kryteriach każdego zadania; jedno znalezisko na zasadę z listą wszystkich
    miejsc; punkt listy kontrolnej QA);
  - dopisać do zlecenia FIX: napraw wszystkie miejsca z listy i sprawdź `grep` po wyjściach.
- Gdzie: `.claude/skills/planner/SKILL.md`, `.claude/skills/integration/SKILL.md`, `.claude/agents/qa-adversarial.md`
  (PR #676).
- Wpływ: −1…−3 rundy, czyli −1…−2 h w AF i −2…−9 h w METRICS, jeśli limit trafia na noc [s].
- Ryzyko: runda 1 dłuższa o około 10–20 min [s]; jakość ↑.
- Pomiar: liczba rund na epik; Important tej samej zasady w więcej niż jednej rundzie (cel 0).

**R2. Jedno automatyczne przedłużenie zamiast pytania (P0, wymaga decyzji użytkownika).**
- Problem i dowód: P2; 7 z 11 zatrzymań z odpowiedzią „tak”; ≈ 8 h bezczynności AO, 459 min w nocy.
- Zmiana:
  - (a) `/ao-run`: przy `max_qa_rounds` bez Critical, gdy Important nie powtarza miejsc z poprzedniej rundy, sesja sama
    robi `epic resume --add-qa-rounds 1` raz na epik, z pushem informacyjnym; za drugim razem pyta.
  - (b) `/delivery`: trzecia runda poprawek bez pytania, gdy ostatni DELTA zgłosił nowe znalezisko (nie powtórzone)
    albo gdy wymagany check psuje test innego zadania tego samego epiku (przypadek AF-12). Potem `BLOCKED` jak dziś.
  - Blokery `business`, `architecture`, bezpieczeństwa i merge'e zostają przy człowieku.
- Gdzie: `.claude/skills/ao-run/SKILL.md` (sekcja 5), `.claude/skills/delivery/SKILL.md` (krok 7),
  `docs/features/ao-run/spec.md`.
- Wpływ: −3,25 h w AF i −4,4 h w METRICS [z: przy tych samych odpowiedziach, jakie dał użytkownik].
- Ryzyko: o jedną rundę więcej tokenów (około $3–10 [z: koszt rund]); mniej nadzoru nad pętlą, którą ogranicza
  „raz na epik”.
- Pomiar: zatrzymania z mechaniczną odpowiedzią (cel 0–1 na epik); bezczynność AO przez człowieka w nocy (cel 0);
  znaleziska `/sweep` i regresje bez wzrostu.

**R3. `/delivery` oddaje CI do AO i czeka na developera na pierwszym planie (P1).**
- Problem i dowód: P3, P6; 308 min czekania na CI w próbach AF; 42 min opóźnionego odpytywania.
- Zmiana:
  - (a) po PR i lokalnych bramkach wynik `CI_PENDING` zamiast `gh pr checks --watch` (AO już obserwuje CI, merguje
    zielone i uruchamia poprawkę przy czerwonym);
  - (b) w wywołaniu developera i reviewera jawne `run_in_background: false`.
- Gdzie: `.claude/skills/delivery/SKILL.md` (kroki 4, 6, 8, „Checki CI”). Przed wdrożeniem sprawdzić na jednym
  zadaniu, czy AO przy `--max-parallel` uruchamia kolejne zadanie, gdy poprzednie ma `CI_PENDING`
  (`orchestrator/core.py` `_drive`).
- Wpływ:
  - AF: do −4,7 h przy szeregowym AO [s];
  - przy 2 próbach naraz mniej, bo czekanie i tak się nakłada;
  - −42 min opóźnionego odpytywania [z].
- Ryzyko: czerwone CI po oddaniu oznacza nową próbę w trybie poprawki (wznowienie mierzone na około 1 min i 0,3–0,7 mln
  tokenów); częstsza utrata dziedziczenia CI przy merge'ach w locie (+14 min, jak M-7).
- Pomiar: `in_attempts.ci_watch` (cel ≤ 3 min/PR); czas od PR do startu kolejnej próby; opóźnione odpytywanie (cel 0).

**R4. Plan, który pozwala na równoległość (P1, ryzyko średnie).**
- Problem i dowód: P3; symulacja AF 20,4 → 12,3 h (2) / 10,1 h (3) [s]; METRICS 38 min równolegle.
- Zmiana:
  - planner tworzy fale z rozdzielnymi `paths` (raport par z #653 traktować jako błąd do poprawy, nie informację);
  - narzędzie z jednym dużym plikiem dzielić na moduły w pierwszym zadaniu, żeby kolejne nie dzieliły pliku;
  - `--max-parallel 2` zostaje; 3 dopiero po pomiarze obciążenia hosta.
- Gdzie: `.claude/skills/planner/SKILL.md`, `plan_manifest.py`, `/ao-run` (`MAX_PARALLEL` w `parallel_gate.py`).
- Wpływ: −4…−8 h w epikach typu AF [s]; mało w METRICS bez podziału modułów.
- Ryzyko:
  - obciążenie hosta (2–3 stosy Dockera i e2e, niezmierzone);
  - konflikty w fali;
  - szybsze zużycie okna limitu (AF +34 pp na tydzień).
- Pomiar: suma prób / czas epiku (cel ≥ 1,3); czekanie w kolejce przy wolnym miejscu (cel < 5 min); REPAIR
  i konflikty bez wzrostu.

**R5. Sygnał CI: rerun przed rundą i zapis niestabilności (P1).**
- Problem i dowód: P4; METRICS INT-2 F1; rerun 22:54 zamaskował wadę AF-8.
- Zmiana:
  - (a) `/integration`: czerwony push HEAD w częściach, których epik nie zmienia, najpierw `rerun --failed` (albo
    czekanie na rerun AO), a znalezisko dopiero po drugiej czerwieni;
  - (b) `/ao-run`: przy przejściu czerwony → zielony po rerunie zapisać nazwy testów i wysłać push; w tym samym epiku
    ten sam test dwa razy = zgłoszenie `agent`/`bug`;
  - (c) naprawić `PromotionsEndpointTest` (granica czasu) i `VariantStockMigrationTest` (deadlock).
- Gdzie: `.claude/skills/integration/SKILL.md` (`delta.py ci`), `.claude/skills/ao-run/SKILL.md` (zdarzenia),
  testy `apps/api/tests`.
- Wpływ: ≈ 10–20 min na rundę z niestabilnym testem [s]. Wczesne wykrycie wady takiej jak AF-8 pozwala uniknąć nocnej
  blokady (tu 3,25 h) [s].
- Ryzyko: niskie.
- Pomiar: czerwone runy pusha zamienione w znaleziska (cel 0); przejścia czerwony → zielony z zapisem (cel 100%).

**R6. Synchronizacja z `main` przed FULL i przed FINALIZE (P1).**
- Problem i dowód: P5; AF 1,8 h na konflikt końcowego PR; ≈ 100 min lokalnych bramek, bo epik nie miał #595; METRICS
  #672 (15 min ręcznie).
- Zmiana: `/ao-run` tworzy `sync/<epik>-main` (procedura już istnieje) przed pierwszą rundą FULL i przed FINALIZE,
  gdy `main` zmienił pliki dotknięte przez epik albo `.claude/**`.
- Gdzie: `.claude/skills/ao-run/SKILL.md`.
- Wpływ: −1…−1,8 h na konflikt [s]; poprawki procesu trafiają do epiku od razu.
- Ryzyko: dodatkowy run CI (około 13 min, poza ścieżką krytyczną, jeśli przed rundą); merge commit w gałęzi epiku (już
  dozwolony).
- Pomiar: REPAIR po końcowym PR (cel 0); czas od FINALIZE do zielonego końcowego PR.

**R7. Higiena koordynatora (P2).**
- Problem i dowód: P6; 15,5 + 4 + 4,4 min.
- Zmiana:
  - przed czekaniem na reset sprawdzić `get_usage`, a udane wywołanie traktować jak dowód, że limit jest dostępny;
  - nie pytać ponownie przy zgodzie z góry;
  - zatrzymywać AO po końcu próby, nie przez zabicie drzewa procesów.
- Gdzie: `.claude/skills/ao-run/SKILL.md`.
- Wpływ: minuty [z]. Ryzyko: brak.
- Pomiar: koordynator na ścieżce krytycznej (cel ≤ 10 min na epik).

**R8. Zakres CI dla `.github/ISSUE_TEMPLATE/**` (P2).**
- Problem i dowód: #655 dostał pełne CI (26 min wobec około 5 min) [z/s].
- Zmiana: dodać ścieżkę do zakresu dokumentacji.
- Gdzie: `.github/scripts/scope.sh`.
- Wpływ: ≈ 20 min na taki PR. Ryzyko: niskie (szablon issue nie wpływa na kod).

**R9. Przegląd UX w integracji (P2 dla czasu, ważne dla jakości).**
- Problem i dowód: w AF żaden ekran nie został obejrzany (`round-1.md`).
- Zmiana: `AGENT_DIR` przeglądu UX w udostępnionej ścieżce (np. pod `~/.local/state/shop-sweep/`) albo udostępnienie
  `~/.local/state` w Docker Desktop. To ustawienie użytkownika.
- Gdzie: `.claude/skills/integration/SKILL.md` (krok UX).
- Wpływ na czas: +10–20 min na rundę z frontami [s]. Ryzyko: niskie.

**R10. Pomiar i model (P2).**
- `metrics.py`:
  - `gate_wait` liczyć od decyzji o starcie, nie od pierwszej odmowy;
  - rozkład czasu dla epiku zamkniętego ręcznie (`BLOCKED` ze zmergowanym końcowym PR) (#673).
- Eksperyment Sonnet/Opus (#636) bez zmian. Te dane go nie rozstrzygają:
  - AF CRITICAL na Opusie: $17,9 i 82,7 min na zadanie;
  - STANDARD na Sonnecie: $11,2 i 72,3 min;
  - FULL z poprawkami: 5 z 6 wobec 4 z 9.

## 10. Plan zmian

**Szybkie (zmiany skilli i dokumentacji, każda do około pół dnia, bez kodu AO):**
1. Merge PR #676 (R1), już przygotowany.
2. Decyzja użytkownika o R2. Po zgodzie zmiana `ao-run` (sekcja 5) i `/delivery` (krok 7) ze specyfikacją `ao-run`.
3. R3b: jawne `run_in_background: false`.
4. R7: higiena koordynatora.
5. R8: zakres CI dla `.github/ISSUE_TEMPLATE/**`.
6. R9: ścieżka `AGENT_DIR` (albo ustawienie Docker Desktop po stronie użytkownika).

**Średni termin (1–3 dni, weryfikacja na dwóch kolejnych epikach):**
1. R3a: wynik `CI_PENDING` po PR, po próbie na jednym zadaniu.
2. R5: rerun przed rundą, zapis niestabilności, naprawa dwóch testów.
3. R6: synchronizacja przed FULL i FINALIZE.
4. R10: poprawki `metrics.py`, żeby mierzyć kolejne epiki, także zamknięte ręcznie.

**Zmiany architektoniczne (wymagają zgody na pracę w AO, które jest zamrożone poza #50):**
1. R4: plany z rozdzielnymi modułami i równoległość jako domyślny tryb, z pomiarem obciążenia hosta przed
   `--max-parallel 3`.
2. AO #45 i #46: DELTA po REPAIR lub FINALIZE nie zużywa rundy QA.
3. Polityka przedłużeń (R2) w AO zamiast w sesji, z trybem nocnym, jeśli wersja w skillu się sprawdzi.

## 11. Proponowane metryki

Źródła: `metrics.py trend` (kolumny `to_done`, `work`, `human_wait`, `review`, `repeat_runs`, `value`,
`interventions`), raporty rund i oś czasu sesji koordynującej. Cele dotyczą epików z niezależnymi falami.

| Metryka | Definicja | AF | METRICS | Cel |
|---|---|---|---|---|
| Czas do merge'u na zadanie planu | start → merge końcowego PR / zadania planu | 2,0 h | 2,4 h | ≤ 1,2 h |
| Bezczynność AO przez człowieka | czas bez próby z blokerem lub limitem / w tym noc | 3,6 h / 3,25 h | 6,7 h / 4,4 h | ≤ 0,5 h / 0 |
| Zatrzymania z mechaniczną odpowiedzią | odpowiedź „tak” albo „+N” | 4 | 3 | 0–1 |
| Rundy QA i powtórzenia zasady | rundy; Important tej samej zasady w więcej niż jednej rundzie | 5; 3 | 5; 8 | ≤ 2; 0 |
| Czekanie na CI w próbach | `ci_watch` / PR zadań | 15,4 min | 5,9 min | ≤ 3 min |
| Współbieżność | suma prób / czas do merge'u | 0,84 | 0,54 | ≥ 1,3 |
| Lokalne bramki `/integration` | min na rundę | 23 | 3,7 | ≤ 5 |
| Opóźnione odpytywanie developera | min na epik | 14 | 29 | 0 |
| Koordynator na ścieżce krytycznej | min na epik | 4 | 97 | ≤ 10 |
| Niestabilne CI | czerwone runy pusha naprawione rerunem; czerwienie zamienione w znaleziska | 4; 1 | 1; 1 | zapis 100%; 0 |
| Koszt zadania planu (strażnik) | wartość tokenów wykonawców / zadania planu | $16,8 | $16,9 | ≤ +10% |
| Jakość (strażnik) | Critical i Important po merge'u (`/sweep`, `bug` w 14 dni), Important w rundzie 1 wobec kolejnych | `/sweep`: 5 propozycji | brak `/sweep` | bez wzrostu |

Sposób oceny: dwa kolejne epiki po wdrożeniu R1–R3, osobno czas zegarowy i tokeny. Epik zamknięty ręcznie musi mieć
policzony rozkład (R10), inaczej wypada z porównania.

## Odpowiedzi na pytania A–E

**A. Ile czasu straciliśmy i na czym.**
- **ADMIN-FEATURES (29,9 h): ≈ 9 h zmierzonych strat [z].**
  - nocne zatrzymanie AF-12: 3,25 h;
  - dodatkowe rundy QA: 1,9 h;
  - konflikt z `main` przy końcowym PR: 1,8 h;
  - lokalne bramki powtarzające CI: 1,7 h (już naprawione);
  - opóźnione odpytywanie i restart: 0,3 h.

  Do tego koszt szeregowości: faza zadań krótsza o ≈ 8 h przy 2 próbach naraz [s, górna granica].
- **METRICS (16,7 h): ≈ 10,5 h strat [z/s].**
  - rundy QA 2–5 z wywołanymi przez nie przestojami (noc 4,4 h, rano 2,2 h, ręczne domknięcie 0,7 h, dodatkowe rundy
    1,6 h): ≈ 9,1 h; w tym 84 min limitu i błędu koordynatora;
  - opóźnione odpytywanie: 0,5 h;
  - pełne CI #655, CI M-7 i blokada skanera M-2: ≈ 0,9 h.

**B. Trzy największe przyczyny spowolnienia.**
1. Zasady przekrojowe sprawdzane próbką i poprawiane punktowo: kolejne rundy QA, METRICS ≈ 9 h, AF ≈ 2 h.
2. Limity zamieniane w pytania z odpowiedzią „tak”, także w nocy: ≈ 8 h bezczynności AO.
3. Szeregowe wykonanie i czekanie na CI w próbie: AF 5,1 h czekania na CI, kontrfaktycznie −8 h. Plan METRICS
   zablokował równoległość jednym plikiem.

**C. Pięć zmian o najlepszym stosunku korzyści do ryzyka.**
1. R1: pełny audyt zasady przy pierwszym znalezisku (PR #676).
2. R2: jedno automatyczne przedłużenie rund QA i poprawek zamiast pytania.
3. R3: `CI_PENDING` po PR i developer na pierwszym planie.
4. R6: synchronizacja z `main` przed FULL i FINALIZE.
5. R4: plany z rozdzielnymi `paths` i podziałem dużych plików, przy `--max-parallel 2`. Ryzyko średnie, największy
   zysk w epikach typu AF.

**D. O ile można skrócić podobne epiki.**
Wiarygodnie tylko w przedziale [s]:
- epik typu ADMIN-FEATURES: 29,9 h → około 13–17 h (−43…−57%);
- epik typu METRICS: 16,7 h → około 7–9 h (−46…−58%).

Pewność oszacowania:
- **Średnia** dla usunięcia zmierzonych przestojów i rund (R1, R2, R6).
- **Niska** dla równoległości (R4): symulacja bez obciążenia hosta i konfliktów.
- Dwa epiki to mała próba.

**E. Od czego zacząć.**
1. Merge PR #676 (R1), gotowy i bez ryzyka.
2. Decyzja o R2. To najtańsza zmiana, która usuwa najdłuższe przestoje.
3. R3b (`run_in_background: false`) i R7, które są trywialne.

Następnie R3a i R6 do pomiaru na kolejnym epiku z niezależnymi falami. R4 po tym pomiarze.
