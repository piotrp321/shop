# ABUSE-LIMITS – plan zadań

Specyfikacja: `docs/features/abuse-limits/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | ABUSE-LIMITS-1, ABUSE-LIMITS-2, ABUSE-LIMITS-3, ABUSE-LIMITS-4, ABUSE-LIMITS-5, ABUSE-LIMITS-6, ABUSE-LIMITS-7, ABUSE-LIMITS-8, ABUSE-LIMITS-9 | |

Stan epiku prowadzonego przez AO jest w `ao status`, a plan usuwa `FINALIZE`.

## Praca szeregowa

- ABUSE-LIMITS-1, ABUSE-LIMITS-2: `docs/features/cart/spec.md`; jedna specyfikacja koszyka: część API (czas życia, limity) i część BFF opisują dwie strony jednego przepływu.
- ABUSE-LIMITS-1, ABUSE-LIMITS-3: `apps/api/CLAUDE.md`, `apps/api/openapi.json`, `apps/store/src/api/schema.d.ts`, `docs/features/hardening/spec.md`; jeden wygenerowany kontrakt OpenAPI i klient TS.
- ABUSE-LIMITS-1, ABUSE-LIMITS-4: `apps/api/CLAUDE.md`, `apps/api/openapi.json`, `apps/api/src/Shared/Infrastructure/Http/ClientIp.php`, `apps/store/src/api/schema.d.ts`, `docs/features/discount-codes/spec.md`, `docs/features/hardening/spec.md`; jeden wygenerowany kontrakt OpenAPI i klient TS.
- ABUSE-LIMITS-1, ABUSE-LIMITS-5: `apps/api/CLAUDE.md`; jeden `CLAUDE.md` API, który każde z nich zmienia w tym samym PR co kod.
- ABUSE-LIMITS-1, ABUSE-LIMITS-6: `apps/api/CLAUDE.md`, `apps/api/config/services.yaml`; jeden plik definicji serwisów API.
- ABUSE-LIMITS-1, ABUSE-LIMITS-7: `apps/api/CLAUDE.md`; jeden `CLAUDE.md` API, który każde z nich zmienia w tym samym PR co kod.
- ABUSE-LIMITS-2, ABUSE-LIMITS-3: `apps/store/CLAUDE.md`, `apps/store/src/checkout`; jeden kod kasy (`problems.ts`, `CheckoutPage.tsx`) obsługuje wszystkie odpowiedzi złożenia.
- ABUSE-LIMITS-2, ABUSE-LIMITS-4: `apps/store/CLAUDE.md`, `apps/store/src/checkout`; jeden kod kasy (`problems.ts`, `CheckoutPage.tsx`) obsługuje wszystkie odpowiedzi złożenia.
- ABUSE-LIMITS-2, ABUSE-LIMITS-8: `apps/store/CLAUDE.md`; jeden `CLAUDE.md` sklepu, który każde z nich zmienia w tym samym PR co kod.
- ABUSE-LIMITS-3, ABUSE-LIMITS-4: `apps/api/CLAUDE.md`, `apps/api/openapi.json`, `apps/api/src/Ordering/Application`, `apps/api/src/Ordering/Domain`, `apps/api/src/Ordering/UI/Http`, `apps/store/CLAUDE.md`, `apps/store/src/api/schema.d.ts`, `apps/store/src/checkout`, `docs/features/checkout/spec.md`, `docs/features/hardening/spec.md`; oba zmieniają `PlaceOrder` i jego reguły w jednym module Ordering.
- ABUSE-LIMITS-3, ABUSE-LIMITS-5: `apps/api/CLAUDE.md`; jeden `CLAUDE.md` API, który każde z nich zmienia w tym samym PR co kod.
- ABUSE-LIMITS-3, ABUSE-LIMITS-6: `apps/api/CLAUDE.md`; jeden `CLAUDE.md` API, który każde z nich zmienia w tym samym PR co kod.
- ABUSE-LIMITS-3, ABUSE-LIMITS-7: `apps/api/CLAUDE.md`; jeden `CLAUDE.md` API, który każde z nich zmienia w tym samym PR co kod.
- ABUSE-LIMITS-3, ABUSE-LIMITS-8: `apps/store/CLAUDE.md`; jeden `CLAUDE.md` sklepu, który każde z nich zmienia w tym samym PR co kod.
- ABUSE-LIMITS-4, ABUSE-LIMITS-5: `apps/api/CLAUDE.md`; jeden `CLAUDE.md` API, który każde z nich zmienia w tym samym PR co kod.
- ABUSE-LIMITS-4, ABUSE-LIMITS-6: `apps/api/CLAUDE.md`; jeden `CLAUDE.md` API, który każde z nich zmienia w tym samym PR co kod.
- ABUSE-LIMITS-4, ABUSE-LIMITS-7: `apps/api/CLAUDE.md`; jeden `CLAUDE.md` API, który każde z nich zmienia w tym samym PR co kod.
- ABUSE-LIMITS-4, ABUSE-LIMITS-8: `apps/store/CLAUDE.md`; jeden `CLAUDE.md` sklepu, który każde z nich zmienia w tym samym PR co kod.
- ABUSE-LIMITS-5, ABUSE-LIMITS-6: `apps/api/CLAUDE.md`; jeden `CLAUDE.md` API, który każde z nich zmienia w tym samym PR co kod.
- ABUSE-LIMITS-5, ABUSE-LIMITS-7: `apps/api/CLAUDE.md`; jeden `CLAUDE.md` API, który każde z nich zmienia w tym samym PR co kod.
- ABUSE-LIMITS-6, ABUSE-LIMITS-7: `apps/api/CLAUDE.md`; jeden `CLAUDE.md` API, który każde z nich zmienia w tym samym PR co kod.

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
      "scope": "apps/api (spec: L1, L2, L3, D1–D3, D15, „Wymagane przed startem” E1): symfony/lock (zgoda użytkownika z „Wymaganych przed startem”) z magazynem w Redisie i dekoratorem LockFactory (TTL 5 s, czekanie najwyżej 300 ms, rywalizacja to 429, błąd Redisa fail open z warning) jako lock_factory wszystkich limiterów w prod i dev; nowe limitery guest_cart_create, guest_cart_write, discount_preview w config/packages/rate_limiter.yaml z kluczem adresu klienta; warunek parametru discountCode w mechanizmie limiterów tras (RateLimit, RateLimitSubscriber); trasy GuestCartController i CustomerCartController (odczyt z kodem); pusty koszyk gościa wygasa po 1 dniu (Cart::touch, założenie); 429 w OpenAPI tras koszyka, eksport openapi.json i klient TS sklepu. Dokumentacja: apps/api/CLAUDE.md, hardening spec (tabela limiterów, D1), discount-codes spec (limit podglądu), cart spec (czas życia pustego koszyka, limity API), vision „Bezpieczeństwo”. Nie: BFF i UI sklepu (ABUSE-LIMITS-2), komunikaty odrzucenia kodu (D4 bez zmian).",
      "acceptance_criteria": [
        "Raport zawiera wynik eksperymentu E1 (zachowanie blokady RateLimiter 8.1) przed implementacją.",
        "Integracyjny na prawdziwym Redisie: co najmniej 20 równoległych procesów do limitera o limicie L daje najwyżej L przyjętych; raport pokazuje fazę RED przy lock_factory: null.",
        "Blokada klucza trzymana dłużej niż 300 ms daje kolejnemu żądaniu tego klucza 429 po najwyżej ok. 300 ms; inny klucz nie czeka; porzucona blokada przestaje blokować po 5 s.",
        "Redis niedostępny: żądanie limitowanej trasy przechodzi w limitach czasu DSN i log ma warning (RedisRateLimitTest).",
        "Funkcjonalne (when@test): zakładanie koszyka gościa i zmiany jego linii ponad limit to 429 z Retry-After przed kontrolerem; POST, PUT i DELETE liczą się do jednego licznika; inny adres przechodzi; 429 nie zakłada koszyka ani nie zmienia linii.",
        "Próby obejścia L2 i L3: inny koszyk tego samego adresu, adres z tego samego /64, CF-Connecting-IP przy pustym CLIENT_IP_HEADER, nagłówek z dwoma adresami albo śmieciem, wielkość liter id koszyka, żądanie odrzucone walidacją (422) i discountCode za długi albo jako tablica (400) zużywają limit.",
        "Podgląd kodu ponad limit to 429 przed wyceną na trasie gościa i klienta, a przełączanie tras z jednego adresu nie zeruje licznika; kod małymi literami, z odstępami ASCII i NBSP zużywa ten sam licznik; ?discountCode= i odczyt bez parametru nie zużywają limitu.",
        "Jednostkowe (TDD): nowy koszyk gościa i koszyk po usunięciu ostatniej linii wygasają po 1 dniu, koszyk z linią po 30 dniach, koszyk klienta bez zmian.",
        "RateLimitConfigurationTest sprawdza wartości prod i dev z D3 oraz blokadę każdego limitera.",
        "openapi.json opisuje 429 na nowych trasach; klient TS sklepu wygenerowany; dokumenty z zakresu zaktualizowane; make -C apps/api check i test, make -C apps/store check przechodzą."
      ],
      "risk": "CRITICAL",
      "depends_on": [],
      "paths": [
        "apps/api/composer.json",
        "apps/api/composer.lock",
        "apps/api/symfony.lock",
        "apps/api/config/packages/rate_limiter.yaml",
        "apps/api/config/packages/lock.yaml",
        "apps/api/config/services.yaml",
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
        "docs/features/cart/spec.md",
        "docs/vision.md"
      ],
      "parallel_with": [
        "ABUSE-LIMITS-8",
        "ABUSE-LIMITS-9"
      ]
    },
    {
      "id": "ABUSE-LIMITS-2",
      "title": "BFF sklepu: adres klienta w koszyku, obsługa 429 i limit treści JSON",
      "scope": "apps/store (spec: L4, D10 część BFF, D14, „Komunikaty”): forwardingClientIp dla wszystkich wywołań API z runCartCommand (także zakładanie koszyka i scalenie); 429 API koszyka (zapis, zakładanie, podgląd kodu) → 429 BFF z detail i Retry-After, wynik rate_limited w callCart; komunikaty na stronie koszyka, przy przycisku dodania i w polu kodu kasy (kasa czyta wtedy koszyk bez kodu); wspólny odczyt JSON z limitem 16 KiB (przed odczytem i w trakcie, 413 z polskim detail bez wywołania API) w trasach koszyka, kasy i płatności oraz 32 KiB w /api/revalidate. Dokumentacja: apps/store/CLAUDE.md, cart spec (BFF). Nie: limitery API (ABUSE-LIMITS-1), ingress (ABUSE-LIMITS-7).",
      "acceptance_criteria": [
        "Vitest: każda trasa koszyka (GET, summary, POST, PATCH, DELETE, zakładanie koszyka, gałąź klienta ze scaleniem) wysyła do API nagłówek z CLIENT_IP_HEADER, gdy zmienna jest ustawiona, a bez zmiennej żadnego.",
        "Vitest: 429 API (zapis, zakładanie, podgląd kodu) → 429 BFF z Retry-After i detail; strona koszyka, przycisk dodania i pole kodu pokazują teksty z sekcji „Komunikaty” spec; formularz kasy i koszyk zostają.",
        "Vitest: treść o 1 bajt ponad limit (z Content-Length, strumieniem bez niego, z zaniżonym Content-Length) to 413 bez wywołania API we wszystkich trasach z odczytem JSON i w /api/revalidate; treść równa limitowi przechodzi; /api/revalidate ze 100 tagami najdłuższego dozwolonego formatu przechodzi.",
        "apps/store/CLAUDE.md i cart spec opisują nowe zachowanie BFF; make -C apps/store check i test przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "apps/store/src/cart/",
        "apps/store/src/checkout/",
        "apps/store/src/api/client.ts",
        "apps/store/app/api/",
        "apps/store/CLAUDE.md",
        "docs/features/cart/spec.md"
      ],
      "parallel_with": [
        "ABUSE-LIMITS-5",
        "ABUSE-LIMITS-6",
        "ABUSE-LIMITS-7",
        "ABUSE-LIMITS-9"
      ]
    },
    {
      "id": "ABUSE-LIMITS-3",
      "title": "Limit wielkości zamówienia gościa (#332)",
      "scope": "apps/api Ordering i apps/store kasa (spec: L5, D5, „Komunikaty”): reguła domeny wielkości zamówienia gościa (10 pozycji, 10 sztuk jednej pozycji) sprawdzana w PlaceOrder dla gościa zaraz po blokadzie koszyka i sprawdzeniu powtórzenia, przed wyceną, kodem, numerem i rezerwacją, w kolejności odrzuceń z D5; 409 guest-order-too-large w OrderConflictProblem, eksport OpenAPI i klient TS; nowy powód w apps/store/src/checkout/problems.ts i obsługa w kasie jak inne 409 koszyka; E2E. Dokumentacja: apps/api/CLAUDE.md (reguły złożenia), apps/store/CLAUDE.md, checkout spec, hardening spec (D16, D19). Nie: limity koszyka ani zamówienia klienta.",
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
        "apps/api/CLAUDE.md",
        "apps/store/CLAUDE.md",
        "docs/features/checkout/spec.md",
        "docs/features/hardening/spec.md"
      ],
      "parallel_with": [
        "ABUSE-LIMITS-9"
      ]
    },
    {
      "id": "ABUSE-LIMITS-4",
      "title": "Limit nieopłaconych zamówień na adres, e-mail gościa i konto",
      "scope": "apps/api Ordering i apps/store kasa (spec: L6, D6–D8, „Komunikaty”): adres klienta z ClientIp w komendzie PlaceOrder (poza odciskiem żądania); tabela roszczeń nieopłaconych zamówień (rekord ORM, migracja, indeksy po kluczu adresu i tożsamości z expires_at) zapisywana z zamówieniem i usuwana w markPaid i ExpireUnpaidOrder; blokady doradcze transakcji adres, potem tożsamość (jawny wyjątek DBAL), liczenie pod nimi; wartości w config/packages/ordering.yaml (prod 5 i 3, dev 10 000, test 20); 409 too-many-unpaid-orders w OpenAPI i kliencie TS, powód w problems.ts; e-mail k6 unikalny w iteracji i test plików k6. Dokumentacja: apps/api/CLAUDE.md (roszczenia, blokady, kolejność blokad), docblock ClientIp, apps/store/CLAUDE.md, checkout spec, discount-codes spec (kolejność blokad), hardening spec (D16, D19). Nie: krótsza rezerwacja, limit wielkości (ABUSE-LIMITS-3).",
      "acceptance_criteria": [
        "Jednostkowe (TDD): klucz e-maila (odstępy, wielkość liter, +tag), klucz tożsamości gościa i klienta, reguła limitu z portem liczącym.",
        "Funkcjonalne (when@test): złożenie ponad limit adresu przy nieopłaconych poprzednich to 409 too-many-unpaid-orders; po opłaceniu (webhook) albo wygaśnięciu jednego z nich złożenie przechodzi; inny adres przechodzi; odpowiedź 409 jest taka sama dla limitu adresu, e-maila i konta.",
        "Próby obejścia: e-mail wielkimi literami, z odstępami i z +tag liczy się do tego samego limitu; konto klienta składa zamówienie mimo 3 nieopłaconych zamówień gościa z jego e-mailem.",
        "Integracyjny (PostgreSQL, procesy równoległe): N równoległych złożeń jednego adresu z różnymi e-mailami tworzy dokładnie tyle zamówień, ile pozwala limit adresu; to samo dla jednego e-maila i różnych adresów; raport pokazuje fazę RED.",
        "Powtórzenie złożenia (ten sam Idempotency-Key i treść) przy wyczerpanym limicie, także z innego adresu, zwraca 200 z istniejącym zamówieniem.",
        "Zamówienie między terminem płatności a wygaszeniem liczy się; roszczenie po expires_at nie liczy się, nawet gdy zamówienie jest wciąż new; po markPaid i po wygaśnięciu roszczenia nie ma; żaden widok API ani panelu nie zwraca adresu.",
        "Istniejące testy (PlaceOrderConcurrencyTest i inne z jednym e-mailem) przechodzą na wartościach when@test.",
        "Test plików k6 (infra/k8s/tests/test_load_files.py): e-mail checkoutu jest unikalny w iteracji; make load-smoke przechodzi.",
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
        "apps/store/CLAUDE.md",
        "docs/features/checkout/spec.md",
        "docs/features/discount-codes/spec.md",
        "docs/features/hardening/spec.md"
      ],
      "parallel_with": [
        "ABUSE-LIMITS-9"
      ]
    },
    {
      "id": "ABUSE-LIMITS-5",
      "title": "Równoległe rozpoczęcia płatności jednego zamówienia odrzucane od razu (409)",
      "scope": "apps/api Payment (spec: L7, D9): StartPayment po sprawdzeniu dostępu i powtórzenia bierze pg_try_advisory_xact_lock na zamówienie (port Payment, adapter DBAL jako jawny wyjątek), a bez blokady kończy się istniejącym PaymentInProgress::concurrentStart (409); kontrakt API bez zmian. Dokumentacja: apps/api/CLAUDE.md (StartPayment), payment spec (W4, W5, rozpoczęcie). Nie: wywołanie operatora poza transakcją, symulator poza API.",
      "acceptance_criteria": [
        "Integracyjny (StartPaymentConcurrencyTest, procesy równoległe): przy rozpoczęciu trzymającym blokadę (operator wstrzymany) N równoległych rozpoczęć z różnymi kluczami kończy się 409 PaymentInProgress w czasie znacznie krótszym niż limit czasu operatora (pomiar w teście); powstaje jedna płatność pending; raport pokazuje fazę RED.",
        "Próby obejścia: ten sam klucz w locie i inny dostawca dostają 409 w trakcie pierwszego rozpoczęcia.",
        "Po zakończeniu pierwszego rozpoczęcia kolejne działa jak dziś; rozpoczęcia płatności różnych zamówień nie blokują się nawzajem.",
        "Scenariusze W4 i W5 payment spec opisują i sprawdzają nowe zachowanie; make -C apps/api check i test przechodzą."
      ],
      "risk": "CRITICAL",
      "depends_on": [],
      "paths": [
        "apps/api/src/Payment/",
        "apps/api/tests/Unit/Payment/",
        "apps/api/tests/Functional/Payment/",
        "apps/api/tests/Integration/Payment/",
        "apps/api/CLAUDE.md",
        "docs/features/payment/spec.md"
      ],
      "parallel_with": [
        "ABUSE-LIMITS-2",
        "ABUSE-LIMITS-8",
        "ABUSE-LIMITS-9"
      ]
    },
    {
      "id": "ABUSE-LIMITS-6",
      "title": "Błędny token Bearer bez zdarzenia Sentry i bez zrzutu logów",
      "scope": "apps/api konfiguracja Monologa (spec: L10, D13): logger obsługi tokenu dostępu w kanale security (services.yaml), kanał poza handlerem sentry i poza fingers_crossed w prod, z osobnym handlerem JSON od warning. Dokumentacja: apps/api/CLAUDE.md (kanały Sentry), sentry spec (kryterium 401). Nie: inne kanały i zgłaszanie 5xx.",
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
        "apps/api/tests/Integration/Shared/",
        "apps/api/CLAUDE.md",
        "docs/features/sentry/spec.md"
      ],
      "parallel_with": [
        "ABUSE-LIMITS-2",
        "ABUSE-LIMITS-8",
        "ABUSE-LIMITS-9"
      ]
    },
    {
      "id": "ABUSE-LIMITS-7",
      "title": "Limit rozmiaru i czasu treści żądania w API i na ingressie",
      "scope": "apps/api Caddy i chart (spec: L8, D10, E2, E4): FrankenPHP ≥ 1.13.5 w apps/api/Dockerfile, gdy na main jest starszy; Caddyfile: request_body 1 MB, wyjątek 71 MB tylko dla POST na dokładnej ścieżce /api/v1/admin/product-images, timeouts read_header 10 s i read_body 60 s, 413 jako problem JSON z polskim detail (handle_errors); chart: middleware buffering na hoście api (1 MiB, osobna ścieżka uploadu 71 MiB) i na ścieżce /api/ hosta sklepu (1 MiB), bez buforowania stron HTML; testy renderu, test dymny, E2E na compose. Dokumentacja: apps/api/CLAUDE.md (limit treści), infra/k8s/CLAUDE.md. Nie: limit w BFF (ABUSE-LIMITS-2).",
      "acceptance_criteria": [
        "Raport zawiera wyniki eksperymentów E2 i E4 przed implementacją.",
        "E2E e2e/tests/request-body-limit.spec.ts na compose: JSON 1 MB + 1 bajt do POST /api/v1/orders to 413 z polskim detail bez wpisu błędu PHP; 1 MB przechodzi do walidacji; zdjęcie 20 MB do POST /api/v1/admin/product-images przechodzi, a 70 MB + 1 bajt daje dzisiejsze 422.",
        "Próby obejścia: treść chunked bez Content-Length, zaniżony Content-Length, ścieżka uploadu inną wielkością liter, z dopisanym segmentem i metodą PUT dostają limit 1 MB.",
        "Compose: wolna treść kończy się zamknięciem połączenia najpóźniej po 60 s, a /health odpowiada najpóźniej po tym czasie.",
        "Render chartu: ingress api ma buffering 1 MiB, ścieżka uploadu 71 MiB, ścieżka /api/ hosta sklepu 1 MiB; reszta hosta sklepu, admin, auth i img bez buffering.",
        "Test dymny k3d: 2 MiB do /api/checkout sklepu i do /api/v1/orders przez ingress to 413; przy wolnych treściach w liczbie większej niż wątki API /health przez ingress odpowiada od razu; strona produktu dalej przychodzi.",
        "make -C apps/api check i test, make -C infra/k8s check przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "apps/api/docker/frankenphp/Caddyfile",
        "apps/api/Dockerfile",
        "apps/api/CLAUDE.md",
        "infra/k8s/charts/shop/templates/ingress.yaml",
        "infra/k8s/charts/shop/templates/middlewares.yaml",
        "infra/k8s/tests/",
        "infra/k8s/smoke/",
        "infra/k8s/CLAUDE.md",
        "e2e/tests/request-body-limit.spec.ts"
      ],
      "parallel_with": [
        "ABUSE-LIMITS-2",
        "ABUSE-LIMITS-8",
        "ABUSE-LIMITS-9"
      ]
    },
    {
      "id": "ABUSE-LIMITS-8",
      "title": "Cache ISR strony produktu ograniczony w pamięci, bez zapisu na dysk",
      "scope": "apps/store i chart (spec: L9, D12, E3): cache ISR i fetch ograniczony do 128 MB w pamięci poda bez zapisu na dysk (opcje Next albo własny handler w pamięci w apps/store/src/isr-cache/, bez nowej zależności), rewalidacja po tagach bez zmian; slug spoza isProductSlug to 404 bez odczytu API; limit ephemeral-storage 1Gi kontenera store w chartcie. Dokumentacja: apps/store/CLAUDE.md, product-page spec. Nie: Redis jako wspólny cache, liczba replik.",
      "acceptance_criteria": [
        "Raport zawiera wynik eksperymentu E3 przed implementacją.",
        "Test na buildzie produkcyjnym: po N unikalnych slugach przekraczających limit pamięci katalog cache na dysku ma ten sam rozmiar co przed nimi, a pierwszy slug po przepełnieniu znów daje x-nextjs-cache MISS.",
        "Jednostkowy: slug z wielkimi literami, kropką albo za długi to 404 bez wywołania API.",
        "product-page-freshness.spec.ts i inne E2E produkcyjne przechodzą bez zmian.",
        "Render chartu: kontener store ma limits.ephemeral-storage 1Gi, inne kontenery bez zmian.",
        "make -C apps/store check i test, make -C infra/k8s check przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "apps/store/app/p/",
        "apps/store/next.config.ts",
        "apps/store/src/catalog/",
        "apps/store/src/isr-cache/",
        "apps/store/tests/",
        "apps/store/Dockerfile",
        "apps/store/CLAUDE.md",
        "infra/k8s/charts/shop/values.yaml",
        "infra/k8s/tests/",
        "docs/features/product-page/spec.md"
      ],
      "parallel_with": [
        "ABUSE-LIMITS-1",
        "ABUSE-LIMITS-5",
        "ABUSE-LIMITS-6",
        "ABUSE-LIMITS-7",
        "ABUSE-LIMITS-9"
      ]
    },
    {
      "id": "ABUSE-LIMITS-9",
      "title": "Polityka haseł w realmach Keycloaka",
      "scope": "infra (spec: L11, D11, E5): passwordPolicy w infra/keycloak/realms/shop.json i backoffice.json; _keycloak.tpl kopiuje wartość z pliku realmu do <realm>.settings.json, więc Job keycloak-config ustawia ją przy każdym uruchomieniu; testy renderu i skryptu Joba; E2E rejestracji. Dokumentacja: CLAUDE.md (wartości ustawiane przez Job keycloak-config), deploy spec („Keycloak i konta”). Nie: lista zakazanych haseł, limity prób logowania.",
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
        "e2e/tests/registration.spec.ts",
        "CLAUDE.md",
        "docs/features/deploy/spec.md"
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
