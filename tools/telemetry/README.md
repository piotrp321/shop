# tools/telemetry

Telemetria Claude Code w lokalnym stosie na Macu (specyfikacja: `docs/features/telemetry/spec.md`). Python 3.9+, tylko
biblioteka standardowa. Skrypt `telemetry.py` nie zależy od plików stosu, tylko od jego adresów i uid źródła danych
(niżej). Stała adresu i lista zmiennych eksportu są w `tools/agent-security/render_settings.py`; `telemetry.py` ładuje je stamtąd,
bez kopii (nic więcej z `tools/agent-security` ani z `.claude/skills` nie jest mu potrzebne).

## Polecenia

```
python3 tools/telemetry/telemetry.py snippet [--endpoint URL]
python3 tools/telemetry/telemetry.py check [--endpoint URL] [--grafana URL] [--timeout S]
```

Kody wyjścia: `0` sukces, `1` niepowodzenie sprawdzenia (jedna linia z przyczyną na stderr), `2` błąd użycia (zły adres
albo limit czasu, nieznana opcja). Adresy (`--endpoint`, `--grafana`) to wyłącznie
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
  Dwa `check` uruchomione naraz mogą dać fałszywy kod `1`: oba piszą do tej samej serii (te same etykiety), więc
  późniejszy punkt zastępuje wcześniejszy, a wcześniejszy `check` nie zobaczy swojej wartości. Uruchamiaj je po kolei.

## Ograniczenia sesji interaktywnych

Blok z `snippet` w `~/.claude/settings.json` działa tylko tam, gdzie nic go nie nadpisuje. Sesja interaktywna nie ma
ochrony wrappera `claude-agent` (profil P12 i czyszczenie odziedziczonego środowiska, które chronią wykonawców AO), więc:

- Środowisko uruchomieniowe (zmienne `OTEL_*` i `CLAUDE_CODE_ENABLE_TELEMETRY` w powłoce) albo ustawienia zarządzane
  (managed settings) mogą nadpisać blok z `~/.claude/settings.json`: eksport trafi wtedy gdzie indziej albo go nie będzie.
- Projekt może wyłączyć niektóre eksportery, więc telemetria sesji interaktywnej nie ma gwarancji kompletności.
- Zmienne `OTEL_*` w ustawieniach projektu (`.claude/settings.json` repozytorium) nie mogą włączać telemetrii,
  przekierowywać endpointów ani włączać eksportu treści (od wersji Claude Code 2.1.282, według dokumentacji produktu),
  więc ten kierunek nadpisania nie istnieje i telemetry.py nie ma na to sprawdzenia. Endpointy per sygnał
  (`OTEL_EXPORTER_OTLP_METRICS_ENDPOINT`, `OTEL_EXPORTER_OTLP_LOGS_ENDPOINT`) mają w standardzie OTel pierwszeństwo przed
  `OTEL_EXPORTER_OTLP_ENDPOINT`, ale z ustawień projektu Claude Code też ich nie czyta, więc próba przez te ustawienia nie
  sprawdziłaby rzeczywistej luki.
- Wykonawców AO chroni `--settings` (ma pierwszeństwo przed ustawieniami projektu), profil P12 i czyszczenie
  odziedziczonego środowiska przez wrapper; pilnują ich testy wrappera i sonda przy każdym starcie AO (spec R2 i R3).

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

Bez modelu, stosu i sieci poza `127.0.0.1`: punkt OTLP i Grafana to lokalne serwery testowe. Testy obejmują ścieżki
sukcesu i błędu każdego polecenia oraz to, że opcje `--project-override` i `--claude-bin` nie istnieją (kod `2`).
