# Eksperyment DELIVERY-001: benchmark `/delivery` v2.0, Superpowers i czystego Claude Code

**Wynik: benchmark zatrzymany po Etapie I.** Smoke test wykrył istotny błąd workflow `/delivery`: po przerwaniu
procesu stan taska nie pozwala wznowić pracy (brak zapisu stanu po powrocie developera). Zgodnie z warunkami
eksperymentu Etapy II–IV nie zostały uruchomione. Skill nie został zmieniony.

## 1. Metodologia i warunki

- Data: 2026-10-30. Bazowy commit: `785ebfcb1e26686ba0dc5bdeb19b04c55fd7e529` (`main`).
- Narzędzia: Claude Code 2.1.308, Docker 29.7.2, Docker Compose 5.4.0, git 2.54.0, gh 2.103.2, macOS 26.6.2,
  16 CPU, 128 GB RAM. Plugin superpowers 6.4.2 (źródło `synced`).
- Orkiestrator eksperymentu: główna sesja Claude Code (Opus 5.5). Wykonawcy: osobne procesy
  `claude -p --model opus --effort high --permission-mode auto --permission-prompts none --max-budget-usd 80`.
  Tryb `bypassPermissions` został odrzucony przez klasyfikator auto mode; `auto` to bezpieczniejszy zamiennik.
- Katalog eksperymentu poza repo: `~/Development/claude/shop-bench/delivery-001`
  (`runs/`, `logs/`, `state/`, `eval/`, `prompts/`, `tools/`, `hidden-archive/`).
- Izolacja przebiegu (`tools/mkrun.sh`): własne bare repo `origin` zawierające tylko `main` = baza taska, własny
  klon na gałęzi `feature/<slug>`, własny projekt Compose `d001-<run>`, porty `<P>xxx`, własna podsieć
  `10.210.<P>.0/24` (lokalny `compose.override.yaml` w `.git/info/exclude`; domyślne pule adresów Dockera na tym
  hoście są wyczerpane przez nieużywane sieci innych projektów, których nie ruszałem).
- Brak dostępu do GitHuba w przebiegach: `GH_TOKEN` niepoprawny, pusty `GH_CONFIG_DIR`, `GIT_SSH_COMMAND=false`.
  Bezpiecznego repo testowego na GitHubie nie ma (na koncie są tylko repozytoria robocze), więc PR i GitHub CI
  są **nieprzetestowane**.
- Wariant C wymuszony flagami: `--disable-slash-commands`, `--settings '{"disableAllHooks":true}'` (bez hooka
  SessionStart superpowers), `--disallowedTools Agent Task Workflow`. Sprawdzone zdarzeniem `init`: brak skilli
  i narzędzi Agent/Task/Workflow.
- Metryki: `stream-json` (`tools/metrics.py`), wynik `result` (`total_cost_usd`, `duration_ms`,
  `duration_api_ms`), transkrypty z `~/.claude/projects` (także subagentów) skopiowane do `logs/transcripts/`.
  Koszt to `total_cost_usd` z CLI (cennik API), nie rozliczenie subskrypcji.

Przygotowane, ale nieuruchomione (z powodu zatrzymania):
- Specyfikacje tasków jako osobne bazowe commity w `seed.git`: T1 FAST `CAT-UNKNOWN-CATEGORY` (`b70a9e0`,
  nieznana kategoria → 404 w API i 404 w sklepie), T2 STANDARD `CUSTOMER-ADDRESSES` (`695b545`, książka adresowa
  z nową tabelą), T3 CRITICAL `PRODUCT-RENAME` (`4b5724b`, zmiana nazwy z optimistic locking, uprawnienia,
  outbox), T4 do testu BLOCKED `CATEGORY-DELETE` (`35ce6a9`, celowo bez reguły dla kategorii z produktami).
- Ukryte testy (napisane przed obejrzeniem jakiejkolwiek implementacji, poza katalogami wykonawców, hashe
  w `eval/hidden-tests-sha256-v2.txt`). Na bazie są czerwone: T1 3/7 i 2/3 (sklep), T2 28/29, T3 26/28, w tym
  test prawdziwego wyścigu (serwer `php -S` z 12 workerami, 12 równoległych `PATCH`). Wersja v1 miała mój błąd
  (`status()` koliduje z finalną metodą PHPUnit); poprawiony przed uruchomieniem jakiegokolwiek przebiegu.
- Prompty: wspólny blok wymagań (`prompts/common.txt`), różni się tylko wywołanie workflow
  (`tools/mkprompt.sh`). Stosy T1 A/B/C uruchomione, potem zatrzymane (`docker compose stop`).

Baza przed smoke testem na stosie `d001-s0-a`: `make check` OK, `make test` OK (API 597 testów, sklep 21 plików,
panel 11 plików).

## 2. Wynik smoke testu

Task: `CAT-NAME-TRIM` (FAST): przycinanie `ProductName` i `CategoryName`. Spec: `9dad674`.

