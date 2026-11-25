# ASH-DASHBOARD: epiki natywne w lokalnej Grafanie

Dashboard „ASH: epiki natywne” w lokalnym stosie `agent-observability` na Macu pokazuje epiki prowadzone natywnie
(`docs/adr/agentic-software-house.md`, „Przebieg epiku”): czas, wartość tokenów, zużycie limitów, jakość, wersję ASH,
kompletność danych i porównanie kolejnych przebiegów. Kod stosu (eksporter, dashboard, Compose) jest w prywatnym repo
`piotrp321/agent-observability`; ten dokument opisuje zasady i kontrakt po stronie sklepu.

Zakres: `docs/features/ash-dashboard`, `docs/features/telemetry/spec.md`; w `agent-observability`: `exporter/`,
`grafana/`, `compose.yaml`, `prometheus/`, `scripts/`, `Makefile`, `README.md`.

## Źródło prawdy

Źródłem prawdy są pliki METRICS (`docs/features/metrics/spec.md`, „Epik natywny”; `tools/metrics/README.md`):

- rekord epiku `~/.local/state/shop-metrics/epics/<epik>.json` (`metrics.py epic <epik>`), tylko `source: native`;
- plik przebiegu `~/.local/state/shop-metrics/runs/<epik>.json` (`metrics.py start`, `done`, `pause`, `process`).

Dashboard niczego nie liczy od nowa z transkryptów ani z surowej telemetrii i nie ma własnych definicji miar: każda
liczba jest polem rekordu albo pliku przebiegu, ewentualnie podzielonym przez liczbę zadań planu (jak w
`metrics.py trend`: niepełna suma wartości tokenów i epik bez zadań nie dają wartości na zadanie). Odczyt limitów
ze stanem innym niż `ok` nie jest pokazywany. Wyjątki, w których eksporter zlicza wpisy list rekordu: przeglądy według roli, trybu i werdyktu
(`reviews`), braki danych według pola (`gaps`), wpisy `process.changes` oraz, tylko dla epiku bez rekordu, statusy
wpisów `process.loaded` pliku przebiegu (rekord ma je policzone w `loaded.summary`). Ocena różnicy na dysku
(informacja albo niezgodność) powtarza regułę `process_version.place_problems`; zmiana tej reguły wymaga tej samej
zmiany w eksporterze.

Pole `process` mają oba pliki i dashboard pokazuje nowsze dane, nigdy ustaloną z góry stronę. Dotyczy to osobno
dwóch części pola:

- **stan kontroli wersji** (status, wynik, pliki na dysku, zmiany, akceptacje, pokazane niezgodności): zapisują go
  w pliku przebiegu `start`, `process <epik>`, `start --resume` i `done`, a rekord ma jego kopię z chwili budowy;
- **instrukcje załadowane** (`loaded`): odczytuje je z transkryptów tych samych sesji epiku każda kontrola (do pliku
  przebiegu) i `metrics.py epic` (do rekordu, z `summary` i `by_role`).

Gdy obie strony mówią to samo, dane są z rekordu. Gdy się różnią, nowszą wskazują najpierw same dane, bo znacznik
czasu to start komendy, a nie chwila zapisu (rekord budowany w trakcie kontroli ma późniejszy czas i starszy stan,
a wpis z `--at` czas wsteczny): plik przebiegu ze stanem sprawdzonym jest nowszy niż rekord, który mówi „nie
sprawdzono”; historia `changes`, która zaczyna się całą historią drugiej strony i ma kolejne wpisy, jest nowsza (METRICS
tylko dopisuje do `changes`); odczyt `loaded`, który zna wszystkie przebiegi subagentów drugiej strony i kolejne, jest
nowszy. Dopiero gdy to nie rozstrzyga, decyduje najnowszy znacznik czasu w danych (`checked_at`, `rechecked_at`,
`shown.at`, `changes[].at`, `loaded.checked_at`; dla `loaded` jego `checked_at`). Kolejność jest **nieustalona**, gdy
strony się różnią, a znacznika po jednej z nich nie ma, nie da się go odczytać albo oba są równe, oraz gdy drugiego
pliku nie da się odczytać (plik przebiegu dla stanu kontroli; którykolwiek dla `loaded`): ta część to wtedy „brak
danych” z oznaczeniem „nieustalone”, bez zgadywania. Oczekiwana baza epiku jest ze stanu kontroli, a gdy go nie ma,
z rekordu. `attempts[].instructions` i pozostałe miary są zawsze z rekordu.

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
  pliku, ponad 5 MB, wpis, którego nie da się sprawdzić) jest w panelu „Pliki i katalogi, których nie da się użyć”
  z powodem; pozostałe epiki mają swoje dane, a epik z zepsutym rekordem zachowuje dane pliku przebiegu. Pole
  o nieoczekiwanym typie jest brakiem danych i jest liczone w „Pola o nieoczekiwanym typie”.
