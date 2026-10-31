# Eksperyment DELIVERY-001: benchmark `/delivery`, Superpowers i czystego Claude Code

Raport ma cztery części, a wyniki dwóch wersji `/delivery` są w nich rozdzielone:

- **Część I – `/delivery` v2.0** (2026-10-30). Smoke test wykrył istotny błąd wznowienia i benchmark zatrzymano po
  Etapie I. Ta część jest zapisem tamtego przebiegu, bez zmian.
- **Część II – poprawka v2.1 i regresja.** Poprawka: commit `76280cb`. Regresja: R1–R4 na żywo.
- **Część III – benchmark A/B/C na `/delivery` v2.1** (2026-10-30/31). Obejmuje 9 przebiegów, ukryte testy i ślepe
  audyty. Ma sekcje 1–10 zgodne z briefem.
- **Część IV – Etap IV: niezawodność `/delivery` v2.1** (2026-10-31). Testy kontraktu BLOCKED, RESUME, FIX LIMIT,
  CI FAILURE oraz PR → CI → `READY_FOR_INTEGRATION` na prywatnym repo testowym GitHub. Do tego analiza błędów
  `code-reviewer` z T2 i werdykt dla MVP orkiestratora.

Żaden wynik z Części III i IV nie dotyczy v2.0.

---

# Część I – `/delivery` v2.0 (zapis bez zmian)

**Wynik: benchmark zatrzymany po Etapie I.** Smoke test wykrył istotny błąd workflow `/delivery`: po przerwaniu
procesu stan taska nie pozwala wznowić pracy (brak zapisu stanu po powrocie developera). Zgodnie z warunkami
eksperymentu Etapy II–IV nie zostały uruchomione. Skill nie został zmieniony.

## I.1. Metodologia i warunki

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

## I.2. Wynik smoke testu

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

## I.3. Tabela 9 przebiegów A/B/C

Nie wykonano. Benchmark zatrzymany po Etapie I.

| Task | A `/delivery` | B Superpowers | C czysty Claude Code |
|---|---|---|---|
| FAST T1 | nie uruchomiono | nie uruchomiono | nie uruchomiono |
| STANDARD T2 | nie uruchomiono | nie uruchomiono | nie uruchomiono |
| CRITICAL T3 | nie uruchomiono | nie uruchomiono | nie uruchomiono |

## I.4. Wyniki testów i ślepej oceny

- Ukryte testy: tylko T0 na s0-A (10/10) oraz walidacja testów T1–T3 na bazie (czerwone, opis w sekcji 1).
- Ślepa ocena X/Y/Z: nie wykonano.

## I.5. Analiza FAST / STANDARD / CRITICAL

Nie wykonano. Jedyny pomiar dotyczy FAST (smoke): `/delivery` wykonał prosty task poprawnie, z prawdziwym TDD,
w 7,7 min za 0,83 USD.

## I.6. Niezawodność i autonomia `/delivery`

| Test | Stan |
|---|---|
| BLOCKED (decyzja biznesowa) | nie wykonano (spec T4 gotowa). Pośrednio: s0r-A pokazał poprawną strukturę `blockers` (`technical`, pytanie, opcje ze skutkami, rekomendacja, dowody) |
| RESUME | **niezaliczony**: stan bez SHA i etapu po pracy developera; wznowienie kończy się `BLOCKED` |
| CI FAILURE | nie wykonano |
| FIX LIMIT | nie wykonano |
| JSON CONTRACT | 2/2 odpowiedzi poprawne, kompletne i równe plikowi stanu; stan pośredni nieaktualny (patrz RESUME) |
| Statusy | `CI_PENDING` i `BLOCKED` użyte poprawnie; `READY_FOR_INTEGRATION` i `FAILED` niewystąpiły |

Autonomia: s0-A bez interwencji; s0r-A wymaga człowieka po wznowieniu.

## I.7. Błędy i ograniczenia

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

## I.8. Czy dodatkowe review i subagenci poprawiły jakość

Brak danych: benchmark nie doszedł do porównania. W smoke teście (FAST) review było własną kontrolą orkiestratora.

## I.9. Rekomendowane poprawki do `/delivery` v2.1 (z tamtego etapu)

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

## I.10. Werdykt

**Nie.** `/delivery` v2.0 poprawnie realizuje pojedynczy task od startu do końca (smoke s0-A), ale w obecnej
postaci nie nadaje się do wpięcia w nadrzędnego orkiestratora 24/7. Przerwanie w trakcie pracy, najczęstszy
przypadek w takim środowisku, kończy się blokadą wymagającą człowieka. Po poprawkach 1–3 trzeba powtórzyć smoke
test wznowienia i przeprowadzić Etapy II–IV na przygotowanych już taskach i ukrytych testach.

---

# Część II – `/delivery` v2.1: poprawka i regresja

## II.1. Poprawka (commit `76280cb`)

`76280cb` to commit eksperymentu na gałęzi `claude/benchmark-agentic-sdlc-c7ffe5`. Do `main` ta sama zmiana trafiła
przez gałąź `claude/delivery-v2.1` (`6c6cdaa`, bez zmian treści), razem z poprawkami z Części IV.

`fix(claude): persist delivery task state at every checkpoint and recover after interruption`, na gałęzi
`claude/benchmark-agentic-sdlc-c7ffe5`, bez PR i bez merge'a. Zmienione pliki:

- `.claude/skills/delivery/delivery_state.py` (nowy, Python stdlib). Komendy:
  - `checkpoint`: atomowy zapis tmp + fsync + `os.replace`. `head_sha` bierze z gita, podsumowanie TDD z logu dowodów.
  - `run --label red|green -- <komenda>`: uruchamia komendę i dopisuje wynik do logu dowodów JSONL.
  - `record-commit`: rejestruje commit developera.
  - `resume-check`: zwraca werdykt `current`, `recoverable` albo `blocked`.
- `.claude/skills/delivery/test_delivery_state.py`: 26 testów, wszystkie przechodzą.
- `.claude/skills/delivery/SKILL.md`. Wprowadza:
  - jawne checkpointy: `implementation` przed delegacją, `local_checks` zaraz po powrocie developera, `review`
    i `fixes` z licznikiem rund;
  - reguły wznowienia;
  - kontrakt `schema_version: 2` z polami `developer.agent_id`, `tdd {status, red, green}`, `evidence_file`
    i `updated_at`;
  - zasadę, że końcowy JSON to dokładne wyjście ostatniego checkpointu.
- `.claude/agents/developer.md`: jedna linia. Developer uruchamia RED i GREEN przez `run` i po każdym commicie
  wywołuje `record-commit`.

Główne zasady:

