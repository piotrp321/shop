# ASH-DASHBOARD: epiki natywne w lokalnej Grafanie

Dashboard „ASH: epiki natywne” w lokalnym stosie `agent-observability` na Macu pokazuje epiki prowadzone natywnie
(`docs/adr/agentic-software-house.md`, „Przebieg epiku”): czas, wartość tokenów, zużycie limitów, jakość, wersję ASH,
kompletność danych i porównanie kolejnych przebiegów. Kod stosu (eksporter, dashboard, Compose) jest w prywatnym repo
`piotrp321/agent-observability`; ten dokument opisuje zasady i kontrakt po stronie sklepu.

Zakres: `docs/features/ash-dashboard`, `docs/features/telemetry/spec.md`; w `agent-observability`: `exporter/`,
`grafana/`, `compose.yaml`, `prometheus/`, `scripts/`, `README.md`.

## Źródło prawdy

Źródłem prawdy są pliki METRICS (`docs/features/metrics/spec.md`, „Epik natywny”; `tools/metrics/README.md`):

- rekord epiku `~/.local/state/shop-metrics/epics/<epik>.json` (`metrics.py epic <epik>`), tylko `source: native`;
- plik przebiegu `~/.local/state/shop-metrics/runs/<epik>.json` (`metrics.py start`, `done`, `pause`, `process`).

Dashboard niczego nie liczy od nowa z transkryptów ani z surowej telemetrii i nie ma własnych definicji miar: każda
liczba jest polem rekordu albo pliku przebiegu, ewentualnie podzielonym przez liczbę zadań planu (jak w
`metrics.py trend`). Wyjątki, w których eksporter zlicza wpisy list rekordu: przeglądy według roli, trybu i werdyktu
(`reviews`), braki danych według pola (`gaps`), wpisy `process.changes` oraz, tylko dla epiku bez rekordu, statusy
wpisów `process.loaded` pliku przebiegu (rekord ma je policzone w `loaded.summary`). Ocena różnicy na dysku
(informacja albo niezgodność) powtarza regułę `process_version.mismatch_paths`.

Rekordów AO (`source` inne niż `native`, także rekord bez pola `source`) dashboard nie pokazuje.

## Zasady danych

- **Brak danych to nie zero.** Pole `null`, brak pola i epik bez rekordu dają na dashboardzie „brak danych”
  (w Prometheusie `NaN`). Zero jest tylko tam, gdzie rekord ma zero. Suma albo iloraz z brakującym składnikiem też jest
  brakiem danych.
- **Wartość tokenów to szacunek** według cennika API (`pricing.read_at` rekordu), nie rachunek; panele wartości mają
  znak „≈” i ten opis. Zużycie subskrypcji (punkty procentowe okien limitu) jest osobno i nie sumuje się z wartością.
- **Rzeczywiste daty epiku.** Start, gotowość i merge to `started_at`, `done_at` i `merged_at` rekordu. Wczytanie
  rekordu nie jest aktywnością modelu: nie tworzy punktów `claude_code_*` w surowej telemetrii, a z sum rekordu nie
  powstają szeregi czasowe.
- **Bez podwójnego liczenia.** Eksporter podaje bieżącą treść plików jako wskaźniki (gauge), a panele czytają ostatnią
  wartość zapytaniem chwilowym. Ponowne wczytanie, restart stosu i poprawiony rekord niczego nie dodają: nowa wartość
  zastępuje starą.
- **Surowa telemetria tylko jako diagnostyka.** Zwinięta sekcja „Aktywność na żywo” pokazuje tempo tokenów i aktywny
  czas sesji epiku z `claude_code_*` (złączenie po `session_id` z pliku przebiegu, `docs/features/telemetry/spec.md`,
  R8). Nie pokazuje sum ani kosztu: koszt z CLI i wartość z cennika to różne miary, a miarą epiku jest rekord.
- **Błąd jednego pliku jest widoczny i lokalny.** Plik nie do odczytania (zły JSON, nie obiekt, inny `epic` niż nazwa
  pliku, ponad 5 MB) jest w panelu „Pliki, których nie da się użyć” z powodem; pozostałe epiki mają swoje dane, a epik
  z zepsutym rekordem zachowuje dane pliku przebiegu. Pole o nieoczekiwanym typie jest brakiem danych i jest liczone
  w „Pola o nieoczekiwanym typie”.
- **Prywatność.** Do stosu trafiają identyfikatory (epik, sesja, przebieg subagenta), nazwy ról, modeli, zadań,
  gałęzi i plików instrukcji, liczby, statusy i teksty `gaps`. Nie trafiają ścieżki katalogów (`process.disk[].path`,
  `loaded[].origin`, `sessions[].worktree`; katalog domowy w tekście jest zamieniany na `~`), treść rozmów, zleceń,
  raportów ani kodu (rekord ich nie ma).

## Co pokazuje dashboard

- **Stan danych:** liczba epików natywnych, epiki w toku, epiki bez rekordu, pliki z błędem, pominięte rekordy AO,
  czytelność katalogu stanu, czas ostatniego odczytu plików i ostatniej zmiany rekordu.
- **Lista epików:** stan (w toku, gotowy, zmergowany), daty, zadania, czas do gotowości i do merge'u, praca, czekanie
  na człowieka, wartość tokenów, interwencje, rundy poprawek, regresje, wersja ASH, niezgodne pliki na dysku, instrukcje
  załadowane w treści innej niż baza, braki danych, czas aktualizacji rekordu.
