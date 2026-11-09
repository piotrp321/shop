# HARDENING – plan zadań

Specyfikacja: `docs/features/hardening/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | HARDENING-1, HARDENING-3, HARDENING-4 | |
| 2 | HARDENING-2, HARDENING-5 | |
| 3 | HARDENING-6 | |
| 4 | HARDENING-7 | |

Stan prób zadań zgłoszonych do AO: `ao status`. Kolumnę „Status” (PR, zmergowane) uzupełnia użytkownik przy
integracji zadań fali.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "HARDENING",
  "spec": "docs/features/hardening/spec.md",
  "tasks": [
    {
      "id": "HARDENING-1",
      "title": "Rate limiting of order placement, payment start and webhooks with the client IP from the store and Cloudflare",
      "scope": "Spec: D1–D8, „Adres IP klienta”, „Rate limiting” (API, Sklep: komunikaty), „Weryfikacja originu na serwerze”. apps/api: zależność symfony/rate-limiter (composer.json, composer.lock, symfony.lock; przejrzyj zmiany przepisu Fleksa), config/packages/rate_limiter.yaml (limitery order_placement, payment_start, payment_webhook; wartości produkcyjne, when@dev wysokie, when@test małe z cache.adapter.array), pula cache.rate_limiter na Redisie z limitami czasu 1 s, monolog.yaml when@prod: kanał cache od warning poza fingers_crossed, Shared\\Infrastructure\\Http: ClientIp i subscriber limitów (RateLimit::ROUTE_DEFAULT na trasach Ordering i Payment), odpowiedź 429 (problem details, Retry-After, private no-store), OpenAPI 429 na limitowanych endpointach (openapi.json) i klient sklepu (make api-client); PaymentSimulator: DeliverSimulatedWebhook ponawia 429 jak 5xx. apps/store: src/checkout przekazuje nagłówek CLIENT_IP_HEADER z żądania przeglądarki do API, BFF kasy i płatności mapuje 429 (detail, Retry-After), strona kasy i potwierdzenia pokazuje komunikaty ze spec i zachowuje Idempotency-Key. infra/k8s: wartość chartu clientIpHeader → CLIENT_IP_HEADER w api i store, reguły renderu D3 (bez ipAllowList tylko z clientIpHeaderWithoutAllowList, ten przełącznik razem z image.requireDigests to błąd), prod: CF-Connecting-IP, testy renderu w infra/k8s/tests; infra/k8s/README.md: krok weryfikacji originu. Dokumentacja: apps/api/CLAUDE.md (limity, ClientIp, 429, symulator), apps/store/CLAUDE.md (nagłówek adresu w BFF, 429 kasy), docs/vision.md „Bezpieczeństwo”, docs/features/checkout/spec.md (T2 i ryzyko), docs/features/payment/spec.md (429 webhooka), docs/features/deploy/spec.md (clientIpHeader, D17), CLAUDE.md (clientIpHeader w opisie checków chartu). Nie obejmuje limitu /auth/login (HARDENING-2) ani profilu load (HARDENING-5).",
      "acceptance_criteria": [
        "11. złożenie zamówienia gościa z jednego adresu w oknie (wartości produkcyjne) to 429 z Retry-After i Cache-Control private, no-store; inny adres przechodzi (test funkcjonalny z małymi limitami when@test i test wartości produkcyjnych konfiguracji)",
        "Limit klienta liczy się po sub, niezależnie od adresu; limity gościa i klienta mają osobne przestrzenie kluczy",
        "Rozpoczęcie płatności i webhook mają limity z D5; 429 webhooka zapada przed sprawdzeniem podpisu",
        "ClientIp: CF-Connecting-IP używany tylko przy ustawionym CLIENT_IP_HEADER i tylko jako jeden poprawny adres; IPv6 kluczowany prefiksem /64; podrobiony nagłówek bez konfiguracji ignorowany (testy jednostkowe i funkcjonalne)",
        "Rzeczywista awaria Redisa (adapter Redis na adresie bez działającego Redisa): żądanie przechodzi w granicy limitów czasu DSN, a log ma wpis warning kanału cache; konfiguracja prod Monologa kieruje kanał cache od warning poza fingers_crossed (test integracyjny)",
        "Symulator ponawia dostawę webhooka po 429, inne 4xx kończą dostawę bez zmian",
        "Render prod ma CLIENT_IP_HEADER=CF-Connecting-IP w api i store oraz ipallowlist na każdym ingressie, ci go nie ma; clientIpHeader bez ipAllowList i bez potwierdzenia oraz clientIpHeaderWithoutAllowList z image.requireDigests się nie renderują",
        "Kasa pokazuje komunikat 429, nie traci formularza i ponawia z tym samym Idempotency-Key; zamówienie z 429 płatności można opłacić ze strony potwierdzenia (Vitest)",
        "openapi.json i klient sklepu są aktualne; make check, testy api i store oraz E2E przechodzą"
      ],
      "risk": "CRITICAL",
      "depends_on": []
    },
    {
      "id": "HARDENING-2",
      "title": "Rate limit of the login start in the store",
      "scope": "Spec: „Sklep: rozpoczęcie logowania”, D4, D5. apps/store: app/auth/login i src/auth: licznik w Redisie sklepu (INCR, EXPIRE przy pierwszym trafieniu, okno stałe 10 min) na adres z nagłówka CLIENT_IP_HEADER (bez niego wspólny klucz), limit STORE_LOGIN_RATE_LIMIT (domyślnie 30), 429 z Retry-After i stroną ze spec bez zapisu stanu logowania; Redis niedostępny: dotychczasowe 503. infra/docker-compose.yml: wysoki STORE_LOGIN_RATE_LIMIT dla stosu deweloperskiego i E2E. Chart nie ustawia tej zmiennej. Dokumentacja: apps/store/CLAUDE.md, docs/vision.md „Bezpieczeństwo” (logowanie w sklepie). Zależy od HARDENING-1 (CLIENT_IP_HEADER w chartcie sklepu).",
      "acceptance_criteria": [
        "Przekroczenie limitu /auth/login to 429 z Retry-After (sekundy do końca okna) i komunikatem ze spec, bez zapisu stanu logowania w Redisie (Vitest na Redisie)",
        "Klucz to adres z CLIENT_IP_HEADER; bez zmiennej albo nagłówka wspólny klucz",
        "Niedostępny Redis daje 503 jak dotąd",
        "E2E logowania przechodzą z wartością compose; make check i testy store przechodzą"
      ],
      "risk": "STANDARD",
      "depends_on": ["HARDENING-1"]
    },
    {
      "id": "HARDENING-3",
      "title": "Correlation ID from the HTTP entry through Messenger to the JSON logs",
      "scope": "Spec: D9, „Correlation ID”. apps/api: Shared\\Infrastructure\\Observability (CorrelationId z resetem kernel.reset, subscriber kernel.request/kernel.response z X-Correlation-ID, ConsoleEvents::COMMAND poza messenger:consume, CorrelationIdStamp i middleware na command.bus, query.bus i event.bus z ustawieniem i przywróceniem identyfikatora przy obsłudze, procesor Monologa extra.correlation_id), config messenger.yaml i monolog.yaml. infra/varnish/default.vcl: usunięcie X-Correlation-ID z odpowiedzi zapisywanej w cache, test .vtc. Dokumentacja: apps/api/CLAUDE.md, docs/vision.md „Obserwowalność” (brzmienie z D9). Nie zmienia sklepu ani panelu.",
      "acceptance_criteria": [
        "Każda odpowiedź API (także błąd) ma X-Correlation-ID z UUIDv7; nagłówek z żądania jest ignorowany",
        "Wpisy logu żądania, wiadomości outboxa i konsumenta zdarzenia w workerze mają ten sam correlation_id (test integracyjny test_messaging na prawdziwych transportach)",
        "Wiadomość bez stempla dostaje nowy identyfikator; po obsłudze wiadomości i po żądaniu identyfikator jest przywrócony albo wyzerowany (testy jednostkowe)",
        "Odpowiedź z cache Varnisha nie ma X-Correlation-ID (make varnish-test)",
        "make check i testy api przechodzą"
      ],
      "risk": "STANDARD",
      "depends_on": []
    },
    {
      "id": "HARDENING-4",
      "title": "Security headers of the store, the panel and the API",
      "scope": "Spec: D10, „Nagłówki bezpieczeństwa”. apps/store/next.config.ts (headers() na każdej trasie, bez script-src), apps/admin/docker/nginx.conf (add_header … always w każdej lokalizacji, CSP z script-src 'self'), apps/api: subscriber kernel.response z X-Content-Type-Options: nosniff, infra/k8s/charts/shop: middleware Traefika z HSTS tylko przy ingress.tls, testy renderu; infra/k8s/scripts/smoke.sh: nagłówki panelu i załadowanie panelu w przeglądarce bez błędów CSP. Dokumentacja: apps/store/CLAUDE.md, docs/features/deploy/spec.md (HSTS). Nagłówki nie zmieniają Cache-Control ani tagów cache.",
      "acceptance_criteria": [
        "Odpowiedzi sklepu mają nagłówki z D10 (Vitest konfiguracji albo test trasy)",
        "Każda odpowiedź API ma X-Content-Type-Options: nosniff (test funkcjonalny)",
        "Test dymny sprawdza nagłówki panelu z obrazu produkcyjnego i ładuje panel bez błędów CSP w konsoli",
        "HSTS (max-age=31536000, bez includeSubDomains i preload) jest tylko w renderze z ingress.tls (test renderu)",
        "make check, testy api i store oraz make -C infra/k8s smoke przechodzą"
      ],
      "risk": "STANDARD",
      "depends_on": []
    },
    {
      "id": "HARDENING-5",
      "title": "k6 load test scripts, CI smoke run and the load profile on k3d",
      "scope": "Spec: D14, D15, D18, „Testy obciążeniowe” (bez wykonania pomiaru i raportu). load/: skrypty k6 scenariuszy ze spec (lista i kategoria, strona produktu, wyszukiwarka, koszyk, checkout gościa), dane wejściowe z publicznego API, nowy adres CF-Connecting-IP w każdej iteracji, 409 i 429 liczone osobno, przebieg rosnący bez checkoutu i checkout ze stałą ograniczoną równoległością. Obraz grafana/k6 przypięty tagiem i digestem, uruchamiany w kontenerze. Makefile: make load-smoke (jeden użytkownik, jedna iteracja, do 30 s) na stosie compose; .github/workflows/ci.yml: load-smoke w zadaniu e2e po Playwrighcie. infra/k8s: profil environments/load (ci plus zasoby z prod, clientIpHeader CF-Connecting-IP, clientIpHeaderWithoutAllowList true) w make -C infra/k8s check, make -C infra/k8s load (klaster jak w smoke.sh, dane demo, podniesienie stanów SQL, rozgrzanie, przebiegi, wyniki do LOAD_OUT, usunięcie klastra przy każdym wyjściu, kod 2 przy błędzie środowiska), test ścieżki błędu na atrapach. Dokumentacja: CLAUDE.md (make load-smoke, make -C infra/k8s load, katalog load/), docs/vision.md (testy obciążeniowe k6). Zależy od HARDENING-1 (clientIpHeader i reguły renderu).",
      "acceptance_criteria": [
        "make load-smoke przechodzi na stosie compose i w CI po Playwrighcie, kupując 1 sztukę",
        "Profil load renderuje się i przechodzi kubeconform w make -C infra/k8s check",
        "make -C infra/k8s load bez LOAD_OUT albo z niegotowymi podami kończy się kodem 2 i usuwa klaster (test na atrapach docker/k3d w check)",
        "Skrypty dają osobne wyniki przebiegu rosnącego (odczyt i koszyk) i checkoutu z ograniczoną równoległością, z 409 i 429 liczonymi osobno",
        "make check przechodzi"
      ],
      "risk": "STANDARD",
      "depends_on": ["HARDENING-1"]
    },
    {
      "id": "HARDENING-6",
      "title": "Load test measurement and its report",
      "scope": "Spec: D13, D15, D18, „Testy obciążeniowe” (raport). Wykonanie make -C infra/k8s load na kodzie z wszystkimi zmianami epiku (po zatrzymaniu stosu compose własnego worktree) i zapis docs/reports/load-test-<data>.md oraz docs/reports/README.md (opis katalogu i tabela raportów jak w docs/experiments/README.md). Raport ze wszystkimi sekcjami spec, w tym dwie osobne tabele: maksymalna przepustowość systemu (odczyt i koszyk) i checkout przy ograniczonej równoległości z zastrzeżeniem, że to nie benchmark checkoutu; sekcja „Czego z tego nie wnioskujemy”. Bez zmian kodu; błąd skryptów pomiaru to bloker zadania, nie poprawka w nim. Dokumentacja: docs/vision.md i CLAUDE.md (docs/reports/).",
      "acceptance_criteria": [
        "Raport ma datę, SHA, host i zasoby VM Dockera, wersje obrazów, zasoby podów, dane, scenariusze, wyniki z 409 i 429, zużycie podów, wąskie gardła, obciążenie hosta i sekcję „Czego z tego nie wnioskujemy”",
        "Maksymalna przepustowość systemu i checkout przy ograniczonej równoległości są w osobnych tabelach, a wynik checkoutu nie jest przedstawiony jako przepustowość checkoutu",
        "Raport nie twierdzi niczego o skali docelowej ponad pomiar",
        "docs/reports/README.md opisuje katalog i wymienia raport"
      ],
      "risk": "STANDARD",
      "depends_on": ["HARDENING-2", "HARDENING-3", "HARDENING-4", "HARDENING-5"]
    },
    {
      "id": "HARDENING-7",
      "title": "Security review of the store, the API and the panel",
      "scope": "Spec: D12, D13, D19, „Przegląd bezpieczeństwa”. Sprawdzenie 11 punktów listy na kodzie i działającym stosie po wszystkich zmianach epiku i zapis docs/reports/security-review-<data>.md (wpis w docs/reports/README.md). Dla każdego punktu wynik, dowód i waga; sekcja „Blokery publicznego demo” (Critical, High i niezweryfikowana próba ominięcia Cloudflare) i propozycje zgłoszeń. Przegląd niczego nie naprawia i nie zakłada issues.",
      "acceptance_criteria": [
        "Raport ma wynik każdego z 11 punktów z dowodem (plik albo komenda z wynikiem) i wagą znalezisk",
        "Punkt 1 obejmuje próbę z własnym CF-Connecting-IP, render prod i odmowę renderu z clientIpHeaderWithoutAllowList; próba ominięcia Cloudflare na serwerze jest zapisana jako niezweryfikowana",
        "Sekcja „Blokery publicznego demo” wymienia każde znalezisko Critical i High; propozycje zgłoszeń mają etykiety bug/tech-debt i agent",
        "Zadanie nie zmienia kodu"
      ],
      "risk": "STANDARD",
      "depends_on": ["HARDENING-6"]
    }
  ]
}
```