- `head_sha` przesuwa się tylko po commitach zarejestrowanych w logu.
- Obcy lub niewyjaśniony commit daje `BLOCKED` i zostawia zaufany `head_sha`. `--accept-head` jest tylko dla
  zmian wyjaśnionych.
- `tdd.status: verified` wymaga zapisu RED i GREEN oraz weryfikacji orkiestratora, że RED pada na asercji, a nie
  na błędzie składni czy środowiska. `recorded` znaczy „zapisane, niezweryfikowane”.
- Gdy dowody zginęły, orkiestrator uruchamia testy na bazie i dostaje status `lost_verified_on_base`.

Rozważyłem wymuszenie checkpointów przez harness (hooki). Odrzuciłem je, bo projekt ich nie używa, a w worktree
aplikacji desktopowej i tak nie działają. Zostały instrukcje skilla i skrypt, którego wyjście jest jedynym źródłem
stanu.

## II.2. Regresja na żywo (baza `a96387c`, task T0)

| Test | Scenariusz | Wynik |
|---|---|---|
| R1 | kill po commicie developera, przed checkpointem `local_checks` | `resume-check` daje `recoverable`; wznowienie bez BLOCKED; `CI_PENDING` `9c9186d`, TDD `verified` |
| R2 | zwykłe wznowienie z aktualnym checkpointem | `current`; `CI_PENDING` `eca5f80` |
| R3 | obcy commit `4b1c63c` dopisany na gałęzi | 2 × `BLOCKED`, `head_sha` zostaje `eca5f80` |
| R4 | kill między `git commit` a `record-commit` | `BLOCKED`/`technical`, `head_sha` zostaje na bazie `a96387c` |

We wszystkich przebiegach końcowy JSON był równy plikowi stanu, a RED padało na asercji. Koszt wznowienia
wyniósł 0,3–0,75 USD.

Wcześniejsze iteracje regresji (`v21-*`, `v21c-*`) wykryły trzy błędy, poprawione przed `76280cb`:

- RED z błędem składni liczony jako RED. Teraz obowiązuje rozróżnienie `recorded` i `verified`.
- Ręcznie przepisany końcowy JSON. Teraz JSON musi być wyjściem checkpointu.
- Checkpoint BLOCKED przesuwał `head_sha` na obcy commit. Teraz zostaje zaufany head.

**Ograniczenie zaakceptowane przez użytkownika (R4):** przerwanie w oknie między `git commit` a `record-commit`
kończy się `BLOCKED`, a nie automatycznym odzyskaniem. Jest bezpieczne, ale wymaga człowieka.

---

# Część III – benchmark A/B/C na `/delivery` v2.1

## 1. Metodologia i warunki

Warunki są jak w I.1, z różnicami:

- **Wersje:**
  - A: `/delivery` v2.1 (`76280cb`, skill i agenci `developer` + `code-reviewer`);
  - B: Superpowers 6.4.2, `subagent-driven-development` z wymaganym planem;
  - C: Claude Code bez skilli workflow i bez subagentów (flagi jak w I.1).
- **Bazy:** każdy task ma bazę na `76280cb` z commitem specyfikacji:
  - T1 `cb2a4ab`;
  - T2 `20609a2`;
  - T3 `3c55770`.

  Te same specyfikacje i ukryte testy co w Części I, bez przeprojektowania. A, B i C startują z tego samego SHA.
- **Przebiegi:** `tools/bench.sh <task> <wariant> <port>`, trzy warianty jednego taska równolegle na tym samym
  hoście. Każdy przebieg ma:
  - własne bare repo `origin` z samym `main` = baza;
  - własny klon;
  - własny stos Compose, porty i podsieć.

  Po sesji `tools/freeze.sh` zamraża HEAD (bundle, diff), uruchamia ukryte testy i zapisuje metryki. Checkpoint
  całego benchmarku po każdym przebiegu i etapie trafia do `ledger.json` (zapis atomowy).
- **Prompty:** wspólny blok `prompts/common.txt`, który mówi:
  - sesja jest nienadzorowana;
  - spec jest zaakceptowana;
  - nie czekaj na rutynowe akceptacje;
  - decyzję użytkownika opisz i zakończ;
  - push na lokalny `origin` jest dozwolony, GitHub jest niedostępny, nie merguj.

  Różni się tylko pierwsza linia:
  - A: `/delivery Task <ID>: specyfikacja … Plik stanu taska: …`;
  - B: „Użyj skilla superpowers:subagent-driven-development; plan przygotuj tak, jak ten skill tego wymaga”;
  - C: „Zrealizuj task <ID> opisany w …”.
- **Interwencje:** nikt nie odpowiadał na pytania wykonawców. Przebieg, który zakończył się pytaniem, był zamrażany
  w stanie z chwili zatrzymania, bez wznawiania.
- **Ślepy audyt:** `tools/audit-export.sh` tworzy anonimowe drzewa X/Y/Z. Mapowanie liter jest losowe i trzymane
  prywatnie. Eksport nie zawiera `.git`, `.claude/`, `docs/superpowers/`, `docs/experiments/` ani `plan.md`.
  Audyt: osobna sesja Opus High, tylko odczyt, bez skilli, hooków i agentów, ten sam prompt `prompts/audit.txt`
  dla każdego taska. Ocena 0–10 w sześciu kategoriach, lista Critical/Important/Minor i ranking.
- **Wyciek v2.1 do B/C:** sprawdzany w transkryptach (`delivery_state`, agent `developer`, odczyt `SKILL.md`)
  i przez wywołania narzędzi wskazujące katalogi innych przebiegów, ukryte testy lub eksport audytu (sekcja 7).

## 2. Wynik smoke testu v2.1

Osobnego smoke testu v2.1 nie było. Jego rolę pełniła regresja R1–R4 na żywo (Część II.2). Pierwszy przebieg
benchmarku (T1-A) był jednocześnie pierwszym pełnym przebiegiem v2.1 na nowym tasku. Przeszedł bez interwencji,
z poprawnym stanem i JSON-em.

## 3. Tabela 9 przebiegów A/B/C

Koszt to `total_cost_usd` z CLI, z subagentami. API to `duration_api_ms` z CLI, suma po wszystkich wywołaniach
modelu, także równoległych subagentów. Dlatego u B przekracza czas zegarowy.

