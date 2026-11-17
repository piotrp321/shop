# TELEMETRY – plan zadań

Specyfikacja: `docs/features/telemetry/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | TELEMETRY-1 | |
| 2 | TELEMETRY-2 | |
| 3 | TELEMETRY-3 | |

Stan epiku prowadzonego przez AO jest w `ao status`, a plan usuwa `FINALIZE`.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "TELEMETRY",
  "spec": "docs/features/telemetry/spec.md",
  "tasks": [
    {
      "id": "TELEMETRY-1",
      "title": "Telemetry block in executor settings and the claude-agent wrapper",
      "scope": "R1 i R2 specyfikacji: tools/agent-security/render_settings.py (opcje --telemetry-endpoint i --telemetry-attribute, parametr telemetry w render(), stała DEFAULT_TELEMETRY_ENDPOINT i lista zmiennych bloku env, walidacja adresu i atrybutów) oraz wrapper claude-agent (usuwanie odziedziczonych OTEL_* i CLAUDE_CODE_ENABLE_TELEMETRY także przy --version i auth status, AGENT_OTEL_ENDPOINT z wartością off i odmową przy niepoprawnym adresie, atrybuty shop.* z nazwy AGENT_AO_HOME i AO_TASK_ID z fallbackiem unknown), z testami test_render_settings.py i test_claude_agent.py. Dokumentacja z R6: sekcja R2 w docs/features/agent-security/spec.md (blok telemetrii, usuwanie OTEL_*, drogi przez pętlę zwrotną i Docker w modelu zagrożeń) i docs/adr/agent-security.md (telemetria jako ruch procesu CLI na pętli zwrotnej, luki zapisu i odczytu stosu). Bez sondy, ao-prepare i telemetry.py. Szablon executor-settings.json bez zmian.",
      "acceptance_criteria": [
        "render_settings.py z --telemetry-endpoint i --telemetry-attribute daje blok env z R1: CLAUDE_CODE_ENABLE_TELEMETRY=1, eksportery otlp, http/protobuf, adres, OTEL_METRIC_EXPORT_INTERVAL=10000, OTEL_LOGS_EXPORT_INTERVAL=5000, 0 dla OTEL_LOG_USER_PROMPTS, OTEL_LOG_ASSISTANT_RESPONSES, OTEL_LOG_TOOL_DETAILS, OTEL_LOG_TOOL_CONTENT, OTEL_LOG_RAW_API_BODIES i OTEL_RESOURCE_ATTRIBUTES w stałej kolejności kluczy; render(..., telemetry=...) daje to samo.",
        "Bez opcji telemetrii wynik render_settings.py jest bajt w bajt taki jak przed zmianą (test porównuje z wynikiem bez opcji na tym samym wejściu); runner EVALS nie dostaje bloku env.",
        "Niepoprawny adres, klucz albo wartość atrybutu kończy się kodem 1 bez wyjścia na stdout, także dla prób obejścia: http://localhost.evil:4318, http://127.0.0.1.nip.io:4318, http://127.0.0.1@host:4318, https://127.0.0.1:4318, http://[::1]:4318, porty 0 i 65536, ścieżka /v1, wartość z przecinkiem albo =, klucz spoza listy shop.source, shop.epic, shop.task, shop.task_kind.",
        "Wrapper z atrapą binarki: odziedziczone OTEL_* i CLAUDE_CODE_ENABLE_TELEMETRY nie docierają do binarki (także przy --version i auth status); --settings ma blok env z adresem DEFAULT_TELEMETRY_ENDPOINT albo AGENT_OTEL_ENDPOINT i atrybutami shop.source=ao, shop.epic=<nazwa z AGENT_AO_HOME>, shop.task=AO_TASK_ID, shop.task_kind według R2 (plan, integration, fix, repair, finalize, unknown); wartość spoza wzorca R1 (np. AO_TASK_ID dłuższy niż 128 znaków) daje unknown bez odmowy.",
        "AGENT_OTEL_ENDPOINT=off daje ustawienia bez bloku env (jak bez telemetrii); niepoprawny AGENT_OTEL_ENDPOINT kończy się odmową startu wrappera (kod 2) z przyczyną na stderr.",
        "docs/features/agent-security/spec.md (sekcja R2 i model zagrożeń) i docs/adr/agent-security.md opisują blok telemetrii, usuwanie OTEL_*, wyjątek odmowy przy niepoprawnym adresie oraz otwarte drogi zapisu i odczytu stosu przez pętlę zwrotną i Docker (piotrp321/agent-observability#3, #498) bez przedstawiania integralności danych telemetrii jako zagwarantowanej.",
        "Testy skryptów tools/agent-security przechodzą (python3 -m unittest discover -s tools/agent-security -p 'test_*.py')."
      ],
      "risk": "CRITICAL",
      "depends_on": [],
      "paths": [
        "tools/agent-security/render_settings.py",
        "tools/agent-security/claude-agent",
        "tools/agent-security/test_render_settings.py",
        "tools/agent-security/test_claude_agent.py",
        "docs/features/agent-security/spec.md",
        "docs/adr/agent-security.md"
      ],
      "parallel_with": []
    },
    {
      "id": "TELEMETRY-2",
      "title": "Telemetry cases in the bypass probe, ao-prepare warnings and TELEMETRY acceptance in /ao-run",
      "scope": "R3, R5, R7 i część R6 specyfikacji: nowy moduł odbiornika OTLP/HTTP w tools/agent-security (rozpakowanie gzip, szukanie znaczników w surowych bajtach, minimalny dekoder protobufa par KeyValue, biblioteka standardowa), używany przez probe.py i później przez telemetry.py (TELEMETRY-3), dopisany do listy FILES kopiowanej przez ao-prepare; probe.py: dwa odbiorniki, AGENT_OTEL_ENDPOINT na pierwszy, środowisko wykonawcy z OTEL_LOG_USER_PROMPTS=1, OTEL_LOG_TOOL_DETAILS=1 i OTEL_EXPORTER_OTLP_ENDPOINT na drugi, przypadki telemetry_export (pass, no_export) i telemetry_no_content (pass, fail, no_export, unverifiable) w warstwie telemetry, statusy no_export i unverifiable nieblokujące w obu trybach, opcja --otel-forward; ao-prepare: sprawdzenie TCP adresu telemetrii przed sondą, odmowa przy niepoprawnym AGENT_OTEL_ENDPOINT, linie ostrzeżeń ao-prepare: telemetria: przed linią sondy, wyjątek od reguły odmowy dla no_export i unverifiable. Dokumentacja: sekcja R6 sondy w docs/features/agent-security/spec.md (warstwa telemetry, statusy, --otel-forward), docs/features/ao-run/spec.md i .claude/skills/ao-run/SKILL.md (linie telemetria z run.log nie są odmową, podawane w rozmowie; sekcja Odbiór TELEMETRY z R7). Bez telemetry.py.",
      "acceptance_criteria": [
        "Odbiornik: ciało gzip jest rozpakowane, nieznane Content-Encoding albo ciało nie do rozpakowania daje unverifiable dla telemetry_no_content; znaczniki (znacznik promptu, .agent-probe-cases.sh, PROBE_LOCAL_BODY, kanarek) szuka w surowych bajtach po rozpakowaniu; dekoder wyciąga pary KeyValue z ExportMetricsServiceRequest i ExportLogsServiceRequest; ciało nie do zdekodowania daje no_export dla telemetry_export. Testy na ciałach protobuf (także gzip) bez modelu.",
        "evaluate i wynik sondy: telemetry_export ma status pass albo no_export; telemetry_no_content pass, fail (znacznik w ciele albo żądanie do drugiego odbiornika), no_export (brak ciał) albo unverifiable; no_export i unverifiable nie zmieniają kodu wyjścia sondy w trybie domyślnym ani --mode ao i mają przyczynę w wyniku; fail telemetry_no_content daje kod 1 w obu trybach. Testy obejmują każdy status w obu trybach.",
        "Sonda z --otel-forward http://127.0.0.1:<port> przekazuje każde odebrane żądanie bez zmian także pod ten adres, a ocena przypadków jest taka sama; adres spoza http://127.0.0.1:<port> to błąd użycia.",
        "ao-prepare: przy zamkniętym porcie telemetrii linia ao-prepare: telemetria: brak stosu pod <adres>… przed linią sondy, kod wyjścia bez zmian; brak tej linii przy otwartym porcie i przy AGENT_OTEL_ENDPOINT=off; niepoprawny AGENT_OTEL_ENDPOINT to odmowa przed sondą; wynik sondy z no_export albo unverifiable to start z linią ao-prepare: telemetria: sonda <przypadek> <status> (<przyczyna>), a z fail telemetry_no_content odmowa; moduł odbiornika jest w kopii $AO_HOME/agent-security. Testy test_ao_prepare.py obejmują te przypadki i kolejność linii.",
        "docs/features/agent-security/spec.md (R6), docs/features/ao-run/spec.md i .claude/skills/ao-run/SKILL.md opisują przypadki i statusy telemetrii, linie ao-prepare: telemetria: (nie są odmową, także jako ostatnia linia run.log; sesja podaje je w rozmowie) oraz sekcję Odbiór TELEMETRY z R7: telemetry.py check, sonda domyślna z --otel-forward http://127.0.0.1:4318 i sonda --mode ao, każda z kodem 0 i statusem pass obu przypadków telemetrii w JSON wyniku (sam kod 0 nie wystarcza), telemetry.py check --project-override, zapytanie Loki o api_request zadania sondy z shop_source=ao; nieudany punkt blokuje merge końcowego PR jak w Odbiorze AGENT-SECURITY.",
        "Testy skryptów tools/agent-security i .claude/skills/ao-run przechodzą."
      ],
      "risk": "CRITICAL",
      "depends_on": ["TELEMETRY-1"],
      "paths": [
        "tools/agent-security/",
        ".claude/skills/ao-run/SKILL.md",
        "docs/features/ao-run/spec.md",
        "docs/features/agent-security/spec.md"
      ],
      "parallel_with": []
    },
    {
      "id": "TELEMETRY-3",
      "title": "telemetry.py: snippet, stack check and project-override check",
      "scope": "R4 i część R6 specyfikacji: nowe tools/telemetry/telemetry.py (snippet, check, check --project-override) z testami test_telemetry.py i README.md; stała adresu i lista zmiennych z tools/agent-security/render_settings.py (TELEMETRY-1), odbiornik i dekoder z modułu tools/agent-security (TELEMETRY-2), bez kopii; domyślna binarka --project-override z .claude/skills/ao-run/claude_pin.py. Dokumentacja: tools/telemetry/README.md (polecenia, adresy, uid źródła prometheus, związek z agent-observability i METRICS: złączenie po shop.task i czasie próby, dane stosu bez gwarancji integralności) i docs/adr/agentic-software-house.md (komponent Metryki i evale wskazuje telemetrię w lokalnym stosie). Bez zmian w render_settings.py, probe.py i ao-prepare poza importem.",
      "acceptance_criteria": [
        "telemetry.py snippet wypisuje blok JSON z kluczem env: zmienne eksportu i OTEL_LOG_*=0 z listy R1 (z render_settings.py), OTEL_RESOURCE_ATTRIBUTES=shop.source=interactive, bez interwałów; niczego nie zapisuje; --endpoint spoza http://127.0.0.1:<port> kończy się kodem 2.",
        "telemetry.py check wysyła przez OTLP/HTTP JSON gauge shop_telemetry_check z unikalną wartością i odpytuje co 2 s Prometheus przez /api/datasources/proxy/uid/prometheus/api/v1/query do --timeout (domyślnie 60 s): kod 0, gdy wartość widoczna; kod 1 i jedna linia z przyczyną przy zamkniętym porcie OTLP, niedostępnej Grafanie, braku źródła prometheus albo braku punktu po czasie. Testy z atrapami odbiornika i Grafany obejmują każdy przypadek.",
        "telemetry.py check --project-override uruchamia binarkę (domyślnie z claude_pin.py) z -p, Haiku, --max-budget-usd 0.1, --setting-sources project, --strict-mcp-config, bez CLAUDE* i OTEL_* w środowisku, w tymczasowym repo git z .claude/settings.json projektu (OTEL_EXPORTER_OTLP_ENDPOINT na pierwszy odbiornik, OTEL_LOG_USER_PROMPTS=1, OTEL_RESOURCE_ATTRIBUTES=shop.task=override) i --settings z blokiem R1 na drugi odbiornik; kod 0 tylko gdy pierwszy odbiornik nie dostał żądań, drugi dostał metryki i logi, żadne ciało nie zawiera znacznika promptu, a shop.task nie ma wartości override; tymczasowe repo i odbiorniki są sprzątane także po błędzie. Testy z atrapą binarki obejmują sukces, żądanie do pierwszego odbiornika, znacznik w ciele, brak eksportu i błąd binarki.",
        "tools/telemetry/README.md i docs/adr/agentic-software-house.md opisują polecenia, zależność od adresów stosu i uid źródła prometheus, złączenie z METRICS po shop.task i czasie próby oraz brak gwarancji integralności danych stosu.",
        "Testy skryptów przechodzą (python3 -m unittest discover -s tools/telemetry -p 'test_*.py' i .github/scripts/script-tests.sh)."
      ],
      "risk": "STANDARD",
      "depends_on": ["TELEMETRY-1", "TELEMETRY-2"],
      "paths": [
        "tools/telemetry/",
        "docs/adr/agentic-software-house.md"
      ],
      "parallel_with": []
    }
  ]
}
```
