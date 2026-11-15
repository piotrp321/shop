## Przegląd szkicu `docs/features/sentry/spec.md` (SENTRY, #180)

Szkic pokrywa wszystkie punkty issue #180. Niżej znaleziska według wagi.

### Important

**1. P6 kłóci się z D5 i AK2: błędy 404, 409 i 422 domeny oraz duplikaty staną się zdarzeniami** (techniczne; sekcje P6, D5, Kryteria akceptacji 2)
- Dowód:
  - Wyjątki domeny `NotFound`, `Conflict` i `InvalidInput` nie implementują `HttpExceptionInterface` (`apps/api/src/Shared/Domain/Exception/ProblemException.php`, `NotFound.php`).
  - `ErrorListener` frameworka loguje je na poziomie `critical` ("Uncaught PHP Exception"). Dzieje się to przed `ProblemDetailsSubscriber`, który ma priorytet −100 (`ProblemDetailsSubscriber.php`, komentarz przy `getSubscribedEvents`). Konfiguracja `framework.exceptions` ani `log_level` tego nie zmienia.
  - Handler Monologa od `error` (P6) zrobi więc zdarzenie z każdego 404, 409 i 422.
  - Do tego domyślny `ErrorListener` z `sentry-symfony` łapie każdy wyjątek z `kernel.exception`, także 4xx i `HandlerFailedException` opakowujący 409.
  - Przy 500 powstaną dwa zdarzenia: z listenera i z rekordu Monologa. AK2 wymaga jednego.
  - To samo dotyczy konsoli (log `critical` plus `ConsoleListener`) i ostatniej próby w Messengerze (`critical` z `SendFailedMessageForRetryListener` plus `MessengerListener`; do tego `capture_soft_fails` jest domyślnie włączone).
- Propozycja: opisać mechanizm w D5.
  - Wyłączyć `register_error_listener`.
  - Własny listener po `ProblemDetailsSubscriber` zgłasza wyjątek tylko przy statusie odpowiedzi ≥ 500.
  - Handler Monologa wyklucza rekordy, które opisują wyjątek złapany już przez listener (np. z `context.exception`), albo kanały `request`, `console` i `messenger`.
  - `capture_soft_fails: false`.
  - AK2 dopisuje test „jedno zdarzenie” dla 500, konsoli i workera.

**2. Model zagrożeń nie porównał domyślnych ustawień SDK. Są dwie otwarte drogi danych osobowych** (techniczne; sekcja Model zagrożeń, D4, D9)
- Argumenty ramek stosu w PHP. `sentry-php` dokleja do ramek wyjątku ich argumenty (`vars`). Obraz api nie ma `php.ini`, a `apps/api/docker/php/conf.d/prod.ini` nie ustawia `zend.exception_ignore_args`, więc argumenty są w śladach. Przykład: tablica treści żądania kasy przekazywana do denormalizera.
- `@sentry/vue` ma domyślnie `attachProps: true`. Propsy komponentów, np. szczegóły zamówienia z danymi klienta w `OrderDetailView`, trafią do zdarzenia.
- Propozycja: dodać obie drogi do listy.
  - Api: `zend.exception_ignore_args=On` w `prod.ini` i usuwanie `vars` w `before_send`, z testem.
  - Panel: `attachProps: false`, z testem.
  - Nie używać wtyczki Pinii (jej stan ma dane klientów) albo wpisać to wprost.

**3. D4 nie obejmuje breadcrumbs, danych spanów ani formatów, które Alloy już maskuje** (techniczne; sekcje D4 i Model zagrożeń)
- Breadcrumbs:
  - P6 robi breadcrumbs z rekordów Monologa niższego poziomu. `BreadcrumbHandler` przenosi do danych breadcrumba `context` i `extra` rekordu.
  - D4 ogranicza kontekst Monologa tylko w zdarzeniach, a w breadcrumbs filtruje jedynie adresy `fetch`, `xhr` i `navigation`.
  - Breadcrumbs `console` w przeglądarce i na serwerze Node niosą argumenty `console.*`.
- Dane spanów: D4 usuwa query string tylko z nazw spanów. Spany klienta HTTP mają go też w danych (`http.query`, `url.full`).
- Komunikaty wyjątków: D4 zastępuje w nich tylko e-maile. Tymczasem `infra/k8s/argocd/apps/monitoring.yaml:113-185` maskuje w logach znane formaty z danymi:
  - `Failing row contains (…)` i `Key (…)=(…)` z PostgreSQL oraz `with params [...]` z DBAL;
  - JWT, `Bearer` i hasło w adresie URL;
  - pary klucz=wartość z sekretami.

  Te same komunikaty wyjątków DBAL trafią do Sentry niezamaskowane. To nie jest „nieprzewidziany format” z listy „Poza zakresem”.
- Propozycja: osobne drogi w modelu ze sprawdzeniem.
  - Breadcrumbs: kontekst Monologa ograniczony jak w zdarzeniu, maskowanie wiadomości.
  - Spany: dane z query string usuwane.
  - Komunikaty i wartości wyjątków: ta sama lista wzorców co w Alloy. Testy na wierszach z `infra/k8s/tests/log-corpus`.