| Run | Final SHA | Status / zatrzymanie | Koszt | Wall | API | Tokeny wej. / wyj. (model) | Subagenci | Rundy review/fix | Commity | Interwencje |
|---|---|---|---|---|---|---|---|---|---|---|
| T1-A | `aeb36f3` | `CI_PENDING`, JSON == stan, TDD `verified` | 2,79 USD | 25,1 min | 19,8 min | Opus 2,5M/30k; Sonnet 2,6M/24k | developer (Sonnet), code-reviewer | 1 review DELTA, 1 runda poprawek | 3 | 0 |
| T1-B | `71ee5bc` | gotowe, bez pytań | 4,83 USD | 28,4 min | 15,5 min | Opus 5,2M/49k; Sonnet 3,9M/30k | 7 (6 Sonnet, 1 Opus) | review per task + końcowy | 6 | 0 |
| T1-C | `39b8267` | gotowe, bez pytań | 1,99 USD | 8,3 min | 4,7 min | Opus 3,2M/23k | 0 | brak | 3 | 0 |
| T2-A | `3bd8a5c` | `CI_PENDING`, JSON == stan, TDD `verified` | 6,53 USD | 59,9 min | 43,1 min | Opus 3,4M/37k; Sonnet 13,9M/108k | developer (Sonnet), code-reviewer | 1 review, 1 runda poprawek | 7 | 0 |
| T2-B | `3d6129f` | **zatrzymany pytaniem** o dopisanie przypadków brzegowych do `spec.md` | 14,50 USD | 83,6 min | 185,1 min | Opus 26,0M/175k; Sonnet 4,9M/64k; Haiku 0,2M/9k | 13 (8 Sonnet, 3 Opus, 2 Haiku) | 5 tasków z review, re-review, końcowy | 9 | 0 (pytanie bez odpowiedzi) |
| T2-C | `a74cdab` | gotowe, bez pytań | 8,70 USD | 46,8 min | 18,3 min | Opus 21,1M/116k | 0 | brak | 8 | 0 |
| T3-A | `f87fdd0` | `CI_PENDING`, JSON == stan, TDD `verified` | 22,63 USD | niedostępny | 40,1 min | Opus 34,8M/196k | developer (Opus), code-reviewer (Opus) | 1 review, 0 rund poprawek | 9 | 0 |
| T3-B | `45f1195` | gotowe, czeka na akceptację | 21,08 USD | niedostępny | 247,6 min | Opus 19,9M/139k; Sonnet 24,2M/163k | 16 (13 Sonnet, 3 Opus) | review per task + końcowy | 13 | 0 |
| T3-C | `9107819` | gotowe, bez pytań | 15,15 USD | niedostępny | 72,8 min | Opus 39,0M/130k | 0 | brak | 6 | 0 |

**Wall dla T3 jest niedostępny.** Mac zasnął w nocy na baterii w trakcie przebiegów, więc pomiar zegarowy
(~10 h) nie odpowiada pracy. Zostaje czas API. Nie estymuję wall.

Suma za 3 taski:
- A: 31,95 USD;
- B: 40,41 USD;
- C: 25,84 USD.

Wall dla T1 + T2:
- A: 85 min;
- B: 112 min;
- C: 55 min.

## 4. Wyniki testów i ślepej oceny

**Bramki wykonawców.** Wszystkie 9 przebiegów deklaruje zielone `make check` i testy aplikacji, których
dotyczy zmiana. U A potwierdza to plik stanu (`checks`). U B i C to deklaracja z raportu końcowego.

**Ukryte testy.** Uruchomione przez `tools/hidden.sh` na zamrożonym SHA:

| Task | Ukryte testy | A | B | C |
|---|---|---|---|---|
| T1 | 7 API + 3 sklep | 10/10 | 10/10 | 10/10 |
| T2 | 29 API | 29/29 | 29/29 | 29/29 |
| T3 v3 | 28 API (z testem wyścigu 12 × `PATCH`) | 28/28 | 28/28 | 28/28 |

**Błąd w moim teście ukrytym T3 (v2):**

- **Objaw:** wszystkie trzy warianty oblały identycznie 3 przypadki `testRenameWithCurrentVersionSucceeds`
  (25/3), z asercją `actual size 0 matches expected size 1` dla zdarzeń w outboxie.
- **Przyczyna:** test odczytywał in-memory outbox dopiero po kolejnych żądaniach GET. Ten transport jest czyszczony
  przed każdym kolejnym żądaniem, co własne testy wykonawców wprost opisują. Ten sam błąd sprawiał, że cztery
  asercje „brak zdarzenia” (409, 403, 422, no-op) przechodziły niezależnie od implementacji.
- **Poprawka (v3):** zdarzenia są odczytywane od razu po żądaniu, którego dotyczą. Wymagania i scenariusze się nie
  zmieniły, zmieniło się tylko miejsce odczytu. Poprawkę zastosowałem po zakończeniu przebiegów i uruchomiłem ją
  identycznie dla A, B i C na tych samych SHA.
- **Ślad:** wersja v2 i jej logi są w `eval/` (`HiddenProductRenameTest.v2.php`, `*-hidden.v2.log`). Hash v3 jest
  w `hidden-tests-sha256-v3-t3.txt`. Kod wykonawców się nie zmienił.

**Awaria środowiska T3-C.** Pierwsze uruchomienie v3 na T3-C dało 27/1. Przyczyną był kontener `elasticsearch`
tego stosu, który zakończył się kodem 137 podczas uśpienia hosta. Po `docker compose start elasticsearch` wynik to
28/28. Log z awarią jest zachowany.

**Ślepa ocena.** Suma sześciu kategorii (0–60), w nawiasie miejsce w rankingu audytora:

| Task | A `/delivery` v2.1 | B Superpowers | C czysty CC | Important wg audytu |
|---|---|---|---|---|
| T1 | **53** (1) | 50 (2) | 47 (3) | brak |
| T2 | 45 (3) | 47 (2) | **50** (1) | A: kolejność adresów z zegara PHP w domenie; B: niepełne `violations` przy złym typie pola |
| T3 | 51 (2) | 43,5 (3) | **51,5** (1) | B: `MapRequestPayload` bez `acceptFormat: 'json'` (ciało form/XML może zmienić nazwę) |
| Suma / średnie miejsce | 149 / 2,0 | 140,5 / 2,33 | **148,5 / 1,67** | |

Żaden audyt nie znalazł błędu Critical.

Oceny szczegółowe (poprawność / błędy / architektura / brzegi / czytelność / testy):

| Run | Poprawność | Błędy | Architektura | Brzegi | Czytelność | Testy |
|---|---|---|---|---|---|---|
| T1-A | 9 | 9 | 9 | 9 | 8 | 9 |
| T1-B | 9 | 9 | 8 | 8 | 8 | 8 |
| T1-C | 8 | 8 | 8 | 7 | 9 | 7 |
| T2-A | 8 | 7 | 7 | 8 | 7 | 8 |
| T2-B | 8 | 7 | 8 | 8 | 7 | 9 |
| T2-C | 9 | 8 | 8 | 8 | 8 | 9 |
| T3-A | 9 | 8,5 | 8 | 9 | 7,5 | 9 |
| T3-B | 7,5 | 6,5 | 7 | 8 | 7 | 7,5 |
| T3-C | 9 | 8,5 | 8 | 8 | 8,5 | 9,5 |

