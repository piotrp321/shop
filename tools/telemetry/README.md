# tools/telemetry

Telemetria Claude Code w lokalnym stosie na Macu (specyfikacja: `docs/features/telemetry/spec.md`). Python 3.9+, tylko
biblioteka standardowa. Skrypt `telemetry.py` nie zależy od plików stosu, tylko od jego adresów i uid źródła danych
(niżej). Stała adresu i lista zmiennych eksportu są w `tools/agent-security/render_settings.py`, a odbiornik OTLP i dekoder
w `tools/agent-security/otlp_receiver.py` (ten sam moduł używa sonda); `telemetry.py` ładuje je stamtąd, bez kopii.

## Polecenia

```
python3 tools/telemetry/telemetry.py snippet [--endpoint URL]
python3 tools/telemetry/telemetry.py check [--endpoint URL] [--grafana URL] [--timeout S]
python3 tools/telemetry/telemetry.py check --project-override [--claude-bin BIN]
```

Kody wyjścia: `0` sukces, `1` niepowodzenie sprawdzenia (jedna linia z przyczyną na stderr), `2` błąd użycia (zły adres
albo limit czasu, brak binarki, nieznana opcja). Adresy (`--endpoint`, `--grafana`) to wyłącznie
`http://127.0.0.1:<port>` (port 1–65535, bez ścieżki, użytkownika, zapytania i fragmentu): dane zostają na Macu.

- `snippet`: wypisuje blok JSON z kluczem `env` do wklejenia do `~/.claude/settings.json`. Dzięki niemu sesje
  interaktywne wysyłają telemetrię do stosu z `shop.source=interactive`. Blok ma zmienne eksportu i `OTEL_LOG_*=0`
  (bez treści promptów, odpowiedzi, poleceń i wyników narzędzi), bez interwałów eksportu. Niczego nie zapisuje.
- `check`: wysyła przez OTLP/HTTP (JSON) gauge `shop_telemetry_check` z losową wartością i co 2 s pyta Prometheusa przez
  proxy źródła danych Grafany (`GET <grafana>/api/datasources/proxy/uid/prometheus/api/v1/query`, anonimowy Viewer),
  aż wartość będzie widoczna albo minie `--timeout` (domyślnie 60 s). Kod `0`, gdy wartość jest widoczna. Kod `1`
  z linią „który adres i co nie odpowiada” przy zamkniętym porcie OTLP albo odpowiedzi z błędem, niedostępnej Grafanie,
  braku źródła `prometheus` (odpowiedź 404) i braku punktu po czasie. Wartość odróżnia przebiegi, bo Prometheus promuje do
  etykiet tylko atrybuty zasobu z listy stosu.
- `check --project-override`: sprawdza, że Claude Code ignoruje `OTEL_*` z ustawień projektu (sesje interaktywne w innych
  repozytoriach nie mają ochrony wrappera `claude-agent`). Uruchamia prawdziwy `claude -p` (Haiku,
  `--max-budget-usd 0.1`, `--setting-sources project`, `--strict-mcp-config`, bez `CLAUDE*` i `OTEL_*` w środowisku)
  w tymczasowym repozytorium git, którego `.claude/settings.json` kieruje eksport na pierwszy lokalny odbiornik
  skryptu, włącza `OTEL_LOG_USER_PROMPTS=1` i ustawia `shop.task=override`, a `--settings` ma blok telemetrii
  skierowany na drugi odbiornik. Kod `0` tylko wtedy, gdy pierwszy odbiornik nie dostał żadnego żądania, drugi dostał
  metryki i logi, żadne ciało (po rozpakowaniu) nie zawiera znacznika z promptu, a `shop.task` nie ma wartości
  `override`. Stosu nie dotyka (`--endpoint`, `--grafana` i `--timeout` są wtedy ignorowane). Binarka to `--claude-bin`,
  a bez niej ta przypięta przez AO (`.claude/skills/ao-run/claude_pin.py`); jej brak albo inna wersja to kod `2`, a
  binarka, która zawodzi, zawiesza się (limit 180 s) albo eksportuje inaczej, to kod `1`. Tymczasowe repozytorium
  i odbiorniki są usuwane także po błędzie. Polecenie zużywa budżet modelu (do 0,1 USD), więc uruchamia je sesja
  `/ao-run` przy odbiorze (spec R7) i użytkownik po zmianie wersji Claude Code, nie testy.

## Stos i uid źródła danych

Stos jest w prywatnym repo `piotrp321/agent-observability` (Docker Compose: kolektor OpenTelemetry, Prometheus, Loki,
Grafana z dashboardem „Claude Code”), poza tym repo. Skrypt zależy tylko od:

| Co | Adres albo wartość |
|---|---|
| punkt OTLP/HTTP kolektora | `http://127.0.0.1:4318` (`DEFAULT_TELEMETRY_ENDPOINT` w `render_settings.py`) |
| Grafana | `http://127.0.0.1:3030` |
| uid źródła danych Prometheus | `prometheus` |
| dostęp | anonimowy Viewer (bez logowania) |

Zmiana któregokolwiek z nich w stosie wymaga zmiany tu (`DEFAULT_GRAFANA`, `PROMETHEUS_UID` w `telemetry.py`) i w
`render_settings.py`.

## Dane w stosie a METRICS

- Każdy punkt z wykonawcy AO ma atrybuty `shop.source=ao`, `shop.epic`, `shop.task` i `shop.task_kind` (w Prometheusie
  i Lokim etykiety `shop_*`), a z sesji interaktywnej `shop.source=interactive`. Treści w stosie nie ma.
- Wyniku zadania (merge, CI, rundy review) telemetria nie zawiera. Z rekordami METRICS (`tools/metrics`, z bazy AO) łączy
  się ją po `shop.task` i czasie próby.
- Dane stosu nie mają gwarancji integralności: wykonawca AO może przez pętlę zwrotną dopisać fałszywe punkty, a przez
  Dockera usunąć wolumeny stosu (model zagrożeń w specyfikacji). Dashboard to obraz pomocniczy, a źródłem prawdy o
  przebiegach epików zostaje METRICS.

## Testy

```
python3 -m unittest discover -s tools/telemetry -p 'test_*.py'
```

Bez modelu, stosu i sieci poza `127.0.0.1`: punkt OTLP i Grafana to lokalne serwery testowe, a `claude` to skrypt, który
zachowuje się jak Claude Code (czyta adres i atrybuty z `--settings`, eksportuje OTLP protobuf), z trybami błędów.
Testy obejmują ścieżki sukcesu i błędu każdego polecenia.