**4. Wymuszanie próbkowania z internetu: droga poza listą i poza „Poza zakresem”** (techniczne; sekcje Model zagrożeń i D7)
- Dowód: D7 przyjmuje domyślną kontynuację trace'u z nagłówków. Każdy może wysłać `sentry-trace: …-1` do publicznego api albo do serwera sklepu i wymusić 100% transakcji. Wyczerpuje to limit spanów darmowego planu i podnosi narzut. Nadużycie DSN z „Poza zakresem” to inna droga.
- Propozycja (rekomendacja):
  - Serwer sklepu nie kontynuuje trace'u z przychodzących nagłówków. Przeglądarka sklepu nie śledzi (P2), więc nie ma prawowitego źródła takiego trace'u.
  - Api kontynuuje (wymaga tego panel). Ryzyko wpisać jawnie do „Poza zakresem” z ochroną przed skokami w Sentry, albo dodać `traces_sampler` z limitem.

**5. Mapy źródeł mogą po cichu nie zostać wysłane przez współdzielony cache** (techniczne; sekcje P3, D10, Scenariusze błędów)
- Dowód: w `.github/workflows/deploy.yml` job `smoke` na PR buduje `target: prod` sklepu i panelu bez sekretu, w tym samym `scope=deploy-store` i `scope=deploy-admin` co job `images` (linie 118-145 i 284-306). Montowanie sekretu nie wchodzi do klucza cache BuildKit. Na `main` warstwa `npm run build` z tymi samymi źródłami przyjdzie więc z cache PR, a wysyłka się nie wykona.
  - Dokładnie tego „cichego braku” szkic chce uniknąć.
  - Do tego sekret jest dostępny także w PR z tego repo.
- Propozycja:
  - Przekazywać sekret tylko w jobie `images`.
  - Dodać do kroku budowania argument `ARG` z flagą wysyłki, który wchodzi do klucza cache (albo osobny scope cache).
  - Test w `test_deploy_workflow.py`.

**6. Scenariusz „wysyłka w api po odpowiedzi (`kernel.terminate`)” nie jest zachowaniem SDK** (techniczne; sekcja Scenariusze błędów)
- Dowód: transport HTTP `sentry-php` 4.x wysyła zdarzenie synchronicznie w chwili przechwycenia, czyli na `kernel.exception`, przed odpowiedzią. Na `terminate` wysyłane są tylko transakcje.
- Propozycja: wybrać jeden wariant.
  - (a) Rekomendacja: zapisać, że odpowiedź 5xx może się opóźnić o co najwyżej limit transportu (`http_connect_timeout` i `http_timeout` ustawione na 2 s), plus test.
  - (b) Własny transport buforujący z wysyłką na `kernel.terminate`. To decyzja architektoniczna.

### Minor

**7. D1: zachowanie przy nieprawidłowym DSN** (techniczne; sekcja D1)
- Szkic przypisuje SDK zachowania, których nie ma. `@sentry/nextjs` przy złym DSN loguje błąd i nie przerywa startu. W api wyjątek pojawi się dopiero przy pierwszym użyciu huba, nie przy starcie. Kryteria akceptacji tego nie sprawdzają.
- Propozycja: walidacja formatu DSN wzorcem w `values.schema.json`. W aplikacjach zachowanie SDK bez obietnicy błędu startu albo jawny test.

**8. D8: `error.tsx` sklepu** (techniczne; sekcje D8 i Kryteria akceptacji 3)
- Błąd renderowania na serwerze trafia już do Sentry przez `onRequestError`. W przeglądarce `error.tsx` dostaje go z samym `digest`, więc zgłoszenie z `error.tsx` będzie duplikatem.
- Statyczny import SDK w `error.tsx` (ładowanym razem z segmentem) łamie P2 i AK3.
- Propozycja: zgłaszać tylko błędy bez `digest`, przez ten sam leniwy moduł z `src/observability`.

**9. Linia `Zakres:` bez katalogu `apps/admin/src/stores`** (techniczne; linia `Zakres:`)
- Dowód: `setUser` po `/me` (P5) wymaga zmiany w `apps/admin/src/stores/auth.ts:21`, a tego katalogu nie ma w zakresie.
- Propozycja: dopisać katalog. Doprecyzować czyszczenie `user` przy wylogowaniu, także przy wylogowaniu po bezczynności.

**10. Telemetria wtyczek budowania** (techniczne; sekcje D10 i Kryteria akceptacji 1)
- `withSentryConfig` i `@sentry/vite-plugin` domyślnie wysyłają telemetrię do sentry.io przy każdym buildzie: `STORE_MODE=production` w CI i E2E oraz obrazy.
- Propozycja: `telemetry: false` i zapis w D10.

### Werdykt

**DO POPRAWY**: 6 znalezisk Important (1–6), 0 Critical.