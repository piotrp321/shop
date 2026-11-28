# PERF-3 (OVERLOAD-PROTECTION) – plan zadań

Specyfikacja: `docs/features/overload-protection/spec.md`. Epik prowadzi główna sesja Claude Code z natywnymi
subagentami. Epik ma jedną gałąź (poza `epic/*`) z PR-em do `main` otwartym od początku jako szkic. Każde zadanie
realizuje subagent we własnym worktree na gałęzi zadania; sesja orkiestrująca merguje gotowe gałęzie zadań do gałęzi
epiku i wypycha ją. Zadania nie mają własnych PR. Zadania jednej fali z listą `parallel_with` mogą iść naraz. Plan jest
dokumentem roboczym: po ukończeniu epiku usuń go.

**Warunki startu.** Decyzja właściciela o realizacji epiku (po wynikach bieżących optymalizacji) i akceptacja D1–D8;
PERF-1 zmergowany do `main` (wspólne `Caddyfile`, mapowanie 503, `values.yaml`); lokalny stos z pełnym profilem
`server` dostępny dla zadania 5. Bez operacji na worf.pl.

TDD obowiązuje zadania 2 i 3 (RED i GREEN w raporcie); zadanie 1 pisze `varnishtest` przed zmianą VCL; zadanie 4
weryfikuje się testem renderu chartu i `make -C infra/k8s check`.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | PERF-3-1, PERF-3-2, PERF-3-3, PERF-3-4 | |
| 2 | PERF-3-5 | |