- **Nieczytelny katalog to błąd, nie pusty stan.** `epics/` i `runs/` są czytane osobno. Katalog, który jest, a nie
  da się go odczytać (brak dostępu, plik w miejscu katalogu, dowiązanie donikąd, błąd odczytu, katalog bez dostępu do
  swoich plików), daje „Katalog stanu: błąd odczytu”, wiersz z powodem w tej samej tabeli i niezerowy wynik
  `make check` stosu. Pliki drugiego katalogu są nadal pokazane, a czego nie dało się sprawdzić, to „brak danych”:
  czy epik ma rekord albo plik przebiegu i czy są zepsute, liczby plików katalogu, czy epik jest zmergowany (merge
  zna tylko rekord, więc epik z nieczytelnym rekordem nie jest pokazany jako „gotowy”), stan kontroli i instrukcje
  załadowane. Katalog, którego jeszcze nie ma (`runs/` zakłada pierwszy `metrics.py start`, `epics/` pierwszy
  rekord), i katalog pusty to pusty stan; plik usunięty w trakcie odczytu katalogu po prostu znika.
- **Prywatność.** Do stosu trafiają identyfikatory (epik, sesja, przebieg subagenta), nazwy ról, modeli, zadań,
  gałęzi i plików instrukcji, liczby, statusy i teksty `gaps`. Nie trafiają ścieżki katalogów (`process.disk[].path`,
  `loaded[].origin`, `sessions[].worktree`; katalog domowy w tekście jest zamieniany na `~`), treść rozmów, zleceń,
  raportów ani kodu (rekord ich nie ma).

## Co pokazuje dashboard

- **Stan danych:** liczba epików natywnych, epiki w toku, epiki bez rekordu, pliki z błędem, pominięte rekordy AO,
  wynik odczytu katalogu stanu i jego `epics/` i `runs/`, czas ostatniego odczytu plików i ostatniej zmiany rekordu;
  obok tabela plików i katalogów, których nie da się użyć.
- **Lista epików:** stan (w toku, gotowy, zmergowany), daty, zadania, czas do gotowości i do merge'u, praca, czekanie
  na człowieka, wartość tokenów, interwencje, rundy poprawek, regresje, wersja ASH, niezgodne pliki na dysku,
  niezaakceptowane niezgodności ostatniej kontroli, źródło stanu kontroli i źródło instrukcji załadowanych (rekord,
  plik przebiegu, plik przebiegu nowszy niż rekord, nieustalone), instrukcje załadowane w treści innej niż baza, braki
  danych, czas aktualizacji rekordu.
- **Porównanie przebiegów** w kolejności rzeczywistego czasu startu: czas zegarowy, rozkład czasu, wartość tokenów
  według modelu, jakość i udział człowieka, limity subskrypcji, instrukcje załadowane, wartości na jedno zadanie planu.
- **Szczegóły wybranego epiku:** czas do gotowości i merge'u, rozkład czasu (`work`, `human_wait`, `host_pause`, `idle`
  i kategorie bez źródła jako „brak danych”), wartość tokenów razem, według modelu, roli, zadania oraz roli i modelu,
  tokeny według rodzaju, odczyty limitów i ich przyrost, przeglądy, rundy poprawek, kryteria akceptacji, CI końcowego
  PR, regresje i znaleziska po merge'u, powtórzone uruchomienia testów, wąskie gardła, przebiegi subagentów.
  Czas przebiegu subagenta i czas zadania to czas realizacji zleceń (`attempts[].wall_s`, `by_task[].work`); tabela
  „Przebiegi subagentów” ma kolumnę „Odstępy” (`attempts[].idle_s`), a pod nią jest tabela „Zlecenia”: wiersz na
  zlecenie przebiegu (`attempts[].orders`) z numerem, granicą, przypisaniem, zadaniem, identyfikatorem, rolą, modelem,
  startem, czasem i wartością. Tabela „Zadania” ma kolumnę „Zlecenia” (`by_task[].orders`), a klucz `unknown_task`
  etykietę „zadanie nieustalone (wznowienie bez ID)” obok etykiety `no_task`. W „Przeglądach według roli i werdyktu”
  przegląd z `mode: null` jest osobnym wierszem „tryb nieustalony”, nie wierszem `FULL` ani `DELTA`. Rekord bez
  `orders` (zbudowany starszym narzędziem) daje w tych kolumnach i w tabeli „Zlecenia” „brak danych”.
- **Wersja ASH i instrukcje**, trzy poziomy osobno: oczekiwana baza (`process.expected`: `describe`, commit), pliki na
  dysku w miejscach ładowania (`process.disk`: worktree epiku i główny checkout, z oceną każdej różnicy; niezgodne
  pliki przy kontroli i niezgodne zmiany dysku w trakcie epiku z `process.changes`, bez powrotów do bazy; gdy dysku
  nie sprawdzono, obie liczby to „brak danych”) oraz instrukcje potwierdzone
  z transkryptów agentów (`process.loaded`: potwierdzone, w wersji epiku, inne niż w bazie, bez potwierdzenia; według
  roli i wpisu; ze źródłem i czasem odczytu). Wynik pierwszej kontroli (`process.match`, z chwilą kontroli `checked_on`) jest
  osobną wartością, bo obejmuje dysk, skille użytkownika i `CLAUDE.md` wczytany przez sesję. Obok: niezgodności,
  które pokazała ostatnia zapisana kontrola i których nikt nie zaakceptował (`process.shown`), czas tej kontroli
  i źródło stanu kontroli. Epik, którego dysku nie sprawdzono, ma to napisane z powodem. Akceptacje niezgodności
  w trakcie epiku (wpisy `changes` rodzaju `accepted`) są liczone osobno, a instrukcja inna niż w bazie, którą
  tłumaczy zaakceptowany stan dysku (`explained`), jest oznaczona i nie jest niezgodnością.