Koszt audytów:
- T1: 1,36 USD, 193 s;
- T2: 3,03 USD, 401 s;
- T3: 2,56 USD, 313 s.

## 5. Analiza FAST / STANDARD / CRITICAL

**FAST (T1).**
- Wszyscy dostarczyli poprawny kod.
- A dostał najlepszą ocenę. Jedyną istotną różnicę złapało review A i B: normalizację id kategorii przekazywanego
  do Elasticsearch. C jej nie zrobił, więc wielkie litery dają 200 z pustą listą zamiast 404.
- Koszt jakości: C był 3 razy szybszy i o 30% tańszy niż A.
- A sklasyfikował task jako **STANDARD** zamiast FAST i uruchomił `code-reviewer`. To review złapało różnicę, ale
  przy FAST skill przewiduje własną kontrolę diffu.

**STANDARD (T2).**
- Wszyscy przeszli ukryte testy. Audyt ocenił A najniżej: kolejność oparta na zegarze aplikacji, nazwa schematu
  `AddressResponse` zamiast `Address`, ręczne parsowanie ciała zamiast `MapRequestPayload`, nieaktualna
  `walking-skeleton/spec.md`.
- `code-reviewer` A tego nie wyłapał. Przy dwóch ostatnich punktach to zgodność z konwencją i dokumentacją repo,
  którą review miało sprawdzić.
- B był najdroższy (14,50 USD) i jako jedyny zatrzymał się pytaniem. Jego pytanie dotyczyło jednak realnego
  problemu: rozstrzygnięć bez pokrycia w spec, które po usunięciu planu zniknęłyby z dokumentacji.
- C był najlepszy w audycie i drugi pod względem kosztu.

**CRITICAL (T3).**
- Wszyscy przeszli 28/28, łącznie z testem prawdziwego wyścigu.
- A i C są praktycznie równi (51 i 51,5). A ma najlepsze pokrycie przypadków brzegowych i spójność odpowiedzi
  z zapisem, ale więcej warstw. C ma najprostszy kod i jako jedyny testuje wyścig na równoległych żądaniach HTTP.
- B jest wyraźnie słabszy: przyjmuje ciała inne niż JSON, dopisuje rzeczy spoza zakresu i sam zmienia spec.
- A był najdroższy (22,63 USD). Orkiestrator wybrał Opus dla developera i reviewera, zgodnie z ryzykiem CRITICAL.

**Wniosek.**
- Na tych trzech taskach nie widać przewagi jakości kodu żadnego workflow nad czystym Claude Code. Różnice
  w audycie mieszczą się w kilku punktach na 60, a każda komórka to jeden przebieg.
- Przewaga A nad B jest spójna w kosztach: B był droższy w T1 i T2, w T3 tańszy o ~7%.
- A był lepszy od B w audycie w T1 i T3.

## 6. Niezawodność i autonomia `/delivery` v2.1

| Test | Stan | Dowód |
|---|---|---|
| RESUME po przerwaniu po commicie developera | **zaliczony** | R1 (`recoverable` → `CI_PENDING`, TDD `verified`) |
| RESUME z aktualnym checkpointem | **zaliczony** | R2 |
| Obcy commit | **zaliczony** (`BLOCKED`, zaufany `head_sha`) | R3 |
| Przerwanie między `git commit` a `record-commit` | `BLOCKED` (ograniczenie zaakceptowane) | R4 |
| BLOCKED – decyzja biznesowa (T4) | nie w benchmarku; wykonane w Części IV | – |
| CI FAILURE | nie w benchmarku; wykonane w Części IV | – |
| FIX LIMIT | nie w benchmarku; wykonane w Części IV | – |
| JSON CONTRACT | **zaliczony** | 3/3 przebiegi benchmarku i wszystkie przebiegi R1–R4: JSON == plik stanu, `schema_version 2` |
| TDD | **zaliczony** | 3/3 przebiegi `tdd.status: verified`, RED z asercji w logu dowodów |
| Statusy | poprawne | `CI_PENDING` 3/3 (PR/CI niedostępne, bez fałszywego `READY_FOR_INTEGRATION`) |

Autonomia w benchmarku:

| | A `/delivery` v2.1 | B Superpowers | C czysty CC |
|---|---|---|---|
| Przebiegi bez zatrzymania | 3/3 | 2/3 | 3/3 |
| Wynik maszynowo czytelny | tak (JSON, plik stanu, dowody TDD) | nie (raport tekstowy, ledger SDD w ignorowanym katalogu) | nie (raport tekstowy) |
| Wznowienie po przerwaniu | przetestowane (R1–R4) | nieprzetestowane | nieprzetestowane, brak stanu |

## 7. Błędy i ograniczenia

`/delivery` v2.1:
- (Istotny dla jakości) T2: review nie wyłapało odstępstw od konwencji repo (ręczne parsowanie ciała, nazwa
  schematu, nieaktualna dokumentacja wspólnego zachowania) ani kolejności z zegara aplikacji, istotnej przy skali
  horyzontalnej z `docs/vision.md`.
- (Drobny) T1: klasyfikacja ryzyka FAST → STANDARD. Pełne review na FAST podnosi koszt, choć tu się opłaciło.
- (Zaakceptowany) okno R4.

Superpowers:
- T2: zatrzymanie pytaniem o dokumentację, mimo że kod był skończony.
- T2: commit pośredni, na którym kontener się nie kompiluje (sam to zgłosił).
- T3: przyjmowanie ciał innych niż JSON, zmiany spec bez akceptacji, kod spoza zakresu.
- Najwyższy łączny koszt i najwięcej subagentów (36 łącznie).

Czysty Claude Code:
- T1: brak normalizacji id kategorii.
- Brak stanu, dowodów TDD i niezależnego review. Raport sam to zaznacza: „niezależny reviewer się nie odbył”.

Ograniczenia benchmarku:
- **Jeden przebieg na komórkę.** Różnice kilku punktów w audycie i kilkudziesięciu procent kosztu mogą być
  szumem. Benchmark nie daje podstaw do twierdzeń statystycznych.
- **Jeden audytor na task** (Opus High). Część zarzutów audytor oznaczył jako niezweryfikowane uruchomieniem,
  np. NUL w T3 i `acceptFormat` w B.
- **C odczytał `.claude/skills/delivery/SKILL.md`** w trakcie eksploracji (T1, T2, T3), ale go nie zastosował:
  brak `delivery_state`, brak agentów. Pliki skilli są częścią repo, więc tego nie da się ukryć bez zmiany bazy.
- **B nie użył v2.1:** zero wywołań `delivery_state` (dwa trafienia w T3 to tekst eksploracji) i zero agentów
  `developer`.