- **Porównanie przebiegów** w kolejności rzeczywistego czasu startu: czas zegarowy, rozkład czasu, wartość tokenów
  według modelu, jakość i udział człowieka, limity subskrypcji, instrukcje załadowane, wartości na jedno zadanie planu.
- **Szczegóły wybranego epiku:** czas do gotowości i merge'u, rozkład czasu (`work`, `human_wait`, `host_pause`, `idle`
  i kategorie bez źródła jako „brak danych”), wartość tokenów razem, według modelu, roli, zadania oraz roli i modelu,
  tokeny według rodzaju, odczyty limitów i ich przyrost, przeglądy, rundy poprawek, kryteria akceptacji, CI końcowego
  PR, regresje i znaleziska po merge'u, powtórzone uruchomienia testów, wąskie gardła, przebiegi subagentów.
- **Wersja ASH i instrukcje**, trzy poziomy osobno: oczekiwana baza (`process.expected`: `describe`, commit), pliki na
  dysku w miejscach ładowania (`process.disk`: worktree epiku i główny checkout, z oceną każdej różnicy) oraz
  instrukcje potwierdzone z transkryptów agentów (`process.loaded`: potwierdzone, w wersji epiku, inne niż w bazie, bez
  potwierdzenia; według roli i wpisu). Epik, którego dysku nie sprawdzono, ma to napisane z powodem.
- **Kompletność danych:** liczba i lista braków (`gaps`), miary bez danych, pola o nieoczekiwanym typie, przeczytane
  transkrypty, sesje prowadzące, czas aktualizacji rekordu i pliku przebiegu.

## Aktualizacja

Usługa `epics-exporter` stosu montuje katalog stanu METRICS tylko do odczytu i czyta pliki przy każdym odczycie
Prometheusa (co 30 s), bez własnego stanu i bez importu:

- epik pojawia się po `metrics.py start` (plik przebiegu): stan „w toku”, start, zadania, gałąź, odczyt limitów ze
  startu i wersja procesu z ostatniej kontroli; `pause`, `process <epik>` i `done` zmieniają te dane;
- miary rekordu pojawiają się i zmieniają po każdym `metrics.py epic <epik>` (po merge'u, w `/retro`, przy korekcie)
  oraz po `metrics.py trend`, który przelicza regresje rekordów z otwartym oknem;
- restart stosu niczego nie wymaga: po starcie eksporter podaje te same pliki.

Rekordu w trakcie epiku nikt nie odświeża automatycznie: komendy jednego epiku woła po kolei sesja prowadząca
(`tools/metrics/README.md`, „Ograniczenia”), więc do zapisu rekordu epik w toku ma na dashboardzie dane pliku
przebiegu i, w sekcji „Aktywność na żywo”, surową telemetrię swoich sesji.

## Kontrakt z METRICS

Eksporter czyta pola defensywnie: nieznane pole pomija, brakujące traktuje jak `null`, a pole innego typu zgłasza.
Zmiana nazwy albo znaczenia pola, które dashboard pokazuje, wymaga zmiany eksportera w `agent-observability`
(`exporter/ash_epics_exporter.py` i jego testy) w tym samym czasie. Pola czytane z rekordu: `epic`, `source`, `issue`,
`final_pr`, `tasks`, `started_at`, `done_at`, `merged_at`, `wall`, `breakdown`, `breakdown_incomplete`, `integration`,
`retries.fix`, `retries.failed_by_outcome.unfinished`, `subscription.windows`, `subscription.share`, `human`,
`in_attempts` (`categories`, `repeat_runs`, `api_retry`), `by_task`, `versions`, `attempts`, `value`, `reviews`,
`acceptance`, `sessions`, `transcripts_read`, `pricing.read_at`, `ci.final`, `regressions`, `found_after_merge`,
`bottlenecks`, `process` i `gaps`; z pliku przebiegu: `epic`, `started_at`, `done_at`, `base_sha`, `branch`, `pr`,
`tasks`, `sessions[].id`, `usage_start`, `usage_done` i `process`.

## Wymagania i ograniczenia

- Docker Desktop musi udostępniać kontenerom katalog `~/.local/state/shop-metrics` (File Sharing); stos montuje go
  tylko do odczytu.
- Epik natywny bez pliku przebiegu (np. DEMO-SCALE) nie ma rekordu natywnego i nie jest na dashboardzie.
- Pola `process` mają rekordy zbudowane narzędziem z kontrolą wersji procesu; rekord epiku, który wystartował przed
  tą kontrolą, ma `process.loaded` z transkryptów i „dysk: nie sprawdzono”.
- Rozkład czasu przebiegu natywnego nie ma źródła czekania na limit, CI i GitHub, a przerwę komputera ma tylko po
  `metrics.py pause`; te kategorie są „brak danych”, a ich czas jest w pozostałych.
- Surowa telemetria sprzed uruchomienia stosu nie istnieje, więc „Aktywność na żywo” epików historycznych jest pusta.
  Sesje desktopowe są rozpoznawane wyłącznie po `session_id` (`docs/features/telemetry/spec.md`, R8).
- Panele rekordu pokazują stan na koniec wybranego zakresu czasu Grafany (domyślnie teraz).

## Poza zakresem

- Historia AO na tym dashboardzie i migracja starych danych stosu.
- Automatyczne przeliczanie rekordu w trakcie epiku, własny scheduler i alerty.
- Zmiany reguł procesu ASH i narzędzia `tools/metrics`.