- **Kompletność danych:** liczba i lista braków (`gaps`), miary bez danych, pola o nieoczekiwanym typie, przeczytane
  transkrypty, sesje prowadzące, czas aktualizacji rekordu i pliku przebiegu.

## Aktualizacja

Usługa `epics-exporter` stosu montuje katalog stanu METRICS tylko do odczytu i czyta pliki przy każdym odczycie
Prometheusa (co 30 s), bez własnego stanu i bez importu:

- epik pojawia się po `metrics.py start` (plik przebiegu): stan „w toku”, start, zadania, gałąź, odczyt limitów ze
  startu i wersja procesu z ostatniej kontroli; `pause`, `process <epik>` i `done` zmieniają te dane;
- miary rekordu pojawiają się i zmieniają po każdym `metrics.py epic <epik>` (po merge'u, w `/retro`, przy korekcie)
  oraz po `metrics.py trend`, który przelicza regresje rekordów z otwartym oknem;
- kontrola zapisana w pliku przebiegu po zbudowaniu rekordu (`process <epik>`, `start --resume`, `done`) zmienia na
  dashboardzie stan kontroli i instrukcje załadowane od razu, z oznaczeniem „plik przebiegu, nowszy niż rekord”;
  pozostałe miary rekordu zostają z chwili jego budowy do następnego `metrics.py epic <epik>`;
- restart stosu niczego nie wymaga: po starcie eksporter podaje te same pliki.

Rekordu w trakcie epiku nikt nie odświeża automatycznie: zapisuje go sesja prowadząca komendą `metrics.py epic`
(`docs/adr/agentic-software-house.md`, „Przebieg epiku”), więc do zapisu rekordu epik w toku ma na dashboardzie dane
pliku przebiegu i, w sekcji „Aktywność na żywo”, surową telemetrię swoich sesji.

## Kontrakt z METRICS

Eksporter czyta pola defensywnie: nieznane pole pomija, brakujące traktuje jak `null`, a pole innego typu zgłasza.
Zmiana nazwy albo znaczenia pola, które dashboard pokazuje, wymaga zmiany eksportera w `agent-observability`
(`exporter/ash_epics_exporter.py` i jego testy) w tym samym czasie. Pola czytane z rekordu: `epic`, `source`, `issue`,
`final_pr`, `tasks`, `started_at`, `done_at`, `merged_at`, `wall`, `breakdown`, `breakdown_incomplete`, `integration`,
`retries.fix`, `retries.failed_by_outcome.unfinished`, `subscription.windows`, `subscription.share`, `human`,
`in_attempts` (`categories`, `repeat_runs`, `api_retry`), `by_task`, `versions`, `attempts`, `value`, `reviews`,
`acceptance`, `sessions`, `transcripts_read`, `pricing.read_at`, `ci.final`, `regressions`, `found_after_merge`,
`bottlenecks`, `process` i `gaps`; z pliku przebiegu: `epic`, `started_at`, `done_at`, `base_sha`, `branch`, `pr`,
`tasks`, `sessions[].id`, `usage_start`, `usage_done` i `process`. Z przebiegów subagentów i zadań eksporter podaje
`attempts[].wall_s` (czas realizacji zleceń) jako `ash_epic_attempt_wall_seconds`, `attempts[].idle_s` jako
`ash_epic_attempt_idle_seconds`, `by_task[].work` jako `ash_epic_task_work_seconds`, `by_task[].orders` jako
`ash_epic_task_orders` i każde zlecenie `attempts[].orders[]` jako `ash_epic_order_wall_seconds`,
`ash_epic_order_value_usd` i `ash_epic_order_started_timestamp_seconds` z etykietami `agent`, `order`, `assignment`,
`task`, `ref`, `role`, `model`, `boundary`; przegląd ma etykietę trybu z `reviews[].mode` (`null` jako osobna
wartość). Brak `orders`, `idle_s` albo `by_task[].orders` to brak danych, nie zero. Wybór nowszych danych pola `process` opiera się na
tym, że rekord kopiuje stan kontroli pliku przebiegu, `changes` jest tylko dopisywane, odczyt nie jest cofany do „nie
sprawdzono”, `loaded.agents` ma wpis na każdy przebieg subagenta (`session`, `agent_id`), a każdy zapis zostawia
znacznik czasu (`checked_at`, `rechecked_at`, `shown.at`, `changes[].at`, `loaded.checked_at`): zmiana którejkolwiek
z tych własności w METRICS wymaga zmiany eksportera.

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
