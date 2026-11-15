# Wydajność AO w epikach AGENT-SECURITY i SENTRY: czas, koszt i optymalizacje

Rewizja: `main` `a964e94` (oba końcowe PR zmergowane: SENTRY #539 2026-11-14, AGENT-SECURITY #548 2026-11-15),
AO `a178eaf`, Claude Code 2.1.311. Oba epiki szły równolegle jako para kroku 19a (14.11, 10:52–01:09).
Oznaczenia: **[z]** zmierzone, **[s]** szacowane.

## Źródła i metodologia

- **Baza AO** (`~/.local/state/ao-{sentry,agent-security}/orchestrator.db`, tylko odczyt): `tasks`, `attempts`
  (`wall_s`, `cost_usd`, `outcome`), `events` (zmiany stanu, CI epiku, rundy integracji), `ao report`
  (`epics/<epik>/report.md`: czekanie w kolejce, bezczynność, interwencje).
- **Transkrypty prób** (`~/.claude/projects/*/<session_id>.jsonl` i `subagents/agent-*.jsonl` z `meta.json`):
  czas każdego wywołania narzędzia to różnica znaczników czasu `tool_use` i `tool_result`; czas subagenta to rozpiętość
  znaczników jego transkryptu; tokeny z `usage` wiadomości asystenta (bez powtórzeń tego samego `message.id`).
- **Klasy poleceń Bash** (dopasowanie wzorca polecenia): czekanie na CI (`gh pr checks --watch`, `gh run watch`
  i pętle z `gh`), testy (`make … test`, phpunit, vitest, unittest), `make check` i linty, lokalne e2e (`make e2e`),
  stos (`make up`, `docker compose …`), reszta.
- **Koszt roli:** tokeny × ceny dopasowane metodą najmniejszych kwadratów do `modelUsage.costUSD` z 40 wyników CLI
  (Opus 5.5: 4 / 20 / 0,2 / 5 / 8 USD za 1M tokenów wejścia / wyjścia / odczytu cache / zapisu cache 5 min / 1 h;
  Sonnet 5.5: połowa). Suma odtworzona $223,1 wobec $229,5 z CLI (−3%).
- **CI:** `gh run list` gałęzi `task/*` (czas od utworzenia do końca runu).
- **Logi bramek `/integration`** (`epics/<epik>/integration/*gate-*.log`): kod wyjścia, liczba testów, czas.
- **Symulacja równoległości:** czasy prób bez zmian, zależności z manifestu, merge'e po kolei, ta sama chwila
  odpowiedzi człowieka; liczy tylko ścieżkę krytyczną, bez konkurencji o CPU hosta.

## Ograniczenia szacunków

- Koszt to szacunek Claude CLI po cenach katalogowych (`total_cost_usd`), nie opłata w subskrypcji. Faktyczne zużycie
  to punkty procentowe okna limitu: para 19a SENTRY + AGENT-SECURITY podniosła okno tygodniowe o 19 pkt (`pairs.py`).
- Podział kosztu na role to rekonstrukcja z tokenów (błąd około 3%). Jedna próba developera użyła `claude-opus-4-8`
  (80 wiadomości); policzona cenami Opus 5.5.
- Czas subagenta to rozpiętość jego transkryptu: obejmuje jego własne czekanie na testy, nie obejmuje czasu orkiestratora.
- Klasyfikacja poleceń jest heurystyczna; pętle `sleep` orkiestratora czekającego na agenta w tle trafiają do „reszty”.
- Oba epiki działały naraz na jednym hoście i współdzieliły runnery GitHuba; czasy CI (11–15,5 min na PR zadania
  wobec około 8,5 min w pomiarze z 14.11) mogą być zawyżone konkurencją.
- Symulacja równoległości pomija obciążenie hosta drugim stosem, dodatkowe konflikty w fali i limity użycia.
- Dwa epiki to mała próba; AGENT-SECURITY-3 (46% kosztu epiku) jest odstające.

## Wynik

| | SENTRY | AGENT-SECURITY |
|---|---|---|
| Czas zegarowy [z] | 8h38m (10:52–19:30) | 14h04m (11:05–01:09) |
| Praca wykonawcy [z] | 8h05m | 13h13m |
| Zadania | 5 planu, FIX-1, 2 rundy QA, FINALIZE | 5 planu, FIX-1, 3 rundy QA, FINALIZE, REPAIR-1 |
| Próby (nieudane) [z] | 9 (0) | 17 (2 × `invalid_output`) |
| Interwencje człowieka [z] | 0 | 4 (odpowiedzi po 3–9 min) |
| Koszt CLI [z] | $81,84 | $147,64 (AGENT-SECURITY-3: $68,08) |
| Koszt zadania planu [z] | $13,4 | $23,8 ($12,8 bez AGENT-SECURITY-3) |
| Rundy poprawek po review FULL [z] | 4 z 5 zadań | 4 z 5 zadań |

### Czas według czynności [z] (suma po próbach, minuty)

| Czynność | SENTRY | AGENT-SECURITY |
|---|---|---|
| Developer (implementacja, rundy poprawek, własne testy) | 187 | 437 |
| `code-reviewer` (przebiegi) | 55 (11) | 104 (14) |
| Czekanie orkiestratora `/delivery` na CI PR | 127 | 71 |
| `/integration` razem / w tym lokalne bramki | 62 / 34 | 117 / 80 |
| Czekanie w kolejce AO (promocja → start), najdłuższe | SENTRY-4 4h16m, SENTRY-3 3h10m, SENTRY-2 1h42m | AGENT-SECURITY-4 2h06m, AGENT-SECURITY-3 1h00m + ponowienia 1h38m |
| Bezczynność AO bez otwartej próby | 32 min | 49 min (człowiek 22 min) |

### Koszt według roli i modelu [z]

| Rola | SENTRY | AGENT-SECURITY | Razem | Udział |
|---|---|---|---|---|
| Developer | $38,1 | $75,8 | $113,9 | 51% |
| Orkiestrator sesji (`/delivery`, `/integration`) | $21,1 | $32,9 | $54,0 | 24% |
| `code-reviewer` | $17,6 | $23,0 | $40,6 | 18% |
| `architecture-reviewer`, `qa-adversarial`, `ux-reviewer` | $4,9 | $9,5 | $14,4 | 6% |

- Etapy: zadania planu 81%, rundy `/integration` 11%, FIX, FINALIZE i REPAIR 8%.
- Opus to 85% kosztu. Developer Opus: 12 sesji, 400 min, $89,3 ($0,22/min); developer Sonnet: 12 sesji, 226 min,
  $24,6 ($0,11/min). Opus wybierano przy ryzyku CRITICAL, które miało 8 z 10 zadań planu.
- Zapisy cache to 31% kosztu, ale żaden nie nastąpił po przerwie dłuższej niż 5 min (orkiestrator ma cache 1 h):
  wygasanie cache przy czekaniu na CI nie kosztuje.

### Próby [z]

Czasy w minutach, koszt w USD. „Czekanie na CI PR” to polecenia czekania orkiestratora; „Lokalne bramki” to
`make check`, `make test` i `make e2e` w `/integration`.

| Próba | Start | Min | Koszt CLI | Orkiestrator $ | Developer min / $ | code-reviewer min / $ | Inni agenci min / $ | Czekanie na CI PR min | Lokalne bramki min | Wynik |
|---|---|---|---|---|---|---|---|---|---|---|
| AGENT-SECURITY-1#1 | 11:11 | 29 | 9,9 | 2,2 | 23,5 / 5,5 | 7,5 / 2,2 | – | 1 | – | READY_FOR_INTEGRATION |
| AGENT-SECURITY-2#1 | 11:42 | 27 | 7,8 | 1,5 | 15,0 / 4,6 | 6,1 / 1,7 | – | 1 | – | READY_FOR_INTEGRATION |
| AGENT-SECURITY-3#1 | 12:10 | 65 | 21,5 | 2,0 | 52,3 / 11,6 | 6,5 / 1,6 | – | – | – | BLOCKED |
| AGENT-SECURITY-4#1 | 13:16 | 96 | 10,7 | 2,9 | 62,2 / 4,9 | 22,9 / 2,9 | – | 19 | – | READY_FOR_INTEGRATION |
| AGENT-SECURITY-3#2 | 14:53 | 68 | 15,3 | 2,1 | 55,1 / 13,3 | – | – | – | – | BLOCKED |
| AGENT-SECURITY-3#3 | 16:11 | 72 | 16,4 | 2,0 | 47,8 / 10,2 | 12,1 / 4,1 | – | – | – | invalid_output |
| AGENT-SECURITY-3#4 | 17:25 | 2 | 0,9 | 0,9 | – | – | – | – | – | BLOCKED |
| AGENT-SECURITY-3#5 | 17:31 | 67 | 8,9 | 2,8 | 37,6 / 4,0 | 7,1 / 2,1 | – | 11 | – | BLOCKED |
| AGENT-SECURITY-3#6 | 18:49 | 46 | 5,0 | 2,0 | 14,4 / 1,7 | 6,0 / 1,3 | – | 13 | – | READY_FOR_INTEGRATION |
| AGENT-SECURITY-5#1 | 19:36 | 110 | 22,7 | 2,9 | 91,6 / 15,7 | 14,4 / 4,1 | – | 2 | – | READY_FOR_INTEGRATION |
| AGENT-SECURITY-INTEGRATION-1#1 | 21:28 | 52 | 8,4 | 2,6 | – | – | 23,9 / 5,8 | – | 31 | FINDINGS |
| AGENT-SECURITY-FIX-1#1 | 22:21 | 20 | 3,4 | 1,3 | 7,9 / 1,1 | 6,1 / 1,0 | – | 2 | – | invalid_output |
| AGENT-SECURITY-FIX-1#2 | 22:43 | 2 | 0,6 | 0,6 | – | – | – | – | – | READY_FOR_INTEGRATION |
| AGENT-SECURITY-INTEGRATION-2#1 | 22:46 | 26 | 2,9 | 1,6 | – | – | 9,3 / 1,3 | – | 15 | PASSED |
| AGENT-SECURITY-FINALIZE#1 | 23:13 | 8 | 3,2 | 1,4 | 5,6 / 1,8 | – | – | – | – | READY_FOR_INTEGRATION |
| AGENT-SECURITY-REPAIR-1#1 | 23:24 | 64 | 5,4 | 2,1 | 24,3 / 1,3 | 15,6 / 2,0 | – | 22 | – | READY_FOR_INTEGRATION |
| AGENT-SECURITY-INTEGRATION-3#1 | 00:30 | 39 | 4,5 | 2,1 | – | – | 10,4 / 2,4 | – | 34 | PASSED |
| SENTRY-1#1 | 10:54 | 100 | 19,4 | 2,2 | 62,5 / 14,1 | 10,9 / 3,2 | – | 21 | – | READY_FOR_INTEGRATION |
| SENTRY-2#1 | 12:35 | 86 | 19,8 | 2,9 | 35,4 / 12,1 | 12,8 / 4,7 | – | 28 | – | READY_FOR_INTEGRATION |
| SENTRY-3#1 | 14:02 | 65 | 10,6 | 2,5 | 27,2 / 5,0 | 11,7 / 3,0 | – | 26 | – | READY_FOR_INTEGRATION |
| SENTRY-4#1 | 15:08 | 52 | 4,5 | 2,1 | 13,8 / 1,1 | 3,2 / 1,2 | – | 19 | – | READY_FOR_INTEGRATION |
| SENTRY-5#1 | 16:02 | 89 | 12,7 | 3,7 | 40,3 / 4,3 | 13,5 / 4,6 | – | 22 | – | READY_FOR_INTEGRATION |
| SENTRY-INTEGRATION-1#1 | 17:33 | 49 | 7,3 | 3,2 | – | – | 20,3 / 4,0 | – | 27 | FINDINGS |
| SENTRY-FIX-1#1 | 18:23 | 25 | 2,9 | 1,5 | 4,8 / 0,5 | 2,5 / 0,9 | – | 12 | – | READY_FOR_INTEGRATION |
| SENTRY-INTEGRATION-2#1 | 18:50 | 13 | 2,4 | 1,5 | – | – | 4,5 / 1,0 | – | 7 | PASSED |
| SENTRY-FINALIZE#1 | 19:04 | 6 | 2,3 | 1,4 | 3,4 / 0,9 | – | – | – | – | READY_FOR_INTEGRATION |

CI PR zadań [z]: 11,1–15,5 min przy pełnym zakresie, 0,4–2,0 min przy samej dokumentacji lub narzędziach; dwa runy
mają tylko #529 (SENTRY-2) i #535 (AGENT-SECURITY-3, pierwszy czerwony: testy narzędzi na Linuksie).

## Wąskie gardła

1. **Jedna próba naraz (powtarzalne).** `attempts.active UNIQUE` i pętla `_drive` w AO prowadzą jedno zadanie naraz.
   SENTRY-1…4 nie zależały od siebie (api, store, admin, chart), a szły po kolei: SENTRY-4 czekał 4h16m. W
   AGENT-SECURITY zadanie AGENT-SECURITY-3 ruszyło godzinę po starcie, a po odpowiedziach człowieka (3–9 min) czekało
   łącznie 1h38m za AGENT-SECURITY-4. Ten sam wzorzec był w INVENTORY (`plan-v0.3.md`, ustalenie 10) i DEPLOY-OPS (AO #50).
   Symulacja [s]: SENTRY przy 2 próbach naraz około 5h55m (−2h45m), przy 4 około 4h50m; AGENT-SECURITY przy 2
   około 11h30m (−2h35m, ścieżką krytyczną zostaje łańcuch AGENT-SECURITY-3).
2. **`/integration` powtarza testy CI (powtarzalne).** CI pusha gałęzi epiku testuje dokładnie HEAD rundy, a w żadnej
   z 5 rund wynik nie został użyty: w FULL lokalne bramki są obowiązkowe, a w DELTA `delta.py ci` sprawdza run na
   początku rundy, gdy jest jeszcze w toku („job checks … is in_progress”). Lokalne bramki: 31, 15, 34, 27 i 7 min,
   razem 114 min [z]. Ten sam zestaw e2e szedł więc około trzy razy na drzewo: CI PR, CI pusha, lokalnie.
3. **Lokalne `make e2e` dłuższe niż `timeout 570` (powtarzalne od ADMIN-SCREENS).** 190 testów na jednym workerze trwa
   około 9,2 min. Trzy przebiegi (AGENT-SECURITY runda 1 i 3, SENTRY runda 1) przerwano dokładnie po 570 s przy
   przechodzących testach (`make: *** [e2e] Error 130` przy teście 165–187) i powtórzono od zera: około 10 min na
   rundę [z]. Przerwanie z kodem 143 przy `gate-e2e-retry-2` (AGENT-SECURITY runda 1) zbiegło się z restartem Dockera
   (`stack-restart.log`), co opisuje #564; pozostałe trzy to limit czasu.
4. **Opus dla developera CRITICAL (powtarzalne).** Developer to 51% kosztu, Opus kosztuje na minutę dwa razy więcej
   niż Sonnet. Wpływ modelu na jakość jest niezmierzony (pomiar z 13 epików z 2026-11-09 też go nie rozstrzygnął).
5. **Specyficzne dla zadania:**
   - AGENT-SECURITY-3: blokery B1–B8 odkrywane po kolei w sandboxie (6 prób, $68,08, około 5,5 h ścieżki krytycznej);
     przyczynę (brak eksperymentu na przypiętej wersji przed startem) naprawił retro #561.
   - AGENT-SECURITY-REPAIR-1 i runda 3: konflikt końcowego PR z `main` po merge'u SENTRY (wspólne pliki pary),
     1h43m i $9,9.
   - AGENT-SECURITY-3#5: testy narzędzi zielone na macOS, czerwone na Linuksie w CI po wyczerpaniu dwóch rund poprawek.

**Bez problemu [z]:** ponowienie po `invalid_output` kosztowało $0,94 i $0,60 (praca nie przepadła; przyczynę w
kontrakcie wyniku naprawił #561); review DELTA średnio około $1, a review FULL znalazło poprawki w 8 z 10 zadań;
start stosu zadania około 1 min; READY → merge 13 s; pełne zestawy testów developerów razem około 1 h, jako wymagane
bramki przed PR; dziedziczenie CI przy merge'u działało w 15 z 15 merge'y.

## Ranking optymalizacji

| # | Zmiana | Zysk | Trudność | Ryzyko |
|---|---|---|---|---|
| 1 | AO #50: 2 niezależne próby naraz, merge'e po kolei | [s] −2h35m…−2h45m na epik, tokeny bez zmian | średnia (AO) | obciążenie hosta, konflikty w fali, szybsze zużycie okna |
| 2 | `/integration`: testy z CI pusha dla HEAD w FULL i DELTA, z czekaniem na run w toku | [s] około 18 min na rundę | niska | niskie |
| 3 | Fallback e2e w częściach poniżej 570 s | [z] około 10 min na przerwaną rundę | niska | niskie |
| 4 | Eksperyment Sonnet/Opus dla developera CRITICAL | [s] do około −25% kosztu epiku, jeśli jakość się utrzyma | niska | brak zmiany przed wynikiem |
| 5 | Docs-only FINALIZE bez czekania na pełne CI pusha | [z] około 20 min w SENTRY | średnia | średnie |
| 6 | Testy narzędzi w kontenerze Linux przed pushem | [z] około 2 h raz w AGENT-SECURITY | niska | niskie |
| 7 | PR i CI równolegle z pierwszym review | [s] mało: 8 z 10 zadań i tak miało rundę poprawek | niska | niskie |
| 8 | Bez `--force` przy wspólnych plikach pary epików | [z] około 1h40m na kolizję | brak (zasada) | wolniejszy start |

Przyjęte do realizacji (decyzja użytkownika 2026-11-15): 1, 2 z 3 oraz 4 jako eksperyment.

## Kryteria oceny po wdrożeniu

Na dwóch kolejnych epikach po wdrożeniu, osobno dla czasu zegarowego i tokenów:

- **Czas:** czekanie w kolejce przy wolnym miejscu poniżej 5 min; stosunek sumy czasu prób do czasu zegarowego epiku
  powyżej 1,3; lokalne bramki `/integration` najwyżej 5 min na rundę przy zielonym CI pusha; zero przebiegów e2e
  przerwanych po 570 s; runda FULL najwyżej 30 min, DELTA najwyżej 15 min.
- **Tokeny:** koszt zadania planu i rundy integracji wobec bazy z tego raportu ($13,4 na zadanie planu w SENTRY,
  $2,4–8,4 na rundę); równoległość i CI w integracji nie powinny zmienić tokenów o więcej niż ±10%.
- **Jakość i niezawodność:** próby na zadanie najwyżej 1,2; REPAIR, konflikty w fali i nieudane `setup` bez wzrostu;
  znaleziska Critical i Important w integracji i po merge'u (`/sweep`, `bug`) bez spadku wykrywalności.
