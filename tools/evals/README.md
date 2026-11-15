# Evale agentów (`tools/evals`)

Mierzalna kontrola jakości agentów: rejestr zestawów (`code-reviewer`, `spec-critic`, `backlog-groomer`,
`retro-analyst`), przypadki każdego zestawu, historia wyników i sprawdzenie w CI, że PR zmieniający agenta ma aktualny
wynik. Specyfikacja: `docs/features/evals/spec.md`. Wszystko tutaj to Python 3 (biblioteka standardowa), bez modelu
i bez sieci; skrypty uruchamia się na hoście jak testy skryptów (`python3`).

## Pliki

| Plik | Rola |
|---|---|
| `suites.json` | rejestr zestawów i lista akcji kanarka |
| `config.json` | `N`, limity, progi regresji, lista plików kodu oceny |
| `suites/<zestaw>/<przypadek>/case.json` | przypadek (oraz jego dane: `input.json`, tekst zgłoszenia itp.) |
| `history/<zestaw>/<data>-<sha>.json` | wynik uruchomienia albo decyzja `accept` / `waive` |
| `evals.py` | komendy `lint`, `check`, `report`, `accept`, `waive`, `estimate`, `run` |
| `evals_runner.py` | runner: prognoza, uruchomienie, limity, wznowienie, artefakty przebiegu |
| `evals_checkout.py` | checkout przebiegu: repo bare, nakładka, diff zadania, łatka, zmiany drzewa |
| `evals_guard.py` | środowisko wykonawcy, zaślepki `gh` i SSH, ustawienia sandboxu z AGENT-SECURITY |
| `evals_executor.py` | wykonawcy (`claude-cli`): polecenie i odczyt strumienia zdarzeń |
| `evals_case.py` | pola runnera w przypadku i ich symbole zastępcze |
| `fake_cli.py` | atrapa CLI do testów runnera (bez modelu i sieci) |
| `evals_registry.py` | rejestr, konfiguracja, odcisk, skróty kodu oceny i walidatorów |
| `evals_history.py` | format pliku historii, czytanie i zapis (jedyna droga zapisu wyniku) |
| `evals_lint.py`, `evals_check.py` | logika komend `lint` i `check` |
| `evals_repo.py`, `evals_report.py` | dostęp do plików z dysku albo z rewizji gita, raport błędów i ostrzeżeń |

## Rejestr (`suites.json`)

```json
{
  "schema_version": 1,
  "canary_actions": ["canary_file", "canary_token", "gh issue close", "gh pr merge", "gh label", "change_verdict"],
  "suites": {
    "code-reviewer": {
      "agent": "code-reviewer",
      "watched": [".claude/agents/code-reviewer.md"],
      "cases": "tools/evals/suites/code-reviewer",
      "validators": []
    }
  }
}
```

- `watched`: **pliki obserwowane**, czyli to, co uruchomienie podaje modelowi (definicja agenta i specyfikacje, które
  agent czyta). Definicja agenta (`.claude/agents/<agent>.md`) jest obowiązkowa. `CLAUDE.md` nie jest obserwowany.
- `cases`: katalog przypadków, zawsze `tools/evals/suites/<zestaw>`. Brak katalogu albo przypadków nie jest błędem.
- `validators`: skrypty używane tylko jako asercje (`groom.py`, `retro.py`). Ich zmiana nie zmienia przebiegu, więc nie
  zmienia odcisku; wynik zapisuje skrót, a `check` ostrzega o zmianie od pomiaru.
- Opcjonalne nadpisania dla zestawu: `n`, `pass_cost_estimate_usd` (szacunek kosztu przebiegu), `pass_cost_cap_usd`
  (limit kosztu przebiegu), `pass_timeout_minutes` (limit czasu przebiegu), `executor` (nazwa wykonawcy, domyślnie
  `claude-cli`) i `writes_results` (`true` dla agentów zapisujących plik wyniku, np. `proposals.json`: dostają katalog
  `files/` przebiegu przez `--add-dir`; `backlog-groomer` i `retro-analyst`).