### Przebieg s0-A (pełny, bez przerwania)

| Kryterium | Wynik | Dowód |
|---|---|---|
| Skill wywołany | tak | `<command-name>/delivery` i treść SKILL.md w transkrypcie `f912ccb8…` |
| Czyta spec i `CLAUDE.md` | tak | pierwsze wywołania: spec, `apps/api/CLAUDE.md`, kod VO |
| Właściwy checkout | tak | `pwd`, gałąź, HEAD sprawdzone przez orkiestratora i developera |
| Delegacja do `developer` | tak | 1 × `developer` (Sonnet 5.5), bez kolejnych subagentów |
| RED z właściwego powodu → GREEN | tak | RED: 2 failures (`'Buty'` ≠ `'  Buty \n'`) + 2 errors (200 znaków z białymi znakami odrzucane); GREEN: 225 OK |
| Bramki uruchomione przez orkiestratora | tak | `make -C apps/api check` OK, `make -C apps/api test` OK (606 testów) |
| Review | FAST: własna kontrola diffu | zgodnie ze skillem; `code-reviewer` nieuruchomiony |
| Commit i SHA | poprawne | 1 commit `0826dc8` (`fix(api): …`), push na lokalny `origin` |
| Status | `CI_PENDING` | poprawny: PR/CI niedostępne; nie zgłosił fałszywego `READY_FOR_INTEGRATION` |
| JSON | poprawny | wszystkie pola kontraktu; wynik == plik stanu |
| Ukryte testy | 10/10 | `eval/s0-A-hidden.log` |
| Zapis stanu w trakcie | **nie** | zapisy tylko w kroku 5 (preflight) i 12–14 (koniec); żadnego po powrocie developera (krok 7) |

Zużycie: 0,83 USD, 7 min 40 s wall, 153 s API, 15 tur; Opus (orkiestrator) + Sonnet (developer).

### Przebieg s0r-A (kontrolowane przerwanie i wznowienie)

1. Sesja 1: watcher (`tools/interrupt-after-agent.sh`) wysłał `SIGTERM` 5 s po wyniku wywołania `developer`.
   Commit developera `d569ccb` był już na gałęzi, orkiestrator nie zaczął jeszcze bramek. Developer miał prawdziwe
   RED (4 errors, 4 failures) i GREEN (236 OK); widać to w jego transkrypcie.
2. Plik stanu w chwili przerwania (`logs/state-at-interrupt.json`): `IN_PROGRESS`, `phase: implementation`,
   `head_sha` = baza `9dad674`, `checks: []`.
3. Sesja 2: nowy proces z tym samym poleceniem i plikiem stanu (jak orkiestrator 24/7). Wykrył HEAD ≠ `head_sha`,
   zbadał historię, uruchomił `check` (OK) i `test` (OK, 617), po czym zwrócił **`BLOCKED`/`technical`** z
   pytaniem: przyjąć commit bez dowodu TDD czy zaimplementować od nowa. Opcje i rekomendacja są sensowne.
   Kosztowało to 0,57 USD i 3 min 24 s.

Ocena: zachowanie bezpieczne i zgodne z literą skilla (niewyjaśniona zmiana → `BLOCKED`), JSON poprawny i równy
stanowi. **Wznowienie jednak nie działa:** stan nie zachował SHA ani etapu, więc praca nie mogła być kontynuowana
bez człowieka. To kryterium RESUME wprost (task, SHA, etap). Przerwanie było w punkcie, który według reguł samego
skilla powinien być bezpieczny („zapisuj … po każdej zmianie fazy, HEAD SHA”).

### Istotny błąd workflow (powód zatrzymania)

- **Reprodukcja:** `tools/mkrun.sh` + `tools/run.sh <run> A <prompt>` z `/delivery`, watcher zabijający proces po
  zwrocie `developer`, ponowne uruchomienie z tym samym poleceniem. Zachodzi w obu przebiegach (2/2): stan zapisywany
  jest tylko na początku i na końcu.
- **Przyczyna:**
  1. Instrukcja zapisu stanu jest ogólna („po każdej zmianie …”). Model nie wykonuje jej przy przejściu
     implementation → local_checks, bo skill nie ma jawnego punktu kontrolnego po kroku 4.
  2. Kontrakt stanu nie ma miejsca na dowód RED → GREEN ani na identyfikator sesji developera. Nawet poprawny zapis
     `head_sha` nie wystarczy, bo krok 5 wymaga dowodu TDD, który zostaje tylko w transkrypcie przerwanej sesji.
- **Skutek dla orkiestratora 24/7:** każde przerwanie między delegacją a końcem (najdłuższy odcinek pracy) kończy
  się `BLOCKED` i interwencją człowieka.
- **Proponowana poprawka:** sekcja 9, punkty 1–2. Nie wprowadzona.

## 3. Tabela 9 przebiegów A/B/C

Nie wykonano. Benchmark zatrzymany po Etapie I.