- **Żaden przebieg nie wskazał** narzędziem katalogu innego przebiegu, ukrytych testów ani eksportu audytu.
  Równoległe przebiegi były jednak widoczne w wyjściu `docker compose ls` i `ps aux`: ścieżki i fragmenty poleceń
  innych sesji. Jest to wspólny host, a nie odczyt wyników.
- **T3:** uśpienie hosta w nocy. Wall jest niedostępny, a kontener ES T3-C padł po wznowieniu (sekcja 4).
  Wpływu uśpienia na zachowanie wykonawców nie zbadałem.
- **Ukryty test T3 v2 miał mój błąd** (sekcja 4). Poprawiony identycznie dla wszystkich po przebiegach.
- **GitHub PR i CI nieprzetestowane.** Koszt to cennik API z CLI.

## 8. Czy dodatkowe review i subagenci poprawiły jakość

- **Ukryte testy:** brak różnicy, wszyscy 100%.
- **Audyt:** czysty CC ma najlepsze średnie miejsce (1,67) i sumę praktycznie równą A (148,5 wobec 149).
  B, z największą liczbą subagentów i rund review, ma najniższą sumę (140,5).
- **Gdzie review pomogło:** w T1 review A i B wyłapało realną różnicę (normalizacja id).
- **Gdzie review nie pomogło:** w T2 `code-reviewer` A nie wyłapał uwag, które znalazł audytor. W T3 review per
  task w B nie zapobiegło jedynemu Important tego taska.
- **Koszt review:** podnosi koszt i czas. A był 1,4 raza droższy od C w T1 i 1,5 raza w T3. B był 2,4 raza
  droższy od C w T1 i 1,7 raza w T2.

Na tych danych dodatkowe review i subagenci nie poprawiły jakości kodu mierzalnie. Dały za to ślad procesu: dowody
TDD i wynik review w stanie (A) albo raporty per task (B).

## 9. Rekomendowane poprawki do `/delivery`

1. **Review pod kątem konwencji repo i skali.** `code-reviewer` powinien jawnie sprawdzać:
   - zgodność z konwencjami aplikacji (`MapRequestPayload`, nazwy schematów OpenAPI);
   - aktualność dokumentacji wspólnych zachowań;
   - założenia sprzeczne ze skalą z `docs/vision.md` (np. kolejność z zegara instancji).

   To dokładnie to, czego review A nie wyłapało w T2.
2. **Ostrzejsze kryterium FAST.** T1 (jeden endpoint i jedna strona, bez modelu danych) został sklasyfikowany jako
   STANDARD. Doprecyzować kryteria albo pozwolić FAST z lekkim `code-reviewer` w trybie DELTA. Teraz to decyzja
   między „bez review” a pełnym review.
3. **Domknięcie okna R4:** komenda `delivery_state.py commit`, która wykonuje `git commit` i `record-commit`
   razem. Zaakceptowane ograniczenie, ale przy pracy 24/7 to jedyna znana ścieżka przerwania kończąca się
   `BLOCKED`.
4. **Przed wpięciem w orkiestratora 24/7 dokończyć Etap IV** na repo testowym GitHub:
   - BLOCKED biznesowy (T4 jest gotowy);
   - CI FAILURE;
   - FIX LIMIT;
   - pełna ścieżka PR → `READY_FOR_INTEGRATION`.
5. **Koszt CRITICAL:** zmierzyć wariant z developerem na Sonnecie i reviewerem na Opusie. W T3 A był najdroższy,
   a jakościowo równy C.

## 10. Werdykt

**Czy `/delivery` v2.1 dostarcza lepszy kod niż Superpowers i czysty Claude Code?**
- **Wobec Superpowers: tak, umiarkowanie.** A był lepszy w audycie w 2 z 3 tasków (T1, T3) i tańszy łącznie
  o 21% (31,95 wobec 40,41 USD). Ukryte testy są równe.
- **Wobec czystego Claude Code: nie wykazano.** Ukryte testy są równe, suma audytu praktycznie równa (149 wobec
  148,5), a C ma lepsze średnie miejsce i jest o 19% tańszy (25,84 wobec 31,95 USD). Przy jednym przebiegu na
  komórkę różnica jakości w żadną stronę nie jest rozstrzygnięta.

**Czy daje większą autonomię?**
- **Wobec Superpowers: tak.** 3/3 przebiegi bez zatrzymania wobec 2/3.
- **Wobec czystego Claude Code: tak w sensie operacyjnym, nie w liczbie zatrzymań.** C też ukończył 3/3 bez
  pytań. Tylko `/delivery` daje jednak to, czego potrzebuje nadrzędny orkiestrator:
  - kontrakt JSON równy stanowi;
  - zweryfikowany dowód TDD;
  - przetestowane wznowienie po przerwaniu (R1–R2);
  - bezpieczne `BLOCKED` przy obcych zmianach (R3).

  C nie zostawia stanu, z którego dałoby się wznowić pracę.

**Podsumowanie.** `/delivery` v2.1 nadaje się jako jednostka wykonawcza dla orkiestratora, bo jest autonomiczny,
wznawialny i ma maszynowy kontrakt. Ma przewagę nad Superpowers pod względem kosztu, autonomii i (słabiej) jakości.
Nad czystym Claude Code nie poprawia jakości kodu i kosztuje więcej. Płaci się tę różnicę za stan, dowody i
wznawialność. Etap IV (BLOCKED biznesowy, CI FAILURE, FIX LIMIT, prawdziwy PR/CI) pozostaje nieprzetestowany,
więc gotowość do pracy 24/7 jest potwierdzona tylko w zakresie RESUME i JSON CONTRACT. Etap IV wykonano później,
w Części IV.

---

# Część IV – Etap IV: niezawodność `/delivery` v2.1

Benchmarku A/B/C nie powtarzałem. Skill i agenci pozostali w wersji `76280cb`, bez zmian.

## IV.1. Warunki

- **Task:** T4 `CATEGORY-DELETE` (spec `35ce6a9`, celowo bez reguły dla kategorii w użyciu). Lokalnie na bazie
  `85a1522` (spec na `76280cb`), na GitHubie na migawce tej bazy.
- **Repo testowe:** `piotrp321/shop-delivery-sandbox`, prywatne, utworzone za zgodą użytkownika. Zawiera:
  - jeden commit-migawkę drzewa bez historii projektu. Przed pushem sprawdziłem drzewo: nie ma `.env.local`,
    kluczy ani tokenów, a `apps/api/.env` zawiera tylko `APP_ENV=dev`, bo hasła generuje `init-env.sh`;
  - commit `e529b43`, który zwalnia dysk runnera (sekcja IV.4);
  - commit `d735563`, „inny task” na `main`, czyli źródło prawdziwej awarii CI (sekcja IV.2).

  `piotrp321/shop` ani inne repozytoria nie zostały dotknięte. Żaden PR nie został zmergowany.
