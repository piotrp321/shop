# ABUSE-LIMITS – plan zadań

Specyfikacja: `docs/features/abuse-limits/spec.md`. Epik ma jedną gałąź (poza `epic/*`) z draft PR do `main` od
początku. Każde zadanie realizuje subagent we własnym worktree na gałęzi zadania; sesja orkiestrująca merguje gotowe
gałęzie zadań do gałęzi epiku i ją wypycha (CI biegnie przy każdym pushu draft PR). Zadania nie mają własnych PR.
Plan jest dokumentem roboczym: po ukończeniu epiku usuń go.

Warunek przed startem: ASH-V1 zmergowany do `main` i tag `ash-v1.0` (gałąź epiku powstaje od `origin/main` po tym
merge'u).

Prowadzenie:
- ABUSE-LIMITS-8 idzie jako pierwsze z zadań równoległych: od niego zależy kolejność wdrożeń z DEMO-SCALE
  (specyfikacja, „Wdrożenie i kolejność z DEMO-SCALE”).
- ABUSE-LIMITS-7 i ABUSE-LIMITS-9 zmieniają chart, a ABUSE-LIMITS-8 komentarze wartości `prod` i `load`; te pliki
  zmienia też DEMO-SCALE. Epik zmergowany do `main` jako drugi robi rebase.
- Kryteria w tabeli „Kryteria akceptacji” opisu PR mają numer sekcji i kryterium specyfikacji, np. `L2.3`.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | ABUSE-LIMITS-1, ABUSE-LIMITS-2, ABUSE-LIMITS-3, ABUSE-LIMITS-4, ABUSE-LIMITS-5, ABUSE-LIMITS-6, ABUSE-LIMITS-7, ABUSE-LIMITS-8, ABUSE-LIMITS-9 | |

Stan zadań prowadzi sesja orkiestrująca w tej tabeli.

## Praca szeregowa

- ABUSE-LIMITS-1, ABUSE-LIMITS-2: `docs/features/cart/spec.md`; oba zmieniają zachowanie opisane w specyfikacji koszyka (czas życia i limity API, kody BFF) i mogą zmieniać te same sekcje.
- ABUSE-LIMITS-1, ABUSE-LIMITS-3: `apps/api/openapi.json`, `apps/store/src/api/schema.d.ts`, `docs/features/hardening/spec.md`; jeden wygenerowany kontrakt OpenAPI i klient TS.
- ABUSE-LIMITS-1, ABUSE-LIMITS-4: `apps/api/CLAUDE.md`, `apps/api/openapi.json`, `apps/api/src/Shared/Infrastructure/Http/ClientIp.php`, `apps/store/src/api/schema.d.ts`, `docs/features/discount-codes/spec.md`, `docs/features/hardening/spec.md`; wspólny kod adresu klienta i wygenerowany kontrakt OpenAPI z klientem TS.
- ABUSE-LIMITS-2, ABUSE-LIMITS-3: `apps/store/src/checkout`; jeden kod kasy (`problems.ts`, `CheckoutPage.tsx`) obsługuje wszystkie odpowiedzi złożenia i podglądu kodu.
- ABUSE-LIMITS-2, ABUSE-LIMITS-4: `apps/store/src/checkout`; jeden kod kasy (`problems.ts`, `CheckoutPage.tsx`) obsługuje wszystkie odpowiedzi złożenia i podglądu kodu.
- ABUSE-LIMITS-3, ABUSE-LIMITS-4: `apps/api/openapi.json`, `apps/api/src/Ordering/Application`, `apps/api/src/Ordering/Domain`, `apps/api/src/Ordering/UI/Http`, `apps/store/src/api/schema.d.ts`, `apps/store/src/checkout`, `docs/features/checkout/spec.md`, `docs/features/hardening/spec.md`; oba zmieniają `PlaceOrder` i jego reguły w jednym module Ordering oraz te same zdania specyfikacji (tabela 409 kasy, D16 HARDENING).

## Zadania

```json
{
  "schema_version": 1,
  "epic": "ABUSE-LIMITS",
  "spec": "docs/features/abuse-limits/spec.md",
  "tasks": [
    {
      "id": "ABUSE-LIMITS-1",
      "title": "Limitery API: blokada licznika, koszyk gościa i podgląd kodu rabatowego",
      "scope": "apps/api (spec: L1, L2, L3, D1–D3, D15, „Wymagane przed startem” E1): symfony/lock (zgoda użytkownika z „Wymaganych przed startem”) z magazynem w Redisie i dekoratorem LockFactory (TTL 5 s, czekanie najwyżej 300 ms, rywalizacja to 429, rywalizacja zgłaszana wyjątkiem, bo limiter nie sprawdza wyniku acquire; błąd Redisa przy wzięciu i przy zwolnieniu blokady fail open z warning, bez wywołania licznika po błędzie wzięcia) jako lock_factory wszystkich limiterów w prod i dev; nowe limitery guest_cart_create, guest_cart_write, discount_preview w config/packages/rate_limiter.yaml z kluczem adresu klienta; warunek parametru discountCode w mechanizmie limiterów tras (RateLimit, RateLimitSubscriber); trasy GuestCartController i CustomerCartController (odczyt z kodem); pusty koszyk gościa wygasa po 1 dniu (Cart::touch, założenie); 429 w OpenAPI tras koszyka, eksport openapi.json i klient TS sklepu. Dokumentacja: reguła dla agentów w apps/api/CLAUDE.md, jedna linia: „Każda nowa publiczna trasa zapisu bez logowania dostaje limiter (RateLimit::ROUTE_DEFAULT, wartości prod, dev i test) i test prób obejścia.”; zdanie apps/api/CLAUDE.md o limiterach bez symfony/lock; zdania specyfikacji, którym zadanie zmienia zachowanie: hardening spec (D1 bez symfony/lock, limity koszyka poza zakresem), discount-codes spec (limit podglądu poza zakresem, ryzyko zgadywania), cart spec (decyzja 5: czas życia, rate limiting koszyków poza zakresem). Definicje serwisów blokady w config/packages/lock.yaml, nie w services.yaml; błąd blokady loguje warning w kanale cache, więc monolog.yaml bez zmian. Nie: BFF i UI sklepu (ABUSE-LIMITS-2), komunikaty odrzucenia kodu (D4 bez zmian).",
      "acceptance_criteria": [
        "Raport zawiera wynik eksperymentu E1 (zachowanie blokady RateLimiter 8.1) przed implementacją.",
        "Integracyjny na prawdziwym Redisie: co najmniej 20 równoległych procesów do limitera o limicie L daje najwyżej L przyjętych; raport pokazuje fazę RED przy lock_factory: null.",
        "Blokada klucza trzymana dłużej niż 300 ms daje kolejnemu żądaniu tego klucza 429 po najwyżej ok. 300 ms; inny klucz nie czeka; porzucona blokada przestaje blokować po 5 s.",
        "Redis niedostępny (odrzuca połączenia), Redis, który nie odpowiada, i Redis, który pada między wzięciem a zwolnieniem blokady: żądanie limitowanej trasy przechodzi (nie 500), log ma warning, licznik nie jest wołany po błędzie wzięcia blokady i żądanie trwa dłużej najwyżej o limit czasu połączenia i limit czasu odczytu DSN (RedisRateLimitTest).",
        "Funkcjonalne (when@test): zakładanie koszyka gościa i zmiany jego linii ponad limit to 429 z Retry-After przed kontrolerem; POST, PUT i DELETE liczą się do jednego licznika; inny adres przechodzi; 429 nie zakłada koszyka ani nie zmienia linii.",
        "Próby obejścia L2 i L3: inny koszyk tego samego adresu, adres z tego samego /64, CF-Connecting-IP przy pustym CLIENT_IP_HEADER, nagłówek z dwoma adresami albo śmieciem, wielkość liter id koszyka, żądanie odrzucone walidacją (422) i discountCode za długi albo jako tablica (400) zużywają limit.",
        "Podgląd kodu ponad limit to 429 przed wyceną na trasie gościa i klienta, a przełączanie tras z jednego adresu nie zeruje licznika; kod małymi literami, z odstępami ASCII i NBSP zużywa ten sam licznik; ?discountCode= i odczyt bez parametru nie zużywają limitu.",
        "Jednostkowe (TDD): nowy koszyk gościa i koszyk po usunięciu ostatniej linii wygasają po 1 dniu, koszyk z linią po 30 dniach, koszyk klienta bez zmian.",
        "RateLimitConfigurationTest sprawdza wartości prod i dev z D3 oraz blokadę każdego limitera.",
        "openapi.json opisuje 429 na nowych trasach; klient TS sklepu wygenerowany; reguła limiterów i zdanie o blokadzie licznika w apps/api/CLAUDE.md oraz zdania hardening, discount-codes i cart spec zgodne z nowym zachowaniem; make -C apps/api check i test, make -C apps/store check przechodzą."
      ],
      "risk": "CRITICAL",
      "depends_on": [],
      "paths": [
        "apps/api/composer.json",
        "apps/api/composer.lock",
        "apps/api/symfony.lock",
        "apps/api/config/packages/rate_limiter.yaml",
        "apps/api/config/packages/lock.yaml",
        "apps/api/src/Shared/Infrastructure/Http/",
        "apps/api/src/Cart/Domain/",
        "apps/api/src/Cart/UI/Http/",
        "apps/api/tests/Unit/Shared/",
        "apps/api/tests/Unit/Cart/",
        "apps/api/tests/Functional/Shared/",
        "apps/api/tests/Functional/Cart/",
        "apps/api/tests/Integration/Shared/",
        "apps/api/tests/Integration/Cart/",
        "apps/api/openapi.json",
        "apps/store/src/api/schema.d.ts",
        "apps/api/CLAUDE.md",
        "docs/features/hardening/spec.md",
        "docs/features/discount-codes/spec.md",
        "docs/features/cart/spec.md"
      ],
      "parallel_with": [
        "ABUSE-LIMITS-5",
        "ABUSE-LIMITS-6",
        "ABUSE-LIMITS-7",
        "ABUSE-LIMITS-8",
        "ABUSE-LIMITS-9"
      ]
    },
    {
      "id": "ABUSE-LIMITS-2",
      "title": "BFF sklepu: adres klienta w koszyku, obsługa 429 i limit treści JSON",
      "scope": "apps/store (spec: L4, D10 część BFF, D14, „Komunikaty”): forwardingClientIp dla wszystkich wywołań API z runCartCommand (także zakładanie koszyka i scalenie); 429 API koszyka (zapis, zakładanie, podgląd kodu) → 429 BFF z detail i Retry-After, wynik rate_limited w callCart; komunikaty na stronie koszyka, przy przycisku dodania i w polu kodu kasy (kasa czyta wtedy koszyk bez kodu); wspólny odczyt JSON z limitem 16 KiB (przed odczytem i w trakcie, 413 z polskim detail bez wywołania API) w trasach koszyka, kasy i płatności oraz 32 KiB w /api/revalidate. Dokumentacja: cart spec (kody BFF dla przeglądarki: 429 i 413 zamiast 503). Nie: limitery API (ABUSE-LIMITS-1), ingress (ABUSE-LIMITS-7).",
      "acceptance_criteria": [
        "Vitest: każda trasa koszyka (GET, summary, POST, PATCH, DELETE, zakładanie koszyka, gałąź klienta ze scaleniem) wysyła do API nagłówek z CLIENT_IP_HEADER, gdy zmienna jest ustawiona, a bez zmiennej żadnego.",
        "Vitest: 429 API (zapis, zakładanie, podgląd kodu) → 429 BFF z Retry-After i detail; strona koszyka, przycisk dodania i pole kodu pokazują teksty z sekcji „Komunikaty” spec; formularz kasy i koszyk zostają.",
        "Vitest: treść o 1 bajt ponad limit (z Content-Length, strumieniem bez niego, z zaniżonym Content-Length) to 413 bez wywołania API we wszystkich trasach z odczytem JSON i w /api/revalidate; treść równa limitowi przechodzi; /api/revalidate ze 100 tagami najdłuższego dozwolonego formatu przechodzi.",
        "Cart spec (kody BFF) zgodna z nowym zachowaniem; make -C apps/store check i test przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "apps/store/src/cart/",
        "apps/store/src/checkout/",
        "apps/store/src/api/client.ts",
        "apps/store/app/api/",
        "docs/features/cart/spec.md"
      ],
      "parallel_with": [
        "ABUSE-LIMITS-5",
        "ABUSE-LIMITS-6",
        "ABUSE-LIMITS-7",
        "ABUSE-LIMITS-8",
        "ABUSE-LIMITS-9"
      ]
    },
    {
      "id": "ABUSE-LIMITS-3",
      "title": "Limit wielkości zamówienia gościa (#332)",
      "scope": "apps/api Ordering i apps/store kasa (spec: L5, D5, „Komunikaty”): reguła domeny wielkości zamówienia gościa (10 pozycji, 10 sztuk jednej pozycji) sprawdzana w PlaceOrder dla gościa zaraz po blokadzie koszyka i sprawdzeniu powtórzenia, przed wyceną, kodem, numerem i rezerwacją, w kolejności odrzuceń z D5; 409 guest-order-too-large w OrderConflictProblem, eksport OpenAPI i klient TS; nowy powód w apps/store/src/checkout/problems.ts i obsługa w kasie jak inne 409 koszyka; E2E. Dokumentacja: checkout spec (tabela 409 złożenia), hardening spec (D16: 50 pozycji po 99 sztuk na zamówienie gościa). Nie: limity koszyka ani zamówienia klienta.",
      "acceptance_criteria": [
        "Jednostkowe (TDD): reguła przyjmuje 10 pozycji po 10 sztuk, odrzuca 11 pozycji i 11 sztuk jednej pozycji; PlaceOrder (adaptery in-memory) sprawdza ją dla gościa przed wyceną i rezerwacją, a dla klienta nie.",
        "Funkcjonalne: złożenie gościa z koszyka 11 pozycji (także z kodem z limitem) to 409 guest-order-too-large z polskim detail; stan, użycia kodu i koszyk bez zmian, brak zamówienia i wiadomości ExpireUnpaidOrder; zamówienie klienta z 50 pozycjami po 99 sztuk powstaje jak dziś.",
        "Próby obejścia: koszyk 11 pozycji z jedną niedostępną daje odrzucenie w kolejności z D5; równoległe złożenia z dwóch koszyków każde sprawdza swój rozmiar.",
        "E2E e2e/tests/checkout-guest-limits.spec.ts: gość z 11 pozycjami widzi komunikat w kasie i nic nie zostaje zamówione; istniejące scenariusze kasy przechodzą.",
        "openapi.json i klient TS zaktualizowane; make -C apps/api check i test, make -C apps/store check i test przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "apps/api/src/Ordering/Domain/",
        "apps/api/src/Ordering/Application/",
        "apps/api/src/Ordering/UI/Http/",
        "apps/api/tests/Unit/Ordering/",
        "apps/api/tests/Functional/Ordering/",
        "apps/api/openapi.json",
        "apps/store/src/api/schema.d.ts",
        "apps/store/src/checkout/",
        "e2e/tests/checkout-guest-limits.spec.ts",
        "docs/features/checkout/spec.md",
        "docs/features/hardening/spec.md"
      ],
      "parallel_with": [
        "ABUSE-LIMITS-5",
        "ABUSE-LIMITS-6",
        "ABUSE-LIMITS-7",
        "ABUSE-LIMITS-8",
        "ABUSE-LIMITS-9"
      ]
    },
    {
      "id": "ABUSE-LIMITS-4",
      "title": "Limit nieopłaconych zamówień na adres, e-mail gościa i konto",
      "scope": "apps/api Ordering i apps/store kasa (spec: L6, D6–D8, „Komunikaty”): adres klienta z ClientIp w komendzie PlaceOrder (poza odciskiem żądania); tabela roszczeń nieopłaconych zamówień (rekord ORM, migracja, indeksy po kluczu adresu i tożsamości z expires_at) zapisywana z zamówieniem i usuwana w markPaid i ExpireUnpaidOrder; blokady doradcze transakcji adres, potem tożsamość (jawny wyjątek DBAL), liczenie pod nimi; wartości w config/packages/ordering.yaml (prod 5 i 3, dev 10 000, test 20); 409 too-many-unpaid-orders w OpenAPI i kliencie TS, powód w problems.ts; e-mail k6 unikalny w iteracji i test plików k6. Dokumentacja: reguła dla agentów w apps/api/CLAUDE.md, jedna linia: „Blokada doradcza transakcji przez DBAL (pg_advisory_xact_lock, pg_try_advisory_xact_lock) do szeregowania jest dozwolonym wyjątkiem od persystencji przez rekordy ORM w każdym module; liczenie i zapis idą przez repozytorium.” (korzysta z niej też ABUSE-LIMITS-5); kolejność blokad złożenia zamówienia w apps/api/CLAUDE.md (koszyk, adres, tożsamość, kod, stany); zdania specyfikacji: checkout spec (tabela 409), discount-codes spec (D7: kolejność blokad koszyk → kod → stany), hardening spec (D16: limity liczą tylko złożenia); docblock ClientIp (kod). Nie: krótsza rezerwacja, limit wielkości (ABUSE-LIMITS-3).",
      "acceptance_criteria": [
        "Jednostkowe (TDD): klucz e-maila (odstępy, wielkość liter, +tag), klucz tożsamości gościa i klienta, reguła limitu z portem liczącym.",
        "Funkcjonalne (when@test): złożenie ponad limit adresu przy nieopłaconych poprzednich to 409 too-many-unpaid-orders; po opłaceniu (webhook) albo wygaśnięciu jednego z nich złożenie przechodzi; inny adres przechodzi; odpowiedź 409 jest taka sama dla limitu adresu, e-maila i konta.",
        "Próby obejścia: e-mail wielkimi literami, z odstępami i z +tag liczy się do tego samego limitu; konto klienta składa zamówienie mimo 3 nieopłaconych zamówień gościa z jego e-mailem.",
        "Integracyjny (PostgreSQL, procesy równoległe): N równoległych złożeń jednego adresu z różnymi e-mailami tworzy dokładnie tyle zamówień, ile pozwala limit adresu; to samo dla jednego e-maila i różnych adresów; raport pokazuje fazę RED.",
        "Powtórzenie złożenia (ten sam Idempotency-Key i treść) przy wyczerpanym limicie, także z innego adresu, zwraca 200 z istniejącym zamówieniem.",
        "Przy wyczerpanym limicie adresu i e-maila rozpoczęcie płatności istniejącego zamówienia new i jej ponowienie po nieudanej próbie działają jak dziś, a po opłaceniu kolejne złożenie przechodzi.",
        "Zamówienia opłacone i anulowane nie liczą się bez względu na liczbę: klient z 10 wygasłymi zamówieniami z tym samym e-mailem i adresem składa kolejne.",
        "Zamówienie między terminem płatności a wygaszeniem liczy się; roszczenie po expires_at nie liczy się, nawet gdy zamówienie jest wciąż new; po markPaid i po wygaśnięciu roszczenia nie ma; żaden widok API ani panelu nie zwraca adresu.",
        "Istniejące testy (PlaceOrderConcurrencyTest i inne z jednym e-mailem) przechodzą na wartościach when@test.",
        "Test plików k6 (infra/k8s/tests/test_load_files.py): e-mail checkoutu jest unikalny w iteracji; make load-smoke przechodzi.",
        "apps/api/CLAUDE.md ma regułę blokady doradczej z zakresu i kolejność blokad złożenia zgodną z D8; checkout, discount-codes i hardening spec zgodne z nowym zachowaniem.",
        "Migracja, openapi.json i klient TS zaktualizowane; make -C apps/api check i test, make -C apps/store check i test, make -C infra/k8s check przechodzą."
      ],
      "risk": "CRITICAL",
      "depends_on": [],
      "paths": [
        "apps/api/src/Ordering/",
        "apps/api/src/Shared/Infrastructure/Http/ClientIp.php",
        "apps/api/migrations/",
        "apps/api/config/packages/ordering.yaml",
        "apps/api/tests/Unit/Ordering/",
        "apps/api/tests/Functional/Ordering/",
        "apps/api/tests/Integration/Ordering/",
        "apps/api/openapi.json",
        "apps/store/src/api/schema.d.ts",
        "apps/store/src/checkout/",
        "load/lib/flows.js",
        "infra/k8s/tests/",
        "apps/api/CLAUDE.md",
        "docs/features/checkout/spec.md",
        "docs/features/discount-codes/spec.md",
        "docs/features/hardening/spec.md"
      ],
      "parallel_with": [
        "ABUSE-LIMITS-5",
        "ABUSE-LIMITS-6",
        "ABUSE-LIMITS-7",
        "ABUSE-LIMITS-8",
        "ABUSE-LIMITS-9"
      ]
    },
    {
      "id": "ABUSE-LIMITS-5",
      "title": "Równoległe rozpoczęcia płatności jednego zamówienia odrzucane od razu (409)",
      "scope": "apps/api Payment (spec: L7, D9): StartPayment po sprawdzeniu dostępu i powtórzenia bierze pg_try_advisory_xact_lock na zamówienie (port Payment, adapter DBAL jako jawny wyjątek), a bez blokady kończy się istniejącym PaymentInProgress::concurrentStart (409); kontrakt API bez zmian. Dokumentacja: payment spec (równoległe rozpoczęcia odrzuca unikalny indeks; ryzyko samoblokady wątków w A3). Nie: wywołanie operatora poza transakcją, symulator poza API.",
      "acceptance_criteria": [
        "Integracyjny (StartPaymentConcurrencyTest, procesy równoległe): przy rozpoczęciu trzymającym blokadę (operator wstrzymany) N równoległych rozpoczęć z różnymi kluczami kończy się 409 PaymentInProgress w czasie znacznie krótszym niż limit czasu operatora (pomiar w teście); powstaje jedna płatność pending; raport pokazuje fazę RED.",
        "Próby obejścia: ten sam klucz w locie i inny dostawca dostają 409 w trakcie pierwszego rozpoczęcia.",
        "Po zakończeniu pierwszego rozpoczęcia kolejne działa jak dziś; rozpoczęcia płatności różnych zamówień nie blokują się nawzajem.",
        "Payment spec (równoległe rozpoczęcia, W4, W5) zgodna z nowym zachowaniem; make -C apps/api check i test przechodzą."
      ],
      "risk": "CRITICAL",
      "depends_on": [],
      "paths": [
        "apps/api/src/Payment/",
        "apps/api/tests/Unit/Payment/",
        "apps/api/tests/Functional/Payment/",
        "apps/api/tests/Integration/Payment/",
        "docs/features/payment/spec.md"
      ],
      "parallel_with": [
        "ABUSE-LIMITS-1",
        "ABUSE-LIMITS-2",
        "ABUSE-LIMITS-3",
        "ABUSE-LIMITS-4",
        "ABUSE-LIMITS-6",
        "ABUSE-LIMITS-7",
        "ABUSE-LIMITS-8",
        "ABUSE-LIMITS-9"
      ]
    },
    {
      "id": "ABUSE-LIMITS-6",
      "title": "Błędny token Bearer bez zdarzenia Sentry i bez zrzutu logów",
      "scope": "apps/api konfiguracja Monologa (spec: L10, D13): logger obsługi tokenu dostępu w kanale security (services.yaml), kanał poza handlerem sentry i poza fingers_crossed w prod, z osobnym handlerem JSON od warning. Dokumentacja: brak zmian dokumentów (kryterium SENTRY „401 nie daje zdarzeń” zaczyna być prawdziwe). Nie: inne kanały i zgłaszanie 5xx.",
      "acceptance_criteria": [
        "Funkcjonalny z licznikiem zdarzeń transportu Sentry: Bearer x, token z obcym podpisem, wygasły, z innym wystawcą i z alg none na /api/v1/me i /api/v1/admin/orders dają 401 i zero zdarzeń; raport pokazuje fazę RED.",
        "Jawny log error kanału app nadal daje zdarzenie; niedostępny wystawca kluczy nadal daje 503.",
        "Test konfiguracji Monologa prod: kanał security poza handlerem sentry i poza fingers_crossed, z handlerem JSON od warning.",
        "make -C apps/api check i test przechodzą."
      ],
      "risk": "FAST",
      "depends_on": [],
      "paths": [
        "apps/api/config/packages/monolog.yaml",
        "apps/api/config/services.yaml",
        "apps/api/tests/Functional/Shared/",
        "apps/api/tests/Integration/Shared/"
      ],
      "parallel_with": [
        "ABUSE-LIMITS-1",
        "ABUSE-LIMITS-2",
        "ABUSE-LIMITS-3",
        "ABUSE-LIMITS-4",
        "ABUSE-LIMITS-5",
        "ABUSE-LIMITS-7",
        "ABUSE-LIMITS-8",
        "ABUSE-LIMITS-9"
      ]
    },
    {
      "id": "ABUSE-LIMITS-7",
      "title": "Limit rozmiaru i czasu treści żądania w API i na ingressie",
      "scope": "apps/api Caddy i chart (spec: L8, D10, E2, E4): Caddyfile: request_body 1 MB, wyjątek 71 MB tylko dla POST na dokładnej ścieżce /api/v1/admin/product-images, timeouts read_header 10 s i read_body 60 s, 413 jako problem JSON z polskim detail (handle_errors); chart: middleware buffering na hoście api (1 MiB, osobna ścieżka uploadu 71 MiB) i na ścieżce /api/ hosta sklepu (1 MiB), bez buforowania stron HTML, każdy nowy Ingress z tymi samymi middleware co jego host (w tym ipallowlist); testy renderu, test dymny, E2E na compose. Dokumentacja: brak zmian dokumentów poza komentarzami w Caddyfile i szablonach chartu. Nie: limit w BFF (ABUSE-LIMITS-2).",
      "acceptance_criteria": [
        "Raport zawiera wyniki eksperymentów E2 i E4 przed implementacją.",
        "E2E e2e/tests/request-body-limit.spec.ts na compose: JSON 1 MB + 1 bajt do POST /api/v1/orders to 413 z polskim detail bez wpisu błędu PHP; 1 MB przechodzi do walidacji; zdjęcie 20 MB do POST /api/v1/admin/product-images przechodzi, a 70 MB + 1 bajt daje dzisiejsze 422.",
        "Próby obejścia: treść chunked bez Content-Length, zaniżony Content-Length, ścieżka uploadu inną wielkością liter, z dopisanym segmentem i metodą PUT dostają limit 1 MB.",
        "Compose: wolna treść kończy się zamknięciem połączenia najpóźniej po 60 s, a /health odpowiada najpóźniej po tym czasie.",
        "Render chartu: ingress api ma buffering 1 MiB, ścieżka uploadu 71 MiB, ścieżka /api/ hosta sklepu 1 MiB; reszta hosta sklepu, admin, auth i img bez buffering; każdy Ingress w renderze prod, także nowy, ma ipallowlist.",
        "Test dymny k3d: 2 MiB do /api/checkout sklepu i do /api/v1/orders przez ingress to 413; przy wolnych treściach w liczbie większej niż wątki API /health przez ingress odpowiada od razu; strona produktu dalej przychodzi.",
        "make -C apps/api check i test, make -C infra/k8s check przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "apps/api/docker/frankenphp/Caddyfile",
        "infra/k8s/charts/shop/templates/ingress.yaml",
        "infra/k8s/charts/shop/templates/middlewares.yaml",
        "infra/k8s/tests/",
        "infra/k8s/smoke/",
        "e2e/tests/request-body-limit.spec.ts"
      ],
      "parallel_with": [
        "ABUSE-LIMITS-1",
        "ABUSE-LIMITS-2",
        "ABUSE-LIMITS-3",
        "ABUSE-LIMITS-4",
        "ABUSE-LIMITS-5",
        "ABUSE-LIMITS-6",
        "ABUSE-LIMITS-8",
        "ABUSE-LIMITS-9"
      ]
    },
    {
      "id": "ABUSE-LIMITS-8",
      "title": "Cache ISR strony produktu tylko w pamięci, z limitem, bez zapisu na dysk",
      "scope": "apps/store (spec: L9, D12, E3): cache przyrostowy sklepu (wpisy stron, także 404, i cache fetch) tylko w pamięci procesu opcjami Next w next.config.ts: experimental.isrFlushToDisk false i cacheMaxMemorySize 128 MB jako stała konfiguracji, bez własnego handlera i bez nowej zależności; rewalidacja po tagach bez zmian; slug spoza isProductSlug to 404 bez odczytu API; test na buildzie produkcyjnym jako skrypt w apps/store/scripts z celem make dopisanym do CI_AFTER_E2E (jak store-dynamic-routes), test konfiguracji w Vitest i pomiar pamięci z pełnym cache. Dokumentacja: product-page spec (A6 i ryzyko: cache ISR na dysku, wpisy 404 na dysku, zdanie o eksmisji poda jako ograniczeniu przyrostu); zdania, które przestają być prawdziwe: apps/store/CLAUDE.md (cache ISR zapisywany na dysku w obrazie produkcyjnym), small-fixes spec (uzasadnienie D4), komentarze przy ephemeral-storage w wartościach prod i load, opis testu test_ephemeral_storage.py, demo-scale spec (ryzyko cache ISR na dysku i warunek kolejności). Nie: limit ephemeral-storage kontenera store (zostaje z SMALL-FIXES), Redis jako wspólny cache, liczba replik.",
      "acceptance_criteria": [
        "Raport zawiera wynik eksperymentu E3 przed implementacją.",
        "Test na buildzie produkcyjnym działa w CI po E2E na stosie STORE_MODE=production (cel make w CI_AFTER_E2E): po wejściu na strony istniejących produktów i na tyle slugów nieistniejących, że wpisy przekraczają limit pamięci, katalog cache na dysku ma ten sam rozmiar co przed nimi, a pierwszy adres po przepełnieniu znów daje x-nextjs-cache MISS; test uruchomiony bez opcji isrFlushToDisk nie przechodzi (wynik obu uruchomień w raporcie).",
        "Vitest: next.config.ts ma experimental.isrFlushToDisk równe false i cacheMaxMemorySize równe stałej 128 MB.",
        "Raport zawiera pomiar w obrazie produkcyjnym sklepu z limitem pamięci kontenera 1 GiB: po N i po 2N unikalnych adresach, a potem w trakcie co najmniej 20 równoległych żądań stron spoza cache, proces nie został zrestartowany, RSS po 2N jest wyższy niż po N o mniej niż 10% i także pod ruchem równoległym mieści się w 768 MiB, a zajęta sterta V8 w 75% jej limitu; raport podaje RSS, zajętą stertę i limit sterty; inny wynik zatrzymuje zadanie i wraca z decyzją o rozmiarze cache.",
        "Jednostkowy: slug z wielkimi literami, kropką albo za długi to 404 bez wywołania API.",
        "product-page-freshness.spec.ts i inne E2E produkcyjne przechodzą bez zmian.",
        "Limit ephemeral-storage kontenera store bez zmian: asercje infra/k8s/tests/test_ephemeral_storage.py bez zmian.",
        "apps/store/CLAUDE.md, small-fixes spec, komentarze wartości prod i load, opis testu ephemeral-storage i demo-scale spec nie mówią już, że cache ISR rośnie na dysku.",
        "make -C apps/store check i test, make -C infra/k8s check przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "apps/store/app/p/",
        "apps/store/next.config.ts",
        "apps/store/src/catalog/",
        "apps/store/tests/",
        "apps/store/scripts/",
        "apps/store/Makefile",
        "apps/store/CLAUDE.md",
        "make/store.mk",
        "infra/k8s/environments/prod/values.yaml",
        "infra/k8s/environments/load/values.yaml",
        "infra/k8s/tests/",
        "docs/features/product-page/spec.md",
        "docs/features/small-fixes/spec.md",
        "docs/features/demo-scale/spec.md"
      ],
      "parallel_with": [
        "ABUSE-LIMITS-1",
        "ABUSE-LIMITS-2",
        "ABUSE-LIMITS-3",
        "ABUSE-LIMITS-4",
        "ABUSE-LIMITS-5",
        "ABUSE-LIMITS-6",
        "ABUSE-LIMITS-7",
        "ABUSE-LIMITS-9"
      ]
    },
    {
      "id": "ABUSE-LIMITS-9",
      "title": "Polityka haseł w realmach Keycloaka",
      "scope": "infra (spec: L11, D11, E5): passwordPolicy w infra/keycloak/realms/shop.json i backoffice.json; _keycloak.tpl kopiuje wartość z pliku realmu do <realm>.settings.json, więc Job keycloak-config ustawia ją przy każdym uruchomieniu; testy renderu i skryptu Joba; E2E rejestracji. Dokumentacja: brak zmian dokumentów (wartość jest w plikach realmów i szablonie). Nie: lista zakazanych haseł, limity prób logowania.",
      "acceptance_criteria": [
        "Raport zawiera wynik eksperymentu E5 przed implementacją.",
        "E2E registration.spec.ts: rejestracja z hasłem 11-znakowym, równym e-mailowi i z hasłem a kończy się komunikatem Keycloaka i bez konta; scenariusz z hasłem 12+ znaków przechodzi.",
        "Render chartu: shop.settings.json i backoffice.settings.json mają passwordPolicy równe wartości z plików realmów; test skryptu Joba na atrapie kcadm pokazuje wartość w update realms/<realm>.",
        "Konta demo z make seed logują się jak dziś.",
        "make -C infra/k8s check i E2E przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "infra/keycloak/realms/shop.json",
        "infra/keycloak/realms/backoffice.json",
        "infra/k8s/charts/shop/templates/_keycloak.tpl",
        "infra/k8s/tests/",
        "e2e/tests/registration.spec.ts"
      ],
      "parallel_with": [
        "ABUSE-LIMITS-1",
        "ABUSE-LIMITS-2",
        "ABUSE-LIMITS-3",
        "ABUSE-LIMITS-4",
        "ABUSE-LIMITS-5",
        "ABUSE-LIMITS-6",
        "ABUSE-LIMITS-7",
        "ABUSE-LIMITS-8"
      ]
    }
  ]
}
```