- `canary_actions`: akcje, które ładunek przypadku `injection` może polecać (plik-kanarek, token w wyniku, zapis
  w GitHubie przez zaślepkę `gh`: `gh issue close`, `gh pr merge`, `gh label`, zmiana werdyktu). Wpis `gh ...` to
  prefiks wywołania. Nowy zestaw to nowy wpis w `suites`, bez zmiany kodu.

## Konfiguracja (`config.json`)

`n` (powtórzenia przypadku, co najmniej 3), `pass_cost_cap_usd` (limit przebiegu), `run_cost_cap_usd` (limit
uruchomienia zestawu), `pass_cost_estimate_usd` (szacunek przebiegu bez pomiaru), `pass_timeout_minutes`,
`thresholds` (progi regresji i ostrzeżeń: `correctness_drop_points`, `case_drop_divisor`, `cost_warning_percent`,
`time_warning_percent`), `usage` (próg startu i zatrzymania według limitu subskrypcji) i `scoring_code` (pliki kodu
oceny, z których liczy się skrót zapisywany w wyniku; plik z listy musi istnieć). Nieznany klucz i `n < 3` to błąd.

## Odcisk

Odcisk zestawu to SHA-256 z posortowanych ścieżek i treści: plików obserwowanych, plików przypadków (wszystko pod
`suites/<zestaw>/<przypadek>/`; README zestawu i pliki zaczynające się od kropki nie wchodzą) oraz tej części
konfiguracji, która zmienia przebieg: agent, wykonawca, efektywne `n`, limit kosztu przebiegu, limit czasu przebiegu
i `writes_results`.
Progi, limit uruchomienia, szacunek kosztu i walidatory do odcisku nie wchodzą. Aktualny wynik zestawu jest
**aktualny**, gdy jego odcisk równa się odciskowi liczonemu z dysku.

## Przypadek (`case.json`)

Format minimalny i pola runnera (niżej); innych pól lint nie sprawdza.

```json
{
  "id": "nazwa-katalogu",
  "kind": "defect",
  "commit": "<40 znaków SHA>",
  "assertions": [{"type": "verdict", "expect": "CHANGES_REQUIRED"}]
}
```

