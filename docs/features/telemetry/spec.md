# TELEMETRY: telemetria Claude Code w lokalnym stosie na Macu

Krok 18 planu Agentic Software House (`docs/features/agentic-software-house/plan.md`), część 2, komponent „Metryki
i evale” ADR `docs/adr/agentic-software-house.md`. Część 1 (pomiar epiku z bazy AO, alarmy, decyzje
optymalizacyjne) to METRICS (#496). Epik podłącza do lokalnego stosu telemetrii na Macu wykonawców AO i sesje
interaktywne Claude Code. Każdy punkt danych z wykonawcy ma przypisany epik, zadanie i rodzaj zadania. Stos
(Docker Compose: kolektor OpenTelemetry, Prometheus, Loki, Grafana z dashboardem „Claude Code”) jest w prywatnym repo
`piotrp321/agent-observability` i nie należy do tego epiku.

Issues: #577
Zakres: `tools/agent-security`, `tools/telemetry`, `.claude/skills/ao-run/SKILL.md`, `docs/features/ao-run/spec.md`, `docs/features/agent-security/spec.md`, `docs/adr/agent-security.md`, `docs/adr/agentic-software-house.md`

## Cel i kryteria sukcesu

- Próby zadań AO wysyłają do stosu metryki (koszt, tokeny według typu, czas aktywny, sesje) i zdarzenia
  (`api_request`, `tool_result`, `tool_decision`, `api_error` i pozostałe) z atrybutami `shop.source=ao`, `shop.epic`,
  `shop.task` i `shop.task_kind`. Dashboard „Claude Code” filtruje po nich bez zmian w stosie.
- Eksport jest staraniem (best effort), a nie gwarancją. Eksperymenty E1–E2 potwierdzają zachowanie Claude Code
  2.1.311 w zwykłych warunkach: krótki proces eksportuje przed końcem, a wyłączony stos nie wydłuża przebiegu. Dane
  mogą jednak przepaść, gdy:
  - proces zostanie zabity (limit czasu AO);
  - stos jest przeciążony albo restartuje;
  - Claude Code zmieni zachowanie w kolejnej wersji.

  Opóźnienie przy stosie, który przyjmuje połączenie, ale nie odpowiada, nie zostało zmierzone. Sonda (R3) i odbiór
  (R7) sprawdzają eksport na przypiętej wersji.
- Sesje interaktywne użytkownika trafiają do tego samego stosu z `shop.source=interactive` po wklejeniu bloku
  z `telemetry.py snippet` do `~/.claude/settings.json`.
- Do stosu nie trafia treść: promptów, odpowiedzi, poleceń, argumentów i wyników narzędzi ani surowe ciała żądań API.
  Zostają długości, nazwy narzędzi, wynik (`success`, `error_type`) i liczby.
- Brak danych telemetrii nie zatrzymuje AO. Ani niedziałający stos, ani brak eksportu (np. po zmianie wersji
  Claude Code), ani techniczna niemożność sprawdzenia prywatności nie są odmową startu. Dane z tego okresu
  przepadają, a `ao-prepare` mówi o tym linią ostrzeżenia (R5). Start AO zatrzymują tylko dwie sytuacje:
  - wykryty wyciek treści, czyli wynik `fail` przypadku `telemetry_no_content` (R3);
  - niepoprawna konfiguracja adresu (`AGENT_OTEL_ENDPOINT` spoza `http://127.0.0.1:<port>`). To wyjątek celowy:
    zła wartość mogłaby skierować eksport poza Maca, więc wrapper i `ao-prepare` odmawiają (R2, R5).
- Dane w stosie nie są gwarantowane co do integralności. Wykonawca AO może dopisać fałszywe punkty i, przez Dockera,
  usunąć wolumeny stosu (model zagrożeń). Liczby z dashboardu to obraz pomocniczy, a nie dowód. Źródłem prawdy
  o przebiegach epików zostaje METRICS (baza AO i wyniki CLI).
- Eksport idzie wyłącznie na adres pętli zwrotnej Maca. Dane zostają na Macu, także `user.email`, który Claude Code
  dołącza do każdego punktu i którego nie da się wyłączyć.

## Ustalenia researchu i eksperymentów

Research (raport „Telemetria agentów kodujących”, 2026-11-17) i eksperymenty przy przygotowaniu, na Claude Code
2.1.311, `claude -p`, model Haiku, w profilu sandboxa z `render_settings.py` (`--settings` z blokiem `env`):

- Zdarzenia (logi OTLP, eksport co 5 s) są dokładniejszym źródłem kosztu na zadanie niż metryki (eksport co 60 s).
  `api_request` ma `cost_usd`, tokeny, `duration_ms` i `query_source`. Koszt to szacunek CLI według cen API, nie
  faktura: przy subskrypcji jest to tylko wartość równoważna. Taką samą definicję ma `value` w METRICS.
- Wynik zadania (merge, CI, rundy review) w telemetrii nie występuje. Łączy się go po `shop.task` i czasie z rekordami
  METRICS, które pochodzą z bazy AO.
- Konwencje `gen_ai.*` OpenTelemetry mają status Development. Claude Code używa własnego schematu `claude_code.*`,
  więc epik ich nie wprowadza.
- Praktycy (Willison, Huntley, Pocock, Yegge, Emanuel, zespół Claude Code) publicznie mierzą niewiele: szacowany koszt
  z lokalnych logów (ccusage) i pojedyncze szacunki kosztu na godzinę. Gotowy wzorzec do skopiowania nie istnieje,
  a wskaźnik „koszt na zmergowany PR” promują głównie dostawcy narzędzi.
- Eksperyment E1: proces `claude -p` trwający 5 s eksportuje metryki i zdarzenia przed końcem. Działa to przy
  interwałach domyślnych i skróconych, w trzech przebiegach na trzech.
  - Atrybuty z `OTEL_RESOURCE_ATTRIBUTES` są etykietami `shop_*` w Prometheusie i Lokim.
  - `prompt`, `prompt_text` i `response` mają wartość `<REDACTED>`, a `tool_result` nie ma treści polecenia.
  - W danych są `user_email` i `session_id`.
- Eksperyment E2: punkt OTLP pod zamkniętym portem (stos wyłączony) nie wydłuża przebiegu (5,0 s wobec 4,9 s) i nie
  pisze niczego na stderr.
- Eksperyment E3: Bash wykonawcy w sandboxie łączy się z `127.0.0.1:4318` i `127.0.0.1:3030` (odpowiedź 200). Do hosta
  spoza listy połączyć się nie może. Grafana ma domyślne hasło admina.
- Eksperyment E4: blok `env` z `--settings` przechodzi do poleceń Bash wykonawcy tylko jako
  `CLAUDE_CODE_ENABLE_TELEMETRY`, bez `OTEL_*`. Sam `CLAUDE_CODE_ENABLE_TELEMETRY=1` bez eksportera nie zmienia
  wyjścia `stream-json` ani stderr, więc nie psuje runnera EVALS.
- Eksperymenty przy METRICS (2.1.311): `.claude/settings.json` projektu z `OTEL_EXPORTER_OTLP_ENDPOINT`,
  `OTEL_LOG_USER_PROMPTS=1` i `OTEL_LOG_TOOL_DETAILS=1` nie zmienia eksportu.

## Decyzje użytkownika

- 2026-11-15: telemetria obejmuje także sesje interaktywne. Dashboard jest w repo (w `agent-observability`).
- 2026-11-15: telemetria trafia do lokalnego stosu na Macu zamiast do Grafana Cloud, bo darmowy plan usuwa dane po
  14 dniach. Stos jest w osobnym prywatnym repo `piotrp321/agent-observability` (stos zmergowany, retencja 400 dni,
  OTLP `127.0.0.1:4318`, Grafana `127.0.0.1:3030`). Wcześniejsza decyzja o wspólnym stosie Grafana Cloud z budżetem
  2000 serii nie obowiązuje.
- 2026-11-15: dane zostają na Macu, także `user.email`. Epik go nie usuwa.
- 2026-11-17 (przy przygotowaniu):
  - Q1: rekordy epików z METRICS nie trafiają do stosu w tym epiku. Osobne zgłoszenie powstaje po merge'u METRICS
    do `main`.
  - Q2: ochrona wolumenów Dockera i punktów HTTP stosu przed wykonawcą jest poza zakresem. Zgłoszenie
    bezpieczeństwa: piotrp321/agent-observability#3 (domyślne hasło admina Grafany, priorytet wysoki).
    Integralności danych telemetrii specyfikacja nie przedstawia jako zagwarantowanej.
  - Q3: lokalny odczyt metadanych przez anonimowego Viewera jest zaakceptowanym ryzykiem, z opisem w modelu
    zagrożeń.
  - Q4: epik obejmuje ostrzeżenie `ao-prepare` (R5), sekcję „Odbiór TELEMETRY” w `/ao-run` (R7) i opcję sondy
    `--otel-forward` (R3).
  - Q5: `telemetry.py check` zależy od adresu Grafany i uid źródła `prometheus` (R4).
  - Q6: propozycje researchu odrzucone (sekcja „Poza zakresem”).
  - Q7: brak eksportu nie zatrzymuje AO. Statusy przypadków telemetrii rozdzielają brak eksportu, wykryty wyciek
    i niemożność sprawdzenia (R3).

## Poza zakresem

- Pomiar epiku, alarmy i decyzje optymalizacyjne: METRICS (#496).
- Zmiany stosu `agent-observability` (kolektor, dashboard, retencja, hasło Grafany) należą do tamtego repo.
- Monitoring klastra demo (Grafana Cloud), dostęp do dashboardu spoza Maca, monitoring produkcji i dyżur agenta
  (krok 11).
- Ślady (traces, w Claude Code beta) i `TRACEPARENT`. Złączenie z METRICS daje `shop.task` z czasem próby.
- Alerty Grafany, także `absent()` na brak danych. Brak danych przy starcie przebiegu sygnalizuje `/ao-run`.
- Inne agenty (Codex, Gemini CLI). AO uruchamia tylko Claude Code.
- Telemetria runnera EVALS i sondy w stosie. Runner nie dostaje opcji telemetrii, a sonda wysyła telemetrię do
  własnego odbiornika (R3; przekazanie do stosu tylko przy odbiorze, opcją `--otel-forward`).
- Ochrona danych stosu przed wykonawcą przez Docker (#498) i przez HTTP na pętli zwrotnej (model zagrożeń, „Poza
  zakresem”).
- Propozycje z researchu, których epik nie przyjmuje:
  - `TRACEPARENT` i ślady: złączenie z METRICS daje `shop.task` z czasem próby;
  - osobny `service.name` dla wykonawców: dashboard filtruje po `service_name="claude-code"`, a `shop_source` jest
    już etykietą w Lokim;
  - `shop.task` poza etykietami metryk: serie i tak mnoży `session_id` (sekcja „Ryzyka”);
  - usuwanie `user.email` w kolektorze: decyzja 2026-11-15, zresztą to zmiana w `agent-observability`.

## Wymagane przed startem

- Działający stos `agent-observability` na Macu (`make up` w tamtym repo), z punktem OTLP `http://127.0.0.1:4318`
  i Grafaną `http://127.0.0.1:3030` ze źródłem danych `prometheus` dostępnym dla anonimowego Viewera. Po co: odbiór
  epiku przez sesję `/ao-run` (R7). Zadania stosu nie potrzebują: ich testy używają atrap odbiorników. Źródło:
  istnieje: `piotrp321/agent-observability` (merge 2026-11-16), sprawdzone przy przygotowaniu.
- Claude Code 2.1.311 eksportuje z `claude -p` przed końcem procesu, z blokiem `env` z `--settings`, w profilu sandboxa.
  Źródło: istnieje: eksperymenty E1–E4 (sekcja „Ustalenia”), polecenie
  `env -i HOME PATH USER claude -p … --settings "<render_settings.py + env>" --setting-sources project --strict-mcp-config --model haiku`.
  Zmiana wersji przypiętej przez AO wymaga ponownego `telemetry.py check --project-override` (R4).
- Przebiegi z prawdziwym modelem (sonda, `check --project-override`) i ze stosem wykonuje przy odbiorze sesja
  `/ao-run`, nie wykonawca: sandbox wykonawcy nie czyta Keychain ani binarki przypiętej pod `~` (jak odbiór
  AGENT-SECURITY). Źródło: zadanie dokumentacji `/ao-run` (R7).

## Model zagrożeń

Aktywa i strony:

- treść pracy wykonawców (prompty, odpowiedzi, polecenia, pliki) i dane konta (`user.email`) wobec każdego odbiorcy
  spoza Maca;
- poprawność danych w stosie (koszt i atrybuty zadań) wobec wykonawcy AO.

Drogi ataku w zakresie:

1. Ustawienia projektu (`.claude/settings.json`, `.claude/settings.local.json`) przekierowują eksport albo włączają
   treść. Zamyka to wrapper, który odmawia startu przy ustawieniach zmienionych względem `main` (AGENT-SECURITY P12),
   oraz Claude Code, który ignoruje `OTEL_*` z ustawień projektu (eksperyment METRICS). Sprawdzenie: test wrappera
   (istniejący P12) i `telemetry.py check --project-override` (R4).
2. Odziedziczone środowisko (`OTEL_*` i `CLAUDE_CODE_ENABLE_TELEMETRY` z sesji `/ao-run` albo z powłoki użytkownika)
   zmienia adres albo włącza treść. Zamyka to wrapper, który usuwa te zmienne przed startem binarki (R2), oraz profil,
   który ustawia `0` dla każdego `OTEL_LOG_*` (R1). Sprawdzenie: test wrappera z atrapą binarki (R2) i przypadek
   sondy `telemetry_no_content` (R3).
3. Adres eksportu spoza Maca. Zamyka to `render_settings.py`, który przyjmuje tylko `http://127.0.0.1:<port>`
   (R1). Sprawdzenie: testy `render_settings.py`, w tym próby obejścia (`localhost.evil`, `127.0.0.1.nip.io`,
   `http://127.0.0.1@host`, `https://`, `[::1]` z innym hostem, port spoza 1–65535).
4. Wykonawca podaje fałszywe atrybuty własnego procesu (`shop.task`, `shop.epic`). Wartości ustala wrapper poza
   sandboxem z `AO_TASK_ID` i nazwy `AGENT_AO_HOME` (R2), a wykonawca nie zmienia środowiska ani pliku ustawień
   własnego procesu Claude Code. Sprawdzenie: test wrappera i przypadek sondy `telemetry_export` (R3). Fałszywe
   punkty wysłane z Basha to osobna droga, poza zakresem (niżej).
5. Pliki stosu (repo `agent-observability`, `.env`) poza worktree. Zamykają to `denyRead` `~` i zapis tylko
   w worktree w profilu AGENT-SECURITY. Sprawdzenie: istniejące przypadki sondy `write_home` i odczytu kanarka;
   bez nowego przypadku.

Poza zakresem (świadomie otwarte, założenia):

- Zapis do stosu przez Docker: wykonawca ma gniazdo Dockera, więc może usunąć wolumeny `agent-observability_*` albo
  zatrzymać kontenery. To ta sama luka co #498 (AGENT-ISOLATION). Epik jej nie zamyka (decyzja Q2).
- Zapis przez HTTP na pętli zwrotnej (E3). Bash wykonawcy może wysłać fałszywe punkty OTLP na `127.0.0.1:4318`, także
  z atrybutami innych zadań. Może też zalogować się do Grafany domyślnym hasłem admina. Pętli zwrotnej nie da się
  wyłączyć, bo wykonawcy potrzebują portów własnego stosu compose. Skutek: integralność danych telemetrii nie jest
  zagwarantowana (sekcja „Cel”). Zgłoszenie: piotrp321/agent-observability#3, w którym zmiana domyślnego hasła admina
  Grafany ma priorytet wysoki (decyzja Q2).
- Odczyt przez Grafanę na pętli zwrotnej (E3, anonimowy Viewer; zaakceptowane ryzyko, decyzja Q3).
  - Co jest dostępne: wykonawca może przez proxy źródeł danych odczytać dane wszystkich sesji w stosie, nie tylko
    własnej. To innych zadań AO i sesji interaktywnych użytkownika:
    - `user_email`, identyfikatory konta i organizacji;
    - koszt, tokeny i modele;
    - nazwy narzędzi i ich wyniki (`success`, `error_type`);
    - epiki i zadania;
    - czasy aktywności, czyli kiedy i jak długo użytkownik pracował.
  - Wyniesienie: wykonawca może te dane wynieść przez dozwolony GitHub (commit, PR, komentarz).
  - Co ogranicza szkodę: treści promptów, odpowiedzi i poleceń w stosie nie ma (R1, przypadek `telemetry_no_content`).
    Ryzyko dotyczy metadanych i wzorców pracy, a nie treści.
  - Czego epik nie robi: wykrywania ani blokowania takiego odczytu. Anonimowego Viewera używa `telemetry.py check`,
    a jego wyłączenie z wpływem na `check` rozważa zgłoszenie piotrp321/agent-observability#3.
- `user.email` i identyfikatory konta w danych: decyzja użytkownika 2026-11-15 (dane zostają na Macu).

## Rozwiązanie

### R1. Blok telemetrii w ustawieniach wykonawcy (`render_settings.py`)

- Blok `env` w `--settings`, a nie zmienne eksportowane przez wrapper. Według E4 do poleceń Bash wykonawcy przechodzi
  wtedy tylko `CLAUDE_CODE_ENABLE_TELEMETRY`, bez adresu i atrybutów.
- Nowe opcje `--telemetry-endpoint URL` i `--telemetry-attribute KEY=VALUE` (powtarzalna). Podaje je tylko wrapper
  `claude-agent`. Runner EVALS (`render()` jako moduł) ich nie używa i nie dostaje bloku `env`. Interfejs modułu:
  `render(..., telemetry=None)`, gdzie `telemetry` to `{'endpoint': str, 'attributes': dict}` albo `None`.
- Stała `DEFAULT_TELEMETRY_ENDPOINT = 'http://127.0.0.1:4318'` i lista zmiennych bloku są w `render_settings.py`.
  Wrapper, `ao-prepare` i `telemetry.py` korzystają z nich bez kopii.
- Adres: dokładnie `http://127.0.0.1:<port>` (port 1–65535, bez ścieżki, użytkownika, zapytania i fragmentu). Inny
  adres to `SettingsError` i kod 1.
- Atrybuty: klucze z listy `shop.source`, `shop.epic`, `shop.task`, `shop.task_kind`. Wartości pasują do
  `[A-Za-z0-9._-]{1,128}`. Inny klucz albo wartość: kod 1. Wrapper podaje tylko wartości zgodne ze wzorcem (R2), więc
  ten kod nie zatrzymuje startu z powodu danych zadania. W `OTEL_RESOURCE_ATTRIBUTES` nie ma przez to spacji,
  przecinków, `=` ani znaków spoza ASCII, więc kodowanie procentowe nie jest potrzebne.
- Blok `env`:
  - `CLAUDE_CODE_ENABLE_TELEMETRY=1`, `OTEL_METRICS_EXPORTER=otlp`, `OTEL_LOGS_EXPORTER=otlp`,
    `OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf`, `OTEL_EXPORTER_OTLP_ENDPOINT=<adres>`;
  - `OTEL_METRIC_EXPORT_INTERVAL=10000` i `OTEL_LOGS_EXPORT_INTERVAL=5000`: AO zabija próbę po limicie czasu, a krótszy
    interwał metryk zmniejsza to, co przepada w buforze;
  - jawne `0` dla `OTEL_LOG_USER_PROMPTS`, `OTEL_LOG_ASSISTANT_RESPONSES`, `OTEL_LOG_TOOL_DETAILS`,
    `OTEL_LOG_TOOL_CONTENT` i `OTEL_LOG_RAW_API_BODIES`;
  - `OTEL_RESOURCE_ATTRIBUTES` z atrybutów w stałej kolejności kluczy.
- Bez opcji ustawienia są bajt w bajt takie jak dziś. Szablon `executor-settings.json` się nie zmienia.

### R2. Wrapper `claude-agent`

- Przed startem binarki wrapper usuwa ze środowiska wszystkie zmienne `OTEL_*` oraz `CLAUDE_CODE_ENABLE_TELEMETRY`,
  także przy `--version` i `auth status`.
- Adres: `AGENT_OTEL_ENDPOINT`, a bez niej `DEFAULT_TELEMETRY_ENDPOINT`. Wartość `off` wyłącza telemetrię: bez opcji
  `--telemetry-*`, ustawienia jak przed epikiem. Zmienną ustawia tylko proces spoza sandboxa (sonda, testy, sesja
  `/ao-run` na polecenie użytkownika). Niepoprawny adres oznacza odmowę startu (kod 2), jak każdy błąd
  `render_settings.py`.
- Atrybuty wrapper ustala bez bazy AO, z danych, które już sprawdza:
  - `shop.source=ao`;
  - `shop.epic` = `<nazwa>` z `AGENT_AO_HOME` (`~/.local/state/ao-<nazwa>`, jeden katalog na epik, zmienna `name`
    wrappera);
  - `shop.task` = `AO_TASK_ID`;
  - `shop.task_kind` z `AO_TASK_ID` po odcięciu prefiksu `<NAZWA>-` (nazwa wielkimi literami): `<liczba>` → `plan`,
    `INTEGRATION-<liczba>` → `integration`, `FIX-<liczba>` → `fix`, `REPAIR-<liczba>` → `repair`, `FINALIZE` →
    `finalize`, inaczej `unknown`. Te rodzaje to dzisiejsze `tasks.kind` AO i METRICS (zgodność sprawdzona na bazach
    ADMIN-FEATURES i METRICS).
  - Wartość spoza wzorca z R1 (np. `AO_TASK_ID` dłuższy niż 128 znaków) zamienia się na `unknown`. Start się nie
    zatrzymuje.

### R3. Przypadki sondy (`probe.py`)

- Sonda uruchamia wykonawcę z `AGENT_OTEL_ENDPOINT` ustawionym na własny odbiornik OTLP/HTTP na `127.0.0.1` (losowy
  port). Odbiornik zbiera ciała żądań `/v1/metrics` i `/v1/logs`, więc dane sondy nie trafiają do stosu.
- Odbiornik rozpakowuje ciało według `Content-Encoding` (`gzip` albo brak; `gzip` ze wszystkimi członami, a bajty po
  członie, które nie są całym członem, czynią ciało nie do rozpakowania). Znaczników `telemetry_no_content` szuka
  w surowych bajtach po rozpakowaniu: łańcuchy protobufa są w UTF-8, więc dekoder nie jest potrzebny. Do atrybutów
  `telemetry_export` dekoduje protobuf minimalnym dekoderem z biblioteki standardowej: varint i pola
  length-delimited, rekurencyjnie, z wyciągnięciem par `KeyValue` (klucz, wartość tekstowa). Ciało, którego nie da
  się zdekodować, daje `no_export` dla `telemetry_export`. Przypadku prywatności to nie dotyczy.
- Nowe przypadki, warstwa `telemetry`, ocenia je odbiornik, nie tekst modelu:
  - `telemetry_export`: odbiornik dostał co najmniej jedno żądanie logów i jedno żądanie metryk. Wśród par są
    `shop.source=ao`, `shop.task=<zadanie sondy>`, `shop.epic=<nazwa atrapy AO_HOME>` i `shop.task_kind=plan`.
    Statusy:
    - `pass`;
    - `no_export`: brak żądań albo brak oczekiwanych atrybutów.
  - `telemetry_no_content`. Statusy:
    - `pass`: dostarczono co najmniej jedno ciało, każde dało się rozpakować, żadne nie zawiera żadnego ze znaczników,
      a drugi odbiornik nie dostał żadnego żądania;
    - `fail` (wykryty wyciek albo przekierowanie): któreś ciało po rozpakowaniu zawiera:
      - losowy znacznik, który sonda dopisuje do promptu,
      - nazwę skryptu przypadków `.agent-probe-cases.sh`,
      - wartość `PROBE_LOCAL_BODY` (wynik narzędzia z `work_curl_localhost`),
      - wartość kanarka,

      albo drugi odbiornik (droga 2) dostał jakiekolwiek żądanie;
    - `no_export`: nie dostarczono żadnego ciała, więc nie ma czego sprawdzić. To nie jest potwierdzenie wycieku;
    - `unverifiable`: któreś ciało ma nieznane `Content-Encoding` albo nie daje się rozpakować, a w pozostałych nie
      ma znacznika. Prywatności nie da się wtedy sprawdzić, a wycieku nie wykryto.
- Co blokuje (wyjątek od reguły sondy, że każdy przypadek ma przejść):
  - Statusy `no_export` i `unverifiable` nigdy nie blokują: ani kod wyjścia sondy w żadnym trybie, ani `ao-prepare`.
    Sonda zapisuje je w wyniku z przyczyną, a `ao-prepare` wypisuje dla nich linię ostrzeżenia (R5).
  - Start AO blokuje tylko `fail` przypadku `telemetry_no_content`, jak `fail` każdego przypadku `bypass`.
  - Odbiór (R7) wymaga `pass` obu przypadków, sprawdzanego w JSON wyniku, nie tylko kodu wyjścia.
- Sonda startuje wykonawcę w środowisku z `OTEL_LOG_USER_PROMPTS=1`, `OTEL_LOG_TOOL_DETAILS=1` i
  `OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:<port drugiego odbiornika>`. Wrapper musi je usunąć.
- Opcja `--otel-forward URL` (tylko `http://127.0.0.1:<port>`) przekazuje każde odebrane żądanie bez zmian także pod
  ten adres. Ocena przypadków jest taka sama. Używa jej tylko odbiór ze stosem (R7).
- Przypadku „ustawienia projektu przekierowują eksport” sonda nie ma. P12 odmawia startu przy zmienionych
  `.claude/settings*.json`, a `OTEL_*` Claude Code czyta tylko przy starcie, więc w sesji AO tej drogi nie da się
  odtworzyć. Ten przypadek sprawdza `telemetry.py check --project-override` (R4).
- Testy z atrapą binarki (`test_claude_agent.py`, `test_probe.py`, bez modelu):
  - odziedziczone `OTEL_*` i `CLAUDE_CODE_ENABLE_TELEMETRY` nie docierają do binarki;
  - `--settings` ma blok `env` z R1 z atrybutami z `AGENT_AO_HOME` i `AO_TASK_ID`, w tym każdy rodzaj zadania
    i `unknown`;
  - `AGENT_OTEL_ENDPOINT=off` daje ustawienia bez bloku `env`;
  - niepoprawny `AGENT_OTEL_ENDPOINT` kończy się odmową (kod 2);
  - `evaluate` daje dla każdego statusu przypadków telemetrii właściwy kod wyjścia w obu trybach. `no_export`
    i `unverifiable` kończą się kodem 0 z ostrzeżeniem. `fail` przypadku `telemetry_no_content` kończy się kodem 1;
  - odbiornik rozpakowuje ciało gzip. Nieznane `Content-Encoding` daje `unverifiable`, a znacznik w ciele `fail`.

### R4. `tools/telemetry/telemetry.py`

Python 3.9+, wyłącznie biblioteka standardowa (stała adresu i lista zmiennych z `render_settings.py`). Kody wyjścia:
0 sukces, 1 niepowodzenie sprawdzenia, 2 błąd użycia.

- `snippet [--endpoint URL]` wypisuje blok JSON z kluczem `env` do wklejenia do `~/.claude/settings.json` sesji
  interaktywnych. Zawiera te same zmienne eksportu i `OTEL_LOG_*=0` co R1, `OTEL_RESOURCE_ATTRIBUTES=shop.source=interactive`
  i nie ma interwałów. Niczego nie zapisuje.
- `check [--endpoint URL] [--grafana URL] [--timeout S]`:
  - wysyła przez OTLP/HTTP (JSON) gauge `shop_telemetry_check` z unikalną wartością przebiegu (liczba losowa). Etykiety
    zasobu Prometheus promuje tylko z listy stosu, więc rozróżnia wartość, nie etykieta;
  - co 2 s odpytuje Prometheus przez proxy źródła danych Grafany (`/api/datasources/proxy/uid/prometheus/api/v1/query`,
    anonimowy Viewer), aż do `--timeout` (domyślnie 60 s);
  - kod 0, gdy wartość jest widoczna. Niedostępny punkt OTLP, niedostępna Grafana, brak źródła `prometheus` albo
    brak punktu po czasie: kod 1 i jedna linia z przyczyną (który adres i co nie odpowiada).
  - Domyślne adresy to `http://127.0.0.1:4318` i `http://127.0.0.1:3030`. Sklep zależy od adresów stosu i uid źródła
    `prometheus` (zapisane w `tools/telemetry/README.md`), nie od jego plików.
- `check --project-override [--claude-bin BIN]` (domyślna binarka z `.claude/skills/ao-run/claude_pin.py`) uruchamia
  prawdziwy `claude -p` (Haiku, `--max-budget-usd 0.1`, `--setting-sources project`, `--strict-mcp-config`, bez
  zmiennych `CLAUDE*` i `OTEL_*` w środowisku) w tymczasowym repo git. `.claude/settings.json` projektu ma w `env`:
  - `OTEL_EXPORTER_OTLP_ENDPOINT` na pierwszy lokalny odbiornik skryptu;
  - `OTEL_LOG_USER_PROMPTS=1`;
  - `OTEL_RESOURCE_ATTRIBUTES=shop.task=override`.

  `--settings` ma blok z R1 skierowany na drugi odbiornik skryptu. Kod 0, gdy:
  - pierwszy odbiornik nie dostał żadnego żądania;
  - drugi dostał metryki i logi;
  - żadne ciało nie zawiera znacznika z promptu;
  - `shop.task` nie ma wartości `override`.

  Odbiornik i dekoder są te same co w R3 (wspólny moduł, bez kopii). Sprawdzenie nie dotyka stosu. Uzasadnienie:
  sesje interaktywne w innych repozytoriach nie mają ochrony P12, więc to, że Claude Code ignoruje `OTEL_*`
  z ustawień projektu, trzeba sprawdzać po każdej zmianie wersji. Uruchamia je sesja `/ao-run` przy odbiorze (R7),
  a użytkownik po zmianie wersji Claude Code.
- Testy `test_telemetry.py` działają bez modelu i bez stosu, z atrapami odbiorników HTTP, Grafany i binarki `claude`.
  Pokrywają ścieżki sukcesu i błędu każdego polecenia: zamknięty port, Grafana bez źródła `prometheus`, limit czasu,
  niepoprawny adres, błąd binarki, żądanie do pierwszego odbiornika i znacznik w ciele.

### R5. Ostrzeżenie przy starcie przebiegu (`ao-prepare`, `/ao-run`)

- `ao-prepare` przed sondą sprawdza połączenie TCP z adresem telemetrii (`AGENT_OTEL_ENDPOINT` z jego środowiska
  albo `DEFAULT_TELEMETRY_ENDPOINT`; przy `off` nie sprawdza), z limitem 2 s. Niepoprawny `AGENT_OTEL_ENDPOINT` to
  odmowa przed sondą: `ao-prepare: niepoprawny AGENT_OTEL_ENDPOINT <wartość>: <przyczyna>`. To jedyny błąd
  konfiguracji telemetrii, który zatrzymuje start (sekcja „Cel”).
- Wynik sondy: przypadki telemetrii ze statusem `no_export` albo `unverifiable` nie są odmową. Reguła `ao-prepare`
  „wynik z przypadkiem, który nie przeszedł, to odmowa” ich nie obejmuje. Dla każdego `ao-prepare` wypisuje linię
  `ao-prepare: telemetria: sonda <przypadek> <status> (<przyczyna>)` przed linią wyniku sondy.
- Gdy połączenia nie ma, wypisuje na stderr jedną linię `ao-prepare: telemetria: brak stosu pod <adres>; dane tego
  przebiegu nie trafią do stosu`. Linia jest przed linią sondy, więc ostatnią linią `run.log` zostaje wynik sondy
  albo odmowa. Kod wyjścia się nie zmienia, a start nie jest wstrzymany.
- `/ao-run` czyta tę linię z `run.log` po starcie AO i podaje ją w rozmowie. Linia `ao-prepare: telemetria:` nigdy
  nie jest odmową, także gdy jest ostatnia.
- Testy `test_ao_prepare.py`:
  - linia przy zamkniętym porcie, brak linii przy otwartym porcie i przy `off`, kolejność linii, kod wyjścia bez zmian;
  - odmowa przy niepoprawnym `AGENT_OTEL_ENDPOINT`;
  - wynik sondy z `no_export` i `unverifiable` to start z ostrzeżeniem, a z `telemetry_no_content` `fail` to odmowa.

### R6. Dokumentacja (w zadaniach implementacji)

- `docs/features/agent-security/spec.md`: R1–R3 tego epiku w sekcjach R2 (blok telemetrii, usuwanie `OTEL_*`) i R6
  (warstwa `telemetry`, przypadki, `--otel-forward`), drogi przez pętlę zwrotną i Docker w modelu zagrożeń.
- `docs/adr/agent-security.md`: telemetria jako ruch sieciowy procesu CLI (pętla zwrotna, poza sandboxem Bash)
  i luki zapisu do stosu i odczytu z niego.
- `docs/adr/agentic-software-house.md`: komponent „Metryki i evale” wskazuje telemetrię w lokalnym stosie.
- `docs/features/ao-run/spec.md` i `.claude/skills/ao-run/SKILL.md`: ostrzeżenie z R5 i sekcja „Odbiór TELEMETRY”
  (R7).
- `tools/telemetry/README.md`: polecenia, adresy i uid źródła danych, związek z `agent-observability` i METRICS
  (złączenie po `shop.task` i czasie próby).

### R7. Odbiór (sesja `/ao-run`)

Sekcja „Odbiór TELEMETRY” w `.claude/skills/ao-run/SKILL.md`, wzorowana na „Odbiorze AGENT-SECURITY”. Obejmuje tylko
epik TELEMETRY i działa przed raportem odbioru i przed merge'em końcowego PR, z HEAD końcowego PR, w osobnym worktree:

1. `telemetry.py check`: kod 0 (stos działa).
2. Sonda w przebiegu domyślnym z `--otel-forward http://127.0.0.1:4318`, potem sonda w `--mode ao`. W każdym
   przebiegu wymagany jest kod 0 oraz status `pass` przypadków `telemetry_export` i `telemetry_no_content` w JSON
   wyniku. Sam kod 0 nie wystarcza, bo `no_export` i `unverifiable` go nie zmieniają.
3. `telemetry.py check --project-override` na binarce przypiętej: kod 0.
4. Zapytanie przez proxy Grafany (Loki) zwraca zdarzenia `api_request` zadania sondy z `shop_source="ao"`
   z ostatniego przebiegu.

Wyniki (kody, czas, koszt sondy, zapytanie) trafiają do raportu odbioru. Nieudany punkt to brak merge'a, także na
polecenie, do czasu usunięcia przyczyny, jak w „Odbiorze AGENT-SECURITY”.

## Scenariusze błędów

| Sytuacja | Zachowanie |
|---|---|
| Stos wyłączony albo port zamknięty | w E2 wykonawca działał bez zmian, dane przepadają; `ao-prepare` ostrzega (R5); `telemetry.py check` kod 1 |
| Nowa wersja Claude Code przestaje eksportować | sonda: `telemetry_export` i `telemetry_no_content` `no_export`, ostrzeżenie, start AO bez zmian; odbiór i `check --project-override` zgłaszają błąd |
| Ciało eksportu w nieznanym kodowaniu | `telemetry_no_content` `unverifiable`, ostrzeżenie, start AO bez zmian; odbiór nie przechodzi |
| Nowa wersja Claude Code wysyła treść mimo `OTEL_LOG_*=0` | `telemetry_no_content` `fail` (wykryty wyciek): `ao-prepare` odmawia startu AO |
| `AGENT_OTEL_ENDPOINT` niepoprawny w środowisku `ao-prepare` | odmowa startu przed sondą (wyjątek konfiguracji, R5) |
| `AO_TASK_ID` albo nazwa `AGENT_AO_HOME` spoza wzorca | atrybut `unknown`, start bez zmian |
| `AGENT_OTEL_ENDPOINT` niepoprawny | odmowa startu wrappera (kod 2) z przyczyną |
| Próba zabita po limicie czasu AO | tracone do 10 s metryk i 5 s zdarzeń z bufora |

## Kryteria akceptacji

Kryteria 1–5 sprawdzają testy zadań bez modelu i bez stosu. Kryterium 6 sprawdza sesja `/ao-run` przy odbiorze.

1. `render_settings.py` z `--telemetry-*` daje blok `env` z R1. Bez opcji wynik jest bajt w bajt taki jak przed
   epikiem. Niepoprawny adres, klucz albo wartość kończy się kodem 1 bez wyjścia na stdout, także przy próbach
   obejścia: `http://localhost.evil:4318`, `http://127.0.0.1.nip.io:4318`, `http://127.0.0.1@host:4318`,
   `https://127.0.0.1:4318`, `http://[::1]:4318`, port `0` i `65536`, ścieżka `/v1`, wartość z przecinkiem albo `=`.
2. Wrapper usuwa odziedziczone `OTEL_*` i `CLAUDE_CODE_ENABLE_TELEMETRY`, ustala atrybuty według R2 (każdy rodzaj
   i `unknown`) i obsługuje `AGENT_OTEL_ENDPOINT` (adres, `off`, wartość niepoprawna). Wszystko pokrywają testy
   z atrapą binarki.
3. Sonda ma przypadki `telemetry_export` i `telemetry_no_content` ze statusami z R3, odbiornik z dekoderem
   i `--otel-forward`. Testy `evaluate` (wszystkie statusy w obu trybach) i odbiornika z R3 przechodzą.
4. `telemetry.py snippet`, `check` i `check --project-override` działają według R4. Testy obejmują ścieżki błędu.
5. `ao-prepare` działa według R5: ostrzeżenia nie zmieniają kodu wyjścia, a odmowa jest tylko przy niepoprawnym
   adresie i przy `fail` `telemetry_no_content`. Testy obejmują kolejność linii.
6. Odbiór z R7 przechodzi wszystkie cztery punkty, a wyniki są w raporcie odbioru.
7. Testy skryptów (`.github/scripts/script-tests.sh`) przechodzą w CI. Dokumentacja z R6 jest zaktualizowana
   w zadaniach, które zmieniają opisane zachowanie.

## Ryzyka

- Integralność danych: wykonawca może dopisać albo usunąć dane stosu (model zagrożeń, piotrp321/agent-observability#3).
  Wnioski o kosztach i przebiegach opieramy na METRICS, a dashboard służy do przeglądu.
- Zachowanie eksportu potwierdzono eksperymentami na jednej wersji i w zwykłych warunkach. Kolejna wersja Claude Code
  albo inne warunki (zabity proces, przeciążony stos, punkt, który przyjmuje połączenie i nie odpowiada) mogą dać
  utratę danych albo opóźnienie końca procesu. Wykrywa to sonda przy każdym starcie AO (ostrzeżenie) i odbiór.

- Claude Code zmienia nazwy zmiennych `OTEL_LOG_*` albo atrybutów między wersjami. Ochrona: `check
  --project-override` i sonda po zmianie przypięcia.
- Liczba serii w Prometheusie rośnie z `session_id` (domyślnie włączone w Claude Code), a nie z `shop.task`: każda
  sesja ma jedno zadanie. Przy jednym użytkowniku to dziesiątki tysięcy serii rocznie, co mieści się w możliwościach
  lokalnego Prometheusa. Wyłączenie `session_id` na metrykach łączyłoby strumienie równoległych procesów w procesorze
  `delta_to_cumulative` stosu, więc epik go nie wyłącza.
- Przypadki telemetrii ocenia odbiornik, więc nie zależą od tekstu modelu. Zależą tylko od tego, czy wykonawca sondy
  w ogóle wysłał żądanie do API.
- Minimalny dekoder protobufa może nie rozpoznać nowego układu wiadomości OTLP. Wtedy `telemetry_export` ma
  `no_export`, a nie fałszywy `pass` (R3). `telemetry_no_content` przeszukuje surowe bajty po rozpakowaniu, więc nie
  zależy od dekodera. Nieznane kodowanie albo ciało nie do rozpakowania daje `unverifiable`, chyba że w innym ciele
  wykryto wyciek (`fail`).