Kolumnę „Status” (zmergowane do gałęzi epiku, commit) uzupełnia sesja orkiestrująca po merge'u gałęzi zadania.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "PERF-3",
  "spec": "docs/features/overload-protection/spec.md",
  "tasks": [
    {
      "id": "PERF-3-1",
      "title": "Varnish: limit i kolejka wyszukiwania, kopia przy błędzie, miękki purge",
      "scope": "infra/varnish: R1, R2 i część Varnisha z R3 specyfikacji. Backendy api_search i api z limitami, kolejką i czasami (wartości kandydujące, ostateczne ustala PERF-3-5), grace 1 h, req.grace 0 s przy zdrowej odpowiedzi, abandon i restart po 5xx, X-Cache STALE, Retry-After na 503 Varnisha, xkey.softpurge. Bez zmian chartu (VCL jest dowiązany) i sklepu.",
      "acceptance_criteria": [
        "varnishtest: ponad max_connections żądania czekają w kolejce do wait_limit, nadmiar dostaje od razu 503 z Retry-After; limit api_search nie dotyczy pozostałych odczytów katalogu i odwrotnie",
        "varnishtest: po softpurge każdego tagu następne żądanie dostaje świeżą odpowiedź; przy zdrowym backendzie nigdy STALE, także po TTL",
        "varnishtest: 5xx albo błąd backendu przy istniejącej kopii młodszej niż 1 h daje 200 z kopii i X-Cache STALE; bez kopii 503 z Retry-After; po 503 następne żądanie znów idzie do backendu (brak hit-for-miss)",
        "varnishtest: istniejące testy (pass, ACL i token purge, sitemap, przekierowanie, correlation id, TTL) przechodzą z purge miękkim; obejścia: HEAD, inna kolejność parametrów, adres spoza listy VCL, PURGE bez tokenu",
        "komentarze default.vcl opisują reguły; make varnish-test i make check przechodzą"
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": ["infra/varnish/"],
      "parallel_with": ["PERF-3-2", "PERF-3-3", "PERF-3-4"]
    },
    {
      "id": "PERF-3-2",
      "title": "API: Retry-After i log zamiast Sentry dla 503 wyszukiwarki, max_wait_time",
      "scope": "apps/api: R4 specyfikacji. ListProductsController (503 z Retry-After 5), filtr zgłoszeń Sentry dla 503 z SearchUnavailable (log ostrzeżenia), max_wait_time w Caddyfile (kandydat 2 s). OpenAPI i klienci TS bez zmian. Bez zmiany klienta Elasticsearcha i num_threads.",
      "acceptance_criteria": [
        "test integracyjny z nieosiągalnym Elasticsearchem: GET /api/v1/products daje 503 z Retry-After 5, problemem bez szczegółów technicznych i private, no-store; RED przed zmianą",
        "test: 503 z SearchUnavailable nie wywołuje zdarzenia Sentry i zapisuje log ostrzeżenia; inne 5xx nadal trafiają do Sentry",
        "Caddyfile ma max_wait_time z komentarzem o odpowiedzi 503 text/plain (spec, eksperyment 4)",
        "make -C apps/api check i testy API przechodzą"
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "apps/api/src/Catalog/UI/Http/ListProductsController.php",
        "apps/api/src/Shared/Infrastructure/Observability/",
        "apps/api/config/packages/sentry.yaml",
        "apps/api/docker/frankenphp/Caddyfile",
        "apps/api/tests/"
      ],
      "parallel_with": ["PERF-3-1", "PERF-3-3", "PERF-3-4"]
    },
    {
      "id": "PERF-3-3",
      "title": "Sklep: limit czasu odczytów katalogu, lista niedostępna bez 500, rewalidacja catalog w tle",
      "scope": "apps/store: R5 i część sklepu z R3 specyfikacji. Limit czasu (kandydat 5 s) dla odczytów przez API_CATALOG_URL, wynik unavailable w loadCatalog i jego render na /, /c/... i /szukaj (także metadane), revalidateTag catalog w trybie max, tagi produktów z expire 0. Bez limitu czasu dla API_INTERNAL_URL. Dokumentacja rewalidacji.",
      "acceptance_criteria": [
        "testy: loadCatalog zwraca unavailable dla 5xx, przekroczenia limitu czasu i błędu sieci; 400 i 404 bez zmian; RED przed zmianą",
        "testy renderu: /, /c/... i /szukaj przy unavailable dają 200 z komunikatem z specyfikacji w aria-live, odnośnikiem ponowienia osiągalnym klawiaturą, nazwą kategorii, bez listy i filtrów, bez noindex; ekran 375 px",
        "test: odczyty przez API_CATALOG_URL mają limit czasu, odczyty i zapisy przez API_INTERNAL_URL nie",
        "test: POST /api/revalidate woła revalidateTag z max dla catalog i z expire 0 dla tagów produktów",
        "apps/store/CLAUDE.md, docs/features/product-page/spec.md i docs/features/isr-tag-limit/spec.md opisują nowy tryb rewalidacji",
        "make -C apps/store check i testy sklepu przechodzą"
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "apps/store/src/",
        "apps/store/app/",
        "apps/store/tests/",
        "apps/store/CLAUDE.md",
        "docs/features/product-page/spec.md",
        "docs/features/isr-tag-limit/spec.md"
      ],
      "parallel_with": ["PERF-3-1", "PERF-3-2", "PERF-3-4"]
    },
    {
      "id": "PERF-3-4",
      "title": "Chart: inFlightReq na bezpośrednim wejściu do listy produktów",
      "scope": "infra/k8s: R6 specyfikacji. Ingress hosta api dla /api/v1/products (Exact) z middleware inFlightReq (requestHost, kandydat 2, wartość w values.yaml) i middleware polityki hosta. Bez zmian compose (lokalnie nie ma Traefika).",
      "acceptance_criteria": [
        "test renderu: ingress /api/v1/products ma inFlightReq z requestHost i middleware allow list, HSTS, noindex i buffering jak host api",
        "test renderu: inne ścieżki hosta api i host sklepu bez inFlightReq; ścieżka /api/v1/products/{slug} nie dostaje limitu",
        "make -C infra/k8s check przechodzi"
      ],
      "risk": "FAST",
      "depends_on": [],
      "paths": [
        "infra/k8s/charts/shop/templates/ingress.yaml",
        "infra/k8s/charts/shop/templates/middlewares.yaml",
        "infra/k8s/charts/shop/values.yaml",
        "infra/k8s/tests/"
      ],
      "parallel_with": ["PERF-3-1", "PERF-3-2", "PERF-3-3"]
    },
    {
      "id": "PERF-3-5",
      "title": "Pomiar na lokalnym profilu server i wartości limitów",
      "scope": "Weryfikacja K1–K6 specyfikacji na lokalnym pełnym profilu server bez generowania danych i bez reindeksu, istniejącymi narzędziami (load/baseline.py, load/steady.js). Ustala wartości limitów R1, R4, R5 i R6 z pomiaru i wpisuje je do konfiguracji; raport w docs/reports/.",
      "acceptance_criteria": [
        "K1 zmierzone przed zmianami (rewizja main), K2–K5 po, na tej samej rewizji danych; trzy przebiegi, wynik środkowy z rozrzutem",
        "K2: W12 przy 2 × K1 z koszykiem i zamówieniami spełnione albo niespełnione z przyczyną; odpowiedzi STALE, 429 i 503 liczone jako niepowodzenia",
        "K4: zatrzymany Elasticsearch przez 5 min ruchu daje 0 odpowiedzi 500 sklepu, zamówienia w W6, W13 po przebiegu",
        "wartości max_connections, wait_limit, wait_timeout, max_wait_time, limit czasu sklepu i inFlightReq wynikają z pomiaru i są w raporcie z regułą ze specyfikacji",
        "raport z komendami do powtórzenia; make check i testy dotkniętych aplikacji przechodzą"
      ],
      "risk": "STANDARD",
      "depends_on": ["PERF-3-1", "PERF-3-2", "PERF-3-3", "PERF-3-4"],
      "paths": [
        "infra/varnish/default.vcl",
        "apps/api/docker/frankenphp/Caddyfile",
        "apps/store/src/",
        "infra/k8s/charts/shop/values.yaml",
        "docs/reports/",
        "docs/features/overload-protection/spec.md"
      ],
      "parallel_with": []
    }
  ]
}
```