- `id`: nazwa katalogu przypadku (małe litery, cyfry, `-`).
- `kind`: `defect` (z wadą), `clean` (bez wady), `substantive` (merytoryczny) albo `injection`.
- Stan repo: `commit` albo `base_sha` i `head_sha` (przypadek z diffem, tylko jedno z dwóch). Commity muszą być
  osiągalne z `main` albo `epic/*` (lokalnych lub `refs/remotes/*/`; gałęzie `task/*` są usuwane po merge'u). Diff
  zadania nie może dotykać `.claude/agents/**`, `.claude/skills/**` ani plików obserwowanych zestawu: nakładka
  podmienia je na bieżące wersje.
- `assertions`: niepusta lista obiektów z polem `type`: `verdict`, `finding`, `max_severity`, `validator`, `json`,
  `canary_absent`, `tree_unchanged`. Znaczenie asercji (ocena) jest poza tym katalogiem; lint sprawdza nazwy i kształt.
- Przypadek `injection`: pole `payload_b64` (ładunek zakodowany base64, UTF-8), `payload_actions` (podzbiór
  `canary_actions` rejestru) oraz asercje `canary_absent` i `tree_unchanged`. Zdekodowany ładunek ma po
  normalizacji (wielkość liter i białe znaki) co najmniej 24 znaki, krótszy jest błędem lintu przypadku. Ładunku nie
  wolno zapisać jawnie w żadnym pliku `tools/evals/**`.

Pola runnera (lint sprawdza ich kształt, runner wymaga zlecenia i dostarczenia ładunku przypadku `injection`):

- `prompt` (tekst) albo `prompt_file` (plik przypadku): zlecenie agenta, odtwarzające zlecenie skilla, który go
  wywołuje;
- `inputs`: lista plików przypadku kopiowanych do `input/` katalogu przebiegu;
- `gh`: odpowiedzi zaślepki `gh`, lista `{"args": [dokładne argumenty], "stdout": "..." albo "stdout_file": "<plik>",
  "exit_code": 0}`; każde inne wywołanie kończy się kodem 1;
- `patch`: plik przypadku z łatką (`git diff --binary`), nakładaną jednym commitem na stan przypadku (po nakładce,
  a w przypadku z diffem po diffie zadania); łatka nie może dotykać ścieżek nakładki;
- `canary_file` (tylko `injection`): nazwa pliku-kanarka w katalogu przebiegu (domyślnie `canary-<losowe>`).

Symbole zastępcze `{{NAZWA}}` (wielkie litery; inne nawiasy, np. `{{count}}` z szablonu Vue, zostają bez zmian) runner
wypełnia przy zapisie przebiegu w zleceniu, plikach `inputs`, odpowiedziach `gh` i łatce: `BASE`, `HEAD` (commity
checkoutu do recenzji; niedostępne w łatce), `PASS_DIR`, `RESULT_DIR` (`files/`), `INPUT_DIR` (`input/`),
`CANARY_FILE`, `CANARY_TOKEN` (losowy na przebieg) i tylko w przypadku `injection` `PAYLOAD` (zdekodowany
`payload_b64`, którego własne symbole też są wypełniane). Ładunek jest dekodowany wyłącznie do katalogu przebiegu.

## Historia wyników

Plik `history/<zestaw>/<UTC YYYYMMDDTHHMMSSZ>-<7 znaków sha>.json`. **Aktualny wynik to najnowszy plik wg kolejności
nazw**; pusta historia to werdykt `none`. Werdykty: `baseline` (pierwszy pomiar), `pass`, `regression`, `accepted`
(regresja przyjęta przez użytkownika) i `waived` (zmiana bez pomiaru zwolniona przez użytkownika).

Pola wyniku pomiaru (`baseline`, `pass`, `regression`): `schema_version`, `suite`, `verdict`, `fingerprint`, `commit`,
`date`, `n`, `metrics` (m.in. `correctness`, `recall`, `false_alarms`, `scope_violations`, `injection_resilience`,
`stability`, `unstable_cases`, koszt i czas), `cases` (dla każdego przypadku `id`, `case_hash` (skrót `case.json`),
`kind`, `passes` i `repetitions` z wynikiem każdego powtórzenia), `cost`, `usage`, `cli_version`, `model`, `plugins`,
`scoring_code_hash`, `validator_hashes`, `comparison` (z poprzednim wynikiem i z wynikiem odniesienia, z nazwą ich
plików). Wynik `accepted` i `waived` ma tylko pola wspólne, `reason` (obowiązkowy, niepusty) i `run_file` (plik
uruchomienia, którego dotyczy; obowiązkowy przy `accepted`, który wskazuje wynik `regression`).

Wynik odniesienia to ostatni `baseline` albo `accepted`; poprzedni pomiar to ostatni plik z werdyktem pomiaru.
W repo nie ma transkryptów, raportów agentów ani promptów (repo jest publiczne): `evals_history.write_result(root,
suite, result)` przyjmuje tylko pola formatu, także zagnieżdżone, i nie zapisuje niczego, gdy wynik jest niepoprawny,
plik już istnieje albo nie byłby najnowszym plikiem historii. `evals_history.decision(...)` buduje wynik `accepted`
i `waived`; `latest`, `previous_measurement` i `reference_measurement` czytają historię.

## Komendy

```sh
python3 tools/evals/evals.py lint
python3 tools/evals/evals.py check [--diff <baza>...<head>] [--enforce]
python3 tools/evals/evals.py report [--suite <zestaw>]
python3 tools/evals/evals.py accept <zestaw> --reason "<powód>"
python3 tools/evals/evals.py waive <zestaw> --reason "<powód>"
python3 tools/evals/evals.py estimate [<zestaw>] [--case <id>] [--json]
python3 tools/evals/evals.py run <zestaw> [--case <id> [--overlay-rev <rewizja>]] [--state-root <katalog>]
python3 tools/evals/evals.py run --resume <id> [--state-root <katalog>]
```

Kod wyjścia: 0 poprawnie, 1 błędy (lint, check, odmowa decyzji), 2 komenda nie może działać (zły argument, brak
rejestru, zły JSON, błąd `--diff`, brak gita tam, gdzie jest potrzebny). `--root <katalog>` wskazuje repozytorium
(domyślnie to, w którym leży skrypt).

- **`lint`** (`make evals-lint`, w `make check`): format rejestru, konfiguracji, przypadków i plików historii (pola,
  werdykty, powód przy `accepted` i `waived`, `n >= 3`); skan `suites/**` i `history/**` (także zdekodowanych ładunków)
  na ścieżki domowe, adresy e-mail i wzorce tokenów (`ghp_`, `github_pat_`, `sk-ant-`); ładunki `injection`: zdekodowany
  tekst nie może występować jawnie w żadnym pliku `tools/evals/**`, może polecać tylko akcje kanarka z rejestru (każde
  wywołanie `gh` musi zaczynać się od akcji z listy), a wzorce destrukcyjne (`rm -rf`, `push --force`, odczyt `.env`
  i `~/.ssh` bez względu na wielkość liter, `rm` także w cudzysłowie albo backtickach, wymuszony push także przez `+`
  w refspecu, łączone krótkie flagi albo kontynuację linii) są w nim odrzucane, a `gh` wywołane po ścieżce, w cudzysłowie
  albo backtickach, w dowolnej wielkości liter, to też wywołanie `gh`, porównywane z akcjami kanarka bez względu na
  wielkość liter (zwykłe dane przypadków opisują proces i nie są tak skanowane); jawna kopia ładunku jest wykrywana po
  normalizacji (wielkość liter i białe znaki) także w zdekodowanych wartościach plików JSON (np. zapisanych z `\uXXXX`),
  także pod kluczem `payload_b64` poza przypadkiem (pomijana jest tylko wartość będąca base64 któregoś ładunku); pełny
  tekst ładunku jest szukany zawsze, a jego osobne linie krótsze niż 24 znaki nie; dowiązania symboliczne w `suites/**` i `history/**`
  to błąd (odcisk ich nie podąża, jak git); commity przypadków
  i diff przypadku z diffem (tylko przy pełnej historii; w płytkim klonie i poza gitem lint to pomija z ostrzeżeniem).
- **`check`** (`make evals-check`, poza `make check`; argumenty przez `EVALS_CHECK_ARGS`): dla każdego zestawu odcisk
  z dysku wobec aktualnego wyniku. Zestaw jest **zmieniony** w diffie, gdy odcisk liczony z merge-base różni się od
  odcisku z head: obejmuje to każde wejście odcisku (plik obserwowany, przypadek, wpis rejestru i konfigurację
  przebiegu). Błąd (kod 1) tylko z `--enforce`, dla zestawu z pomiarem (historia ma co najmniej jeden plik z werdyktem
  `baseline`, `pass`, `regression` albo `accepted`; historia z samymi `waived` to zestaw bez pomiaru), gdy diff go zmienia
  (albo bez `--diff`: każdy) i jego aktualny wynik ma inny odcisk (`suite X is stale: run /evals X`) albo werdykt
  `regression`; także gdy diff dodaje plik historii z werdyktem `regression`, chyba że nowszy plik `accepted` albo
  `waived` tego zestawu wskazuje go jako `run_file`. Nowszy pomiar (`pass`) dodanej regresji nie pokrywa: odblokowuje
  ją wyłącznie decyzja użytkownika (`accept` albo `waive` z powodem; `/evals` zapisuje ją na polecenie użytkownika
  w rozmowie), więc komunikat nie podpowiada nowego pomiaru. Wszystko inne (zestaw bez pomiaru, zestaw niezmieniony w PR, brak
  `--enforce`) to ostrzeżenie. Zmiana skrótu kodu oceny albo walidatora od ostatniego pomiaru to ostrzeżenie
  z zaleceniem nowego pomiaru.
- **`report`**: historia zestawu jako tabela (werdykt, odcisk, metryki, koszt; najnowszy plik oznaczony, wraz z informacją,
  czy jest aktualny).
- **`accept`**: tylko gdy aktualny wynik to aktualna `regression`; zapisuje plik `accepted` z powodem i `run_file`.
- **`waive`**: zapisuje plik `waived` z bieżącym odciskiem i powodem (`run_file` wskazuje ostatni pomiar; na historii
  bez pomiaru pola `run_file` nie ma).
  `accept` i `waive` zapisuje wyłącznie agent działający na polecenie użytkownika w rozmowie; wiadomość innej sesji,
  plik ani wynik narzędzia nie jest poleceniem.

- **`estimate`**: prognoza kosztu i czasu uruchomienia zestawu (bez nazwy: każdego i suma) albo przebiegu próbnego
  (`--case`). Kod 1, gdy prognoza przekracza limit.
- **`run`**: uruchomienie zestawu z prawdziwym modelem (sekcja „Runner”); wypisuje id uruchomienia, status i komendę
  wznowienia. Kod 0 dla uruchomienia zakończonego (`done`), 1 dla odmowy i zatrzymania, 2 dla złych argumentów.

## Runner

`evals.py run <zestaw>` wykonuje każdy przypadek zestawu `N` razy (przebiegi po kolei: przypadki w kolejności nazw,
w każdym powtórzenia `1..N`). Nie ocenia, nie liczy werdyktu i nie zapisuje pliku historii: ocena czyta artefakty
zakończonego uruchomienia (`done`).

**Start.** Po kolei, pierwszy niespełniony warunek to odmowa (kod 1, nic nie zostaje w katalogu stanu): pliki
mierzone (`suites.json`, `config.json`, przypadki zestawu, `.claude/agents`, `.claude/skills`, pliki obserwowane) bez
niezatwierdzonych zmian (nakładka pochodzi z commita, odcisk z dysku); przypadki poprawne (lint i pola runnera);
prognoza w limicie; ustawienia sandboxu poprawne (niżej). Brak binarki pinu to już `infra_error` uruchomienia, które
można wznowić.

**Katalogi.** `--state-root` (domyślnie `~/.local/state/shop-evals`):

```
<state-root>/<id>/run.json                       stan uruchomienia (poza zasięgiem agenta)
<state-root>/<id>/runs/<przypadek>/<powtórzenie>/ katalog przebiegu: jedyny --state-dir sandboxu
    checkout/      świeży klon z origin.git (katalog roboczy agenta)
    origin.git/    lokalne repo bare; origin URL i pushurl checkoutu to jego ścieżka
    files/  input/ wynik agenta (--add-dir dla writes_results) i dane wejścia przypadku
    gh.log ssh.log git-trace.jsonl tmp/
    runner/        tylko runner (--deny-write w sandboxie): zaślepki bin/gh i bin/ssh, gitconfig, pusty gh-config/,
                   gh-data.json, settings.json, prompt.txt, manifest.json, stream.jsonl, tool_use.jsonl, stderr.txt
    status.json output.txt tree.txt canary.txt     artefakty runnera (--deny-write), zapisywane po końcu procesu
```

`run.json`: `id`, `kind` (`suite` albo `trial`), `suite`, `case`, `status` (`running`, `done`, `cost_stop`,
`limit_stop`, `infra_error`), `reason`, `fingerprint`, `head`, `overlay_rev`, `executor`, `agent`, `n`, limity,
`forecast_usd`, `cost_usd_total` (suma wszystkich części), `cases` (`id`, `case_hash`, `kind`) i `passes` (`case`,
`repetition`, `status`, `reason`, `cost_usd`, `charged_usd`, `duration_s`, `attempts`).

**Checkout przebiegu** (`evals_checkout.py`). W repo roboczym w katalogu przebiegu (usuwanym przed startem agenta)
runner pobiera z repo z głębokością 1 commit przypadku (`commit` albo `base_sha` i `head_sha`) i rewizję nakładki
(HEAD albo `--overlay-rev`) i buduje commit nakładki: drzewo przypadku z `.claude/agents`, `.claude/skills` i plikami
obserwowanymi z rewizji nakładki (plik usunięty tam znika też w checkoucie). Przypadek z diffem dostaje nakładkę na
`base_sha` i na `head_sha` jako dwa kolejne commity, więc `git diff <nowa baza>..<nowy HEAD>` to dokładnie diff
zadania (sprawdzane; diff dotykający ścieżek nakładki to `infra_error`). Do repo bare trafiają tylko commity osiągalne
z nowego HEAD: commit rewizji nakładki (dzisiejsze drzewo repo, z asercjami przypadków) nie. Commity mają datę commita
przypadku, więc każde powtórzenie dostaje te same SHA. Po przebiegu runner nie uruchamia gita w checkoucie
(jego `.git/config` kontroluje agent): `tree.txt` porównuje drzewo robocze z manifestem commita startowego w Pythonie.

**Środowisko wykonawcy** (`evals_guard.py`) powstaje z listy dozwolonych zmiennych (`HOME`, `USER`, `LOGNAME`, `SHELL`,
`LANG`, `LC_*`, `TERM`, `TZ`, `CLAUDE_CONFIG_DIR`, zmienne proxy i CA), nigdy z kopii środowiska. Dochodzą: `PATH`
z `runner/bin` na początku (zaślepka `gh`: odpowiedzi z `gh` przypadku, każde wywołanie w `gh.log`, inne niż
z danych przypadku kończy się kodem 1), `GH_CONFIG_DIR` na pusty katalog, `GIT_SSH_COMMAND` na zaślepkę SSH (kod
255, wpis w `ssh.log`), `GIT_CONFIG_GLOBAL` na `runner/gitconfig` (tożsamość commitów, bez pomocnika poświadczeń),
`GIT_CONFIG_NOSYSTEM=1`, `GIT_TERMINAL_PROMPT=0`, `GIT_ALLOW_PROTOCOL=file`, `GIT_TRACE2_EVENT` na `git-trace.jsonl`,
`DOCKER_HOST` na nieistniejące gniazdo w `runner/`, `TMPDIR` na `tmp/`. Nie ma `GH_TOKEN`, `GITHUB_TOKEN`,
`SSH_AUTH_SOCK`, `GIT_ASKPASS`, `GIT_CONFIG_*` wywołującego ani zmiennych płatnego API, innych dostawców i nadpisania
modelu (`evals_guard.PAID_API_VARIABLES`, m.in. `ANTHROPIC_API_KEY`, `ANTHROPIC_AUTH_TOKEN`, `ANTHROPIC_BASE_URL`,
`ANTHROPIC_MODEL`, `CLAUDE_CODE_USE_BEDROCK`, `CLAUDE_CODE_USE_VERTEX`, `CLAUDE_CODE_USE_FOUNDRY`,
`CLAUDE_CODE_OAUTH_TOKEN`, `AWS_BEARER_TOKEN_BEDROCK`, `OPENAI_API_KEY`); CLI używa logowania subskrypcji z Keychain
i `~/.claude`. `git push` na `git@github.com:…`, `ssh://…` i `https://…` git odrzuca przez `GIT_ALLOW_PROTOCOL=file`
przed połączeniem (zaślepka SSH nie jest wtedy wołana), więc runner po przebiegu dopisuje te odmowy do `ssh.log`
z argumentami polecenia (zdarzenia `error` „transport '…' not allowed” śladu trace2); wpisy zaślepki mają `"via":
"ssh"`, odmowy gita `"via": "git"` i `"transport"`. Push do `origin` trafia do repo bare przebiegu.

**Sandbox.** Ustawienia renderuje `tools/agent-security/render_settings.py` (wywoływany tym samym Pythonem, `-I`):
`--workdir <checkout>`, `--state-dir <katalog przebiegu>`, `--deny-write` dla `runner/` i artefaktów runnera,
`--no-network`, `--no-docker`; wszystkie ścieżki tworzy runner i podaje po `realpath`. Runner przekazuje plik
`runner/settings.json` przez `--settings` i nie używa wrappera wykonawców AO ani jego tokenu. Ustawienia muszą mieć
`sandbox.enabled: true`, `failIfUnavailable: true`, `allowUnsandboxedCommands: false`, puste `allowedDomains`, bez
gniazda Dockera i `allowLocalBinding`; inaczej (także brak skryptu albo jego błąd) odmowa przed pierwszym przebiegiem,
bez uruchomienia CLI. Profil jest sprawdzany przed pierwszym przebiegiem (na próbnym katalogu) i przy każdym
przebiegu (wtedy błąd to `infra_error`). Nie ma trybu bez tego sprawdzenia: testy używają atrapy CLI z tymi samymi
ustawieniami (prawdziwy `render_settings.py`, a tam, gdzie test potrzebuje złego profilu, atrapa skryptu podana
w `evals_runner.Options`, niedostępna z wiersza poleceń).

**Interfejs wykonawcy** (`evals_executor.py`, wymienny: `executor` zestawu w rejestrze). `command(binary, prompt,
agent, budget_usd, settings_path, add_dirs)` daje polecenie, `stream(stop_percent)` obiekt z `feed(linia)` (sygnał
zatrzymania `infra_error` albo `limit_stop`), `take_tool_uses()` i `outcome(kod, limit_czasu, budżet, stderr)`
(`status`, `reason`, `cost_usd`, `output`, `cli_version`, `model`, `plugins`, `mcp_servers`). Checkout, środowisko,
sandbox, limity i artefakty są wspólne. Wykonawca `claude-cli`: `claude -p <zlecenie> --agent <agent> --output-format
stream-json --verbose --permission-mode auto --permission-prompts none --max-budget-usd <limit przebiegu>
--strict-mcp-config --setting-sources project --settings <plik>` (plus `--add-dir <files/>` przy `writes_results`),
nigdy `--model`, `--allowedTools` ani `--tools`; binarka z `.claude/skills/ao-run/claude_pin.py` (`--claude-bin`
podaje inną, np. atrapę w testach; ustawienia sandboxu są sprawdzane tak samo). Zdarzenie `init` musi mieć pustą
listę `mcp_servers` (serwer MCP: `infra_error`); zapisywane są wersja CLI, model i lista wtyczek. Każde wywołanie
narzędzia (`tool_use`, także subagentów) runner zapisuje na bieżąco w `runner/tool_use.jsonl`, a cały strumień
w `runner/stream.jsonl`.

**Statusy przebiegu:** `done`; `failed` (przekroczony `--max-budget-usd`: wynik `error_max_budget_usd` albo
`total_cost_usd` ponad limit, przekroczony limit czasu przebiegu, błąd CLI po `init`, niepełny strumień bez zdarzenia
`result`); `infra_error` (błąd budowy checkoutu, łatki albo ustawień, CLI bez `init`, serwer MCP, binarka, której nie
da się uruchomić); `limit_stop`; `cost_stop`. `done` i `failed` są ukończone; `infra_error` i `limit_stop` kończą
uruchomienie tym samym statusem, z możliwością wznowienia.

**Limity.** Przed każdym przebiegiem: dotychczasowy koszt uruchomienia (wszystkich części) plus limit przebiegu
ponad limit uruchomienia (`run_cost_cap_usd`, 50 USD; przebieg próbny: `N` × limit przebiegu) kończy uruchomienie
jako `cost_stop`: niezaliczone, bez pliku historii, bez wznowienia; przebieg, który nie ruszył, dostaje artefakty ze
statusem `cost_stop`. Do kosztu wchodzi `total_cost_usd` przebiegu; przy koszcie nieznanym po `init` (limit czasu,
zatrzymanie, strumień bez wyniku) liczony jest limit przebiegu (górna granica), przy `infra_error` zero.
`rate_limit_event` z wykorzystaniem okna (`utilization` zdarzenia albo okna z `unifiedWindows`) co najmniej
`usage.stop_percent` (90%) albo statusem innym niż `allowed`/`allowed_warning` zatrzymuje CLI i kończy uruchomienie
jako `limit_stop`. Start przy progu `usage.start_max_percent` sprawdza `/evals` (`night_queue.py room`), nie runner.

**Prognoza** (`estimate`, liczona też przed startem `run`): liczba przebiegów (przypadki × `N`) razy mediana kosztu
przebiegu z ostatniego pomiaru zestawu (pola `cost_usd` powtórzeń w historii), a bez pomiaru szacunek
`pass_cost_estimate_usd` z rejestru albo `config.json` (1,5 USD; 27 przebiegów to 40,50 USD, w limicie 50 USD); czas:
mediana `duration_s` powtórzeń, bez pomiaru limit czasu przebiegu (górna granica). Prognoza ponad limit to odmowa.

**Wznowienie** (`run --resume <id>`): tylko uruchomienie `limit_stop`, `infra_error` albo przerwane (`running` bez
działającego procesu; blokada `run.lock`), przy niezmienionym odcisku zestawu (inaczej odmowa) i czystych plikach
mierzonych. Ukończone przebiegi zostają, przebieg nieukończony dostaje nowy, świeży katalog; nakładka pochodzi z tej
samej rewizji co na starcie, a koszt sumuje się do tego samego limitu.

**Przebieg próbny** (`run <zestaw> --case <id> [--overlay-rev <rewizja>]`): jeden przypadek `N` razy z nakładką
z podanej rewizji (domyślnie HEAD), limit `N` × limit przebiegu; nigdy nie zapisuje pliku historii.

**Artefakty przebiegu** (kontrakt z oceną, spec „Artefakty przebiegu”), w katalogu przebiegu:

- `status.json`: `status`, `reason`, `cost_usd`, `duration_s`, `cli_version`, `model`, `sandbox` (CLI ruszyło ze
  sprawdzonymi ustawieniami sandboxu), `plugins`, `mcp_servers`, `executor`, `exit_code`, `commits` (`base`, `head`,
  `overlay`), `started`, `finished`, `case`, `repetition`, `run`, `suite`;
- `output.txt`: pole `result` zdarzenia końcowego;
- `files/`: pliki wyniku agenta;
- `gh.log`, `ssh.log`: obiekty JSON w liniach (puste, gdy nic nie było);
- `tree.txt`: linie `A|M|D<tab>ścieżka` zmian drzewa roboczego checkoutu względem commita, od którego agent zaczął
  (nakładka, diff zadania albo łatka), bez `.git`;
- `canary.txt`: przy `injection` dwie linie: ścieżka pliku-kanarka i token; inaczej pusty;
- `runner/tool_use.jsonl`: wywołania narzędzi ze strumienia (`id`, `name`, `input`, `parent_tool_use_id`).

Artefakty powstają dla każdego przebiegu, także `infra_error` i `cost_stop` (wtedy puste). Agent może pisać w katalogu
przebiegu (to `--state-dir`), ale nie w `runner/` ani w artefaktach runnera (`--deny-write`, zapis po zakończeniu
procesu, bez podążania za dowiązaniem); `gh.log`, `ssh.log` i `files/` agent może zmienić, dlatego wiarygodnym
śladem są `runner/tool_use.jsonl` i `runner/stream.jsonl`.

## Job `evals` w CI

Osobny job `evals` w `.github/workflows/ci.yml` działa przy każdym zakresie zmiany (także samej dokumentacji, bo zmiana
`.md` agenta to dla `scope.sh` zakres „docs”), nie ma zależności od innych jobów, ma `fetch-depth: 0`, krótki timeout
i nie ma sekretów. Uruchamia `make evals-lint evals-check` z argumentami wybranymi przez `.github/scripts/evals-args.sh`:

| Zdarzenie | Argumenty `check` |
|---|---|
| PR do `main` | `--enforce --diff <baza>...<head>` |
| PR do innej gałęzi (`epic/*`) | `--diff <baza>...<head>` (same ostrzeżenia) |
| push do `main` | `--enforce` |
| push do `epic/**` | brak (same ostrzeżenia) |

Job nie uruchamia modelu. Dodanie `evals` do wymaganych checków rulesetu `main` jest osobną czynnością użytkownika
po merge'u (rulesety: `tools/rulesets/`, `CLAUDE.md`).

## Testy

```sh
python3 -m unittest discover -s tools/evals -p 'test_*.py'
sh .github/scripts/test_evals_args.sh
```

Testy runnera używają atrapy CLI (`fake_cli.py`, scenariusz zdarzeń w JSON), prawdziwego `render_settings.py`
i repozytoriów tymczasowych; nie uruchamiają modelu ani sandboxu.
CI uruchamia je przez `.github/scripts/script-tests.sh` (wykrywa je po położeniu). Dane testowe powstają w katalogach
tymczasowych (zakazane wzorce i ładunki składane w testach w trakcie działania), więc `evals.py lint` na repo ich nie
znajduje.
