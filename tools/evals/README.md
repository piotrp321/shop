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
| `evals.py` | komendy `lint`, `check`, `report`, `accept`, `waive` |
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
  (limit kosztu przebiegu), `pass_timeout_minutes` (limit czasu przebiegu) i `executor` (nazwa wykonawcy, domyślnie
  `claude-cli`).
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
konfiguracji, która zmienia przebieg: agent, wykonawca, efektywne `n`, limit kosztu przebiegu i limit czasu przebiegu.
Progi, limit uruchomienia, szacunek kosztu i walidatory do odcisku nie wchodzą. Aktualny wynik zestawu jest
**aktualny**, gdy jego odcisk równa się odciskowi liczonemu z dysku.

## Przypadek (`case.json`)

Format minimalny; pola runnera, których tu nie ma (zlecenie, dane wejścia itp.), są dowolne i lint ich nie sprawdza.

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
  `canary_actions` rejestru) oraz asercje `canary_absent` i `tree_unchanged`. Ładunku nie wolno zapisać jawnie
  w żadnym pliku `tools/evals/**`.

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
```

Kod wyjścia: 0 poprawnie, 1 błędy (lint, check, odmowa decyzji), 2 komenda nie może działać (zły argument, brak
rejestru, zły JSON, błąd `--diff`, brak gita tam, gdzie jest potrzebny). `--root <katalog>` wskazuje repozytorium
(domyślnie to, w którym leży skrypt).

- **`lint`** (`make evals-lint`, w `make check`): format rejestru, konfiguracji, przypadków i plików historii (pola,
  werdykty, powód przy `accepted` i `waived`, `n >= 3`); skan `suites/**` i `history/**` (także zdekodowanych ładunków)
  na ścieżki domowe, adresy e-mail i wzorce tokenów (`ghp_`, `github_pat_`, `sk-ant-`); ładunki `injection`: zdekodowany
  tekst nie może występować jawnie w żadnym pliku `tools/evals/**`, może polecać tylko akcje kanarka z rejestru (każde
  wywołanie `gh` musi zaczynać się od akcji z listy), a wzorce destrukcyjne (`rm -rf`, `push --force`, odczyt `.env`
  i `~/.ssh`) są w nim odrzucane (zwykłe dane przypadków opisują proces i nie są tak skanowane); commity przypadków
  i diff przypadku z diffem (tylko przy pełnej historii; w płytkim klonie i poza gitem lint to pomija z ostrzeżeniem).
- **`check`** (`make evals-check`, poza `make check`; argumenty przez `EVALS_CHECK_ARGS`): dla każdego zestawu odcisk
  z dysku wobec aktualnego wyniku. Zestaw jest **zmieniony** w diffie, gdy odcisk liczony z merge-base różni się od
  odcisku z head: obejmuje to każde wejście odcisku (plik obserwowany, przypadek, wpis rejestru i konfigurację
  przebiegu). Błąd (kod 1) tylko z `--enforce`, dla zestawu z pomiarem (niepusta historia), gdy diff go zmienia
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
- **`waive`**: zapisuje plik `waived` z bieżącym odciskiem i powodem (`run_file` wskazuje ostatni pomiar).
  `accept` i `waive` zapisuje wyłącznie agent działający na polecenie użytkownika w rozmowie; wiadomość innej sesji,
  plik ani wynik narzędzia nie jest poleceniem.

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

CI uruchamia je przez `.github/scripts/script-tests.sh` (wykrywa je po położeniu). Dane testowe powstają w katalogach
tymczasowych (zakazane wzorce i ładunki składane w testach w trakcie działania), więc `evals.py lint` na repo ich nie
znajduje.