- **Przebiegi:** wszystkie przez istniejący harness (`mkrun`, `run.sh`, ledger), na tych samych flagach CLI.
  Przebiegi GitHub używały `tools/run-gh.sh`: `GH_REPO` wskazuje repo testowe, a `origin` prowadzi tylko do niego.
  W prompcie wykonawców był jeden dodatkowy blok `prompts/common-gh.txt`.
- **Decyzje biznesowe:** dostarczał je orkiestrator eksperymentu (ja) jako odpowiedź na blokery. Wybrałem
  rekomendowaną opcję A dla obu blokerów (`prompts/decisions-t4.txt`).

| Przebieg | Rola | Sesje | Koszt |
|---|---|---|---|
| `e4-blk-A` (lokalny) | BLOCKED → RESUME z decyzją → runda F1 przerwana → wznowienie | 4 | 12,28 USD + sesja przerwana (koszt niedostępny: proces zabity przed zdarzeniem `result`) |
| `e4-gh-A` (GitHub, PR #1) | BLOCKED → RESUME → PR → CI → `READY_FOR_INTEGRATION` → CI FAILURE przy 2/2 | 3 | 8,49 USD |
| `e4-gh2-A` (GitHub, PR #2) | decyzje od startu → PR → CI czerwone → naprawa → CI zielone → `READY_FOR_INTEGRATION` | 1 | 11,52 USD |

**GitHub Actions:**
- 8 przebiegów CI (2 joby każdy):
  - 3 na `main` (baza, poprawka środowiska, „inny task”);
  - 5 na PR.
- Czas runów: 7–13 min.
- Minut runnera nie odczytałem z billingu. Górny szacunek to ok. 180 min (2 równoległe joby × łącznie 90 min runów).

Żaden przebieg nie był powtarzany bez potrzeby.

## IV.2. Wyniki testów kontraktu

Każdy wynik oparty jest na pliku stanu, dzienniku dowodów, historii gita i rzeczywistym statusie PR i CI
z `gh`, a nie na raporcie modelu.

### 1. BLOCKED – PASS (2/2)

W obu niezależnych przebiegach (`e4-blk-A`, `e4-gh-A`) `/delivery` najpierw zaimplementował i przetestował
przypadki jednoznaczne (204/401/403/404). Review FULL przeszło. Potem zwrócił `BLOCKED` z dwoma wpisami, oba
z pytaniem, 3 opcjami ze skutkami, rekomendacją i dowodami:

- **B1 `business`:** kategoria z podkategoriami lub produktami. Dowód: FK bez `ON DELETE` w migracjach, wynik
  500 z testu developera, brak przypadku w spec.
- **B2 `architecture`:** zasada z `apps/api/CLAUDE.md` „zmiany z panelu zapisują identyfikator pracownika”, której
  usunięcie nie spełnia.

`head_sha` był równy HEAD, a JSON równy plikowi stanu. Przy `e4-gh-A` powstał draft PR #1 z opisem otwartych
pytań i zielonym CI.

Klasyfikacja ryzyka: STANDARD, po decyzji podniesiona do CRITICAL (transakcja, wyścig FK).

### RESUME po dostarczeniu decyzji – PASS (2/2)

Wznowienie tym samym poleceniem, z odpowiedziami B1 = A i B2 = A:

- decyzje są wdrożone: 409 `CategoryInUse`, `CategoryDeleted(categoryId, staffId, occurredAt)` w outboxie;
- decyzje są opisane w `integration_notes` i w PR;
- `blockers` są puste;
- review DELTA przeszło.

Wyniki:
- lokalnie: `CI_PENDING` `e784aa4`;
- na GitHubie: `READY_FOR_INTEGRATION` `ebe52a8`.

Licznik rund w obu przebiegach wzrósł z powodu review, a nie z powodu decyzji. Review znalazło wyścig dwóch
równoległych DELETE.

### 2. FIX LIMIT – PASS, z zastrzeżeniem

**Przerwanie w trakcie rundy** (`e4-blk-A`):
1. Integracja zgłosiła prawdziwą wadę F1: `pattern` parametru `id` w `openapi-admin.json` bez kotwic `^…$`.
2. Watcher (`tools/interrupt-round.sh`) zabił proces, gdy w dzienniku pojawił się commit poprawki `57f4756`.
   Orkiestrator nie zdążył jeszcze zapisać checkpointu rundy.
3. Stan w chwili przerwania: `IN_PROGRESS`, `phase: implementation`, `fix_rounds_used: 1`. `resume-check` zwrócił
   `recoverable`, z commitem zapisanym w dzienniku.
4. Wznowienie (1,25 USD, 7 min) przyjęło commit bez ponownego delegowania implementacji i samo uruchomiło bramki
   i review DELTA. Licznik zakończył na **2**: runda nie zginęła i nie została policzona podwójnie.

**Wyczerpany limit** (`e4-gh-A`, 2/2): zgłoszenie czerwonego CI dało `BLOCKED` z wpisem `B3 technical`
(„czy zezwolić na trzecią rundę”, 3 opcje, rekomendacja). Nie powstał żaden commit (HEAD dalej `ebe52a8`),
licznik nie został wyzerowany, a sesja zakończyła się po 98 s bez wywołania agentów. Skrypt stanu odrzuca
`fix_rounds_used` > 2 (sprawdzone na kopii stanu), więc limit jest wymuszony także poza instrukcją.

**Zastrzeżenie:** obie rundy w tych przebiegach były skuteczne. Nie zasymulowałem dwóch rund, które nie usuwają
błędu, więc wyzwalacz „dwie nieskuteczne rundy → BLOCKED” nie został sprawdzony wprost. Sprawdzona jest jego
twarda część: po 2 rundach nie ma trzeciej.

### 3. CI FAILURE – PASS

**Prawdziwa awaria:**
- Na `main` repo testowego trafił commit „innego taska” `d735563`: katalog kontraktów zdarzeń
  `apps/api/domain-events.json` z testem `DomainEventCatalogTest`.
- Test sprawdziłem przed pushem na drzewie PR #1: RED bez wpisu `CategoryDeleted`, GREEN z wpisem, PHPStan i CS
  czyste.
- CI na `main` jest zielone. CI PR-ów z `CategoryDeleted` jest czerwone: „Domain event CategoryDeleted is missing
  from domain-events.json”.

**PR #2** (`e4-gh2-A`, 1 runda przed awarią):
- `/delivery` sam odczytał czerwone CI (run `41201778609`) w fazie `pr_ci`.
- Wykonał rundę 2 w istniejącej gałęzi i PR: merge `origin/main` i wpis w katalogu `f04f4ef`.
- Ponownie uruchomił lokalne bramki i CI. Run `41203272900` jest zielony.
- Wynik `READY_FOR_INTEGRATION`, licznik 1 → 2 (bez resetu), PR z linią `Rundy poprawek: 2/2`.

**PR #1** (`e4-gh-A`, 2/2): awaria wróciła do tego samego taska i PR. Wynik opisany w punkcie 2 (`BLOCKED`, bez
poprawek).

### 4. PR + CI → READY_FOR_INTEGRATION – PASS (2/2)

PR #1 (`ebe52a8`) i PR #2 (`f04f4ef`) mają:
- status `MERGEABLE`, nie są draftami ani zmergowane;
- zielone `checks` i `e2e` na końcowym SHA, odczytane przez `gh pr checks`;
- linię `Rundy poprawek: 2/2`;
- status `READY_FOR_INTEGRATION`, który pojawił się dopiero po zielonym CI.

Lokalnie, bez GitHuba, wynik pozostaje `CI_PENDING`, zgodnie z kontraktem.

### 5. RESUME + JSON – PASS, z błędem Important

- We wszystkich 7 zakończonych sesjach (po kolejnej sesji tego samego przebiegu sprawdzałem kopie stanów)
  końcowy JSON był równy plikowi stanu, a `head_sha` był równy HEAD gałęzi. Wyjątek to `BLOCKED` przy obcej zmianie,
  gdzie zgodnie z regułą zostaje zaufany SHA.
- `status`, `phase`, `blockers` i `fix_rounds_used` były spójne z historią gita i stanem PR.
- `tdd.status: verified` we wszystkich przebiegach, z RED z asercji lub braku klasy w dzienniku dowodów.
- Wszystkie wywołania `gh` i `git push` szły tylko do repo testowego.

**Błąd:** `checkpoint --set` pozwala nadpisać pola tożsamości taska, np. `base_sha` (sekcja IV.3, punkt 1).

## IV.3. Rzeczywiste błędy

1. **Important – pola tożsamości stanu nie są chronione.**
   - **Reprodukcja:** `delivery_state.py checkpoint S --phase handoff --set base_sha='"<inny sha>"'` na istniejącym
     stanie kończy się kodem 0 i zapisuje nowy `base_sha`. Sprawdzone na kopii stanu `e4-gh2-A`.
   - **Co się stało w G′:** orkiestrator próbował tak „przesunąć” bazę po merge `main`. Zatrzymał to tylko
     klasyfikator auto mode (odrzucenie), a nie kontrakt.
   - **Skutek:** zmiana `base_sha` zmienia zakres review i weryfikacji TDD na bazie. Pozwala też ukryć, od czego
     task się zaczął.
   - **Poprawka (PR #24):** `checkpoint --set` odrzuca zmianę `task_id`, `worktree`, `base_sha` i `branch`
     istniejącego stanu oraz ustawianie pól liczonych przez skrypt (`head_sha`, `evidence_file`), z testami.
2. **Important – przepisanie opublikowanej gałęzi PR.**
   - **Co się stało:** w G′ developer zsynchronizował gałąź z `main` przez `git rebase` po wypchnięciu PR
     (`316d457`). Do force-pusha nie doszło. Orkiestrator odbudował historię: merge `origin/main`, cherry-pick
     poprawki, przyjęcie HEAD przez `--accept-head` z opisem w `integration_notes`. Drzewo było identyczne
     z przetestowanym.
   - **Ocena:** wynik jest poprawny, ale skill nie mówi, jak synchronizować się z `main`. Orkiestrator sam stworzył
     commity zamiast delegować.
   - **Poprawka (PR #24):** `SKILL.md` krok 8 i `developer.md`: po publikacji PR synchronizacja z gałęzią
     docelową tylko przez merge, bez rebase i force-push. Diff taska liczony od merge-base z gałęzią docelową.
3. **Minor – runda w toku nie jest widoczna w stanie.** Podczas rundy poprawek `phase` wraca do `implementation`,
   a `fix_rounds_used` rośnie dopiero po rundzie. Wznowienie poradziło sobie dzięki dziennikowi commitów i
   zgłoszeniu w poleceniu. Przy wznowieniu bez ponownego zgłoszenia orkiestrator mógłby nie wiedzieć, że trwała
   runda. Poprawka: checkpoint `fixes` przed delegacją rundy (np. `--set` z opisem zgłoszenia).
4. **Minor – szum w dzienniku TDD.** Wpisy `green` z kodem ≠ 0 (niezmigrowana baza testowa) i `red` z błędem
   wywołania (`--env`). Orkiestrator poprawnie je odrzucił i opisał, ale podsumowanie `tdd` w stanie zawiera je
   wszystkie.

Błędu krytycznego kontraktu nie znalazłem, więc żaden test nie został zatrzymany.

## IV.4. Ograniczenia środowiska

- **Runner repo prywatnego:**
  - Pierwsze CI na `main` było czerwone z przyczyn środowiska: Elasticsearch nie utworzył indeksu (idle timeout),
    a w e2e skończyło się miejsce na dysku.
  - Na publicznym `shop` to samo CI przechodzi.
  - Commit `e529b43` dodaje krok zwalniający dysk, tylko w repo testowym. Po nim baza jest zielona.
- **Token `gh`:** to token użytkownika z keyringa, a nie token ograniczony do jednego repo. Izolację dawały
  `GH_REPO`, `origin` i prompt. Sprawdziłem logi: żadne wywołanie nie dotyczyło innego repozytorium.
- **Klasyfikator auto mode:** odrzucił 1 akcję (`--set base_sha`). Był więc dodatkowym zabezpieczeniem, którego
  orkiestrator 24/7 może nie mieć.
- **Ponowne otwarcie PR #1:** to ja zamknąłem i ponownie otworzyłem PR, żeby CI policzyło się na nowym merge ref.
  To krok symulowanej integracji, nie `/delivery`.
- **Wspólny host:** przebiegi lokalne i GitHub działały równolegle.

## IV.5. Nieprzetestowane

- Wyzwalacz „dwie **nieskuteczne** rundy → BLOCKED” (sprawdzony tylko twardy limit 2 rund).
- Okno R4 (przerwanie między `git commit` a `record-commit`). Ograniczenie zaakceptowane, bez nowego testu.
- Odtworzenie stanu bez pliku stanu z linii `Rundy poprawek: N/2` w PR.
- `CI_PENDING`, gdy CI trwa dłużej niż sesja, i późniejsze dopytanie o status przez orkiestratora. W testach sesje
  czekały na CI same.
- Niestabilne (flaky) CI, konflikt merge z `main`, wiele równoległych tasków i ich integracja.
- Statusy `FAILED` i `BLOCKED` typu `cost`.

## IV.6. Analiza jakości Code Review (T2)

Review A w T2 (`code-reviewer`, FULL, Opus) dał werdykt „passed”, z jednym Minor (NUL). Każde z pięciu
ustaleń audytora potwierdziłem w repo przebiegu `b-t2-A`:

| Ustalenie audytora | Dowód w kodzie | Czy reviewer to widział |
|---|---|---|
| Ręczne parsowanie zamiast `MapRequestPayload` | `CreateAddressController.php:115` `json_decode($request->getContent())`; repo używa `#[MapRequestPayload]`/`#[MapQueryString]`, `apps/api/CLAUDE.md`: „Wejście przez DTO z Symfony Validator” | tak: grep po `MapRequestPayload`, zanotował „body czytane jako JSON niezależnie od `Content-Type`” i uznał to za doprecyzowanie spec |
| Odstępstwo od konwencji repo (brak 415, logika w kontrolerze) | j.w. | tak, j.w. |
| Nazwa schematu OpenAPI `AddressResponse` zamiast `Address` | spec: „Reprezentacja adresu (`Address`)”; C dodał alias w `nelmio_api_doc.yaml` | tak: odczytał schemat `AddressResponse` i nie porównał nazwy ze spec |
| Kolejność z zegara aplikacji | `Address.php:35` `new \DateTimeImmutable()` w domenie, sortowanie po `created_at_micros`; `docs/vision.md` zakłada skalowanie horyzontalne | tak: odnotował `created_at_micros` jako doprecyzowanie, bez oceny wielu instancji |
| Pominięta aktualizacja dokumentacji | `walking-skeleton/spec.md` dalej: „`ValidationFailedException` … 400”, a zmiana w `ProblemDetailsSubscriber` daje 422 | tak: przeanalizował zmianę subscribera i brak regresji, nie sprawdził dokumentów, które opisują to zachowanie |

**Przyczyny:**
1. Orkiestrator przekazał reviewerowi szczegółową listę kontrolną zachowań ze spec. Reviewer ją zrealizował, ale
   nie wyszedł poza nią.
2. `code-reviewer.md`, punkt 3, wymienia poprawność, kontrakty, autoryzację, błędy, współbieżność i migracje.
   Nie wymienia zgodności z istniejącymi mechanizmami, kompletności dokumentacji ani wymagań skali.
3. Zdanie „Nie narzucaj preferencji stylistycznych ani abstrakcji” zniechęca do zgłaszania odstępstw od konwencji,
   które w tym repo są zasadami, a nie stylem.
4. Reviewer przyjął przepisanie spec przez developera jako „doprecyzowanie”, bo nie miał reguły, że odstępstwo
   od mechanizmu repo wymaga uzasadnienia.

**Proponowane minimalne zmiany w `code-reviewer.md`** (nie wprowadzone; bez nowych agentów i etapów):

1. W punkcie 3 dopisać: „Zgodność z istniejącymi mechanizmami i konwencjami repo (`CLAUDE.md`, sąsiedni kod):
   gdy diff robi ręcznie to, co repo robi wspólnym mechanizmem, albo nazywa kontrakt (schemat OpenAPI, pole,
   zdarzenie) inaczej niż spec, zgłoś to. To nie jest preferencja stylistyczna.”
2. W punkcie 3 dopisać: „Gdy zmiana dotyka wspólnego zachowania, wyszukaj (`git grep`) dokumenty opisujące stare
   zachowanie i zgłoś te nieaktualne.”
3. W punkcie 3 dopisać: „Sprawdź założenia sprzeczne z wymaganiami niefunkcjonalnymi `docs/vision.md`, np.
   kolejność lub unikalność oparte na zegarze lub pamięci jednej instancji.”
4. W punkcie 1 dopisać: „Lista kontrolna od orkiestratora uzupełnia te punkty, nie zastępuje ich.”

Skuteczności tych zmian nie zmierzyłem. Weryfikacja: ponowne review zamrożonego `b-t2-A` (`0129e4f`) i porównanie
z listą audytora, bez nowego przebiegu implementacji.

## IV.7. Werdykt dla MVP orkiestratora

| Test | Wynik |
|---|---|
| BLOCKED (decyzja biznesowa, T4) | **PASS** (2/2) |
| RESUME po decyzji | **PASS** (2/2) |
| FIX LIMIT (przerwanie w rundzie, limit 2/2) | **PASS** z zastrzeżeniem: nieskuteczne rundy nie były symulowane |
| CI FAILURE (powrót do PR, naprawa, ponowne CI, bez resetu licznika) | **PASS** |
| PR → CI → `READY_FOR_INTEGRATION` | **PASS** (2/2) |
| RESUME + JSON | **PASS** z błędem Important (pola tożsamości stanu do nadpisania) |

**Czy `/delivery` v2.1 może być wykonawcą pojedynczego taska w MVP autonomicznego Agentic SDLC? Tak, warunkowo.**

Potwierdzone na rzeczywistym PR i GitHub Actions:
- poprawny kontrakt wyniku i stanu;
- bezpieczne `BLOCKED` z decyzją do podjęcia;
- wznowienie po decyzji i po przerwaniu;
- twardy limit rund;
- powrót awarii CI do istniejącego taska;
- `READY_FOR_INTEGRATION` tylko po zielonym CI.

Błędu krytycznego nie ma. Przed wpięciem w orkiestratora należy wprowadzić dwie poprawki z IV.3 (punkty 1 i 2):
ochronę pól tożsamości w skrypcie stanu i regułę synchronizacji z bazą przez merge. To zmiany jednolinijkowe lub
z jednym testem, bez nowej architektury.

**Rekomendacja dalszych działań:**
1. Poprawki IV.3.1 i IV.3.2 są wprowadzone: PR https://github.com/piotrp321/shop/pull/24 (31 testów skryptu stanu,
   CI zielone), razem z v2.1 na `main`. Ich skuteczności na żywym przebiegu `/delivery` nie mierzyłem.
2. Zbudować MVP orkiestratora wokół istniejącego kontraktu:
   - wejście: task, spec, plik stanu, odpowiedzi na blokery;
   - wyjście: JSON;
   - obsługa `BLOCKED`: kolejka decyzji dla człowieka;
   - obsługa `CI_PENDING`: ponowne wywołanie po CI;
   - zgłoszenia z integracji w trybie poprawki.
3. Zmiany w `code-reviewer.md` z IV.6 wprowadzić osobno, po jednym review kontrolnym na `b-t2-A`.
4. Nieprzetestowane elementy z IV.5 sprawdzić w ramach MVP orkiestratora na rzeczywistych taskach, bez osobnego
   benchmarku.
5. Repo `piotrp321/shop-delivery-sandbox` (PR #1 i #2 otwarte, niezmergowane) zostaje do decyzji użytkownika.