| Task | A `/delivery` | B Superpowers | C czysty Claude Code |
|---|---|---|---|
| FAST T1 | nie uruchomiono | nie uruchomiono | nie uruchomiono |
| STANDARD T2 | nie uruchomiono | nie uruchomiono | nie uruchomiono |
| CRITICAL T3 | nie uruchomiono | nie uruchomiono | nie uruchomiono |

## 4. Wyniki testów i ślepej oceny

- Ukryte testy: tylko T0 na s0-A (10/10) oraz walidacja testów T1–T3 na bazie (czerwone, opis w sekcji 1).
- Ślepa ocena X/Y/Z: nie wykonano.

## 5. Analiza FAST / STANDARD / CRITICAL

Nie wykonano. Jedyny pomiar dotyczy FAST (smoke): `/delivery` wykonał prosty task poprawnie, z prawdziwym TDD,
w 7,7 min za 0,83 USD.

## 6. Niezawodność i autonomia `/delivery`

| Test | Stan |
|---|---|
| BLOCKED (decyzja biznesowa) | nie wykonano (spec T4 gotowa). Pośrednio: s0r-A pokazał poprawną strukturę `blockers` (`technical`, pytanie, opcje ze skutkami, rekomendacja, dowody) |
| RESUME | **niezaliczony**: stan bez SHA i etapu po pracy developera; wznowienie kończy się `BLOCKED` |
| CI FAILURE | nie wykonano |
| FIX LIMIT | nie wykonano |
| JSON CONTRACT | 2/2 odpowiedzi poprawne, kompletne i równe plikowi stanu; stan pośredni nieaktualny (patrz RESUME) |
| Statusy | `CI_PENDING` i `BLOCKED` użyte poprawnie; `READY_FOR_INTEGRATION` i `FAILED` niewystąpiły |

Autonomia: s0-A bez interwencji; s0r-A wymaga człowieka po wznowieniu.

## 7. Błędy i ograniczenia

`/delivery`:
- (Istotny) Brak zapisu stanu po powrocie developera; brak dowodu TDD w stanie (sekcja 2).
- (Drobny) W s0-A orkiestrator powtórzył pełne `make -C apps/api check` i `test` po developerze (zgodne ze skillem,
  ok. 3 min kosztu czasu na FAST).

Środowisko (nie agent):
- GitHub PR/CI nieprzetestowane (brak repo testowego).
- Domyślne pule adresów Dockera wyczerpane; obejście: własna podsieć na przebieg.
- `bypassPermissions` zablokowany przez klasyfikator; wykonawcy działają w `auto`, co może odrzucać pojedyncze
  akcje (w smoke teście bez odmów).
- W s0r-A developer zobaczył 6 failures w `MeEndpointTest`, które przy powtórce przeszły. Przyczyny nie zbadano.

Superpowers i czysty Claude Code: nie oceniano (nieuruchomione).

## 8. Czy dodatkowe review i subagenci poprawiły jakość

Brak danych: benchmark nie doszedł do porównania. W smoke teście (FAST) review było własną kontrolą orkiestratora.

## 9. Rekomendowane poprawki do `/delivery` v2.1

1. **Jawny punkt kontrolny po developerze:** w kroku 4 „Po powrocie developera, zanim zrobisz cokolwiek innego,
   zapisz stan: `phase: local_checks`, `head_sha` = HEAD developera, wynik RED → GREEN”. Analogicznie przed
   delegacją (`phase: implementation` z identyfikatorem agenta).
2. **Dowód TDD i delegacja w kontrakcie:** dodać do stanu np. `tdd: [{test, red_command, red_failure, green}]`
   i `developer: {agent_id, transcript}`, żeby wznowienie mogło zweryfikować krok 5 bez przerwanej sesji
   (podbić `schema_version`).
3. **Reguła wznowienia dla znanych commitów:** commity potomne `head_sha` na gałęzi taska, autorstwa developera
   i z dowodem w stanie, przyjmuj; `BLOCKED` tylko bez dowodu.
4. **Test kontraktu w skillu lub poza nim:** skrypt walidujący JSON stanu po każdej fazie (schemat + spójność
   `head_sha` z `git rev-parse HEAD`), uruchamiany przez nadrzędnego orkiestratora.
5. Bez zmian w regułach statusów: `CI_PENDING` i `BLOCKED` działały poprawnie.

## 10. Werdykt

**Nie.** `/delivery` v2.0 poprawnie realizuje pojedynczy task od startu do końca (smoke s0-A), ale w obecnej
postaci nie nadaje się do wpięcia w nadrzędnego orkiestratora 24/7. Przerwanie w trakcie pracy, najczęstszy
przypadek w takim środowisku, kończy się blokadą wymagającą człowieka. Po poprawkach 1–3 trzeba powtórzyć smoke
test wznowienia i przeprowadzić Etapy II–IV na przygotowanych już taskach i ukrytych testach.
